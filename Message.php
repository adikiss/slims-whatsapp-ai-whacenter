<?php

namespace WaNotif;

class Message
{
    public static function line(): string
    {
        return '————————————————';
    }

    public static function fmtDate(?string $date): string
    {
        if (empty($date)) return '-';
        $ts = strtotime($date);
        return $ts ? date('d-m-Y', $ts) : $date;
    }

    public static function fmtMoney($value): string
    {
        return 'Rp ' . number_format((float)$value, 0, ',', '.');
    }

    public static function footer(array $config): string
    {
        $text = trim($config['footer_text'] ?? '');
        return $text === '' ? '' : "\n" . self::line() . "\n" . $text;
    }

    public static function header(array $config, string $title): string
    {
        return '*' . strtoupper($config['library_name'] ?? 'PERPUSTAKAAN') . "*\n" . self::line() . "\n" . $title . "\n" . self::line() . "\n";
    }

    public static function receipt(array $data, array $config): string
    {
        $loan = !empty($config['notify_loan']) ? ($data['loan'] ?? []) : [];
        $extend = !empty($config['notify_extend']) ? ($data['extend'] ?? []) : [];
        $return = !empty($config['notify_return']) ? ($data['return'] ?? []) : [];

        $extendCodes = [];
        foreach ($extend as $item) {
            $extendCodes[$item['itemCode'] ?? ''] = true;
        }
        $return = array_values(array_filter($return, function ($item) use ($extendCodes) {
            return !isset($extendCodes[$item['itemCode'] ?? '']);
        }));

        if (empty($loan) && empty($extend) && empty($return)) return '';

        $text = self::header($config, '📚 RESI TRANSAKSI');
        $text .= 'No. Anggota : ' . ($data['memberID'] ?? '-') . "\n";
        $text .= 'Nama : *' . ($data['memberName'] ?? '-') . "*\n";
        $text .= 'Jenis Anggota : ' . ($data['memberType'] ?? '-') . "\n";
        $text .= 'Tanggal : ' . date('d-m-Y H:i') . "\n";

        if (!empty($loan)) {
            $text .= self::line() . "\n*PEMINJAMAN* (" . count($loan) . " koleksi)\n";
            $no = 1;
            foreach ($loan as $item) {
                $text .= $no++ . '. *' . ($item['title'] ?? '-') . "*\n";
                $text .= '   Kode : ' . ($item['itemCode'] ?? '-') . "\n";
                $text .= '   Pinjam : ' . self::fmtDate($item['loanDate'] ?? null) . "\n";
                $text .= '   Jatuh tempo : ' . self::fmtDate($item['dueDate'] ?? null) . "\n";
            }
        }

        if (!empty($return)) {
            $text .= self::line() . "\n*PENGEMBALIAN* (" . count($return) . " koleksi)\n";
            $no = 1;
            foreach ($return as $item) {
                $text .= $no++ . '. *' . ($item['title'] ?? '-') . "*\n";
                $text .= '   Kode : ' . ($item['itemCode'] ?? '-') . "\n";
                $text .= '   Kembali : ' . self::fmtDate($item['returnDate'] ?? null) . "\n";
                $overdues = $item['overdues'] ?? null;
                if (is_array($overdues) && !empty($overdues['days']) && (float)($overdues['value'] ?? 0) > 0) {
                    $text .= '   Denda : ' . self::fmtMoney($overdues['value']) . ' (' . $overdues['days'] . " hari terlambat)\n";
                }
            }
        }

        if (!empty($extend)) {
            $text .= self::line() . "\n*PERPANJANGAN* (" . count($extend) . " koleksi)\n";
            $no = 1;
            foreach ($extend as $item) {
                $text .= $no++ . '. *' . ($item['title'] ?? '-') . "*\n";
                $text .= '   Kode : ' . ($item['itemCode'] ?? '-') . "\n";
                $text .= '   Jatuh tempo baru : ' . self::fmtDate($item['dueDate'] ?? null) . "\n";
            }
        }

        return $text . self::footer($config);
    }

