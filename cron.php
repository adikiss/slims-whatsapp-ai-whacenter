<?php
/**
 * Cron — Notifikasi Keterlambatan Otomatis.
 *
 * Alamat ini dipanggil oleh cron hosting sesuai jadwal, misalnya setiap hari pukul 07.00:
 *   0 7 * * * curl -s "https://perpustakaan.example/index.php?p=wa_cron&token=<TOKEN>"
 *
 * Tambahkan &dry=1 untuk melihat siapa saja yang akan dihubungi, tanpa mengirim apa pun.
 *
 * Auth     : token (query ?token=, header X-Cron-Token, atau Authorization: Bearer <token>)
 * Keluaran : ringkasan teks biasa, agar terbaca di log/email cron
 */
defined('INDEX_AUTH') or die('Direct access not allowed!');

foreach (['WaConfig', 'Whacenter', 'Message', 'OverdueCron'] as $waClass) {
    if (!class_exists('WaNotif\\' . $waClass)) {
        require_once __DIR__ . '/' . $waClass . '.php';
    }
}

use WaNotif\WaConfig;
use WaNotif\OverdueCron;
use SLiMS\DB;

function wa_cron_respond(int $code, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text, "\n";
    exit;
}

function wa_cron_text(array $r): string
{
    $dry = !empty($r['dry']);
    $lines = [];
    $lines[] = 'Notifikasi keterlambatan otomatis — ' . date('Y-m-d H:i') . ' — ' . ($dry ? 'TES (tidak mengirim apa pun)' : 'KIRIM');
    $lines[] = 'Anggota terlambat: ' . (int)$r['total_overdue']
        . ' | diproses: ' . (int)$r['processed']
        . ' | ' . ($dry ? 'akan dinotifikasi' : 'berhasil dinotifikasi') . ': ' . (int)$r['notified']
        . ' | ditunda ke proses berikutnya: ' . (int)$r['deferred'];

    foreach ($r['channels'] as $key => $c) {
        $name = strtoupper($key);
        if (empty($c['enabled'])) {
            $lines[] = "$name: tidak aktif";
            continue;
        }
        $lines[] = "$name: " . ($dry ? 'akan dikirim ' : 'terkirim ') . (int)$c['sent']
            . ', gagal ' . (int)$c['failed']
            . ', sudah dinotifikasi baru-baru ini ' . (int)$c['recent']
            . ', tanpa kontak ' . (int)$c['no_contact'];
    }

    foreach ($r['notes'] as $note) $lines[] = 'Catatan: ' . $note;

    foreach ($r['details'] as $d) {
        $parts = [];
        foreach (['wa' => 'WA', 'email' => 'Email'] as $key => $label) {
            if ((string)($d[$key] ?? '') !== '') $parts[] = $label . ' ' . $d[$key];
        }
        $lines[] = '- ' . $d['member_name'] . ' (' . $d['member_id'] . '): ' . ($parts ? implode('; ', $parts) : '-');
    }

    $lines[] = 'Selesai dalam ' . $r['seconds'] . ' detik.';
    return implode("\n", $lines);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    wa_cron_respond(405, 'Method not allowed. Gunakan GET atau POST.');
}

$config = WaConfig::load();

// Auth dicek lebih dulu, sebelum status fitur, supaya pemanggil tanpa token tidak mendapat info apa pun.
// Salah satu kredensial yang cocok sudah cukup (token cron terpisah dari token MCP).
$provided = [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) $provided[] = trim($m[1]);
if (!empty($_SERVER['HTTP_X_CRON_TOKEN'])) $provided[] = trim($_SERVER['HTTP_X_CRON_TOKEN']);
if (!empty($_GET['token'])) $provided[] = trim((string)$_GET['token']);

$token = (string)($config['cron_token'] ?? '');
$authorized = false;
foreach ($provided as $candidate) {
    if ($token !== '' && $candidate !== '' && hash_equals($token, $candidate)) { $authorized = true; break; }
}
if (!$authorized) {
    header('WWW-Authenticate: Bearer realm="slims-wa-cron"');
    wa_cron_respond(401, $token === ''
        ? 'Unauthorized: token cron belum dibuat. Buka pengaturan plugin lalu simpan.'
        : 'Unauthorized: token tidak valid atau kosong.');
}

if (empty($config['enable'])) {
    wa_cron_respond(403, 'Plugin WhatsApp Notification tidak aktif.');
}

// TES boleh dijalankan sebelum fitur ini diaktifkan, supaya hasilnya bisa diperiksa dulu.
$dry = !empty($_GET['dry']);
if (empty($config['cron_enable']) && !$dry) {
    wa_cron_respond(403, 'Notifikasi terjadwal belum diaktifkan. Aktifkan di pengaturan plugin.');
}

try {
    $dbs = DB::getInstance('mysqli');
} catch (\Throwable $e) {
    wa_cron_respond(500, 'Koneksi database gagal: ' . $e->getMessage());
}

// Kunci bernama milik MySQL/MariaDB: kalau proses cron lain masih berjalan, jangan jalankan yang baru.
// Kunci ini lepas sendiri saat koneksi database tertutup.
$lockName = 'wa_notif_cron';
$lock = $dbs->query("SELECT GET_LOCK('$lockName', 0) AS l");
if ($lock) {
    if ((int)($lock->fetch_assoc()['l'] ?? 0) !== 1) {
        wa_cron_respond(409, 'Proses notifikasi sebelumnya masih berjalan. Coba lagi nanti.');
    }
}

// Pengirim email memakai pengaturan email SLiMS. Mail bersifat singleton, jadi penerima
// dibersihkan dulu agar email berikutnya tidak ikut terkirim ke penerima sebelumnya.
$sendMail = null;
if (function_exists('config') && !is_null(config('mail'))) {
    $sendMail = function (string $email, string $name, string $subject, string $body): array {
        \SLiMS\Mail::getInstance()->clearAllRecipients();
        $ok = \SLiMS\Mail::to($email, $name)->subject($subject)->message($body)->send();
        return ['ok' => $ok, 'message' => $ok ? 'Terkirim' : (string)\SLiMS\Mail::getInstance()->ErrorInfo];
    };
}

@set_time_limit(0); // jika diizinkan server; tetap berhenti sendiri lewat batas waktu di OverdueCron
$result = (new OverdueCron($dbs, $config, $dry, $sendMail))->run();
if ($lock) $dbs->query("SELECT RELEASE_LOCK('$lockName')");

wa_cron_respond($result['ok'] ? 200 : 500, wa_cron_text($result));
