<?php
// One deployment per bot. Put this behind an HTTPS reverse proxy.
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (in_array($method, ['GET', 'HEAD'], true)) {
    echo json_encode(['ok' => true, 'service' => 'telegram-relay']);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    exit;
}
$secret = trim(getenv('RELAY_SECRET') ?: '');
$token = trim(getenv('TELEGRAM_BOT_TOKEN') ?: '');
$allowed = array_filter(array_map('trim', explode(',', getenv('ALLOWED_IPS') ?: '')));
$input = $_POST;
if (! $input) {
    $input = json_decode(file_get_contents('php://input'), true);
}
$input = is_array($input) ? $input : [];
$provided = $input['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? '');
if ($secret === '' || ! is_string($provided) || ! hash_equals($secret, $provided)
    || ($allowed && ! in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed, true))) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
if ($token === '' || ! extension_loaded('curl')) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}
$apiMethod = $input['method'] ?? 'sendMessage';
$fields = [
    'sendMessage' => ['chat_id', 'text', 'message', 'parse_mode', 'protect_content'],
    'sendPhoto' => ['chat_id', 'caption', 'parse_mode'],
    'sendVideo' => ['chat_id', 'caption', 'parse_mode'],
    'sendAudio' => ['chat_id', 'caption', 'parse_mode'],
    'sendDocument' => ['chat_id', 'caption', 'parse_mode'],
    'getMe' => [],
    'getWebhookInfo' => [],
    'getUpdates' => ['timeout', 'allowed_updates'],
    'setWebhook' => ['url', 'secret_token', 'allowed_updates'],
    'deleteWebhook' => ['drop_pending_updates'],
];
if (! is_string($apiMethod) || ! isset($fields[$apiMethod])) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
$payload = array_intersect_key($input, array_flip($fields[$apiMethod]));
if ($apiMethod === 'sendMessage') {
    $payload['text'] = $payload['text'] ?? $payload['message'] ?? '';
    unset($payload['message']);
}
if (isset($_FILES['file']) && in_array($apiMethod, ['sendPhoto', 'sendVideo', 'sendAudio', 'sendDocument'], true)) {
    if (! is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        exit;
    }
    $field = ['sendPhoto' => 'photo', 'sendVideo' => 'video', 'sendAudio' => 'audio', 'sendDocument' => 'document'][$apiMethod];
    $payload[$field] = new CURLFile($_FILES['file']['tmp_name'], 'application/octet-stream', basename($_FILES['file']['name']));
}
foreach ($payload as $key => $value) {
    if (is_array($value)) $payload[$key] = json_encode($value);
    if (is_bool($value)) $payload[$key] = $value ? 'true' : 'false';
}
$curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$apiMethod);
curl_setopt_array($curl, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true,
]);
$result = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if ($result === false || $status < 100) {
    http_response_code(502);
    echo json_encode(['ok' => false]);
    exit;
}
http_response_code($status);
echo $result;