    public static function overdue(array $member, array $items, float $fineEachDay, array $config): string
    {
        $text = self::header($config, '⚠️ PENGINGAT KETERLAMBATAN');
        $text .= 'No. Anggota : ' . $member['member_id'] . "\n";
        $text .= 'Nama : *' . strtoupper($member['member_name']) . "*\n";
        $text .= self::line() . "\n";

        $totalFine = 0.0;
        $no = 1;
        foreach ($items as $item) {
            $days = (int)($item['Overdue Days'] ?? $item['days'] ?? 0);
            $fine = $days * $fineEachDay;
            $totalFine += $fine;
            $text .= $no++ . '. *' . ($item['title'] ?? '-') . "*\n";
            $text .= '   Kode : ' . ($item['item_code'] ?? '-') . "\n";
            $text .= '   Jatuh tempo : ' . self::fmtDate($item['due_date'] ?? null) . "\n";
            $text .= '   Terlambat : ' . $days . " hari\n";
            if ($fine > 0) {
                $text .= '   Estimasi denda : ' . self::fmtMoney($fine) . "\n";
            }
        }

        $text .= self::line() . "\n";
        if ($totalFine > 0) {
            $text .= 'Total estimasi denda : *' . self::fmtMoney($totalFine) . "*\n";
        }
        $text .= "Mohon segera mengembalikan koleksi di atas ke perpustakaan.\n";
        return $text . self::footer($config);
    }

    public static function memberWelcome(array $data, array $config): string
    {
        $member = is_array($data[0] ?? null) ? $data[0] : $data;
        $name = strtoupper($member['member_name'] ?? '');
        $id = $member['member_id'] ?? '-';
        $type = $member['member_type_name'] ?? '-';
        $register = self::fmtDate($member['register_date'] ?? null);
        $expire = self::fmtDate($member['expire_date'] ?? null);
        $phone = $member['member_phone'] ?? '-';

        $text = self::header($config, 'Selamat Datang!');
        $text .= "Halo *{$name}* 👋\n\n";
        $libName = $config['library_name'] ?? 'Perpustakaan';
        $text .= "Selamat! Anda telah terdaftar sebagai anggota *{$libName}*.\n\n";
        $text .= self::line() . "\n";
        $text .= 'No. Anggota : *' . $id . "*\n";
        $text .= 'Nama : *' . $name . "*\n";
        $text .= 'Jenis Anggota : ' . $type . "\n";
        $text .= 'Terdaftar : ' . $register . "\n";
        $text .= 'Berlaku s.d. : ' . $expire . "\n";
        $text .= self::line() . "\n\n";
        $text .= "Anda dapat menggunakan chatbot kami untuk:\n";
        $text .= "• *CARI* <judul buku> — cari koleksi\n";
        $text .= "• *PINJAM* — lihat pinjaman aktif\n";
        $text .= "• *DENDA* — cek denda\n";
        $text .= "• *MENU* — panduan lengkap\n\n";
        $text .= "Ketik *MENU* di nomor ini untuk informasi lebih lanjut.";
        return $text . self::footer($config);
    }

    public static function menu(bool $isMember, array $config): string
    {
        $text = self::header($config, '🤖 ASISTEN PERPUSTAKAAN');
        if ($isMember) {
            $text .= "Berikut perintah yang dapat digunakan:\n\n";
        } else {
            $text .= "Nomor Anda belum terdaftar sebagai anggota.\nPerintah umum yang dapat digunakan:\n\n";
        }
        $text .= "1. *CARI* <judul buku>\n";
        $text .= "   Contoh: CARI Laskar Pelangi\n";
        if ($isMember) {
            $text .= "2. *PINJAM* — daftar koleksi yang sedang dipinjam\n";
            $text .= "3. *DENDA* — total denda yang belum dibayar\n";
            $text .= "4. *ANGGOTA* — informasi keanggotaan\n";
        }
        if (!empty($config['enable_ai'])) {
            $text .= "\n💡 Anda juga bisa bertanya langsung! Kirim pesan bebas untuk berinteraksi dengan AI.";
        }
        return $text . self::footer($config);
    }

