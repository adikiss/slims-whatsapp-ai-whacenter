# WhatsApp AI Whacenter Plugin for SLiMS 9

Plugin notifikasi WhatsApp untuk SLiMS 9 (Bulian) dengan integrasi AI melalui Whacenter API.

## Fitur

- **Notifikasi Transaksi** — Otomatis kirim pesan WhatsApp saat peminjaman, pengembalian, dan perpanjangan
- **Notifikasi Keterlambatan** — Kirim pengingat keterlambatan dan estimasi denda ke anggota
- **Notifikasi Terlambat Otomatis** — Pengingat keterlambatan terjadwal via cron (WhatsApp dan/atau email), tanpa mengirim ulang ke anggota yang baru dinotifikasi
- **Notifikasi Anggota Baru** — Kirim pesan selamat datang saat anggota baru terdaftar
- **Chatbot WhatsApp** — Anggota dapat mencari buku, cek pinjaman, dan denda via WhatsApp
- **AI Assistant** — Integrasi AI (OpenRouter) untuk menjawab pertanyaan umum di luar perintah chatbot
- **Web Chat AI** — Widget chat AI di OPAC (auto-inject, tanpa edit template)
- **MCP Server** — Agent AI (Claude Code, Codex, opencode) bisa membaca data perpustakaan
- **Log Lengkap** — Log semua pesan terkirim dengan filter dan pencarian
- **Dashboard Widget** — Statistik notifikasi di beranda admin SLiMS

## Persiapan

