<?php
/**
 * MCP Server (Model Context Protocol) — Streamable HTTP transport.
 * Memungkinkan agent AI (Claude Code, Codex, opencode, dll) membaca data
 * SLiMS: katalog, anggota, peminjaman, keterlambatan, denda, statistik.
 * Tool send_whatsapp (mengirim pesan) hanya tersedia bila mcp_allow_send aktif.
 *
 * Endpoint : {base}/index.php?p=wa_mcp
 * Auth     : token dari settings plugin, lewat header "Authorization: Bearer <token>",
 *            header "X-MCP-Token: <token>", atau query "?token=<token>"
 * Protocol : JSON-RPC 2.0 — initialize, ping, tools/list, tools/call
 */
defined('INDEX_AUTH') or die('Direct access not allowed!');

foreach (['WaConfig', 'Whacenter', 'Message'] as $waClass) {
    if (!class_exists('WaNotif\\' . $waClass)) {
        require_once __DIR__ . '/' . $waClass . '.php';
    }
}

use WaNotif\WaConfig;
use WaNotif\Message;
use SLiMS\DB;

header('Cache-Control: no-store');

// CORS preflight untuk client berbasis browser
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-MCP-Token, Mcp-Session-Id');
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Method not allowed. Use POST.'], 'id' => null]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function wa_mcp_json(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = WaConfig::load();
if (empty($config['enable'])) {
    wa_mcp_json(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Plugin WhatsApp Notification tidak aktif.'], 'id' => null]);
}
if (empty($config['mcp_enable'])) {
    wa_mcp_json(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'MCP Server belum diaktifkan. Aktifkan di settings plugin.'], 'id' => null]);
}

// Auth: Bearer token / X-MCP-Token / ?token=
// Semua kredensial yang dikirim klien dikumpulkan; salah satu yang cocok sudah cukup.
// Tanpa ini, header Authorization lain (mis. token OAuth bawaan klien) menutupi token di URL.
$provided = [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) $provided[] = trim($m[1]);
if (!empty($_SERVER['HTTP_X_MCP_TOKEN'])) $provided[] = trim($_SERVER['HTTP_X_MCP_TOKEN']);
if (!empty($_GET['token'])) $provided[] = trim((string)$_GET['token']);