    public static function botReply($dbs, array $config, string $from, string $text): string
    {
        $text = trim($text);
        $parts = preg_split('/\s+/', $text, 2);
        $command = strtoupper($parts[0] ?? '');
        $argument = trim($parts[1] ?? '');

        $member = self::findMemberByPhone($dbs, $from);

        switch ($command) {
            case 'CARI':
            case 'CARIBUKU':
            case 'SEARCH':
            case 'FIND':
                return self::searchBiblio($dbs, $config, $argument);
            case 'PINJAM':
            case 'PINJAMAN':
            case 'LOAN':
                if (!$member) return self::notRegistered($config);
                return self::memberLoans($dbs, $config, $member);
            case 'DENDA':
            case 'FINES':
                if (!$member) return self::notRegistered($config);
                return self::memberFines($dbs, $config, $member);
            case 'ANGGOTA':
            case 'MEMBER':
            case 'PROFIL':
                if (!$member) return self::notRegistered($config);
                return self::memberInfo($config, $member);
            case 'HELP':
            case 'BANTUAN':
            case 'MENU':
            case 'HI':
            case 'HALO':
            case 'HELLO':
                return self::menu((bool)$member, $config);
            default:
                if ($argument !== '' && in_array($command, ['CARI', 'CARIBUKU', 'SEARCH', 'FIND'])) {
                    return self::searchBiblio($dbs, $config, $argument);
                }
                return self::aiReply($dbs, $config, $from, $text, $member);
        }
    }

    public static function aiReply($dbs, array $config, string $from, string $text, ?array $member = null): string
    {
        if (empty($config['enable_ai'])) {
            if ($member) {
                return 'Halo *' . strtoupper($member['member_name']) . "*! 👋\n\n" . self::menu(true, $config);
            }
            return self::menu(false, $config);
        }

        $ai = AiClient::fromConfig();
        if (is_null($ai)) {
            return self::header($config, '⚠️ AI TIDAK TERSEDIA') .
                'Layanan AI belum dikonfigurasi. Silakan hubungi admin.' . self::footer($config);
        }

        $libName = $config['library_name'] ?? 'Perpustakaan';
        $systemPrompt = "Anda adalah asisten virtual untuk {$libName}. ";
        $systemPrompt .= "Anda membantu anggota perpustakaan dengan informasi tentang layanan perpustakaan. ";
        $systemPrompt .= "Jawab pertanyaan dalam Bahasa Indonesia dengan singkat, ramah, dan profesional. ";
        $systemPrompt .= "Jika ditanya tentang buku/koleksi, sarankan untuk menggunakan perintah CARI <judul>. ";
        $systemPrompt .= "Jika ditanya tentang layanan yang bukan bidang perpustakaan, jelaskan bahwa Anda adalah asisten perpustakaan. ";
        $systemPrompt .= "Gunakan format WhatsApp: *teks* untuk bold, _teks_ untuk italic. ";

        $context = [];
        if ($member) {
            $context['nama_anggota'] = $member['member_name'];
            $context['jenis_anggota'] = $member['member_type_name'] ?? '-';
            $context['status'] = 'Aktif';
        }

        $result = $ai->chat($systemPrompt, $text, $context);

        if (!$result['ok']) {
            if ($member) {
                return 'Halo *' . strtoupper($member['member_name']) . "*! 👋\n\n" . self::menu(true, $config);
            }
            return self::menu(false, $config);
        }

        return self::header($config, '🤖 ASISTEN PERPUSTAKAAN') .
            $result['message'] . self::footer($config);
    }

