<?php

defined('INDEX_AUTH') or die('Direct access not allowed!');

use WaNotif\{WaConfig, Whacenter};

require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

$canRead = utility::havePrivilege('system', 'r') || utility::havePrivilege('system', 'w');
if (!$canRead) die('<div class="alert alert-danger">' . __('You don\'t have enough privileges to access this area!') . '</div>');

$config = WaConfig::load();
$alert = null;
$alertType = 'success';

// Base URL absolut dari host saat ini (SWB bisa relatif jika baseurl kosong)
$waScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
$waHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$waRootPath = preg_replace('#/admin/.*$#', '/', $_SERVER['PHP_SELF'] ?? '/');
$waBaseUrl = $waScheme . '://' . $waHost . $waRootPath;

$defaultWebhookUrl = (str_starts_with(SWB, 'http') ? SWB : $waBaseUrl) . 'index.php?p=wa_webhook';

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
                'enable_ai' => isset($_POST['enable_ai']),
                'ai_api_key' => trim($_POST['ai_api_key'] ?? ''),
                'ai_model' => trim($_POST['ai_model'] ?? ''),
                'ai_base_url' => trim($_POST['ai_base_url'] ?? ''),
                'library_name' => trim($_POST['library_name'] ?? ''),
                'footer_text' => trim($_POST['footer_text'] ?? ''),
                'webhook_url' => trim($_POST['webhook_url'] ?? ''),
            ]);
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
          <label><input type="checkbox" name="enable_chatbot" <?= $checked($config['enable_chatbot']) ?>> Aktifkan chatbot
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
</script>