$token = (string)($config['mcp_token'] ?? '');
$authorized = false;
foreach ($provided as $candidate) {
    if ($candidate !== '' && hash_equals($token, $candidate)) { $authorized = true; break; }
}
if ($token === '' || !$authorized) {
    header('WWW-Authenticate: Bearer realm="slims-mcp"');
    wa_mcp_json(401, ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Unauthorized: token tidak valid atau kosong.'], 'id' => null]);
}

function wa_mcp_rows(mysqli $dbs, string $sql, string $types = '', array $params = []): array
{
    $st = $dbs->prepare($sql);
    if (!$st) throw new RuntimeException('DB prepare failed: ' . $dbs->error);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

function wa_mcp_text(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
}

function wa_mcp_tool_error(string $message): array
{
    return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
}

// ============================ TOOLS ============================

function wa_mcp_tool_search_catalog(mysqli $dbs, array $args): array
{
    $query = trim((string)($args['query'] ?? ''));
    $limit = max(1, min(25, (int)($args['limit'] ?? 10)));
    if ($query === '') return wa_mcp_tool_error('Parameter "query" wajib diisi.');
    $like = '%' . $query . '%';

    $rows = wa_mcp_rows($dbs,
        "SELECT b.biblio_id, b.title, b.publish_year,
                GROUP_CONCAT(DISTINCT a.author_name SEPARATOR ', ') AS authors,
                (SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id) AS copies,
                (SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id
                   AND NOT EXISTS (SELECT 1 FROM loan l WHERE l.item_code = i.item_code AND l.is_return = 0
                       AND (l.return_date IS NULL OR l.return_date = '0000-00-00'))) AS available
           FROM biblio b
           LEFT JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
           LEFT JOIN mst_author a ON a.author_id = ba.author_id
          WHERE b.title LIKE ? OR b.notes LIKE ?
             OR b.biblio_id IN (
                  SELECT ba2.biblio_id FROM biblio_author ba2
                  JOIN mst_author a2 ON a2.author_id = ba2.author_id
                  WHERE a2.author_name LIKE ?)
          GROUP BY b.biblio_id, b.title, b.publish_year
          ORDER BY b.last_update DESC
          LIMIT ?",
        'sssi', [$like, $like, $like, $limit]);

    if (empty($rows)) return wa_mcp_text("Tidak ditemukan bibliografi untuk pencarian \"$query\".");

    $out = 'Ditemukan ' . count($rows) . " hasil untuk \"$query\":\n";
    foreach ($rows as $i => $r) {
        $out .= sprintf("\n%d. %s\n", $i + 1, $r['title']);
        if (!empty($r['authors'])) $out .= '   Penulis: ' . $r['authors'] . "\n";
        if (!empty($r['publish_year'])) $out .= '   Tahun: ' . $r['publish_year'] . "\n";
        $out .= '   Eksemplar: ' . (int)$r['available'] . ' tersedia dari ' . (int)$r['copies'] . " total\n";
        $out .= '   Detail: ' . Message::absoluteUrl('index.php?p=show_detail&id=' . (int)$r['biblio_id']) . "\n";
    }
    return wa_mcp_text(rtrim($out));
}

function wa_mcp_tool_get_member(mysqli $dbs, array $args): array
{
    $memberId = trim((string)($args['member_id'] ?? ''));
    if ($memberId === '') return wa_mcp_tool_error('Parameter "member_id" wajib diisi.');

    $rows = wa_mcp_rows($dbs,
        "SELECT m.member_id, m.member_name, m.member_email, m.member_phone, m.member_address,
                m.register_date, m.expire_date, m.is_pending, m.last_login,
                mt.member_type_name, mt.loan_limit
           FROM member m
           LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
          WHERE m.member_id = ? LIMIT 1",
        's', [$memberId]);

    if (empty($rows)) return wa_mcp_tool_error("Anggota dengan ID \"$memberId\" tidak ditemukan.");
    $m = $rows[0];

    // Data lama kerap punya is_return=0 padahal return_date terisi — anggap sudah kembali
    $open = "is_return = 0 AND (return_date IS NULL OR return_date = '0000-00-00')";
    $loans = wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM loan WHERE member_id = ? AND is_lent = 1 AND $open", 's', [$memberId]);
    $overdue = wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM loan WHERE member_id = ? AND is_lent = 1 AND $open AND due_date < CURDATE()", 's', [$memberId]);
    $fines = wa_mcp_rows($dbs, "SELECT COALESCE(SUM(GREATEST(debet - credit, 0)), 0) total FROM fines WHERE member_id = ?", 's', [$memberId]);

    $out = "Data Anggota:\n"
        . '  ID           : ' . $m['member_id'] . "\n"
        . '  Nama         : ' . $m['member_name'] . "\n"
        . '  Tipe         : ' . ($m['member_type_name'] ?: '-') . "\n"
        . '  Email        : ' . ($m['member_email'] ?: '-') . "\n"
        . '  Telepon/WA   : ' . ($m['member_phone'] ?: '-') . "\n"
        . '  Registrasi   : ' . $m['register_date'] . "\n"
        . '  Berlaku s.d. : ' . $m['expire_date'] . "\n"
        . '  Status       : ' . ((int)$m['is_pending'] === 1 ? 'PENDING (menunggu aktivasi)' : 'Aktif') . "\n"
        . '  Login terakhir: ' . ($m['last_login'] ?: 'belum pernah') . "\n"
        . "Sirkulasi:\n"
        . '  Pinjaman aktif : ' . (int)$loans[0]['n'] . ' (batas ' . (int)($m['loan_limit'] ?? 0) . ")\n"
        . '  Terlambat      : ' . (int)$overdue[0]['n'] . "\n"
        . '  Denda belum bayar: Rp ' . number_format((float)$fines[0]['total'], 0, ',', '.');
    return wa_mcp_text($out);
}

function wa_mcp_tool_get_loans(mysqli $dbs, array $args): array
{
    $memberId = trim((string)($args['member_id'] ?? ''));
    if ($memberId === '') return wa_mcp_tool_error('Parameter "member_id" wajib diisi.');
    $status = strtolower(trim((string)($args['status'] ?? 'active')));

    $open = "l.is_return = 0 AND (l.return_date IS NULL OR l.return_date = '0000-00-00')";
    $where = 'l.member_id = ? AND l.is_lent = 1';
    if ($status === 'active') $where .= " AND $open";
    elseif ($status === 'overdue') $where .= " AND $open AND l.due_date < CURDATE()";
    elseif ($status === 'returned') $where .= " AND (l.is_return = 1 OR (l.return_date IS NOT NULL AND l.return_date <> '0000-00-00'))";
    elseif ($status !== 'all') return wa_mcp_tool_error('status harus salah satu dari: active, overdue, returned, all');

    $rows = wa_mcp_rows($dbs,
        "SELECT l.item_code, b.title, l.loan_date, l.due_date, l.return_date, l.renewed,
                DATEDIFF(IFNULL(l.return_date, CURDATE()), l.due_date) AS days_diff
           FROM loan l
           LEFT JOIN item i ON i.item_code = l.item_code
           LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
          WHERE $where
          ORDER BY l.loan_date DESC
          LIMIT 50",
        's', [$memberId]);

    if (empty($rows)) return wa_mcp_text("Tidak ada pinjaman (status: $status) untuk anggota \"$memberId\".");

    $labels = ['active' => 'AKTIF', 'overdue' => 'TERLAMBAT', 'returned' => 'SUDAH KEMBALI', 'all' => 'SEMUA'];
    $out = 'Daftar pinjaman anggota ' . $memberId . ' (status: ' . ($labels[$status] ?? $status) . ', total ' . count($rows) . "):\n";
    foreach ($rows as $i => $r) {
        $late = ((int)$r['days_diff'] > 0 && empty($r['return_date'])) ? ' — TERLAMBAT ' . (int)$r['days_diff'] . ' hari' : '';
        $out .= sprintf("\n%d. [%s] %s\n", $i + 1, $r['item_code'], $r['title'] ?: '(judul tidak ditemukan)');
        $out .= '   Pinjam: ' . $r['loan_date'] . ' | Jatuh tempo: ' . $r['due_date'];
        if (!empty($r['return_date'])) $out .= ' | Kembali: ' . $r['return_date'];
        $out .= ' | Perpanjangan: ' . (int)$r['renewed'] . 'x' . $late . "\n";
    }
    return wa_mcp_text(rtrim($out));
}

function wa_mcp_tool_get_overdue(mysqli $dbs, array $args): array
{
    $limit = max(1, min(50, (int)($args['limit'] ?? 25)));

    $summary = wa_mcp_rows($dbs,
        "SELECT COUNT(*) total, COUNT(DISTINCT member_id) members FROM loan
          WHERE is_lent = 1 AND is_return = 0 AND (return_date IS NULL OR return_date = '0000-00-00') AND due_date < CURDATE()");

    $rows = wa_mcp_rows($dbs,
        "SELECT l.member_id, m.member_name, m.member_phone, l.item_code, b.title,
                l.due_date, DATEDIFF(CURDATE(), l.due_date) AS days_late
           FROM loan l
           JOIN member m ON m.member_id = l.member_id
           LEFT JOIN item i ON i.item_code = l.item_code
           LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
          WHERE l.is_lent = 1 AND l.is_return = 0
            AND (l.return_date IS NULL OR l.return_date = '0000-00-00')
            AND l.due_date < CURDATE()
          ORDER BY days_late DESC
          LIMIT ?",
        'i', [$limit]);

    if (empty($rows)) return wa_mcp_text('Tidak ada pinjaman yang terlambat. 🎉');

    $out = 'Ringkasan: ' . (int)$summary[0]['total'] . ' pinjaman terlambat dari ' . (int)$summary[0]['members'] . " anggota.\n"
        . "Menampilkan " . count($rows) . " terlambat terlama:\n";
    foreach ($rows as $i => $r) {
        $out .= sprintf("\n%d. %s (%s) — telat %d hari\n", $i + 1, $r['member_name'], $r['member_id'], (int)$r['days_late']);
        $out .= '   ' . ($r['title'] ?: $r['item_code']) . ' [' . $r['item_code'] . ']' . "\n";
        $out .= '   Jatuh tempo: ' . $r['due_date'] . ' | WA: ' . ($r['member_phone'] ?: '-') . "\n";
    }
    return wa_mcp_text(rtrim($out));
}

function wa_mcp_tool_get_fines(mysqli $dbs, array $args): array
{
    $memberId = trim((string)($args['member_id'] ?? ''));
    if ($memberId === '') return wa_mcp_tool_error('Parameter "member_id" wajib diisi.');

    $rows = wa_mcp_rows($dbs,
        "SELECT fines_date, description, debet, credit
           FROM fines WHERE member_id = ? ORDER BY fines_date DESC LIMIT 50",
        's', [$memberId]);
    $total = wa_mcp_rows($dbs,
        "SELECT COALESCE(SUM(GREATEST(debet - credit, 0)), 0) total FROM fines WHERE member_id = ?",
        's', [$memberId]);

    if (empty($rows)) return wa_mcp_text("Tidak ada catatan denda untuk anggota \"$memberId\".");

    $out = 'Denda anggota ' . $memberId . ' — TOTAL BELUM BAYAR: Rp ' . number_format((float)$total[0]['total'], 0, ',', '.') . "\n";
    foreach ($rows as $i => $r) {
        $unpaid = max((float)$r['debet'] - (float)$r['credit'], 0);
        $out .= sprintf("\n%d. %s — %s | tagih Rp %s | bayar Rp %s | sisa Rp %s\n",
            $i + 1, $r['fines_date'], $r['description'] ?: '(denda)',
            number_format((float)$r['debet'], 0, ',', '.'),
            number_format((float)$r['credit'], 0, ',', '.'),
            number_format($unpaid, 0, ',', '.'));
    }
    return wa_mcp_text(rtrim($out));
}

function wa_mcp_tool_get_stats(mysqli $dbs, array $args): array
{
    $q = [
        'members' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM member")[0]['n'],
        'members_pending' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM member WHERE is_pending = 1")[0]['n'],
        'titles' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM biblio")[0]['n'],
        'items' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM item")[0]['n'],
        'loans_active' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM loan WHERE is_lent = 1 AND is_return = 0 AND (return_date IS NULL OR return_date = '0000-00-00')")[0]['n'],
        'loans_overdue' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM loan WHERE is_lent = 1 AND is_return = 0 AND (return_date IS NULL OR return_date = '0000-00-00') AND due_date < CURDATE()")[0]['n'],
        'loans_today' => wa_mcp_rows($dbs, "SELECT COUNT(*) n FROM loan WHERE DATE(input_date) = CURDATE()")[0]['n'],
        'fines_unpaid' => wa_mcp_rows($dbs, "SELECT COALESCE(SUM(GREATEST(debet - credit, 0)), 0) n FROM fines")[0]['n'],
    ];
    $out = "Statistik Perpustakaan:\n"
        . '  Anggota terdaftar   : ' . number_format((float)$q['members'], 0, ',', '.') . "\n"
        . '  Menunggu aktivasi   : ' . number_format((float)$q['members_pending'], 0, ',', '.') . "\n"
        . '  Judul koleksi       : ' . number_format((float)$q['titles'], 0, ',', '.') . "\n"
        . '  Eksemplar           : ' . number_format((float)$q['items'], 0, ',', '.') . "\n"
        . '  Pinjaman aktif      : ' . number_format((float)$q['loans_active'], 0, ',', '.') . "\n"
        . '  Transaksi hari ini  : ' . number_format((float)$q['loans_today'], 0, ',', '.') . "\n"
        . '  Pinjaman terlambat  : ' . number_format((float)$q['loans_overdue'], 0, ',', '.') . "\n"
        . '  Denda belum bayar   : Rp ' . number_format((float)$q['fines_unpaid'], 0, ',', '.');
    return wa_mcp_text($out);
}

/**
 * Kirim pesan WhatsApp lewat Whacenter. Satu-satunya tool yang melakukan aksi (bukan baca data),
 * sehingga hanya tersedia bila mcp_allow_send aktif dan diberi penanda readOnlyHint = false
 * agar klien seperti ChatGPT meminta konfirmasi dulu.
 *
 * Pengamanan: pesan identik ke nomor yang sama dalam 2 menit ditolak (mencegah pengiriman ganda),
 * maksimal 20 pesan per 10 menit, dan pesan tidak dikirim bila riwayat (wa_notif_log) tak terbaca.
 */
function wa_mcp_tool_send_whatsapp(mysqli $dbs, array $args, array $config): array
{
    if (empty($config['mcp_allow_send'])) {
        return wa_mcp_tool_error('Tool "send_whatsapp" belum diaktifkan. Aktifkan "Izinkan MCP mengirim WhatsApp" di pengaturan plugin.');
    }
    if (empty($config['enable'])) return wa_mcp_tool_error('Plugin WhatsApp Notification tidak aktif.');
    if (empty($config['device_id'])) return wa_mcp_tool_error('Device ID Whacenter belum diatur.');
    if (!function_exists('wa_notif_send')) return wa_mcp_tool_error('Fungsi pengiriman plugin tidak tersedia.');

    // Nilai argumen yang bukan teks (mis. array) dianggap kosong
    $arg = static function (string $key) use ($args): string {
        $value = $args[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    };
    $strlen = static function (string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    };

    $maxLength = 4000;
    $message = $arg('message');
    if ($message === '') return wa_mcp_tool_error('Parameter "message" wajib diisi berupa teks.');
    if ($strlen($message) > $maxLength) {
        return wa_mcp_tool_error('Pesan terlalu panjang: ' . $strlen($message) . " karakter (maksimal $maxLength).");
    }

    $memberId = $arg('member_id');
    $phone = $arg('phone');
    if ($memberId !== '' && $phone !== '') return wa_mcp_tool_error('Isi salah satu saja: "member_id" atau "phone".');

    $memberName = '';
    if ($memberId !== '') {
        $rows = wa_mcp_rows($dbs, 'SELECT member_id, member_name, member_phone FROM member WHERE member_id = ? LIMIT 1', 's', [$memberId]);
        if (empty($rows)) return wa_mcp_tool_error("Anggota dengan ID \"$memberId\" tidak ditemukan.");
        $memberName = (string)$rows[0]['member_name'];
        $phone = trim((string)$rows[0]['member_phone']);
        if ($phone === '') return wa_mcp_tool_error("Anggota \"$memberName\" ($memberId) tidak punya nomor WhatsApp di data anggota.");
    } elseif ($phone === '') {
        return wa_mcp_tool_error('Isi "member_id" atau "phone" sebagai tujuan pesan.');
    }

    $number = WaNotif\Whacenter::normalizeNumber($phone);
    if (!preg_match('/^628[0-9]{7,11}$/', $number)) {
        return wa_mcp_tool_error("Nomor \"$phone\" tidak tampak seperti nomor WhatsApp Indonesia.");
    }

    try {
        $dup = wa_mcp_rows($dbs,
            "SELECT COUNT(*) n FROM wa_notif_log
              WHERE type = 'mcp' AND status = 'success' AND phone = ? AND message = ? AND created_at > ?",
            'sss', [$number, $message, date('Y-m-d H:i:s', time() - 120)]);
        if ((int)$dup[0]['n'] > 0) {
            return wa_mcp_tool_error('Pesan yang sama baru saja dikirim ke nomor itu (dalam 2 menit terakhir), jadi tidak dikirim ulang.');
        }
        $rate = wa_mcp_rows($dbs,
            "SELECT COUNT(*) n FROM wa_notif_log WHERE type = 'mcp' AND status = 'success' AND created_at > ?",
            's', [date('Y-m-d H:i:s', time() - 600)]);
        if ((int)$rate[0]['n'] >= 20) {
            return wa_mcp_tool_error('Batas sementara tercapai: maksimal 20 pesan per 10 menit lewat MCP. Coba lagi nanti.');
        }
    } catch (\Throwable $e) {
        return wa_mcp_tool_error('Riwayat pengiriman tidak dapat dibaca, pesan tidak dikirim. ' . $e->getMessage());
    }

    // wa_notif_send mencatat hasilnya sendiri di wa_notif_log (tipe "mcp")
    $send = wa_notif_send($phone, $message, 'mcp', $memberId, $memberName);
    if (empty($send['ok'])) return wa_mcp_tool_error('Gagal mengirim: ' . (string)($send['message'] ?? ''));

    $target = $memberName !== '' ? "$memberName ($memberId)" : $number;
    return wa_mcp_text("Pesan WhatsApp terkirim ke $target (nomor $number).");
}

function wa_mcp_tool_definitions(bool $allowSend = false): array
{
    $str = fn(string $desc) => ['type' => 'string', 'description' => $desc];
    // Semua tool hanya membaca data (SELECT). Penanda ini membuat klien seperti ChatGPT
    // tidak memperlakukan setiap pemanggilan sebagai aksi tulis yang perlu dikonfirmasi.
    $readonly = ['readOnlyHint' => true];
    $tools = [
        ['name' => 'search_catalog', 'description' => 'Cari bibliografi/koleksi perpustakaan berdasarkan judul, penulis, atau sinopsis. Menampilkan ketersediaan eksemplar.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object', 'properties' => ['query' => $str('Kata kunci pencarian'), 'limit' => ['type' => 'integer', 'description' => 'Maksimal hasil (1-25)', 'default' => 10]], 'required' => ['query']]],
        ['name' => 'get_member', 'description' => 'Lihat data lengkap satu anggota: profil, status keanggotaan, pinjaman aktif, keterlambatan, dan total denda.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object', 'properties' => ['member_id' => $str('ID anggota, contoh: 1002')], 'required' => ['member_id']]],
        ['name' => 'get_loans', 'description' => 'Daftar pinjaman satu anggota beserta jatuh tempo dan status keterlambatan.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object', 'properties' => ['member_id' => $str('ID anggota'), 'status' => ['type' => 'string', 'enum' => ['active', 'overdue', 'returned', 'all'], 'default' => 'active']], 'required' => ['member_id']]],
        ['name' => 'get_overdue', 'description' => 'Semua pinjaman terlambat di seluruh perpustakaan, diurutkan dari yang terlama, lengkap dengan kontak WA anggota.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'Maksimal hasil (1-50)', 'default' => 25]]]],
        ['name' => 'get_fines', 'description' => 'Riwayat denda satu anggota beserta total yang belum dibayar.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object', 'properties' => ['member_id' => $str('ID anggota')], 'required' => ['member_id']]],
        ['name' => 'get_stats', 'description' => 'Statistik ringkas perpustakaan: anggota, koleksi, pinjaman aktif, keterlambatan, dan denda.', 'annotations' => $readonly, 'inputSchema' => ['type' => 'object']],
    ];
    if ($allowSend) {
        // Aksi kirim: readOnlyHint = false agar klien (mis. ChatGPT) meminta konfirmasi sebelum mengirim
        $tools[] = ['name' => 'send_whatsapp',
            'description' => 'Mengirim pesan WhatsApp atas nama perpustakaan. Gunakan hanya bila pengguna secara eksplisit meminta mengirim pesan, setelah penerima dan isi pesan disebutkan. Tujuan: isi "member_id" (anggota) atau "phone" (nomor langsung), salah satu saja. Isi pesan berupa teks biasa; penanda *tebal* WhatsApp didukung.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            'inputSchema' => ['type' => 'object', 'properties' => [
                'member_id' => $str('ID anggota tujuan, contoh: 1002'),
                'phone' => $str('Nomor WhatsApp tujuan, misalnya 081234567890; dipakai kalau penerima bukan anggota'),
                'message' => ['type' => 'string', 'description' => 'Isi pesan yang dikirim'],
            ], 'required' => ['message']]];
    }
    return $tools;
}