    public static function webchatReply($dbs, array $config, string $text, ?array $member = null): string
    {
        $text = trim($text);
        $parts = preg_split('/\s+/', $text, 2);
        $command = strtoupper($parts[0] ?? '');
        $argument = trim($parts[1] ?? '');

        // Perintah CARI tetap pakai pencarian database (tanpa AI)
        if ($argument !== '' && in_array($command, ['CARI', 'CARIBUKU', 'SEARCH', 'FIND'])) {
            return self::searchBiblio($dbs, $config, $argument);
        }

        $ai = AiClient::fromConfig();
        if (is_null($ai) || empty($config['enable_ai'])) {
            return "Layanan AI belum diaktifkan.\nGunakan perintah *CARI* <judul buku> untuk mencari koleksi.\nContoh: CARI Laskar Pelangi";
        }

        $libName = $config['library_name'] ?? 'Perpustakaan';
        $systemPrompt = "Anda adalah asisten AI di website {$libName}. ";
        $systemPrompt .= "Bantu pengunjung dengan pertanyaan seputar perpustakaan, koleksi, dan layanan. ";
        $systemPrompt .= "Jawab dalam Bahasa Indonesia, singkat dan ramah. ";
        $systemPrompt .= "PENTING: JANGAN gunakan tag HTML (seperti <b>, <i>, <br>) sama sekali. ";
        $systemPrompt .= "Format teks dengan gaya WhatsApp: *teks* untuk bold, _teks_ untuk italic, dan baris baru biasa untuk ganti baris. ";
        $systemPrompt .= "Jika ada daftar koleksi pada konteks, gunakan untuk merekomendasikan buku yang relevan. ";

        // Konteks: hasil pencarian katalog berdasarkan pesan user
        $context = [];
        $catalogContext = self::searchBiblioForContext($dbs, $text);
        if ($catalogContext !== '') {
            $context['hasil_pencarian_katalog'] = $catalogContext;
            $context['catatan'] = 'Gunakan daftar di atas jika relevan dengan pertanyaan pengguna.';
        }
        if ($member) {
            $context['nama_pengunjung'] = $member['member_name'];
            $context['jenis_anggota'] = $member['member_type_name'] ?? '-';
        }

        $result = $ai->chat($systemPrompt, $text, $context);

        if (!$result['ok']) {
            return "Maaf, terjadi kendala pada layanan AI. Silakan coba lagi atau gunakan perintah *CARI* <judul buku>.";
        }

        return self::sanitizeForChat($result['message']);
    }

