<?php

namespace WaNotif;

/**
 * Notifikasi keterlambatan terjadwal (dipanggil dari cron.php).
 *
 * Satu proses menghubungi paling banyak `cron_max_per_run` anggota, dimulai dari
 * yang paling lama terlambat, dan berhenti setelah TIME_BUDGET detik. Sisanya
 * dilanjutkan pada jadwal berikutnya.
 *
 * Supaya anggota tidak dibombardir, sebuah saluran baru dipakai lagi setelah
 * `cron_repeat_days` hari sejak pengiriman berhasil terakhir di saluran itu.
 * Riwayatnya dibaca dari wa_notif_log, jadi pengingat yang dikirim lewat menu
 * "Notifikasi Terlambat WA" ikut menunda pengiriman otomatis.
 */
class OverdueCron
{
    /** Tipe log sama dengan pengiriman manual di overdued.php */
    const TYPE_WA = 'overdue';
    const TYPE_EMAIL = 'overdue_email';

    /** Batas waktu (detik) satu proses, agar tidak dipotong server sebelum ringkasan tercetak */
    const TIME_BUDGET = 20.0;

    /** @var \mysqli */
    private $dbs;
    private array $config;
    private bool $dry;
    /** @var callable|null function(string $email, string $name, string $subject, string $body): array{ok: bool, message: string} */
    private $sendMail;

    private array $channels = [];
    private array $details = [];
    private array $notes = [];

    public function __construct($dbs, array $config, bool $dry = false, ?callable $sendMail = null)
    {
        $this->dbs = $dbs;
        $this->config = $config;
        $this->dry = $dry;
        $this->sendMail = $sendMail;
    }

    /**
     * @return array{ok: bool, dry: bool, total_overdue: int, processed: int, notified: int, deferred: int,
     *     channels: array, details: array, notes: array, seconds: float}
     */
    public function run(): array
    {
        $start = microtime(true);
        $now = time();
        $minDays = max(1, (int)($this->config['cron_min_days'] ?? 1));
        $repeatDays = max(1, (int)($this->config['cron_repeat_days'] ?? 7));
        $maxPerRun = max(1, (int)($this->config['cron_max_per_run'] ?? 50));

        $this->channels = [
            'wa' => ['enabled' => !empty($this->config['cron_channel_wa']), 'sent' => 0, 'failed' => 0, 'recent' => 0, 'no_contact' => 0],
            'email' => ['enabled' => !empty($this->config['cron_channel_email']), 'sent' => 0, 'failed' => 0, 'recent' => 0, 'no_contact' => 0],
        ];
        $this->details = [];
        $this->notes = [];

        if ($this->channels['wa']['enabled'] && empty($this->config['device_id'])) {
            $this->channels['wa']['enabled'] = false;
            $this->notes[] = 'WhatsApp dilewati karena Device ID Whacenter belum diatur.';
        }
        if ($this->channels['wa']['enabled'] && !function_exists('wa_notif_send')) {
            $this->channels['wa']['enabled'] = false;
            $this->notes[] = 'WhatsApp dilewati karena fungsi pengiriman plugin tidak tersedia.';
        }
        if ($this->channels['email']['enabled'] && is_null($this->sendMail)) {
            $this->channels['email']['enabled'] = false;
            $this->notes[] = 'Email dilewati karena pengaturan email SLiMS belum ada.';
        }
        if (!$this->channels['wa']['enabled'] && !$this->channels['email']['enabled']) {
            $this->notes[] = 'Tidak ada saluran yang aktif; tidak ada yang dikirim.';
            return $this->result(true, 0, 0, 0, 0, $start);
        }

        // Tanpa riwayat yang bisa dibaca, pengiriman ulang tidak bisa dicegah: jangan kirim apa pun.
        try {
            $members = $this->rows($this->sqlMembers(), 'i', [$minDays]);
            $last = $this->fetchLastSent(array_column($members, 'member_id'));
        } catch (\Throwable $e) {
            return $this->result(false, 0, 0, 0, 0, $start, ['Dihentikan tanpa mengirim apa pun. ' . $e->getMessage()]);
        }

        $processed = $notified = $deferred = 0;
        foreach ($members as $member) {
            $id = (string)$member['member_id'];

            // Status tiap saluran untuk anggota ini: off (saluran mati), recent (baru dinotifikasi), due
            $states = [];
            foreach ([ 'wa' => self::TYPE_WA, 'email' => self::TYPE_EMAIL ] as $key => $type) {
                if (!$this->channels[$key]['enabled']) $states[$key] = 'off';
                elseif ($this->isDue($last[$id][$type] ?? null, $repeatDays, $now)) $states[$key] = 'due';
                else { $states[$key] = 'recent'; $this->channels[$key]['recent']++; }
            }
            if (!in_array('due', $states, true)) continue;

            // Dihitung sebagai "ditunda" hanya anggota yang memang perlu dihubungi
            if ($processed >= $maxPerRun || microtime(true) - $start > self::TIME_BUDGET) { $deferred++; continue; }

            if ($states['wa'] === 'due' && trim((string)$member['member_phone']) === '') {
                $states['wa'] = 'no_contact';
                $this->channels['wa']['no_contact']++;
            }
            if ($states['email'] === 'due' && trim((string)$member['member_email']) === '') {
                $states['email'] = 'no_contact';
                $this->channels['email']['no_contact']++;
            }
            if (!in_array('due', $states, true)) continue;

            try {
                $items = $this->rows($this->sqlItems(), 's', [$id]);
            } catch (\Throwable $e) {
                $this->notes[] = 'Daftar koleksi ' . $member['member_name'] . ' (' . $id . ') gagal dibaca: ' . $e->getMessage();
                continue;
            }
            if (empty($items)) continue;

            $processed++;
            $message = Message::overdue($member, $items, (float)($member['fine_each_day'] ?? 0), $this->config);
            $detail = ['member_id' => $id, 'member_name' => (string)$member['member_name'], 'wa' => '', 'email' => ''];
            $delivered = false;
            foreach ($states as $key => $state) {
                if ($state !== 'due') continue;
                $r = $key === 'wa' ? $this->sendWa($member, $message) : $this->sendEmail($member, $message);
                $this->channels[$key][$r['ok'] ? 'sent' : 'failed']++;
                $detail[$key] = $r['text'];
                if ($r['ok']) $delivered = true;
            }
            if ($delivered) $notified++;
            $this->details[] = $detail;

            if (!$this->dry) usleep(300000); // jeda antar anggota, seperti halaman pengiriman manual
        }

        if ($deferred > 0) $this->notes[] = "$deferred anggota ditunda ke proses berikutnya karena batas jumlah atau waktu per proses.";
        return $this->result(true, count($members), $processed, $notified, $deferred, $start);
    }