function wa_mcp_call_tool(mysqli $dbs, string $name, array $args, array $config = []): array
{
    try {
        switch ($name) {
            case 'search_catalog': return wa_mcp_tool_search_catalog($dbs, $args);
            case 'get_member': return wa_mcp_tool_get_member($dbs, $args);
            case 'get_loans': return wa_mcp_tool_get_loans($dbs, $args);
            case 'get_overdue': return wa_mcp_tool_get_overdue($dbs, $args);
            case 'get_fines': return wa_mcp_tool_get_fines($dbs, $args);
            case 'get_stats': return wa_mcp_tool_get_stats($dbs, $args);
            case 'send_whatsapp': return wa_mcp_tool_send_whatsapp($dbs, $args, $config);
        }
        return wa_mcp_tool_error("Tool \"$name\" tidak dikenal.");
    } catch (\Throwable $e) {
        return wa_mcp_tool_error('Tool error: ' . $e->getMessage());
    }
}

// ========================= JSON-RPC DISPATCH =========================

$raw = file_get_contents('php://input');
$message = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    wa_mcp_json(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error: JSON tidak valid.'], 'id' => null]);
}

$batch = is_array($message) && isset($message[0]) && is_array($message[0]) && isset($message[0]['jsonrpc']);
$messages = $batch ? $message : [$message];
$responses = [];
$libName = $config['library_name'];
$sendAllowed = !empty($config['mcp_allow_send']);

