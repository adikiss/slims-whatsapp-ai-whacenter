<?php

defined('INDEX_AUTH') or die('Direct access not allowed!');

use SLiMS\DB;
use WaNotif\WaConfig;

require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

$canRead = utility::havePrivilege('system', 'r') || utility::havePrivilege('system', 'w');
if (!$canRead) die('<div class="alert alert-danger">' . __('You don\'t have enough privileges to access this area!') . '</div>');

$dbs = DB::getInstance('mysqli');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
if ($typeFilter !== '') {
    $escaped = $dbs->escape_string($typeFilter);
    $where[] = "type = '$escaped'";
}
if ($statusFilter !== '') {
    $escaped = $dbs->escape_string($statusFilter);
    $where[] = "status = '$escaped'";
}
if ($search !== '') {
    $escaped = $dbs->escape_string($search);
    $where[] = "(member_id LIKE '%$escaped%' OR member_name LIKE '%$escaped%' OR phone LIKE '%$escaped%')";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countQ = $dbs->query("SELECT COUNT(*) as total FROM wa_notif_log $whereSQL");
$total = $countQ->fetch_assoc()['total'];
$totalPages = max(1, ceil($total / $perPage));

$q = $dbs->query("SELECT * FROM wa_notif_log $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$rows = [];
if ($q) {
    while ($row = $q->fetch_assoc()) $rows[] = $row;
}

$selfUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_diff_key($_GET, ['page' => 0]));

// Build base URL for pagination - must go through plugin_container.php
$baseUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_diff_key($_GET, ['page' => 0, 'p' => 0]));
$pageUrl = $baseUrl . '&page=';

function typeBadge(string $type): string {
    $map = [
        'transaction' => ['label' => 'Transaksi', 'class' => 'primary'],
        'overdue' => ['label' => 'Terlambat', 'class' => 'warning'],
        'new_member' => ['label' => 'Anggota Baru', 'class' => 'success'],
        'chatbot' => ['label' => 'Chatbot', 'class' => 'info'],
        'test' => ['label' => 'Tes', 'class' => 'secondary'],
    ];
    $info = $map[$type] ?? ['label' => $type, 'class' => 'secondary'];
    return '<span class="badge badge-' . $info['class'] . '">' . $info['label'] . '</span>';
}

function statusBadge(string $status): string {
    $class = $status === 'success' ? 'success' : 'danger';
    $icon = $status === 'success' ? '✅' : '❌';
    return '<span class="badge badge-' . $class . '">' . $icon . ' ' . $status . '</span>';
}
?>
<div class="menuBox">
  <div class="menuBoxInner systemIcon">
    <div class="per_title">
      <h2><?= __('WhatsApp Notification Log') ?></h2>
    </div>

    <div class="mb-3">
      <form method="get" class="form-inline">
        <input type="hidden" name="mod" value="system">
        <input type="hidden" name="id" value="<?= htmlspecialchars($_GET['id'] ?? '') ?>">
        <div class="input-group mr-2">
          <select name="type" class="form-control form-control-sm">
            <option value="">Semua Tipe</option>
            <option value="transaction" <?= $typeFilter === 'transaction' ? 'selected' : '' ?>>Transaksi</option>
            <option value="overdue" <?= $typeFilter === 'overdue' ? 'selected' : '' ?>>Terlambat</option>
            <option value="new_member" <?= $typeFilter === 'new_member' ? 'selected' : '' ?>>Anggota Baru</option>
            <option value="chatbot" <?= $typeFilter === 'chatbot' ? 'selected' : '' ?>>Chatbot</option>
            <option value="test" <?= $typeFilter === 'test' ? 'selected' : '' ?>>Tes</option>
          </select>
        </div>
        <div class="input-group mr-2">
          <select name="status" class="form-control form-control-sm">
            <option value="">Semua Status</option>
            <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Berhasil</option>
            <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Gagal</option>
          </select>
        </div>
        <div class="input-group mr-2">
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control form-control-sm" placeholder="Cari ID/Nama/Nomor...">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
      </form>
    </div>

    <div class="infoBox mb-2">
      Total: <strong><?= number_format($total) ?></strong> log
      <?php if ($typeFilter || $statusFilter || $search): ?>
        — <a href="<?= $baseUrl ?>">Reset Filter</a>
      <?php endif; ?>
    </div>

    <table class="s-table table bordered" id="dataList" cellpadding="5" cellspacing="0">
      <thead>
        <tr>
          <th width="50">ID</th>
          <th width="80">Tipe</th>
          <th width="90">Status</th>
          <th>Member</th>
          <th>Telepon</th>
          <th width="150">Waktu</th>
          <th>Pesan</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center">Tidak ada data log</td></tr>
        <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= typeBadge($r['type']) ?></td>
          <td><?= statusBadge($r['status']) ?></td>
          <td>
            <?= htmlspecialchars($r['member_name'] ?: '-') ?>
            <?php if ($r['member_id']): ?>
              <br><small class="text-muted"><?= htmlspecialchars($r['member_id']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($r['phone']) ?></td>
          <td><small><?= htmlspecialchars($r['created_at']) ?></small></td>
          <td>
            <button class="btn btn-sm btn-default" onclick="toggleMsg(this)" title="Lihat pesan">👁</button>
            <div class="wa-log-msg" style="display:none;margin-top:5px;white-space:pre-wrap;font-size:12px;max-height:200px;overflow-y:auto;background:#f8f9fa;padding:8px;border-radius:4px;"><?= htmlspecialchars($r['message']) ?></div>
            <?php if ($r['response']): ?>
              <button class="btn btn-sm btn-default" onclick="toggleResp(this)" title="Lihat respons">📤</button>
              <div class="wa-log-resp" style="display:none;margin-top:5px;white-space:pre-wrap;font-size:12px;max-height:100px;overflow-y:auto;background:#fff3cd;padding:8px;border-radius:4px;"><?= htmlspecialchars($r['response']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-2">
      <ul class="pagination pagination-sm justify-content-center">
        <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageUrl . ($page - 1) ?>">«</a>
        </li>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= $pageUrl . $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageUrl . ($page + 1) ?>">»</a>
        </li>
        <?php endif; ?>
      </ul>
    </nav>
    <?php endif; ?>

  </div>
</div>

<script>
function toggleMsg(btn) {
  const div = btn.nextElementSibling;
  div.style.display = div.style.display === 'none' ? 'block' : 'none';
}
function toggleResp(btn) {
  const div = btn.nextElementSibling;
  div.style.display = div.style.display === 'none' ? 'block' : 'none';
}
</script>
