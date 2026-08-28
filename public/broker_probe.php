<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$enabled = (getenv('AI_ENABLED') ?: '0') === '1';
$url = trim((string)(getenv('AI_BROKER_URL') ?: ''));
$token = trim((string)(getenv('AI_BROKER_TOKEN') ?: ''));
$project = trim((string)(getenv('AI_PROJECT_ID') ?: 'cursos-ia-mvp'));

if (!$enabled || $url === '' || $token === '' || $project === '') {
    fwrite(STDERR, "broker_not_configured\n");
    exit(2);
}

$payload = json_encode([
    'project_id' => $project,
    'capability' => '__connectivity_probe__',
    'input' => [
        'user' => 'Teste interno de conectividade do Cursos IA com o Centro IA.',
    ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$ch = curl_init($url);
if ($ch === false) {
    fwrite(STDERR, "curl_init_failed\n");
    exit(3);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'X-Vitrine-Project: ' . $project,
    ],
    CURLOPT_POSTFIELDS => $payload,
]);

$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "broker_unreachable:" . $error . "\n");
    exit(4);
}

$body = json_decode((string) $response, true);
$errorCode = is_array($body) ? (string)($body['error'] ?? '') : '';

if ($status === 422 && $errorCode === 'capability_not_supported') {
    echo "BROKER_OK\n";
    exit(0);
}

fwrite(STDERR, "broker_probe_failed:http=" . $status . ";error=" . $errorCode . "\n");
exit(5);