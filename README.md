# WhatsApp AI Whacenter Plugin for SLiMS 9

Plugin notifikasi WhatsApp untuk SLiMS 9 (Bulian) dengan integrasi AI melalui Whacenter API.

## Fitur

- **Notifikasi Transaksi** — Otomatis kirim pesan WhatsApp saat peminjaman, pengembalian, dan perpanjangan
- **Notifikasi Keterlambatan** — Kirim pengingat keterlambatan dan estimasi denda ke anggota
- **Notifikasi Anggota Baru** — Kirim pesan selamat datang saat anggota baru terdaftar
- **Chatbot WhatsApp** — Anggota dapat mencari buku, cek pinjaman, dan denda via WhatsApp
- **AI Assistant** — Integrasi AI (OpenRouter) untuk menjawab pertanyaan umum di luar perintah chatbot
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
