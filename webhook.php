<?php

use WaNotif\{WaConfig, Whacenter, Message};
use SLiMS\DB;

defined('INDEX_AUTH') or die('Direct access not allowed!');

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$config = WaConfig::load();
$from = trim((string)($data['from'] ?? ''));
$text = trim((string)($data['message'] ?? ''));

if (empty($config['enable']) || empty($config['enable_chatbot'])) {
    echo json_encode(['status' => false, 'message' => 'chatbot disabled']);
    exit;
}

if ($from === '' || $text === '') {
    echo json_encode(['status' => false, 'message' => 'empty payload']);
    exit;
}

$botNumber = Whacenter::normalizeNumber($from);
if (!preg_match('/^62[0-9]{8,13}$/', $botNumber)) {
    echo json_encode(['status' => false, 'message' => 'invalid number']);
    exit;
}

try {
    $dbs = DB::getInstance('mysqli');
    $reply = Message::botReply($dbs, $config, $from, $text);

    $wa = Whacenter::fromConfig();
    $result = $wa ? $wa->send($botNumber, $reply) : ['ok' => false, 'body' => null, 'raw' => 'Device ID not set'];

    $responseText = is_array($result['body'] ?? null) ? ($result['body']['message'] ?? '') : $result['raw'];
    wa_notif_write_log('chatbot', '', '', $botNumber, $reply, $result['ok'] ? 'success' : 'failed', (string)$responseText);

    echo json_encode(['status' => $result['ok']]);
} catch (\Throwable $e) {
    echo json_encode(['status' => false, 'message' => 'server error']);
}
exit;
