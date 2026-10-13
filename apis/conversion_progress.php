<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$downloadDir = __DIR__ . '/../downloads/';
$target = isset($_GET['target']) ? basename($_GET['target']) : '';
$source = isset($_GET['source']) ? basename($_GET['source']) : '';

if ($target === '' || $source === '') {
    echo json_encode(['error' => 'missing params']);
    exit;
}

$targetPath = $downloadDir . $target;
$sourcePath = $downloadDir . $source;

$sourceSize = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
$targetSize = is_file($targetPath) ? (int) filesize($targetPath) : 0;

$percent = 0;
if ($sourceSize > 0 && $targetSize > 0) {
    // estimativa: arquivo convertido H264 costuma ter ~60-80% do original
    $estimatedFinal = (int) ($sourceSize * 0.7);
    $percent = min(99, (int) round($targetSize / $estimatedFinal * 100));
}

echo json_encode([
    'target_exists' => is_file($targetPath),
    'target_mb'     => $targetSize > 0 ? round($targetSize / 1048576, 1) : 0,
    'source_mb'     => $sourceSize > 0 ? round($sourceSize / 1048576, 1) : 0,
    'percent'       => $percent,
]);