1. Buat akun di [app.whacenter.com](https://app.whacenter.com)
2. Hubungkan perangkat WhatsApp dan dapatkan **Device ID**
3. (Opsional) Buat API key di [openrouter.ai/keys](https://openrouter.ai/keys) untuk fitur AI

## Instalasi

1. Unduh atau clone repository ini
2. Copy folder `whatsapp_notification` ke direktori `plugins/` di SLiMS
3. Login ke admin SLiMS
4. Buka **System > Plugin Management**
5. Aktifkan plugin **WhatsApp Notification (Whacenter)**
6. Buka **System > WhatsApp Notification**
7. Masukkan **Device ID** Whacenter
8. Aktifkan fitur yang diinginkan
9. (Opsional) Masukkan **API Key** OpenRouter untuk mengaktifkan AI

## Konfigurasi

| Pengaturan | Deskripsi |
|------------|-----------|
| Device ID | ID perangkat dari dashboard Whacenter |
| Aktifkan Notifikasi | Master switch untuk semua notifikasi |
| Peminjaman | Notifikasi saat buku dipinjam |
| Pengembalian | Notifikasi saat buku dikembalikan |
| Perpanjangan | Notifikasi saat pinjaman diperpanjang |
| Keterlambatan | Pengingat keterlambatan |
| Anggota Baru | Pesan selamat datang untuk anggota baru |
| Notifikasi Terlambat Otomatis | Pengiriman terjadwal via cron: saluran (WhatsApp/email), mulai terlambat berapa hari, jeda pengiriman ulang, token & URL cron |
| Chatbot | Aktifkan chatbot WhatsApp |
| AI Assistant | Aktifkan AI untuk pertanyaan umum |
| API Key | API key dari OpenRouter |
| Model | Model AI yang digunakan (default: google/gemini-2.0-flash-001) |

## Perintah Chatbot

| Perintah | Deskripsi |
|----------|-----------|
| `CARI <judul>` | Cari koleksi berdasarkan judul |
| `PINJAM` | Lihat daftar pinjaman aktif |
| `DENDA` | Lihat total denda |
| `ANGGOTA` | Lihat informasi keanggotaan |
| `MENU` | Tampilkan panduan lengkap |
| Pesan bebas | Dijawab oleh AI (jika diaktifkan) |

## Kebutuhan

- SLiMS 9.8.0 (Bulian)
- PHP 8.0+
- Whacenter account dengan Device ID aktif
- (Opsional) OpenRouter API key untuk fitur AI

## Lisensi

MIT License

## Credits

Dibuat untuk komunitas perpustakaan Indonesia.

## Notifikasi Terlambat Otomatis (Cron)

Pengingat keterlambatan bisa dikirim otomatis sesuai jadwal lewat WhatsApp dan/atau email. Pengingat manual tetap tersedia di menu **Circulation > Notifikasi Terlambat WA**.

### Aktivasi

1. Buka **System > WhatsApp Notification**, bagian **Notifikasi Terlambat Otomatis**
2. Pilih saluran (WhatsApp, Email, atau keduanya). Email memakai pengaturan email SLiMS.
3. Tentukan mulai terlambat berapa hari pesan dikirim, jeda pengiriman ulang, dan maksimal anggota per proses
4. Centang **Aktifkan pengiriman terjadwal** lalu simpan; URL cron beserta tokennya akan muncul
5. Klik **Tes tanpa mengirim** untuk melihat siapa saja yang akan dihubungi
6. Tambahkan cron di hosting yang memanggil URL tersebut, misalnya setiap hari jam 07.00 (waktu server):

```cron
0 7 * * * curl -s "https://perpustakaan.example.com/index.php?p=wa_cron&token=<TOKEN_CRON>"
```

### Perilaku

- Anggota yang paling lama terlambat dihubungi lebih dulu, maksimal 50 anggota atau 20 detik per proses; sisanya dilanjutkan di jadwal berikutnya
- Sebuah saluran tidak dipakai lagi untuk anggota yang sama sebelum jeda pengiriman ulang terlewat (default 7 hari); pengingat yang dikirim manual ikut dihitung
- Jika tabel `wa_notif_log` belum ada, proses berhenti tanpa mengirim apa pun
- Hasil pengiriman tercatat di **WA Notification Log**; tambahkan `&dry=1` pada URL cron untuk mode tes

Token cron terpisah dari token MCP. URL cron memuat token itu, jadi jangan disebarluaskan.

## Web Chat AI (OPAC)

Plugin ini juga menyediakan widget **Web Chat AI** yang muncul di website OPAC (tombol hijau di pojok kanan bawah):

- Pengunjung bisa bertanya bebas — dijawab AI (butuh API Key OpenRouter)
- Perintah `CARI <judul>` — langsung cari di katalog database
- Quick reply buttons untuk pertanyaan umum
- Deteksi member yang sedang login OPAC

### Aktivasi
1. Centang **Aktifkan Web Chat AI di OPAC** di System > WhatsApp Notification
2. Aktifkan **AI Assistant** dan isi API Key OpenRouter

### Integrasi ke Template
Tambahkan 3 baris ini di `template/<tema>/parts/footer.php` (sebelum `</body>`):

```php
<?php
// WhatsApp Notification plugin : Web Chat AI widget
$_wa_widget = SB . 'plugins/whatsapp_notification/webchat_widget.php';
if (file_exists($_wa_widget)) include $_wa_widget;
?>
```

Endpoint chat: `index.php?p=wa_webchat` (POST JSON `{"message": "..."}`)

## MCP Server (untuk Claude Code / Codex / opencode)

Plugin ini menyertakan **MCP Server** (Model Context Protocol, transport Streamable HTTP) sehingga agent AI bisa membaca data perpustakaan secara langsung: katalog, anggota, peminjaman, keterlambatan, dan denda. Semua tool **read-only** (diberi penanda `readOnlyHint`) dan diamankan dengan token.

### Aktivasi
1. Buka **System > WhatsApp Notification**
2. Centang **Aktifkan MCP Server** lalu **Save Settings** (token dibuat otomatis)
3. Salin **URL Endpoint** dan **Token** yang ditampilkan

### Tools yang tersedia

| Tool | Deskripsi |
|------|-----------|
| `search_catalog` | Cari koleksi + ketersediaan eksemplar |
| `get_member` | Profil anggota, status, pinjaman aktif, denda |
| `get_loans` | Daftar pinjaman anggota (aktif/terlambat/kembali) |
| `get_overdue` | Semua keterlambatan + kontak WA anggota |
| `get_fines` | Riwayat & total denda belum bayar |
| `get_stats` | Statistik ringkas perpustakaan |

### Koneksi Claude Code

```bash
claude mcp add --transport http slims <URL_ENDPOINT> --header "Authorization: Bearer <TOKEN>"
```

atau di `.mcp.json` proyek:

```json
{
  "mcpServers": {
    "slims": {
      "type": "http",
      "url": "https://perpustakaan.example.com/index.php?p=wa_mcp",
      "headers": { "Authorization": "Bearer <TOKEN>" }
    }
  }
}
```

### Koneksi opencode

Di `opencode.json`:

```json
{
  "mcp": {
    "slims": {
      "type": "remote",
      "url": "https://perpustakaan.example.com/index.php?p=wa_mcp",
      "headers": { "Authorization": "Bearer <TOKEN>" },
      "enabled": true
    }
  }
}
```

### Koneksi Codex CLI

Di `~/.codex/config.toml`:

```toml
[mcp_servers.slims]
url = "https://perpustakaan.example.com/index.php?p=wa_mcp"
bearer_token_env_var = "SLIMS_MCP_TOKEN"   # export SLIMS_MCP_TOKEN=<TOKEN>
```

### Contoh pemakaian

Setelah terhubung, cukup tanya agent-nya:

- *"Cari buku laskar pelangi, masih ada yang bisa dipinjam?"*
- *"Anggota 1002 pinjam apa saja? Ada yang telat?"*
- *"Siapa saja anggota yang paling terlambat?"*
- *"Berapa total denda yang belum dibayar bulan ini?"*

Catatan: server harus dapat diakses dari tempat agent berjalan (publik/HTTPS untuk agent berbasis cloud; localhost cukup untuk agent lokal).
