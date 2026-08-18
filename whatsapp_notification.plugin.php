<?php
/**
 * Plugin Name: WhatsApp Notification (Whacenter)
 * Plugin URI: https://github.com/
 * Description: Notifikasi WhatsApp via API Whacenter untuk transaksi sirkulasi (pinjam, kembali, perpanjangan), keterlambatan/denda, dan chatbot pencarian bibliografi melalui webhook.
 * Version: 1.0.0
 * Author: Perpustakaan
 * Author URI: https://slims.web.id
 */

use SLiMS\Plugins;
use SLiMS\DB;

require_once __DIR__ . '/WaConfig.php';
require_once __DIR__ . '/Whacenter.php';
require_once __DIR__ . '/Message.php';
require_once __DIR__ . '/AiClient.php';

if (!function_exists('wa_notif_write_log')) {
    function wa_notif_write_log(string $type, string $memberId, string $memberName, string $phone, string $message, string $status, string $response = '')
    {
        try {
            $dbs = DB::getInstance('mysqli');
            if (!$dbs) return;
            $stmt = $dbs->prepare('INSERT INTO wa_notif_log (member_id, member_name, phone, type, message, status, response, created_at) VALUES (?,?,?,?,?,?,?,?)');
            if (!$stmt) return;
            $createdAt = date('Y-m-d H:i:s');
            $response = substr($response, 0, 250);
            $stmt->bind_param('ssssssss', $memberId, $memberName, $phone, $type, $message, $status, $response, $createdAt);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wa_notif_send')) {
    function wa_notif_send(string $phone, string $message, string $type, string $memberId = '', string $memberName = ''): array
    {
        $config = WaNotif\WaConfig::load();
        $wa = WaNotif\Whacenter::fromConfig();
        if (is_null($wa)) {
            wa_notif_write_log($type, $memberId, $memberName, $phone, $message, 'failed', 'Device ID Whacenter belum diatur');
            return ['ok' => false, 'message' => 'Device ID Whacenter belum diatur'];
        }
        $result = $wa->send($phone, $message);
        $messageText = is_array($result['body'] ?? null) ? ($result['body']['message'] ?? '') : $result['raw'];
        wa_notif_write_log($type, $memberId, $memberName, WaNotif\Whacenter::normalizeNumber($phone), $message, $result['ok'] ? 'success' : 'failed', $messageText);
        return ['ok' => $result['ok'], 'message' => $messageText];
    }
}

$plugins = Plugins::getInstance();

$plugins->registerMenu('system', __('WhatsApp Notification'), __DIR__ . '/index.php');
$plugins->registerMenu('system', __('WA Notification Log'), __DIR__ . '/log.php');
$plugins->registerMenu('circulation', __('Notifikasi Terlambat WA'), __DIR__ . '/overdued.php');
$plugins->registerMenu('opac', 'wa_webhook', __DIR__ . '/webhook.php');
$plugins->registerMenu('opac', 'wa_webchat', __DIR__ . '/webchat.php');

// Auto-inject widget Web Chat AI ke semua halaman OPAC — tanpa perlu edit template.
// Widget dirender sebagai string murni (tanpa output buffering) karena
// ob_start() tidak boleh dipanggil di dalam callback output buffer.
if (!defined('WA_NOTIF_WEBCHAT_INJECTOR')) {
    define('WA_NOTIF_WEBCHAT_INJECTOR', 1);
    $waScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($waScriptName, '/admin/') === false && php_sapi_name() !== 'cli') {
        ob_start(function ($html) {
            if (is_string($html)
                && stripos($html, '</body>') !== false
                && stripos($html, 'id="wa-webchat"') === false) {
                $waWidgetFile = __DIR__ . '/webchat_widget.php';
                if (is_readable($waWidgetFile)) {
                    define('WA_NOTIF_RENDER_SILENT', 1);
                    require_once $waWidgetFile;
                    $waWidgetHtml = wa_notif_webchat_widget_html();
                    if ($waWidgetHtml !== '') {
                        $html = preg_replace('/<\/body>/i', $waWidgetHtml . '</body>', $html, 1);
                    }
                }
            }
            return $html;
        });
    }
}

$plugins->register(Plugins::CIRCULATION_AFTER_SUCCESSFUL_TRANSACTION, function (array $data) {
    $config = WaNotif\WaConfig::load();
    if (empty($config['enable'])) return;

    $memberId = $data['memberID'] ?? '';
    if ($memberId === '') return;

    $message = WaNotif\Message::receipt($data, $config);
    if ($message === '') return;

    try {
        $dbs = DB::getInstance('mysqli');
        $escaped = $dbs->escape_string($memberId);
        $q = $dbs->query("SELECT member_phone FROM member WHERE member_id='$escaped' AND member_phone IS NOT NULL AND member_phone != '' LIMIT 1");
        if (!$q || $q->num_rows < 1) return;
        $phone = $q->fetch_assoc()['member_phone'];
    } catch (\Throwable $e) {
        return;
    }

    wa_notif_send($phone, $message, 'transaction', $memberId, $data['memberName'] ?? '');
});

$plugins->register(Plugins::OVERDUE_NOTICE_INIT, function (array $params) {
    $config = WaNotif\WaConfig::load();
    if (empty($config['enable']) || empty($config['notify_overdue'])) return;

    $member = $params['member'] ?? null;    if (is_null($member) || empty($member->member_id)) return;

    try {
        $dbs = DB::getInstance('mysqli');
        $escaped = $dbs->escape_string($member->member_id);
        $q = $dbs->query("SELECT m.member_id, m.member_name, m.member_phone, mt.fine_each_day
            FROM member m LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
            WHERE m.member_id='$escaped' LIMIT 1");
        if (!$q || $q->num_rows < 1) return;
        $memberData = $q->fetch_assoc();
        if (empty($memberData['member_phone'])) return;

        $items = member::getOverduedLoan($dbs, $member->member_id);
        if (empty($items)) return;

        $message = WaNotif\Message::overdue($memberData, $items, (float)($memberData['fine_each_day'] ?? 0), $config);
        wa_notif_send($memberData['member_phone'], $message, 'overdue', $memberData['member_id'], $memberData['member_name']);
    } catch (\Throwable $e) {
    }
});

$plugins->register(Plugins::MEMBERSHIP_AFTER_SAVE, function (array $data) {
    $config = WaNotif\WaConfig::load();
    if (empty($config['enable']) || empty($config['notify_new_member'])) return;

    $member = is_array($data[0] ?? null) ? $data[0] : $data;
    if (empty($member['member_id']) || empty($member['member_phone'])) return;

    if (!empty($member['last_login'])) return;

    $message = WaNotif\Message::memberWelcome($data, $config);
    wa_notif_send($member['member_phone'], $message, 'new_member', $member['member_id'], $member['member_name']);
});