    /** Apakah saluran ini boleh dipakai lagi untuk anggota tersebut */
    private function isDue(?string $lastSent, int $repeatDays, int $now): bool
    {
        if ($lastSent === null || $lastSent === '') return true;
        $ts = strtotime($lastSent);
        return $ts === false || ($now - $ts) >= $repeatDays * 86400;
    }

    private function sendWa(array $member, string $message): array
    {
        $phone = trim((string)$member['member_phone']);
        if ($this->dry) return ['ok' => true, 'text' => 'akan dikirim ke ' . $phone];
        try {
            // wa_notif_send mencatat hasilnya sendiri di wa_notif_log
            $r = \wa_notif_send($phone, $message, self::TYPE_WA, (string)$member['member_id'], (string)$member['member_name']);
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        $ok = !empty($r['ok']);
        return ['ok' => $ok, 'text' => $ok ? 'terkirim ke ' . $phone : 'gagal: ' . (string)($r['message'] ?? '')];
    }

    private function sendEmail(array $member, string $message): array
    {
        $email = trim((string)$member['member_email']);
        if ($this->dry) return ['ok' => true, 'text' => 'akan dikirim ke ' . $email];

        $subject = 'Pengingat Keterlambatan Peminjaman - ' . ($this->config['library_name'] ?? 'Perpustakaan');
        try {
            $r = ($this->sendMail)($email, (string)$member['member_name'], $subject, self::toPlain($message));
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => $e->getMessage()];
        }
        $ok = !empty($r['ok']);
        $info = (string)($r['message'] ?? '');
        \wa_notif_write_log(self::TYPE_EMAIL, (string)$member['member_id'], (string)$member['member_name'], '', $message,
            $ok ? 'success' : 'failed', 'email: ' . $email . ($info !== '' ? ' | ' . $info : ''));
        return ['ok' => $ok, 'text' => $ok ? 'terkirim ke ' . $email : 'gagal: ' . $info];
    }

