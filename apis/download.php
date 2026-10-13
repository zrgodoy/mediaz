<?php
// apis/download.php
header('Content-Type: application/json');

function resolveCookiesFile(): string
{
    $envPath = trim((string) getenv('MEDIAZ_YTDLP_COOKIES'));
    if ($envPath !== '' && is_file($envPath) && is_readable($envPath)) {
        return $envPath;
    }

    $candidates = [
        __DIR__ . '/../config/youtube_cookies.txt',
        __DIR__ . '/../cookies.txt',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function hasYouTubeAuthCookies(string $cookiesFile): bool
{
    if ($cookiesFile === '' || !is_file($cookiesFile) || !is_readable($cookiesFile)) {
        return false;
    }

    $content = (string) @file_get_contents($cookiesFile);
    if ($content === '') {
        return false;
    }

    $tokens = ['SID', 'HSID', 'SSID', 'SAPISID', '__Secure-1PSID', '__Secure-3PSID'];
    foreach ($tokens as $token) {
        if (stripos($content, "\t{$token}\t") !== false) {
            return true;
        }
    }

    return false;
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function cacheMetaPath(string $metaDir, string $cacheKey): string
{
    return rtrim($metaDir, '/\\') . DIRECTORY_SEPARATOR . $cacheKey . '.json';
}

function readCachedFileUrl(string $metaDir, string $cacheKey, string $downloadDir): string
{
    $metaPath = cacheMetaPath($metaDir, $cacheKey);
    if (!is_file($metaPath)) {
        return '';
    }

    $raw = (string) @file_get_contents($metaPath);
    if ($raw === '') {
        return '';
    }

    $meta = json_decode($raw, true);
    if (!is_array($meta)) {
        return '';
    }

    $fileName = basename((string) ($meta['file_name'] ?? ''));
    if ($fileName === '') {
        return '';
    }

    $fullPath = rtrim($downloadDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($fullPath)) {
        return '';
    }

    return 'downloads/' . $fileName;
}

function writeCacheMeta(string $metaDir, string $cacheKey, string $fileName, string $url, string $formatType): void
{
    $metaPath = cacheMetaPath($metaDir, $cacheKey);
    $payload = [
        'file_name' => $fileName,
        'source_url' => $url,
        'format' => $formatType,
        'updated_at' => date('c'),
    ];
    @file_put_contents($metaPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function appendRequestLog(string $downloadDir, array $event): void
{
    $logPath = rtrim($downloadDir, '/\\') . DIRECTORY_SEPARATOR . '.requests-log.jsonl';
    $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function runYtDlp(string $primaryCmd, string $fallbackCmd): array
{
    $output = [];
    $exitCode = 1;
    exec($primaryCmd, $output, $exitCode);

    if ($exitCode !== 0) {
        $retryReason = implode("\n", array_slice($output, -8));
        if ($fallbackCmd !== '' && stripos($retryReason, 'Sign in to confirm you') !== false) {
            $output = [];
            $exitCode = 1;
            exec($fallbackCmd, $output, $exitCode);
        }
    }

    return [$output, $exitCode];
}

function latestGeneratedFile(string $downloadDir): string
{
    $files = glob(rtrim($downloadDir, '/\\') . DIRECTORY_SEPARATOR . '*');
    if (empty($files)) {
        return '';
    }

    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    return (string) $files[0];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$url = trim($_POST['url'] ?? '');
$formatType = $_POST['format'] ?? 'video';

// Compatibilidade com o front mais novo que envia codec_preset.
if (isset($_POST['codec_preset'])) {
    $preset = (string) $_POST['codec_preset'];
    if ($preset === 'audio_mp3') {
        $formatType = 'audio';
    } elseif ($preset === 'h264') {
        $formatType = 'video_premiere';
    } elseif ($preset === 'best') {
        $formatType = 'video_original';
    } else {
        $formatType = 'video_original';
    }
}

if ($formatType === 'video') {
    $formatType = 'video_original';
}

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'URL inválida ou vazia.']);
    exit;
}

// Pasta de destino dos arquivos salvos (subindo de apis/ para a raiz de mediaz)
$downloadDir = __DIR__ . '/../downloads/';
if (!file_exists($downloadDir)) {
    mkdir($downloadDir, 0755, true);
}
$metaDir = $downloadDir . '.meta/';
$lockDir = __DIR__ . '/../tmp/';
ensureDir($metaDir);
ensureDir($lockDir);

// Mesmo link + mesmo formato/perfil retorna arquivo pronto sem baixar novamente.
if ($formatType === 'audio') {
    $pipelineProfile = 'audio_mp3_v1';
} elseif ($formatType === 'video_premiere') {
    $pipelineProfile = 'video_h264_aac_v2';
} else {
    $pipelineProfile = 'video_original_v1';
}
$cacheKey = sha1(trim($url) . '|' . $formatType . '|' . $pipelineProfile);

// Limpeza automática de arquivos com mais de 30 minutos
foreach (glob($downloadDir . '*') as $oldFile) {
    if (is_file($oldFile) && (time() - filemtime($oldFile) > 1800)) {
        @unlink($oldFile);
    }
}

$cachedFileUrl = readCachedFileUrl($metaDir, $cacheKey, $downloadDir);
if ($cachedFileUrl !== '') {
    appendRequestLog($downloadDir, [
        'time' => date('c'),
        'event' => 'cache_hit',
        'url' => $url,
        'format' => $formatType,
        'cache_key' => $cacheKey,
        'file_url' => $cachedFileUrl,
    ]);
    echo json_encode([
        'success' => true,
        'file_url' => $cachedFileUrl,
        'cached' => true
    ]);
    exit;
}

// Evita várias tentativas simultâneas do mesmo link/formato.
$lockPath = $lockDir . 'download_' . $cacheKey . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle !== false) {
    flock($lockHandle, LOCK_EX);
}

// Outro processo pode ter finalizado enquanto aguardávamos o lock.
$cachedFileUrl = readCachedFileUrl($metaDir, $cacheKey, $downloadDir);
if ($cachedFileUrl !== '') {
    if ($lockHandle !== false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    appendRequestLog($downloadDir, [
        'time' => date('c'),
        'event' => 'cache_hit_after_lock',
        'url' => $url,
        'format' => $formatType,
        'cache_key' => $cacheKey,
        'file_url' => $cachedFileUrl,
    ]);
    echo json_encode([
        'success' => true,
        'file_url' => $cachedFileUrl,
        'cached' => true
    ]);
    exit;
}

$outputTemplate = $downloadDir . '%(title)s.%(ext)s';
$cookiesFile = resolveCookiesFile();
$parsedHost = strtolower((string) parse_url($url, PHP_URL_HOST));
$isYouTube = str_contains($parsedHost, 'youtube.com') || str_contains($parsedHost, 'youtu.be');
$hasAuthCookies = hasYouTubeAuthCookies($cookiesFile);
$cookiesArg = ($cookiesFile !== '' && $hasAuthCookies) ? ' --cookies ' . escapeshellarg($cookiesFile) : '';
$output = [];
$exitCode = 1;

if ($formatType === 'video_premiere') {
    $originalCacheKey = sha1(trim($url) . '|video_original|video_original_v1');
    $originalFileUrl = readCachedFileUrl($metaDir, $originalCacheKey, $downloadDir);

    if ($originalFileUrl === '') {
        $downloadPrimaryCmd = sprintf(
            'yt-dlp --js-runtimes node%s -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
            $cookiesArg,
            escapeshellarg($outputTemplate),
            escapeshellarg($url)
        );
        $downloadFallbackCmd = sprintf(
            'yt-dlp --js-runtimes node%s --extractor-args "youtube:player_client=android" -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
            $cookiesArg,
            escapeshellarg($outputTemplate),
            escapeshellarg($url)
        );

        [$output, $exitCode] = runYtDlp($downloadPrimaryCmd, $downloadFallbackCmd);
        if ($exitCode === 0) {
            $latestOriginal = latestGeneratedFile($downloadDir);
            if ($latestOriginal !== '') {
                $latestOriginalName = basename($latestOriginal);
                $originalFileUrl = 'downloads/' . $latestOriginalName;
                writeCacheMeta($metaDir, $originalCacheKey, $latestOriginalName, $url, 'video_original');
                appendRequestLog($downloadDir, [
                    'time' => date('c'),
                    'event' => 'download_success_original_for_premiere',
                    'url' => $url,
                    'cache_key' => $originalCacheKey,
                    'file_name' => $latestOriginalName,
                ]);
            } else {
                $exitCode = 1;
                $output[] = 'Arquivo original nao foi encontrado apos download.';
            }
        }
    }

    if ($exitCode === 0 || $originalFileUrl !== '') {
        $sourceName = basename($originalFileUrl);
        $sourcePath = rtrim($downloadDir, '/\\') . DIRECTORY_SEPARATOR . $sourceName;
        $premiereFileName = pathinfo($sourceName, PATHINFO_FILENAME) . '__premiere.mp4';
        $premierePath = rtrim($downloadDir, '/\\') . DIRECTORY_SEPARATOR . $premiereFileName;

        if (!is_file($premierePath)) {
            $ffmpegCmd = sprintf(
                'ffmpeg -y -i %s -c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p -profile:v high -level 4.1 -c:a aac -b:a 256k -ar 48000 -movflags +faststart %s 2>&1',
                escapeshellarg($sourcePath),
                escapeshellarg($premierePath)
            );
            $output = [];
            exec($ffmpegCmd, $output, $exitCode);
        } else {
            $exitCode = 0;
            $output = [];
        }

        if ($exitCode === 0 && is_file($premierePath)) {
            writeCacheMeta($metaDir, $cacheKey, $premiereFileName, $url, $formatType);
        }
    }
} elseif ($formatType === 'audio') {
    $primaryCmd = sprintf(
        'yt-dlp --js-runtimes node%s -x --audio-format mp3 -o %s %s 2>&1',
        $cookiesArg,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
    $fallbackCmd = sprintf(
        'yt-dlp --js-runtimes node%s --extractor-args "youtube:player_client=android" -x --audio-format mp3 -o %s %s 2>&1',
        $cookiesArg,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
    [$output, $exitCode] = runYtDlp($primaryCmd, $fallbackCmd);
} else {
    $primaryCmd = sprintf(
        'yt-dlp --js-runtimes node%s -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
        $cookiesArg,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
    $fallbackCmd = sprintf(
        'yt-dlp --js-runtimes node%s --extractor-args "youtube:player_client=android" -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
        $cookiesArg,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
    [$output, $exitCode] = runYtDlp($primaryCmd, $fallbackCmd);
}

if ($exitCode !== 0) {
    $lastLines = array_slice($output, -12);
    $rawReason = trim(implode("\n", array_map(static function ($line) {
        return trim((string) $line);
    }, $lastLines)));
    $reason = $rawReason !== '' ? substr(strip_tags($rawReason), 0, 1200) : 'Falha inesperada no yt-dlp.';

    if (stripos($reason, 'No supported JavaScript runtime could be found') !== false) {
        $reason = 'Runtime JavaScript ausente para yt-dlp. Instale Node.js no servidor.';
    } elseif (stripos($reason, 'Sign in to confirm you') !== false) {
        if ($isYouTube) {
            if ($cookiesFile === '') {
                $reason = 'YouTube bloqueou a requisicao (anti-bot). Configure cookies em mediaz/config/youtube_cookies.txt.';
            } elseif (!$hasAuthCookies) {
                $reason = 'YouTube bloqueou a requisicao. O arquivo de cookies existe, mas nao tem cookies de login (SID/PSID). Exporte novamente com sessao logada.';
            } else {
                $reason = 'YouTube bloqueou a requisicao mesmo com cookies. Renove os cookies e tente novamente.';
            }
        }
    }

    echo json_encode([
        'success' => false,
        'error' => 'Falha ao processar link: ' . $reason
    ]);
    appendRequestLog($downloadDir, [
        'time' => date('c'),
        'event' => 'download_error',
        'url' => $url,
        'format' => $formatType,
        'cache_key' => $cacheKey,
        'error' => $reason,
    ]);
    if ($lockHandle !== false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    exit;
}

$files = glob($downloadDir . '*');
if (empty($files)) {
    if ($lockHandle !== false) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    echo json_encode(['success' => false, 'error' => 'O arquivo não foi gerado corretamente.']);
    exit;
}

usort($files, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

$resolvedFileUrl = readCachedFileUrl($metaDir, $cacheKey, $downloadDir);
if ($resolvedFileUrl === '') {
    $latestFile = $files[0];
    $fileName = basename($latestFile);
    $resolvedFileUrl = 'downloads/' . $fileName;
    writeCacheMeta($metaDir, $cacheKey, $fileName, $url, $formatType);
} else {
    $fileName = basename($resolvedFileUrl);
}
appendRequestLog($downloadDir, [
    'time' => date('c'),
    'event' => 'download_success',
    'url' => $url,
    'format' => $formatType,
    'cache_key' => $cacheKey,
    'file_name' => $fileName,
    'file_url' => $resolvedFileUrl,
]);

if ($lockHandle !== false) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

echo json_encode([
    'success' => true,
    'file_url' => $resolvedFileUrl
]);