<?php
header('Content-Type: application/json');

$requestId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_GET['request_id'] ?? ''));
if ($requestId === '') {
    echo json_encode(['success' => false, 'error' => 'request_id ausente']);
    exit;
}

$progressFile = __DIR__ . '/../logs/progress_' . $requestId . '.json';
if (!is_file($progressFile)) {
    echo json_encode([
        'success' => true,
        'found' => false,
        'request_id' => $requestId
    ]);
    exit;
}

$content = @file_get_contents($progressFile);
if ($content === false || trim($content) === '') {
    echo json_encode([
        'success' => true,
        'found' => false,
        'request_id' => $requestId
    ]);
    exit;
}

$data = json_decode($content, true);
if (!is_array($data)) {
    echo json_encode([
        'success' => false,
        'error' => 'arquivo de progresso invalido',
        'request_id' => $requestId
    ]);
    exit;
}

echo json_encode(array_merge(['success' => true, 'found' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
