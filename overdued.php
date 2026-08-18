<?php

defined('INDEX_AUTH') or die('Direct access not allowed!');

use SLiMS\DB;
use WaNotif\{WaConfig, Message};

require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

$canRead = utility::havePrivilege('circulation', 'r') || utility::havePrivilege('circulation', 'w');
if (!$canRead) die('<div class="alert alert-danger">' . __('You don\'t have enough privileges to access this area!') . '</div>');

$config = WaConfig::load();
$dbs = DB::getInstance('mysqli');
$results = [];
$selfUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_diff_key($_GET, ['ajaxload' => 0, '_' => 0]));

$sendOverdue = function (string $memberId) use ($dbs, $config, &$results) {
    $escaped = $dbs->escape_string($memberId);
    $q = $dbs->query("SELECT m.member_id, m.member_name, m.member_phone, mt.fine_each_day
        FROM member m LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
        WHERE m.member_id = '{$escaped}' LIMIT 1");
    if (!$q || $q->num_rows < 1) {
        $results[] = ['name' => $memberId, 'ok' => false, 'message' => 'Data anggota tidak ditemukan'];
        return;
    }
    $member = $q->fetch_assoc();

    if (empty($member['member_phone'])) {
        $results[] = ['name' => $member['member_name'] . ' (' . $member['member_id'] . ')', 'ok' => false, 'message' => 'Nomor telepon kosong'];
        return;
    }

    $q2 = $dbs->query("SELECT l.item_code, b.title, l.loan_date, l.due_date,
            (TO_DAYS(DATE(NOW())) - TO_DAYS(l.due_date)) AS `Overdue Days`
        FROM loan l
        LEFT JOIN item i ON i.item_code = l.item_code
        LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
        WHERE l.member_id = '{$escaped}' AND l.is_lent = 1 AND l.is_return = 0 AND l.due_date < CURDATE()");
    $items = [];
    if ($q2) {
        while ($row = $q2->fetch_assoc()) $items[] = $row;
    }
    if (empty($items)) {
        $results[] = ['name' => $member['member_name'] . ' (' . $member['member_id'] . ')', 'ok' => false, 'message' => 'Tidak ada keterlambatan'];
        return;
    }

    $message = Message::overdue($member, $items, (float)($member['fine_each_day'] ?? 0), $config);
    $send = wa_notif_send($member['member_phone'], $message, 'overdue', $member['member_id'], $member['member_name']);
    $results[] = ['name' => $member['member_name'] . ' (' . $member['member_id'] . ')', 'ok' => $send['ok'], 'message' => $send['ok'] ? 'Terkirim' : (string)$send['message']];
};

if (!empty($_POST['send_member'])) {
    $sendOverdue(trim($_POST['send_member']));
} elseif (!empty($_POST['send_all'])) {
    $q = $dbs->query("SELECT DISTINCT l.member_id
        FROM loan l JOIN member m ON m.member_id = l.member_id
        WHERE l.is_lent = 1 AND l.is_return = 0 AND l.due_date < CURDATE()
            AND m.member_phone IS NOT NULL AND m.member_phone != ''");
    $total = 0;
    while ($row = $q->fetch_row()) {
        $sendOverdue($row[0]);
        $total++;
        usleep(500000);
    }
    if ($total === 0) $results[] = ['name' => '-', 'ok' => true, 'message' => 'Tidak ada anggota terlambat dengan nomor telepon terdaftar'];
}

$members = [];
$q = $dbs->query("SELECT m.member_id, m.member_name, m.member_phone, mt.fine_each_day,
        COUNT(l.loan_id) AS total_items, MIN(l.due_date) AS oldest_due,
        SUM(TO_DAYS(DATE(NOW())) - TO_DAYS(l.due_date)) AS total_days
    FROM loan l
    JOIN member m ON m.member_id = l.member_id
    LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
    WHERE l.is_lent = 1 AND l.is_return = 0 AND l.due_date < CURDATE()
    GROUP BY m.member_id, m.member_name, m.member_phone, mt.fine_each_day
    ORDER BY oldest_due ASC");
if ($q) {
    while ($row = $q->fetch_assoc()) $members[] = $row;
}
?>
<div class="menuBox">
  <div class="menuBoxInner circulationIcon">
    <div class="per_title">
      <h2><?= __('Notifikasi Keterlambatan WhatsApp') ?></h2>
    </div>
    <div class="infoBox">
      Mengirim pesan WhatsApp pengingat keterlambatan &amp; estimasi denda ke anggota melalui API Whacenter.
      <?= empty($config['enable']) ? '<strong style="color:red">Plugin belum diaktifkan di System &gt; WhatsApp Notification.</strong>' : '' ?>
    </div>

<?php if (!empty($results)): ?>
<div class="alert alert-info" id="waResultBox">
  <strong>Hasil pengiriman:</strong>
  <ul class="mb-0">
    <?php foreach ($results as $r): ?>
    <li><?= htmlspecialchars($r['name']) ?> — <?= $r['ok'] ? '✅ ' : '❌ ' ?><?= htmlspecialchars($r['message']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($selfUrl) ?>" id="waOverdueForm" class="form" loadcontainer="waOverdueResult">
  <input type="hidden" name="send_member" id="waSendMember" value="">
  <input type="hidden" name="send_all" id="waSendAll" value="">
  <div class="mb-2">
    <button type="submit" class="btn btn-primary wa-btn-send-all">Kirim ke Semua</button>
  </div>
  <div id="waOverdueResult">
  <table class="s-table table bordered" id="dataList" cellpadding="5" cellspacing="0">
    <thead>
      <tr>
        <th><?= __('Member ID') ?></th>
        <th><?= __('Member Name') ?></th>
        <th><?= __('Phone') ?></th>
        <th>Koleksi Terlambat</th>
        <th>Terlambat Sejak</th>
        <th>Estimasi Denda</th>
        <th><?= __('Action') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($members)): ?>
      <tr><td colspan="7"><?= __('No Data') ?></td></tr>
      <?php else: foreach ($members as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['member_id']) ?></td>
        <td><?= htmlspecialchars($m['member_name']) ?></td>
        <td><?= htmlspecialchars($m['member_phone'] ?: '-') ?></td>
        <td><?= (int)$m['total_items'] ?></td>
        <td><?= htmlspecialchars($m['oldest_due']) ?></td>
        <td><?= $m['fine_each_day'] ? Message::fmtMoney((int)$m['total_days'] * (float)$m['fine_each_day']) : '-' ?></td>
        <td>
          <?php if (!empty($m['member_phone'])): ?>
          <button type="submit" class="btn btn-sm btn-success wa-btn-send" data-member="<?= htmlspecialchars($m['member_id']) ?>">Kirim WA</button>
          <?php else: ?>
          <span class="text-muted">tanpa nomor</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</form>
  </div>
</div>
<script>
$(function() {
  $('#waOverdueForm').on('click', '.wa-btn-send', function(e) {
    e.preventDefault();
    $('#waSendMember').val($(this).data('member'));
    $('#waSendAll').val('');
    $('#waOverdueForm').trigger('submit');
  });
  $('#waOverdueForm').on('click', '.wa-btn-send-all', function(e) {
    e.preventDefault();
    if (!confirm('Kirim notifikasi ke SEMUA anggota terlambat?')) return;
    $('#waSendMember').val('');
    $('#waSendAll').val('1');
    $('#waOverdueForm').trigger('submit');
  });
});
</script>
