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
 * Security: IP whitelist + secret
 * Supports: sendMessage + basic media (photo, document, video, audio)
 */

// === CONFIG (from environment) ===
$allowedIps = array_filter(array_map('trim', explode(',', getenv('ALLOWED_IPS') ?: '')));
$relaySecret = getenv('RELAY_SECRET') ?: '';
$botToken    = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// === IP CHECK ===
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'IP not allowed']);
    exit;
}

// === SECRET CHECK ===
$providedSecret = $_POST['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? '');
if (!empty($relaySecret) && $providedSecret !== $relaySecret) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

if (empty($botToken)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN not set on relay']);
    exit;
}

// === REQUEST DATA ===
$method    = $_POST['method'] ?? 'sendMessage';
$chatId    = $_POST['chat_id'] ?? '';
$parseMode = $_POST['parse_mode'] ?? 'Markdown';

if (empty($chatId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'chat_id is required']);
    exit;
}

$tgUrl = "https://api.telegram.org/bot{$botToken}/{$method}";

// === BUILD REQUEST TO TELEGRAM ===
$post = ['chat_id' => $chatId];
if (!empty($parseMode)) $post['parse_mode'] = $parseMode;

if (isset($_FILES['file'])) {
    $key = 'photo';
    if ($method === 'sendDocument') $key = 'document';
    elseif ($method === 'sendVideo')   $key = 'video';
    elseif ($method === 'sendAudio')   $key = 'audio';

    $post[$key] = new CURLFile(
        $_FILES['file']['tmp_name'],
        $_FILES['file']['type'] ?? 'application/octet-stream',
        $_FILES['file']['name'] ?? 'file'
    );
    if (!empty($_POST['caption'])) $post['caption'] = $_POST['caption'];
} else {
    $post['text'] = $_POST['text'] ?? $_POST['message'] ?? '';
}

$ch = curl_init($tgUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($code);
header('Content-Type: application/json');
echo $response;
RELAYEOF

echo "Creating .env ..."
cat > .env << 'ENVEOF'
ALLOWED_IPS=83.220.173.171          # ← Замени на публичный IP твоего основного сервера
RELAY_SECRET=super-long-random-secret-change-me-1234567890abcdefGHIJK
TELEGRAM_BOT_TOKEN=8799707857:AAFep4R8r750mh9egn1KlOl3NAqMWd9c-Xw
ENVEOF

echo "Creating docker-compose.yml ..."
cat > docker-compose.yml << 'COMPOSEEOF'
version: '3.8'
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
