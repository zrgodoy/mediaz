<?php
// admin/files.php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$downloadDir = __DIR__ . '/../downloads/';
$statusMsg = '';
$statusType = 'info';

if (!file_exists($downloadDir)) {
    mkdir($downloadDir, 0755, true);
}

function appendConversionLog(string $downloadDir, array $entry): void
{
    $logDir = dirname(rtrim($downloadDir, '/\\')) . '/logs/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logPath = $logDir . 'conversion.log';
    $entry['time'] = date('c');
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}


function redirectFiles(string $status = '', string $msg = ''): void
{
    $query = ['_ts' => (string) time()];
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($msg !== '') {
        $query['msg'] = $msg;
    }
    header('Location: files.php?' . http_build_query($query));
    exit;
}

// Ações de gerenciamento/conversão
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['filename'])) {
        $fileToDelete = basename($_POST['filename']);
        $targetPath = $downloadDir . $fileToDelete;
        if (file_exists($targetPath) && is_file($targetPath)) {
            @unlink($targetPath);
        }
    } elseif ($_POST['action'] === 'convert_premiere' && isset($_POST['filename'])) {
        $sourceName = basename($_POST['filename']);
        $sourcePath = $downloadDir . $sourceName;
        $startedAt = microtime(true);

        if (!is_file($sourcePath)) {
            appendConversionLog($downloadDir, [
                'event' => 'convert_premiere_error',
                'source' => $sourceName,
                'error' => 'source_not_found',
            ]);
            redirectFiles('error', 'Arquivo de origem não encontrado para conversão.');
        }

        $targetName = pathinfo($sourceName, PATHINFO_FILENAME) . '__premiere.mp4';
        $targetPath = $downloadDir . $targetName;
        appendConversionLog($downloadDir, [
            'event' => 'convert_premiere_start',
            'source' => $sourceName,
            'target' => $targetName,
        ]);

        $cmd = sprintf(
            'ffmpeg -y -i %s -c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p -profile:v high -level 4.1 -c:a aac -b:a 256k -ar 48000 -movflags +faststart %s 2>&1',
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );
        $output = [];
        $exitCode = 1;
        set_time_limit(0);
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) <= 0) {
            $tailError = implode(' | ', array_slice($output, -3));
            appendConversionLog($downloadDir, [
                'event' => 'convert_premiere_error',
                'source' => $sourceName,
                'target' => $targetName,
                'exit_code' => $exitCode,
                'duration_s' => round(microtime(true) - $startedAt, 2),
                'error_tail' => $tailError,
            ]);
            redirectFiles('error', 'Falha na conversão: ' . $tailError);
        }
        appendConversionLog($downloadDir, [
            'event' => 'convert_premiere_success',
            'source' => $sourceName,
            'target' => $targetName,
            'duration_s' => round(microtime(true) - $startedAt, 2),
            'size_bytes' => filesize($targetPath),
        ]);

        redirectFiles('success', 'Arquivo convertido/reconvertido para Premiere: ' . $targetName);
    } elseif ($_POST['action'] === 'convert_mp3' && isset($_POST['filename'])) {
        $sourceName = basename($_POST['filename']);
        $sourcePath = $downloadDir . $sourceName;
        $bitrate = (string) ($_POST['bitrate'] ?? '128');
        $startedAt = microtime(true);
        if (!in_array($bitrate, ['96', '128'], true)) {
            $bitrate = '128';
        }

        if (!is_file($sourcePath)) {
            appendConversionLog($downloadDir, [
                'event' => 'convert_mp3_error',
                'source' => $sourceName,
                'bitrate' => $bitrate,
                'error' => 'source_not_found',
            ]);
            redirectFiles('error', 'Arquivo de origem não encontrado para conversão.');
        }

        $targetName = pathinfo($sourceName, PATHINFO_FILENAME) . '__mp3_' . $bitrate . 'k.mp3';
        $targetPath = $downloadDir . $targetName;
        appendConversionLog($downloadDir, [
            'event' => 'convert_mp3_start',
            'source' => $sourceName,
            'target' => $targetName,
            'bitrate' => $bitrate,
        ]);
        if (!is_file($targetPath)) {
            $cmd = sprintf(
                'ffmpeg -y -i %s -vn -c:a libmp3lame -b:a %sk %s 2>&1',
                escapeshellarg($sourcePath),
                $bitrate,
                escapeshellarg($targetPath)
            );
            $output = [];
            $exitCode = 1;
            set_time_limit(0);
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0 || !is_file($targetPath)) {
                $tailError = implode(' | ', array_slice($output, -3));
                appendConversionLog($downloadDir, [
                    'event' => 'convert_mp3_error',
                    'source' => $sourceName,
                    'target' => $targetName,
                    'bitrate' => $bitrate,
                    'exit_code' => $exitCode,
                    'duration_s' => round(microtime(true) - $startedAt, 2),
                    'error_tail' => $tailError,
                ]);
                redirectFiles('error', 'Falha na conversão MP3: ' . $tailError);
            }
        }
        appendConversionLog($downloadDir, [
            'event' => 'convert_mp3_success',
            'source' => $sourceName,
            'target' => $targetName,
            'bitrate' => $bitrate,
            'duration_s' => round(microtime(true) - $startedAt, 2),
            'size_bytes' => is_file($targetPath) ? filesize($targetPath) : 0,
        ]);

        redirectFiles('success', 'Arquivo convertido para MP3 ' . $bitrate . 'kbps: ' . $targetName);
    } elseif ($_POST['action'] === 'clear_all') {
        foreach (glob($downloadDir . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        redirectFiles('success', 'Todos os arquivos foram removidos.');
    }
    redirectFiles();
}

if (isset($_GET['msg'])) {
    $statusMsg = (string) $_GET['msg'];
    $statusType = ((string) ($_GET['status'] ?? 'info')) === 'error' ? 'error' : 'success';
}

clearstatcache(true);
$files = glob($downloadDir . '*');
$fileList = [];
if ($files !== false) {
    $nameSet = [];
    foreach ($files as $file) {
        if (is_file($file)) {
            $nameSet[strtolower(basename($file))] = true;
        }
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            $name = basename($file);
            $lowerName = strtolower($name);
            $typeLabel = 'Original';
            $typeClass = 'bg-blue-950/60 text-blue-300 border-blue-700';
            $isConverted = false;
            $sourceBase = '';

            if (str_ends_with($lowerName, '__premiere.mp4')) {
                $typeLabel = 'H264 Premiere';
                $typeClass = 'bg-emerald-950/60 text-emerald-300 border-emerald-700';
                $isConverted = true;
                $sourceBase = substr($name, 0, -strlen('__premiere.mp4'));
            } elseif (str_ends_with($lowerName, '__mp3_128k.mp3')) {
                $typeLabel = 'MP3 128k';
                $typeClass = 'bg-amber-950/60 text-amber-300 border-amber-700';
                $isConverted = true;
                $sourceBase = substr($name, 0, -strlen('__mp3_128k.mp3'));
            } elseif (str_ends_with($lowerName, '__mp3_96k.mp3')) {
                $typeLabel = 'MP3 96k';
                $typeClass = 'bg-yellow-950/60 text-yellow-300 border-yellow-700';
                $isConverted = true;
                $sourceBase = substr($name, 0, -strlen('__mp3_96k.mp3'));
            } elseif (str_ends_with($lowerName, '.mp3')) {
                $typeLabel = 'MP3';
                $typeClass = 'bg-amber-950/60 text-amber-300 border-amber-700';
                $isConverted = true;
            }

            $fileList[] = [
                'name' => $name,
                'size' => round(filesize($file) / (1024 * 1024), 2), // em MB
                'time' => date('Y-m-d H:i:s', filemtime($file)),
                'url' => '../downloads/' . $name,
                'download_url' => 'download_file.php?file=' . rawurlencode($name),
                'is_premiere' => str_ends_with(strtolower($name), '__premiere.mp4'),
                'is_mp3' => str_ends_with(strtolower($name), '.mp3'),
                'is_mp3_128' => str_ends_with(strtolower($name), '__mp3_128k.mp3'),
                'is_mp3_96' => str_ends_with(strtolower($name), '__mp3_96k.mp3'),
                'type_label' => $typeLabel,
                'type_class' => $typeClass,
                'is_converted' => $isConverted,
                'source_base' => $sourceBase
            ];

            if (!$isConverted) {
                $premiereVariant = strtolower(pathinfo($name, PATHINFO_FILENAME) . '__premiere.mp4');
                $fileList[count($fileList) - 1]['has_premiere_variant'] = isset($nameSet[$premiereVariant]);
            } else {
                $fileList[count($fileList) - 1]['has_premiere_variant'] = false;
            }
        }
    }
}
// Ordena por mais recente
usort($fileList, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediaZ - Gerenciar Arquivos</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen p-6">
    <div id="processingOverlay" class="hidden fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center px-4">
        <div class="w-full max-w-md bg-gray-800 border border-gray-700 rounded-xl p-5 shadow-2xl">
            <h2 class="text-sm font-semibold text-indigo-300 mb-2">Processando conversão</h2>
            <p id="processingMessage" class="text-xs text-gray-300 mb-4">Convertendo arquivo no servidor. Aguarde...</p>
            <div class="flex items-center justify-between text-[11px] text-gray-400 mb-2">
                <span id="processingPhase">Iniciando FFmpeg...</span>
                <span id="processingElapsed">00:00</span>
            </div>
            <div class="w-full h-2 bg-gray-700 rounded-full overflow-hidden">
                <div class="h-full w-1/3 bg-indigo-500 rounded-full animate-pulse" style="animation: slidebar 1.2s ease-in-out infinite;"></div>
            </div>
        </div>
    </div>
    <style>
        @keyframes slidebar {
            0% { transform: translateX(-120%); }
            50% { transform: translateX(90%); }
            100% { transform: translateX(260%); }
        }
    </style>

    <div class="max-w-4xl mx-auto bg-gray-800 rounded-xl shadow-2xl p-6 border border-gray-700">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-blue-400">Gerenciador de Downloads</h1>
                <p class="text-sm text-gray-400">Exclua arquivos ou limpe o servidor diretamente pela web.</p>
            </div>
            <div class="space-x-2">
                <a href="../index.php" class="bg-gray-700 hover:bg-gray-600 text-gray-200 px-4 py-2 rounded-lg text-sm transition">Voltar ao Início</a>
                <?php if (!empty($fileList)): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="clear_all">
                        <button type="submit" onclick="return confirm('Tem certeza que deseja apagar TODOS os arquivos?')" 
                            class="bg-red-600 hover:bg-red-500 text-white px-4 py-2 rounded-lg text-sm transition">Limpar Tudo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($statusMsg !== ''): ?>
            <div class="mb-4 p-3 rounded-lg text-sm border <?= $statusType === 'error' ? 'bg-red-950/40 border-red-700 text-red-200' : 'bg-emerald-950/40 border-emerald-700 text-emerald-200' ?>">
                <?= htmlspecialchars($statusMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($fileList)): ?>
            <div class="text-center py-12 text-gray-500">
                A pasta de downloads está vazia.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto sm:overflow-x-visible">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-700 text-gray-400 text-sm">
                            <th class="py-3 px-4">Nome do Arquivo</th>
                            <th class="py-3 px-4">Tipo</th>
                            <th class="py-3 px-4">Tamanho</th>
                            <th class="py-3 px-4">Data</th>
                            <th class="py-3 px-4 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700 text-sm">
                        <?php foreach ($fileList as $f): ?>
                            <tr class="hover:bg-gray-750 transition <?= $f['is_converted'] ? 'bg-gray-800/70' : '' ?>">
                                <td class="py-3 px-4 font-medium text-white max-w-sm" title="<?= htmlspecialchars($f['name']) ?>">
                                    <div class="break-all"><?= htmlspecialchars($f['name']) ?></div>
                                    <?php if ($f['is_converted'] && $f['source_base'] !== ''): ?>
                                        <div class="text-[11px] text-gray-400 mt-1">Convertido de: <?= htmlspecialchars($f['source_base']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center border px-2 py-0.5 rounded text-xs font-semibold <?= $f['type_class'] ?>"><?= htmlspecialchars($f['type_label']) ?></span>
                                </td>
                                <td class="py-3 px-4 text-gray-300"><?= $f['size'] ?> MB</td>
                                <td class="py-3 px-4 text-gray-400"><?= $f['time'] ?></td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                    <a href="<?= htmlspecialchars($f['download_url']) ?>" class="inline-flex items-center bg-blue-600 hover:bg-blue-500 text-white px-3 py-1.5 rounded-md text-xs font-medium transition">Baixar</a>
                                    <?php if (!$f['is_premiere']): ?>
                                        <?php if (!empty($f['has_premiere_variant'])): ?>
                                            <form method="POST" class="inline convert-form" data-processing-label="Reconvertendo para H.264 (Premiere)...">
                                                <input type="hidden" name="action" value="convert_premiere">
                                                <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                                <button type="submit" class="inline-flex items-center bg-emerald-900/70 hover:bg-emerald-800 text-emerald-200 border border-emerald-700 px-3 py-1.5 rounded-md text-xs font-medium transition">Reconverter H264</button>
                                            </form>
                                        <?php else: ?>
                                        <form method="POST" class="inline convert-form" data-processing-label="Convertendo para H.264 (Premiere)...">
                                            <input type="hidden" name="action" value="convert_premiere">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                            <button type="submit" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-md text-xs font-medium transition">Converter H264</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!$f['is_mp3']): ?>
                                        <form method="POST" class="inline convert-form" data-processing-label="Convertendo para MP3 128kbps...">
                                            <input type="hidden" name="action" value="convert_mp3">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                            <input type="hidden" name="bitrate" value="128">
                                            <button type="submit" class="inline-flex items-center bg-amber-600 hover:bg-amber-500 text-white px-3 py-1.5 rounded-md text-xs font-medium transition">MP3 128k</button>
                                        </form>
                                        <form method="POST" class="inline convert-form" data-processing-label="Convertendo para MP3 96kbps...">
                                            <input type="hidden" name="action" value="convert_mp3">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                            <input type="hidden" name="bitrate" value="96">
                                            <button type="submit" class="inline-flex items-center bg-yellow-600 hover:bg-yellow-500 text-white px-3 py-1.5 rounded-md text-xs font-medium transition">MP3 96k</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                        <button type="submit" onclick="return confirm('Deseja realmente excluir este arquivo?')" 
                                            class="inline-flex items-center bg-red-600 hover:bg-red-500 text-white px-3 py-1.5 rounded-md text-xs font-medium transition">Excluir</button>
                                    </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        const overlay = document.getElementById('processingOverlay');
        const processingMessage = document.getElementById('processingMessage');
        const processingPhase = document.getElementById('processingPhase');
        const processingElapsed = document.getElementById('processingElapsed');
        const convertForms = document.querySelectorAll('.convert-form');
        let progressTimer = null;

        const formatElapsed = (totalSeconds) => {
            const min = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const sec = String(totalSeconds % 60).padStart(2, '0');
            return `${min}:${sec}`;
        };

        convertForms.forEach((form) => {
            form.addEventListener('submit', () => {
                // Desabilita todos os botões de conversão
                convertForms.forEach((f) => {
                    const btn = f.querySelector('button[type="submit"]');
                    if (btn) { btn.disabled = true; btn.classList.add('opacity-60', 'cursor-not-allowed'); }
                });
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.textContent = 'Processando...';

                const label = form.getAttribute('data-processing-label') || 'Processando no servidor...';
                processingMessage.textContent = label + ' Aguarde...';
                processingPhase.textContent = 'Iniciando FFmpeg...';
                processingElapsed.textContent = '00:00';
                overlay.classList.remove('hidden');

                // Descobre source e target para polling de progresso
                const filenameInput = form.querySelector('input[name="filename"]');
                const sourceFile = filenameInput ? filenameInput.value : '';
                const action = form.querySelector('input[name="action"]') ? form.querySelector('input[name="action"]').value : '';
                const bitrate = form.querySelector('input[name="bitrate"]') ? form.querySelector('input[name="bitrate"]').value : '';
                let targetFile = '';
                if (action === 'convert_premiere' && sourceFile) {
                    targetFile = sourceFile.replace(/\.[^.]+$/, '') + '__premiere.mp4';
                } else if (action === 'convert_mp3' && sourceFile && bitrate) {
                    targetFile = sourceFile.replace(/\.[^.]+$/, '') + '__mp3_' + bitrate + 'k.mp3';
                }

                const start = Date.now();
                if (progressTimer) clearInterval(progressTimer);

                const pollProgress = () => {
                    const elapsedSeconds = Math.floor((Date.now() - start) / 1000);
                    processingElapsed.textContent = formatElapsed(elapsedSeconds);

                    if (targetFile && sourceFile) {
                        fetch('../apis/conversion_progress.php?source=' + encodeURIComponent(sourceFile) + '&target=' + encodeURIComponent(targetFile))
                            .then(r => r.json())
                            .then(data => {
                                if (data.target_mb > 0) {
                                    processingPhase.textContent = 'Escrevendo: ' + data.target_mb + ' MB de ~' + data.source_mb + ' MB fonte (' + data.percent + '%)';
                                } else {
                                    processingPhase.textContent = elapsedSeconds < 10 ? 'Iniciando FFmpeg...' : 'FFmpeg processando...';
                                }
                            })
                            .catch(() => {
                                processingPhase.textContent = 'Convertendo... ' + formatElapsed(elapsedSeconds);
                            });
                    } else {
                        processingPhase.textContent = 'FFmpeg em execução... ' + formatElapsed(elapsedSeconds);
                    }
                };

                progressTimer = setInterval(pollProgress, 2000);
                pollProgress();
            });
        });
    </script>
</body>
</html>