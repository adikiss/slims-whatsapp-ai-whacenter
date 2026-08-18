<?php
/**
 * Web Chat AI Widget — ditampilkan di OPAC
 * Di-include dari template footer jika plugin aktif & webchat aktif
 */
defined('INDEX_AUTH') or die('Direct access not allowed!');

use WaNotif\WaConfig;

$_waConfig = WaConfig::load();
if (empty($_waConfig['enable']) || empty($_waConfig['enable_webchat'])) return;

$_waLibName = htmlspecialchars($_waConfig['library_name'] ?? 'Perpustakaan');
$_waChatUrl = SWB . 'index.php?p=wa_webchat';
$_waMemberName = '';
if (function_exists('utility') === false) {
    // utility tersedia via sysconfig; widget di-include dalam konteks OPAC yang sudah memuatnya
}
if (!empty($_SESSION['m_name'])) {
    $_waMemberName = htmlspecialchars($_SESSION['m_name']);
}
?>
<!-- WA Notif: Web Chat AI Widget -->
<div id="wa-webchat" style="font-family:inherit;">
  <div id="wa-webchat-panel" style="display:none;position:fixed;bottom:88px;right:16px;width:340px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 120px);background:#fff;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.22);z-index:99999;overflow:hidden;flex-direction:column;">
    <div style="background:#25D366;color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:18px;">🤖</div>
      <div style="flex:1;">
        <div style="font-weight:bold;font-size:14px;line-height:1.2;">AI Assistant</div>
        <div style="font-size:11px;opacity:.9;"><?=$_waLibName?></div>
      </div>
      <button id="wa-webchat-close" title="Tutup" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;">&times;</button>
    </div>
    <div id="wa-webchat-msgs" style="flex:1;overflow-y:auto;padding:14px;background:#f5f6f7;font-size:13px;">
      <div style="text-align:center;color:#8a8f94;font-size:11px;margin-bottom:10px;">
        <?php if ($_waMemberName !== ''): ?>
          Halo, <b><?=$_waMemberName?></b> 👋 — saya tahu kamu sedang login
        <?php else: ?>
          Tanyakan apa saja seputar perpustakaan
        <?php endif; ?>
      </div>
    </div>
    <div style="padding:10px;border-top:1px solid #e4e6e8;background:#fff;">
      <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
        <button class="wa-webchat-quick" style="border:1px solid #d1d5db;background:#fff;border-radius:14px;padding:4px 10px;font-size:11px;cursor:pointer;">CARI Laskar Pelangi</button>
        <?php if ($_waMemberName !== ''): ?>
        <button class="wa-webchat-quick" style="border:1px solid #d1d5db;background:#fff;border-radius:14px;padding:4px 10px;font-size:11px;cursor:pointer;">PINJAM</button>
        <button class="wa-webchat-quick" style="border:1px solid #d1d5db;background:#fff;border-radius:14px;padding:4px 10px;font-size:11px;cursor:pointer;">DENDA</button>
        <?php else: ?>
        <button class="wa-webchat-quick" style="border:1px solid #d1d5db;background:#fff;border-radius:14px;padding:4px 10px;font-size:11px;cursor:pointer;">Bagaimana cara jadi anggota?</button>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:8px;">
        <input type="text" id="wa-webchat-input" placeholder="Ketik pesan..." autocomplete="off"
               style="flex:1;border:1px solid #d1d5db;border-radius:20px;padding:9px 14px;font-size:13px;outline:none;">
        <button id="wa-webchat-send" title="Kirim"
                style="background:#25D366;border:none;color:#fff;border-radius:50%;width:38px;height:38px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;">➤</button>
      </div>
    </div>
  </div>
  <button id="wa-webchat-toggle" title="Chat dengan AI"
          style="position:fixed;bottom:16px;right:16px;width:60px;height:60px;border-radius:50%;background:#25D366;color:#fff;border:none;box-shadow:0 4px 18px rgba(37,211,102,.5);cursor:pointer;font-size:26px;z-index:99999;display:flex;align-items:center;justify-content:center;transition:transform .15s;">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.95 3 3 6.36 3 10.5c0 2.13 1.11 4.05 2.89 5.39-.15 1.14-.62 2.5-1.39 3.52-.05.07 0 .17.09.16 1.66-.14 3.22-.78 4.33-1.44.98.24 2.02.37 3.08.37 5.05 0 9-3.36 9-7.5S17.05 3 12 3z"/></svg>
  </button>
