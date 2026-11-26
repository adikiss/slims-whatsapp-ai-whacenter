<?php

defined('INDEX_AUTH') or die('Direct access not allowed!');

use WaNotif\{WaConfig, Whacenter};

require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

$canRead = utility::havePrivilege('system', 'r') || utility::havePrivilege('system', 'w');
if (!$canRead) die('<div class="alert alert-danger">' . __('You don\'t have enough privileges to access this area!') . '</div>');

$config = WaConfig::load();
// Normalisasi: pastikan semua key tersedia (config.json lama mungkin belum memuat key baru)
$config = array_merge(WaConfig::defaults(), $config);
$alert = null;
$alertType = 'success';

// Base URL absolut dari host saat ini (SWB bisa relatif jika baseurl kosong)
$waScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
$waHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$waRootPath = preg_replace('#/admin/.*$#', '/', $_SERVER['PHP_SELF'] ?? '/');
$waBaseUrl = $waScheme . '://' . $waHost . $waRootPath;

$defaultWebhookUrl = (str_starts_with(SWB, 'http') ? SWB : $waBaseUrl) . 'index.php?p=wa_webhook';
$defaultMcpUrl = (str_starts_with(SWB, 'http') ? SWB : $waBaseUrl) . 'index.php?p=wa_mcp';

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save') {
        if (!WaConfig::isPluginDirWritable()) {
            $alert = 'Folder plugin tidak dapat ditulis. Jalankan: chmod 777 plugins/whatsapp_notification';
            $alertType = 'danger';
        } else {
            $config = array_merge($config, [
                'device_id' => trim($_POST['device_id'] ?? ''),
                'enable' => isset($_POST['enable']),
                'notify_loan' => isset($_POST['notify_loan']),
                'notify_return' => isset($_POST['notify_return']),
                'notify_extend' => isset($_POST['notify_extend']),
                'notify_overdue' => isset($_POST['notify_overdue']),
                'notify_new_member' => isset($_POST['notify_new_member']),
                'enable_chatbot' => isset($_POST['enable_chatbot']),
                'enable_webchat' => isset($_POST['enable_webchat']),
                'enable_ai' => isset($_POST['enable_ai']),
                'ai_api_key' => trim($_POST['ai_api_key'] ?? ''),
                'ai_model' => trim($_POST['ai_model'] ?? ''),
                'ai_base_url' => trim($_POST['ai_base_url'] ?? ''),
                'library_name' => trim($_POST['library_name'] ?? ''),
                'footer_text' => trim($_POST['footer_text'] ?? ''),
                'webhook_url' => trim($_POST['webhook_url'] ?? ''),
                'mcp_enable' => isset($_POST['mcp_enable']),
                'mcp_token' => trim($_POST['mcp_token'] ?? ''),
                'mcp_allow_send' => isset($_POST['mcp_allow_send']),
                'cron_enable' => isset($_POST['cron_enable']),
                'cron_token' => trim($_POST['cron_token'] ?? ''),
                'cron_channel_wa' => isset($_POST['cron_channel_wa']),
                'cron_channel_email' => isset($_POST['cron_channel_email']),
                'cron_min_days' => max(1, (int)($_POST['cron_min_days'] ?? 1)),
                'cron_repeat_days' => max(1, (int)($_POST['cron_repeat_days'] ?? 7)),
                'cron_max_per_run' => max(1, (int)($_POST['cron_max_per_run'] ?? 50)),
            ]);
            if ($config['mcp_enable'] && $config['mcp_token'] === '') {
                $config['mcp_token'] = bin2hex(random_bytes(16));
            }
            // Token cron dibuat sekarang agar URL cron sudah bisa diuji (tanpa mengirim) sebelum fitur diaktifkan
            if ($config['cron_token'] === '') {
                $config['cron_token'] = bin2hex(random_bytes(16));
            }
            $saved = WaConfig::save($config);
            $alert = $saved ? 'Pengaturan berhasil disimpan.' : 'Gagal menyimpan pengaturan.';
            $alertType = $saved ? 'success' : 'danger';
        }
    } elseif ($action === 'status') {
        $wa = Whacenter::fromConfig();
        if (is_null($wa)) {
            $alert = 'Device ID masih kosong. Simpan Device ID terlebih dahulu.';
            $alertType = 'danger';
        } else {
            $result = $wa->status();
            $body = $result['body'] ?? null;
            if ($result['ok'] && is_array($body['data'] ?? null)) {
                $d = $body['data'];
                $alert = 'Status device: <strong>' . htmlspecialchars($d['status'] ?? '-') . '</strong> | Nomor: ' . htmlspecialchars($d['nomor'] ?? '-') . ' | Nama: ' . htmlspecialchars($d['nama'] ?? '-');
                $alertType = str_contains(strtolower($d['status'] ?? ''), 'connect') ? 'success' : 'warning';
            } else {
                $alert = 'Gagal mengambil status device: ' . htmlspecialchars(is_string($result['raw']) ? $result['raw'] : json_encode($result['raw']));
                $alertType = 'danger';
            }
        }
    } elseif ($action === 'test') {
        $phone = trim($_POST['test_phone'] ?? '');
        if ($phone === '') {
            $alert = 'Masukkan nomor WhatsApp tujuan untuk tes kirim.';
            $alertType = 'danger';
        } else {
            $testConfig = $config;
            $message = WaNotif\Message::header($testConfig, '🧪 TES KONEKSI') .
                "Ini adalah pesan tes dari plugin WhatsApp Notification SLiMS.\n" .
                "Jika Anda menerima pesan ini, konfigurasi sudah benar. ✅" .
                WaNotif\Message::footer($testConfig);
            $result = wa_notif_send($phone, $message, 'test');
            $alert = ($result['ok'] ? 'Pesan tes berhasil dikirim ke ' . htmlspecialchars($phone) . '.' : 'Pesan tes GAGAL dikirim: ') . htmlspecialchars((string)$result['message']);
            $alertType = $result['ok'] ? 'success' : 'danger';
        }
    } elseif ($action === 'register_webhook') {
        $url = trim($_POST['webhook_url'] ?? $config['webhook_url']);
        if ($url === '') $url = $defaultWebhookUrl;
        // Normalisasi URL relatif menjadi absolut
        if ($url !== '' && !str_starts_with($url, 'http')) {
            $url = $waBaseUrl . ltrim($url, '/');
        }
        $wa = Whacenter::fromConfig();
        if (is_null($wa)) {
            $alert = 'Device ID masih kosong. Simpan Device ID terlebih dahulu.';
            $alertType = 'danger';
        } else {
            $result = $wa->setWebhook($url);
            if ($result['ok']) {
                $config['webhook_url'] = $url;
                if (WaConfig::isPluginDirWritable()) WaConfig::save($config);
                $alert = 'Webhook <code>' . htmlspecialchars($url) . '</code> berhasil didaftarkan ke Whacenter.';
            } else {
                $alert = 'Gagal mendaftarkan webhook: ' . htmlspecialchars(is_string($result['raw']) ? $result['raw'] : json_encode($result['raw']));
                $alertType = 'danger';
            }
        }
    } elseif ($action === 'test_ai') {
        $ai = WaNotif\AiClient::fromConfig();
        if (is_null($ai)) {
            $alert = 'Konfigurasi AI belum lengkap. Isi API Key terlebih dahulu.';
            $alertType = 'danger';
        } else {
            $libName = $config['library_name'] ?? 'Perpustakaan';
            $result = $ai->chat(
                "Anda adalah asisten virtual untuk {$libName}. Jawab dalam Bahasa Indonesia dengan singkat dan ramah.",
                'Halo, apa yang bisa kamu bantu?'
            );
            $alert = ($result['ok'] ? '✅ AI berhasil merespon: ' : '❌ AI GAGAL: ') . htmlspecialchars($result['message']);
            $alertType = $result['ok'] ? 'success' : 'danger';
        }
    }
}