    /** Teks pesan WhatsApp menjadi teks email biasa (penanda cetak tebal dihapus) */
    private static function toPlain(string $text): string
    {
        return (string)preg_replace('/\*(.+?)\*/u', '$1', $text);
    }

    /** Anggota dengan pinjaman terlambat >= cron_min_days hari. Pinjaman lama yang is_return = 0
     *  padahal sudah punya tanggal kembali tidak dihitung. */
    private function sqlMembers(): string
    {
        return "SELECT m.member_id, m.member_name, m.member_phone, m.member_email,
                   COALESCE(mt.fine_each_day, 0) AS fine_each_day,
                   COUNT(l.loan_id) AS total_items, MIN(l.due_date) AS oldest_due
              FROM loan l
              JOIN member m ON m.member_id = l.member_id
              LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
             WHERE l.is_lent = 1 AND l.is_return = 0
               AND (l.return_date IS NULL OR l.return_date = '0000-00-00')
               AND l.due_date <= CURDATE() - INTERVAL ? DAY
             GROUP BY m.member_id, m.member_name, m.member_phone, m.member_email, mt.fine_each_day
             ORDER BY oldest_due ASC, m.member_id ASC";
    }

    /** Semua koleksi terlambat anggota ini. Kolom "Overdue Days" dipakai Message::overdue(). */
    private function sqlItems(): string
    {
        return "SELECT l.item_code, b.title, l.loan_date, l.due_date,
                   (TO_DAYS(CURDATE()) - TO_DAYS(l.due_date)) AS `Overdue Days`
              FROM loan l
              LEFT JOIN item i ON i.item_code = l.item_code
              LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
             WHERE l.member_id = ? AND l.is_lent = 1 AND l.is_return = 0
               AND (l.return_date IS NULL OR l.return_date = '0000-00-00')
               AND l.due_date < CURDATE()
             ORDER BY l.due_date ASC";
    }

    /** Waktu pengiriman berhasil terakhir tiap anggota per saluran: [member_id][type] => 'Y-m-d H:i:s' */
    private function fetchLastSent(array $memberIds): array
    {
        $last = [];
        if (empty($memberIds)) return $last;

        $params = array_merge([self::TYPE_WA, self::TYPE_EMAIL], array_values($memberIds));
        $sql = 'SELECT member_id, type, MAX(created_at) AS last_sent
                  FROM wa_notif_log
                 WHERE status = \'success\' AND type IN (?, ?)
                   AND member_id IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ')
                 GROUP BY member_id, type';

        $st = $this->dbs->prepare($sql);
        if (!$st) throw new RuntimeException('Riwayat notifikasi (wa_notif_log) tidak dapat dibaca: ' . $this->dbs->error);
        $st->bind_param(str_repeat('s', count($params)), ...$params);
        $res = $st->execute() ? $st->get_result() : false;
        if (!$res) {
            $error = $st->error;
            $st->close();
            throw new RuntimeException('Riwayat notifikasi (wa_notif_log) tidak dapat dibaca: ' . ($error ?: 'tidak ada hasil'));
        }
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
            $last[(string)$row['member_id']][(string)$row['type']] = (string)$row['last_sent'];
        }
        $st->close();
        return $last;
    }

    private function rows(string $sql, string $types, array $params): array
    {
        $st = $this->dbs->prepare($sql);
        if (!$st) throw new RuntimeException('DB prepare failed: ' . $this->dbs->error);
        $st->bind_param($types, ...$params);
        $res = $st->execute() ? $st->get_result() : false;
        if (!$res) {
            $error = $st->error;
            $st->close();
            throw new RuntimeException('DB query failed: ' . $error);
        }
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    private function result(bool $ok, int $total, int $processed, int $notified, int $deferred, float $start, array $notes = []): array
    {
        return [
            'ok' => $ok,
            'dry' => $this->dry,
            'total_overdue' => $total,
            'processed' => $processed,
            'notified' => $notified,
            'deferred' => $deferred,
            'channels' => $this->channels,
            'details' => $this->details,
            'notes' => array_merge($this->notes, $notes),
            'seconds' => round(microtime(true) - $start, 2),
        ];
    }
}
