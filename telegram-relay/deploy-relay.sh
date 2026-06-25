#!/bin/bash
# One-command deployment helper for Telegram Relay (run on the Kazakhstan server)

set -e

echo "=== Telegram Relay Quick Deploy ==="

# Create directory
mkdir -p ~/tg-relay
cd ~/tg-relay

echo "Creating relay.php ..."
cat > relay.php << 'RELAYEOF'
<?php
/**
 * Telegram Relay - Self-hosted proxy for Telegram Bot API
 * 
 * Security features:
 * - IP whitelist (ALLOWED_IPS)
 * - Shared secret (RELAY_SECRET)
 * 
 * Supports:
 * - sendMessage (text)
 * - sendPhoto, sendDocument, sendVideo, sendAudio (with file upload)
 * 
 * Usage from main app:
 * POST to this URL with:
 *   secret=...
 *   chat_id=...
 *   method=sendMessage (or sendPhoto etc.)
 *   text=...          (for text)
 *   file=...          (multipart for media)
 *   caption=... (optional)
 *   parse_mode=Markdown (optional)
 */

// === CONFIG from environment (robust to inline comments) ===
function cleanEnvValue($val) {
    if (!$val) return '';
    // strip inline # comments and trim
    $val = preg_replace('/\s*#.*$/', '', $val);
    return trim($val);
}

$rawAllowed = cleanEnvValue(getenv('ALLOWED_IPS') ?: '');
$allowedIps = array_filter(array_map('trim', explode(',', $rawAllowed)));

$relaySecret = cleanEnvValue(getenv('RELAY_SECRET') ?: '');
$botToken    = cleanEnvValue(getenv('TELEGRAM_BOT_TOKEN') ?: '');

// Parse input early (JSON or form)
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    }
}

// === DEBUG: always log hits (even blocked) ===
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';
$reqMethod  = $_SERVER['REQUEST_METHOD'] ?? '';
$reqUri     = $_SERVER['REQUEST_URI'] ?? '';
error_log("Relay HIT: ip=$clientIp method=$reqMethod uri=$reqUri allowed=[" . implode(',', $allowedIps) . "] input=" . json_encode($input));

// Simple health for GET / (so tests don't 403 just for connectivity)
if (in_array($reqMethod, ['GET', 'HEAD']) && ($reqUri === '/' || $reqUri === '' || $reqUri === '/index.php')) {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo "TG Relay OK\n";
    exit;
}

// === SECURITY ===

// IP whitelist
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'IP not allowed: ' . $clientIp]);
    exit;
}

// Secret
$providedSecret = $input['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? '');
if (!empty($relaySecret) && $providedSecret !== $relaySecret) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

if (empty($botToken)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN is not set on relay']);
    exit;
}

// === INPUT ===
$method     = $input['method'] ?? 'sendMessage';
$chatId     = $input['chat_id'] ?? '';
$parseMode  = $input['parse_mode'] ?? 'Markdown';

if (empty($chatId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'chat_id is required']);
    exit;
}

$telegramUrl = "https://api.telegram.org/bot{$botToken}/{$method}";

// === BUILD PAYLOAD ===
$postFields = [
    'chat_id' => $chatId,
];

if (!empty($parseMode)) {
    $postFields['parse_mode'] = $parseMode;
}

if (isset($_FILES['file'])) {
    // === MEDIA ===
    $fileKey = 'photo';
    if ($method === 'sendDocument') $fileKey = 'document';
    elseif ($method === 'sendVideo')   $fileKey = 'video';
    elseif ($method === 'sendAudio')   $fileKey = 'audio';

    $postFields[$fileKey] = new CURLFile(
        $_FILES['file']['tmp_name'],
        $_FILES['file']['type'] ?? 'application/octet-stream',
        $_FILES['file']['name'] ?? 'file'
    );

    if (!empty($input['caption'])) {
        $postFields['caption'] = $input['caption'];
    }
} else {
    // === TEXT ===
    $postFields['text'] = $input['text'] ?? $input['message'] ?? '';
}

// === FORWARD TO TELEGRAM ===
$ch = curl_init($telegramUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Relay cURL error: ' . $err]);
    exit;
}

http_response_code($httpCode);
header('Content-Type: application/json');
echo $response;
RELAYEOF

echo "Creating .env ..."
cat > .env << 'ENVEOF'
ALLOWED_IPS=83.220.173.171
RELAY_SECRET=super-long-random-secret-change-me-1234567890abcdefGHIJK
TELEGRAM_BOT_TOKEN=8799707857:AAFep4R8r750mh9egn1KlOl3NAqMWd9c-Xw
ENVEOF

echo "Creating docker-compose.yml ..."
cat > docker-compose.yml << 'COMPOSEEOF'
services:
  relay:
    image: php:8.2-apache
    container_name: tg-relay
    restart: unless-stopped
    ports:
      - "80:80"
    volumes:
      - ./relay.php:/var/www/html/index.php:ro
    env_file:
      - .env
    # Suppress apache warning + basic security in script
    command: >
      bash -c 'echo "ServerName localhost" >> /etc/apache2/apache2.conf && apache2-foreground'
COMPOSEEOF

echo "Starting relay..."
docker compose up -d

echo ""
echo "=== Relay is starting ==="
echo "Check status: docker compose logs -f"
echo "Test from main server later with: curl http://YOUR_KAZAKH_IP"
echo ""
echo "IMPORTANT: Edit .env and set correct ALLOWED_IPS (main server public IP)"
echo "Then: docker compose down && docker compose up -d"
