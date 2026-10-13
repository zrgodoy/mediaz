<?php
// download.php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$url = trim($_POST['url'] ?? '');
$formatType = $_POST['format'] ?? 'video';

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'URL inválida ou vazia.']);
    exit;
}

// Pasta de destino dos arquivos salvos
$downloadDir = __DIR__ . '/../downloads/';
if (!file_exists($downloadDir)) {
    mkdir($downloadDir, 0755, true);
}

// ==========================================
// ROTINA DE SEGURANÇA: Limpeza automática
// Apaga arquivos com mais de 30 minutos na pasta
// ==========================================
foreach (glob($downloadDir . '*') as $oldFile) {
    if (is_file($oldFile) && (time() - filemtime($oldFile) > 1800)) {
        @unlink($oldFile);
    }
}

// Configuração do comando yt-dlp
// O yt-dlp detecta automaticamente se é YouTube, Instagram, Facebook, TikTok, etc.
$outputTemplate = $downloadDir . '%(title)s.%(ext)s';

if ($formatType === 'audio') {
    // Baixar e converter para MP3
    $cmd = sprintf('yt-dlp -x --audio-format mp3 -o %s %s 2>&1', 
        escapeshellarg($outputTemplate), 
        escapeshellarg($url)
    );
} else {
    // Força a mesclagem correta para MP4 compatível com web
    $cmd = sprintf('yt-dlp -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4] / bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1', 
        escapeshellarg($outputTemplate), 
        escapeshellarg($url)
    );
}

// Executa o comando no servidor
exec($cmd, $output, $exitCode);

if ($exitCode !== 0) {
   // ALTERAÇÃO TEMPORÁRIA DE DIAGNÓSTICO:
    // Em vez de uma mensagem genérica, vamos devolver o erro cru do yt-dlp para a tela
    echo json_encode([
        'success' => false, 
        'error' => 'Debug yt-dlp: ' . implode(' | ', $output),
        'exit_code' => $exitCode
    ]);
    exit;
}

// Localiza o arquivo recém-criado na pasta downloads comparando por data de modificação
$files = glob($downloadDir . '*');
if (empty($files)) {
    echo json_encode(['success' => false, 'error' => 'O arquivo não foi gerado corretamente.']);
    exit;
}

usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestFile = $files[0];
$fileName = basename($latestFile);
$fileUrl = '../downloads/' . $fileName; // Sobe um nível para o front-end encontrar o arquivo

echo json_encode([
    'success' => true,
    'file_url' => $fileUrl
]);