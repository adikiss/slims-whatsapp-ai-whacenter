<?php

use WaNotif\{WaConfig, AiClient, Message};
use SLiMS\DB;

defined('INDEX_AUTH') or die('Direct access not allowed!');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
    exit;
}

$config = WaConfig::load();
if (empty($config['enable']) || empty($config['enable_webchat'])) {
    echo json_encode(['status' => false, 'message' => 'Webchat disabled']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$message = trim((string)($data['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 1000) {
    echo json_encode(['status' => false, 'message' => 'Invalid message']);
    exit;
}

try {
    $dbs = DB::getInstance('mysqli');

    // Deteksi member yang sedang login OPAC (jika ada)
    $member = null;
    if (!empty($_SESSION['member_id'])) {
        $mid = $dbs->escape_string($_SESSION['member_id']);
        $q = $dbs->query("SELECT m.member_id, m.member_name, mt.member_type_name FROM member m
            LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
            WHERE m.member_id='$mid' LIMIT 1");
        if ($q && $q->num_rows > 0) $member = $q->fetch_assoc();
    }

    $reply = Message::webchatReply($dbs, $config, $message, $member);

    wa_notif_write_log('webchat', $member['member_id'] ?? '', $member['member_name'] ?? '', '-', $message . "\n---\n" . $reply, 'success', '');

    echo json_encode(['status' => true, 'reply' => $reply]);
} catch (\Throwable $e) {
    echo json_encode(['status' => false, 'message' => 'Server error']);
}
exit;