    /**
     * Safety net: jika AI tetap mengeluarkan tag HTML, konversi ke format teks
     * agar tidak tampil sebagai tag mentah di widget (yang men-escape HTML).
     */
    private static function sanitizeForChat(string $text): string
    {
        // <br> dan penutup blok → baris baru
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|ul|ol|pre)>/i', "\n", $text);
        // <li> → bullet
        $text = preg_replace('/<li[^>]*>/i', '• ', $text);
        // *bold* HTML → penanda WhatsApp
        $text = preg_replace('/<(b|strong)[^>]*>(.*?)<\/\1>/is', '*$2*', $text);
        $text = preg_replace('/<(i|em)[^>]*>(.*?)<\/\1>/is', '_$2_', $text);
        // buang sisa tag HTML apapun
        $text = preg_replace('/<[^>]+>/', '', $text);
        // HTML entity dasar
        $text = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;'], [' ', '&', '<', '>', '"'], $text);
        // rapikan baris kosong berlebih
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function searchBiblioForContext($dbs, string $keyword, int $limit = 8): string
    {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) < 3) return '';

        $clean = preg_replace('/^(cari|caribuku|search|find|buku|tentang|apa|yang|bagaimana|kapan|dimana|di mana|tolong|saya|mau|ingin|perlu)\s+/i', '', $keyword);
        $clean = trim($clean ?? $keyword);
        if (mb_strlen($clean) < 3) $clean = $keyword;

        $escaped = $dbs->escape_string(str_replace(['%', '_'], ['\\%', '\\_'], $clean));
        $q = $dbs->query("SELECT b.title, b.publish_year,
                (SELECT GROUP_CONCAT(ma.author_name SEPARATOR ', ') FROM biblio_author ba
                    LEFT JOIN mst_author ma ON ma.author_id = ba.author_id
                    WHERE ba.biblio_id = b.biblio_id) AS authors,
                (SELECT COUNT(i.item_code) FROM item i WHERE i.biblio_id = b.biblio_id) AS total_copy,
                (SELECT COUNT(l.loan_id) FROM loan l INNER JOIN item i ON i.item_code = l.item_code
                    WHERE i.biblio_id = b.biblio_id AND l.is_lent = 1 AND l.is_return = 0) AS on_loan
            FROM biblio b
            WHERE b.title LIKE '%{$escaped}%'
            ORDER BY b.last_update DESC
            LIMIT {$limit}");

        if (!$q || $q->num_rows < 1) return '';

        $lines = [];
        $no = 1;
        while ($row = $q->fetch_assoc()) {
            $available = max(0, (int)$row['total_copy'] - (int)$row['on_loan']);
            $line = $no++ . '. ' . $row['title'];
            if (!empty($row['authors'])) $line .= ' — ' . $row['authors'];
            if (!empty($row['publish_year'])) $line .= ' (' . $row['publish_year'] . ')';
            $line .= ' [' . $available . '/' . $row['total_copy'] . ' tersedia]';
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function notRegistered(array $config): string
    {
        return self::header($config, '⚠️ BELUM TERDAFTAR') .
            "Nomor WhatsApp Anda belum terdaftar sebagai anggota perpustakaan.\n" .
            "Silakan hubungi pustakawan untuk mendaftar atau mengaitkan nomor Anda dengan data keanggotaan.\n" .
            self::footer($config);
    }

    private static function findMemberByPhone($dbs, string $from): ?array
    {
        $phone = Whacenter::normalizeNumber($from);
        $suffix = $dbs->escape_string(substr($phone, -10));
        $q = $dbs->query("SELECT m.*, mt.member_type_name, mt.fine_each_day
            FROM member m LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
            WHERE REPLACE(m.member_phone, '-', '') LIKE '%{$suffix}' LIMIT 1");
        if ($q && $q->num_rows > 0) {
            $row = $q->fetch_assoc();
            return $row;
        }
        return null;
    }

    private static function searchBiblio($dbs, array $config, string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return "Cara penggunaan:\n*CARI* <judul buku>\nContoh: CARI Laskar Pelangi";
        }

        $escaped = $dbs->escape_string(str_replace(['%', '_'], ['\\%', '\\_'], $keyword));
        $q = $dbs->query("SELECT b.biblio_id, b.title, b.edition, b.publish_year, b.isbn_issn,
                (SELECT GROUP_CONCAT(ma.author_name SEPARATOR ', ') FROM biblio_author ba
                    LEFT JOIN mst_author ma ON ma.author_id = ba.author_id
                    WHERE ba.biblio_id = b.biblio_id) AS authors,
                (SELECT COUNT(i.item_code) FROM item i WHERE i.biblio_id = b.biblio_id) AS total_copy,
                (SELECT COUNT(l.loan_id) FROM loan l INNER JOIN item i ON i.item_code = l.item_code
                    WHERE i.biblio_id = b.biblio_id AND l.is_lent = 1 AND l.is_return = 0) AS on_loan
            FROM biblio b
            WHERE b.title LIKE '%{$escaped}%'
            ORDER BY b.last_update DESC
            LIMIT 5");

        if (!$q || $q->num_rows < 1) {
            return self::header($config, '🔎 HASIL PENCARIAN') .
                "Tidak ditemukan koleksi dengan judul: *{$keyword}*\nCoba kata kunci lain yang lebih singkat." .
                self::footer($config);
        }

        $text = self::header($config, '🔎 HASIL PENCARIAN');
        $text .= "Kata kunci: *{$keyword}*\n";
        $no = 1;
        while ($row = $q->fetch_assoc()) {
            $available = (int)$row['total_copy'] - (int)$row['on_loan'];
            if ((int)$row['total_copy'] < 1) $status = 'ℹ️ Tanpa eksemplar';
            elseif ($available > 0) $status = '✅ Tersedia (' . $available . ' dari ' . $row['total_copy'] . ')';
            else $status = '❌ Semua dipinjam';
            $text .= $no++ . '. *' . $row['title'] . "*\n";
            if (!empty($row['authors'])) $text .= '   Pengarang : ' . $row['authors'] . "\n";
            if (!empty($row['publish_year'])) $text .= '   Tahun : ' . $row['publish_year'] . "\n";
            $text .= '   Status : ' . $status . "\n";
        }
        $text .= "\nPinjam melalui pustakawan atau kunjungi perpustakaan.\n";
        return $text . self::footer($config);
    }

    private static function memberLoans($dbs, array $config, array $member): string
    {
        $escaped = $dbs->escape_string($member['member_id']);
        $q = $dbs->query("SELECT l.item_code, l.loan_date, l.due_date, b.title,
                (TO_DAYS(DATE(NOW())) - TO_DAYS(l.due_date)) AS overdue_days
            FROM loan l
            LEFT JOIN item i ON i.item_code = l.item_code
            LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
            WHERE l.member_id = '{$escaped}' AND l.is_lent = 1 AND l.is_return = 0
            ORDER BY l.due_date ASC");

        if (!$q || $q->num_rows < 1) {
            return self::header($config, '📖 KOLEKSI DIPINJAM') .
                'Anda tidak memiliki koleksi yang sedang dipinjam.' . self::footer($config);
        }

        $text = self::header($config, '📖 KOLEKSI DIPINJAM');
        $no = 1;
        while ($row = $q->fetch_assoc()) {
            $text .= $no++ . '. *' . ($row['title'] ?? '-') . "*\n";
            $text .= '   Kode : ' . $row['item_code'] . "\n";
            $text .= '   Jatuh tempo : ' . self::fmtDate($row['due_date']) . "\n";
            if ((int)$row['overdue_days'] > 0) {
                $text .= '   ⚠️ Terlambat : ' . $row['overdue_days'] . " hari\n";
            }
        }
        return $text . self::footer($config);
    }

    private static function memberFines($dbs, array $config, array $member): string
    {
        $escaped = $dbs->escape_string($member['member_id']);
        $q = $dbs->query("SELECT SUM(debet - credit) AS balance FROM fines WHERE member_id = '{$escaped}'");
        $balance = 0.0;
        if ($q && $row = $q->fetch_assoc()) {
            $balance = (float)($row['balance'] ?? 0);
        }

        $text = self::header($config, '💰 INFORMASI DENDA');
        if ($balance <= 0) {
            $text .= "✅ Anda tidak memiliki denda yang belum dibayar.\n";
        } else {
            $text .= 'Total denda belum dibayar : *' . self::fmtMoney($balance) . "*\n";
            $text .= "Silakan lunasi di loket sirkulasi perpustakaan.\n";
        }
        return $text . self::footer($config);
    }

    private static function memberInfo(array $config, array $member): string
    {
        $text = self::header($config, '👤 INFORMASI ANGGOTA');
        $text .= 'No. Anggota : ' . $member['member_id'] . "\n";
        $text .= 'Nama : *' . strtoupper($member['member_name']) . "*\n";
        $text .= 'Jenis : ' . ($member['member_type_name'] ?? '-') . "\n";
        $text .= 'Terdaftar : ' . self::fmtDate($member['register_date'] ?? null) . "\n";
        $text .= 'Berlaku s.d. : ' . self::fmtDate($member['expire_date'] ?? null) . "\n";
        $text .= 'Status : ' . (empty($member['is_pending']) ? '✅ Aktif' : '⏳ Menunggu verifikasi') . "\n";
        return $text . self::footer($config);
    }
}