</div>
<script>
(function() {
  var panel = document.getElementById('wa-webchat-panel');
  var toggle = document.getElementById('wa-webchat-toggle');
  var closeBtn = document.getElementById('wa-webchat-close');
  var msgs = document.getElementById('wa-webchat-msgs');
  var input = document.getElementById('wa-webchat-input');
  var sendBtn = document.getElementById('wa-webchat-send');
  var chatUrl = '<?=$_waChatUrl?>';
  var busy = false;

  function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function addMsg(html, who) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;margin-bottom:10px;' + (who === 'me' ? 'justify-content:flex-end;' : 'justify-content:flex-start;');
    var b = document.createElement('div');
    b.style.cssText = 'max-width:82%;padding:9px 12px;border-radius:12px;line-height:1.45;white-space:normal;word-wrap:break-word;' +
      (who === 'me' ? 'background:#25D366;color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:#222;border-bottom-left-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,.08);');
    b.innerHTML = html;
    wrap.appendChild(b);
    msgs.appendChild(wrap);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function addTyping() {
    var wrap = document.createElement('div');
    wrap.id = 'wa-webchat-typing';
    wrap.style.cssText = 'display:flex;margin-bottom:10px;justify-content:flex-start;';
    wrap.innerHTML = '<div style="padding:9px 14px;border-radius:12px;border-bottom-left-radius:4px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.08);color:#8a8f94;font-size:12px;">mengetik...</div>';
    msgs.appendChild(wrap);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function removeTyping() {
    var t = document.getElementById('wa-webchat-typing');
    if (t) t.remove();
  }

  function formatWhatsApp(text) {
    // *bold* -> <b>, _italic_ -> <i>, newline -> <br>, URL -> link klik-able
    var t = escapeHtml(text);
    t = t.replace(/\*([^*\n]+)\*/g, '<b>$1</b>');
    t = t.replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>');
    t = t.replace(/((?:https?:\/\/|\.?\/)[^\s<]+|index\.php\?[^\s<]+)/g, function(url) {
      var isDetail = url.indexOf('show_detail') !== -1;
      var label = isDetail ? 'Lihat Detail' : url;
      return '<a href="' + url + '" target="_blank" rel="noopener" style="color:#0b7a3e;font-weight:600;text-decoration:underline;">' + label + ' 🔗</a>';
    });
    t = t.replace(/\n/g, '<br>');
    return t;
  }

  function send(msg) {
    msg = (msg || input.value).trim();
    if (!msg || busy) return;
    busy = true;
    input.value = '';
    addMsg(escapeHtml(msg), 'me');
    addTyping();

    fetch(chatUrl, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({message: msg})
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      removeTyping();
      if (res.status && res.reply) {
        addMsg(formatWhatsApp(res.reply), 'bot');
      } else {
        addMsg('Maaf, terjadi kesalahan. Silakan coba lagi.', 'bot');
      }
    })
    .catch(function() {
      removeTyping();
      addMsg('Koneksi bermasalah. Silakan coba lagi.', 'bot');
    })
    .finally(function() { busy = false; input.focus(); });
  }

  toggle.addEventListener('click', function() {
    var show = panel.style.display === 'none';
    panel.style.display = show ? 'flex' : 'none';
    if (show) {
      toggle.style.transform = 'scale(0.9)';
      setTimeout(function() { toggle.style.transform = ''; input.focus(); }, 150);
      if (!msgs.dataset.greeted) {
        msgs.dataset.greeted = '1';
        <?php if ($_waMemberName !== ''): ?>
        addMsg('Halo <b><?=$_waMemberName?></b>! 👋 Saya asisten AI <?=$_waLibName?>.<br>Saya sudah tahu kamu sedang login — tanyakan pinjaman, denda, atau koleksi. Ketik <b>PINJAM</b> / <b>DENDA</b> / <b>CARI &lt;judul&gt;</b>.', 'bot');
        <?php else: ?>
        addMsg('Halo! 👋 Saya asisten AI <?=$_waLibName?>.<br>Tanyakan apa saja seputar koleksi & layanan perpustakaan, atau ketik <b>CARI &lt;judul buku&gt;</b>.', 'bot');
        <?php endif; ?>
      }
    }
  });
  toggle.addEventListener('mouseenter', function() { toggle.style.transform = 'scale(1.08)'; });
  toggle.addEventListener('mouseleave', function() { toggle.style.transform = ''; });

  closeBtn.addEventListener('click', function() { panel.style.display = 'none'; });
  sendBtn.addEventListener('click', function() { send(); });
  input.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });

  document.querySelectorAll('.wa-webchat-quick').forEach(function(btn) {
    btn.addEventListener('click', function() { send(btn.textContent.trim()); });
  });
})();
</script>