// Dihitung setelah penyimpanan, agar token cron yang baru dibuat langsung muncul di URL
$defaultCronUrl = (str_starts_with(SWB, 'http') ? SWB : $waBaseUrl) . 'index.php?p=wa_cron&token=' . rawurlencode((string)($config['cron_token'] ?? ''));

$checked = fn(bool $value) => $value ? 'checked' : '';
$selfUrl = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
?>
<div class="menuBox">
  <div class="menuBoxInner systemIcon">
    <div class="per_title">
      <h2><?= __('WhatsApp Notification (Whacenter)') ?></h2>
    </div>
  </div>

<?php if ($alert): ?>
<div class="alert alert-<?= $alertType ?>"><?= $alert ?></div>
<?php endif; ?>

<form method="post" action="<?= $selfUrl ?>" id="waNotifForm" class="form">
  <input type="hidden" name="action" value="save" id="waActionField">
  <table class="s-table table" id="dataList" cellpadding="5" cellspacing="0">
    <tbody>
      <tr>
        <td width="30%" class="alterCell"><strong>Device ID Whacenter</strong><br><small>Didapat dari dashboard <a href="https://app.whacenter.com" target="_blank">app.whacenter.com</a> setelah perangkat terhubung</small></td>
        <td class="alterCell2"><input type="text" name="device_id" value="<?= htmlspecialchars($config['device_id']) ?>" class="form-control" style="width:100%"></td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Aktifkan Notifikasi</strong><br><small>Master switch semua notifikasi</small></td>
        <td class="alterCell2">
          <label class="mr-3"><input type="checkbox" name="enable" <?= $checked($config['enable']) ?>> Aktif</label>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Notifikasi Transaksi</strong></td>
        <td class="alterCell2">
          <label class="d-block"><input type="checkbox" name="notify_loan" <?= $checked($config['notify_loan']) ?>> Peminjaman</label>
          <label class="d-block"><input type="checkbox" name="notify_return" <?= $checked($config['notify_return']) ?>> Pengembalian (termasuk info denda)</label>
          <label class="d-block"><input type="checkbox" name="notify_extend" <?= $checked($config['notify_extend']) ?>> Perpanjangan</label>
          <label class="d-block"><input type="checkbox" name="notify_overdue" <?= $checked($config['notify_overdue']) ?>> Keterlambatan (via menu Membership / Notifikasi Terlambat WA)</label>
          <label class="d-block"><input type="checkbox" name="notify_new_member" <?= $checked($config['notify_new_member']) ?>> Anggota baru (selamat datang)</label>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Chatbot WhatsApp</strong><br><small>Anggota dapat mencari bibliografi, cek pinjaman &amp; denda dengan mengirim pesan ke nomor perpustakaan. Contoh: <code>CARI Laskar Pelangi</code></small></td>
        <td class="alterCell2">
          <label class="d-block"><input type="checkbox" name="enable_chatbot" <?= $checked($config['enable_chatbot']) ?>> Aktifkan chatbot WhatsApp</label>
          <label class="d-block"><input type="checkbox" name="enable_webchat" <?= $checked($config['enable_webchat']) ?>> Aktifkan Web Chat AI di OPAC (tombol chat di website)</label>
      </tr>
      <tr>
        <td class="alterCell"><strong>AI Assistant</strong><small>Gunakan AI (OpenRouter) untuk menjawab pertanyaan umum di luar perintah chatbot. Pesan yang tidak dikenali akan dijawab oleh AI.</small></td>
        <td class="alterCell2">
          <label class="d-block"><input type="checkbox" name="enable_ai" <?= $checked($config['enable_ai']) ?>> Aktifkan AI</label>
          <div class="mt-2">
            <label>API Key <small>(dari <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>)</small></label>
            <input type="password" name="ai_api_key" value="<?= htmlspecialchars($config['ai_api_key']) ?>" class="form-control" style="width:100%" placeholder="sk-or-...">
          </div>
          <div class="mt-2">
            <label>Model</label>
            <input type="text" name="ai_model" value="<?= htmlspecialchars($config['ai_model'] ?? 'google/gemini-2.0-flash-001') ?>" class="form-control" style="width:100%" placeholder="google/gemini-2.0-flash-001">
          </div>
          <div class="mt-2">
            <label>Base URL <small>(default: OpenRouter)</small></label>
            <input type="text" name="ai_base_url" value="<?= htmlspecialchars($config['ai_base_url'] ?? 'https://openrouter.ai/api/v1') ?>" class="form-control" style="width:100%">
          </div>
          <button type="button" class="btn btn-default btn-sm mt-2" onclick="doAction('test_ai')">Tes AI</button>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Webhook URL</strong><br><small>Endpoint chatbot ini harus didaftarkan ke Whacenter dan server harus dapat diakses publik (HTTPS)</small></td>
        <td class="alterCell2">
          <input type="text" name="webhook_url" value="<?= htmlspecialchars($config['webhook_url']) ?>" class="form-control" style="width:100%" placeholder="<?= htmlspecialchars($defaultWebhookUrl) ?>">
          <button type="button" class="btn btn-default btn-sm mt-1" onclick="fillDefault()">Gunakan URL server ini</button>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Nama Perpustakaan</strong><br><small>Ditampilkan di header pesan</small></td>
        <td class="alterCell2"><input type="text" name="library_name" value="<?= htmlspecialchars($config['library_name']) ?>" class="form-control" style="width:100%"></td>
      </tr>
      <tr>
        <td class="alterCell"><strong>MCP Server</strong><br><small>Izinkan agent AI (Claude Code, Codex, opencode) membaca data perpustakaan: katalog, anggota, peminjaman, keterlambatan, denda. Tool baca data bersifat <em>read-only</em> dan diamankan dengan token; mengirim WhatsApp lewat MCP harus diaktifkan secara terpisah.</small></td>
        <td class="alterCell2">
          <label class="d-block"><input type="checkbox" name="mcp_enable" <?= $checked((bool)($config['mcp_enable'] ?? false)) ?>> Aktifkan MCP Server</label>
          <label class="d-block mt-1"><input type="checkbox" name="mcp_allow_send" <?= $checked((bool)($config['mcp_allow_send'] ?? false)) ?>> Izinkan MCP mengirim WhatsApp (tool <code>send_whatsapp</code>)</label>
          <small class="form-text text-muted" style="display:block">Kalau aktif, agent AI yang memegang token MCP bisa mengirim pesan WhatsApp atas nama perpustakaan. Klien seperti ChatGPT meminta konfirmasi setiap kali mengirim. Pesan tercatat di log dengan tipe MCP.</small>
          <div class="mt-2">
            <label>Token Autentikasi <small>(kirim sebagai header <code>Authorization: Bearer &lt;token&gt;</code>)</small></label>
            <div class="input-group">
              <input type="text" name="mcp_token" id="waMcpToken" value="<?= htmlspecialchars($config['mcp_token'] ?? '') ?>" class="form-control" style="width:100%">
              <div class="input-group-append">
                <button type="button" class="btn btn-secondary" onclick="regenMcpToken()">Buat Token Baru</button>
              </div>
            </div>
          </div>
          <div class="mt-2">
            <label>URL Endpoint <small>(gunakan bersama token di konfigurasi agent)</small></label>
            <input type="text" readonly onclick="this.select()" class="form-control" style="width:100%;background:#f4f6f8" value="<?= htmlspecialchars($defaultMcpUrl) ?>">
          </div>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Notifikasi Terlambat Otomatis</strong><br><small>URL cron di bawah ini dipanggil oleh cron hosting (misalnya Cron Jobs di cPanel) sesuai jadwal yang diinginkan. Anggota yang sudah dinotifikasi tidak dihubungi lagi sebelum jeda pengiriman ulang terlewat. Hasilnya tercatat di WA Notification Log.</small></td>
        <td class="alterCell2">
          <label class="d-block"><input type="checkbox" name="cron_enable" <?= $checked((bool)($config['cron_enable'] ?? false)) ?>> Aktifkan pengiriman terjadwal</label>
          <div class="mt-2">
            <label class="mr-3"><input type="checkbox" name="cron_channel_wa" <?= $checked((bool)($config['cron_channel_wa'])) ?>> WhatsApp</label>
            <label><input type="checkbox" name="cron_channel_email" <?= $checked((bool)($config['cron_channel_email'])) ?>> Email (memakai pengaturan email SLiMS)</label>
          </div>
          <div class="form-row align-items-center mt-2">
            <label class="col-auto col-form-label">Kirim mulai terlambat</label>
            <div class="col-1"><input type="number" name="cron_min_days" min="1" value="<?= (int)$config['cron_min_days'] ?>" class="form-control form-control-sm"></div>
            <label class="col-auto col-form-label">hari, ulangi setiap</label>
            <div class="col-1"><input type="number" name="cron_repeat_days" min="1" value="<?= (int)$config['cron_repeat_days'] ?>" class="form-control form-control-sm"></div>
            <label class="col-auto col-form-label">hari, maksimal</label>
            <div class="col-1"><input type="number" name="cron_max_per_run" min="1" value="<?= (int)$config['cron_max_per_run'] ?>" class="form-control form-control-sm"></div>
            <label class="col-auto col-form-label">anggota per proses</label>
          </div>
          <div class="mt-2">
            <label>Token Cron <small>(bagian dari URL cron; buat baru kalau URL ini pernah tersebar)</small></label>
            <div class="input-group">
              <input type="text" name="cron_token" id="waCronToken" value="<?= htmlspecialchars($config['cron_token'] ?? '') ?>" class="form-control" style="width:100%">
              <div class="input-group-append">
                <button type="button" class="btn btn-secondary" onclick="regenToken('waCronToken')">Buat Token Baru</button>
              </div>
            </div>
          </div>
          <div class="mt-2">
            <label>URL Cron <small>(contoh jadwal setiap hari 07.00: <code>0 7 * * *</code>)</small></label>
            <input type="text" readonly onclick="this.select()" class="form-control" style="width:100%;background:#f4f6f8" value="<?= htmlspecialchars($defaultCronUrl) ?>">
            <input type="text" readonly onclick="this.select()" class="form-control mt-1" style="width:100%;background:#f4f6f8" value='curl -s "<?= htmlspecialchars($defaultCronUrl) ?>"'>
            <?php if (!empty($config['cron_token'])): ?>
            <a class="btn btn-default btn-sm mt-1" href="<?= htmlspecialchars($defaultCronUrl . '&dry=1') ?>" target="_blank">Tes tanpa mengirim</a>
            <small>Melihat siapa saja yang akan dihubungi, tanpa mengirim apa pun.</small>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <tr>
        <td class="alterCell"><strong>Teks Footer</strong></td>
        <td class="alterCell2"><textarea name="footer_text" rows="2" class="form-control" style="width:100%"><?= htmlspecialchars($config['footer_text']) ?></textarea></td>
      </tr>
    </tbody>
  </table>
  <div class="mt-2">
    <input type="submit" name="save" value="<?= __('Save Settings') ?>" class="btn btn-primary">
    <button type="button" class="btn btn-default" onclick="doAction('status')">Cek Status Device</button>
    <button type="button" class="btn btn-default" onclick="doAction('register_webhook')">Daftarkan Webhook</button>
  </div>
  <div class="form-group row mt-3">
    <label class="col-sm-3 col-form-label"><strong>Tes Kirim Pesan</strong></label>
    <div class="col-sm-5">
      <div class="input-group">
        <input type="text" name="test_phone" placeholder="08xxxxxxxxxx" class="form-control">
        <div class="input-group-append">
          <button type="button" class="btn btn-secondary" onclick="doAction('test')">Kirim Tes</button>
        </div>
      </div>
    </div>
  </div>
</form>
</div>

<script>
function doAction(action) {
  document.getElementById('waActionField').value = action;
  const form = document.getElementById('waNotifForm');
  // trigger handler submit AJAX SLiMS via jQuery, bukan submit native
  if (window.jQuery) {
    jQuery(form).trigger('submit');
  } else {
    form.submit();
  }
}
function fillDefault() {
  document.querySelector('input[name="webhook_url"]').value = '<?= addslashes($defaultWebhookUrl) ?>';
}
function regenToken(inputId) {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  document.getElementById(inputId).value = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}
function regenMcpToken() {
  regenToken('waMcpToken');
}
</script>