foreach ($messages as $msg) {
    if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method'])) {
        $responses[] = ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request.'], 'id' => $msg['id'] ?? null];
        continue;
    }
    $method = (string)$msg['method'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $id = array_key_exists('id', $msg) ? $msg['id'] : null;
    $isNotification = !array_key_exists('id', $msg);

    if ($isNotification) continue; // notifications/initialized dll — tidak perlu respons

    switch ($method) {
        case 'initialize':
            $requested = (string)($params['protocolVersion'] ?? '');
            $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
            $protocol = in_array($requested, $supported, true) ? $requested : '2025-03-26';
            $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => $protocol,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'slims-whatsapp-ai-whacenter', 'title' => 'SLiMS Library Data', 'version' => '1.2.0'],
                'instructions' => "Server data perpustakaan $libName. Gunakan search_catalog untuk mencari koleksi, "
                    . 'get_member/get_loans/get_fines untuk data anggota (butuh member_id), get_overdue untuk semua keterlambatan, '
                    . 'get_stats untuk ringkasan statistik.'
                    . ($sendAllowed ? ' Tool send_whatsapp mengirim pesan WhatsApp: pakai hanya saat pengguna secara eksplisit meminta, '
                        . 'dan pastikan penerima serta isi pesannya sudah disebutkan pengguna.' : ''),
            ]];
            break;

        case 'ping':
            $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
            break;

        case 'tools/list':
            $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => wa_mcp_tool_definitions($sendAllowed)]];
            break;

        case 'tools/call':
            $toolName = (string)($params['name'] ?? '');
            $toolArgs = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $dbs = DB::getInstance('mysqli');
            } catch (\Throwable $e) {
                $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => wa_mcp_tool_error('DB connection failed: ' . $e->getMessage())];
                break;
            }
            $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => wa_mcp_call_tool($dbs, $toolName, $toolArgs, $config)];
            break;

        default:
            $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
    }
}

if (empty($responses)) {
    http_response_code(202);
    header('Content-Length: 0');
    if (ob_get_length()) ob_clean();
    exit;
}

header('Access-Control-Allow-Origin: *');
wa_mcp_json(200, $batch ? $responses : $responses[0]);
