<?php
// Força download de arquivo da pasta downloads/
$downloadDir = realpath(__DIR__ . '/../downloads');
$fileName = basename((string) ($_GET['file'] ?? ''));

if ($downloadDir === false || $fileName === '') {
    http_response_code(400);
    exit('Parâmetro inválido.');
}

$filePath = realpath($downloadDir . DIRECTORY_SEPARATOR . $fileName);
if ($filePath === false || strpos($filePath, $downloadDir) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$size = filesize($filePath);
$mime = mime_content_type($filePath) ?: 'application/octet-stream';

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
header('Content-Length: ' . (string) $size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
