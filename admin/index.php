<?php
/**
 * Head of AI — admin dashboard dispatcher.
 *
 *   /admin/                          → first form in registry (default)
 *   /admin/?form=leads               → leads list view
 *   /admin/?form=leads&id=42         → single-lead detail
 *   /admin/?form=leads&export=csv    → CSV download
 *   /admin/?form=leads&date_from=…   → filtered list
 */

declare(strict_types=1);

$adminCfg = require __DIR__ . '/auth.php';
helm_admin_require_login();

require __DIR__ . '/lib/view.php';
$registry = require __DIR__ . '/lib/registry.php';

$formId = (string)($_GET['form'] ?? array_key_first($registry));
if (!isset($registry[$formId])) {
    http_response_code(404);
    echo 'Unknown form id';
    exit;
}
$entry = $registry[$formId];

// CSV export branch.
if (($_GET['export'] ?? '') === 'csv') {
    helm_admin_csv_export($entry, $_GET, $formId);
}

// Single-row detail branch.
$detailRow = null;
if (!empty($_GET['id'])) {
    $detailRow = helm_admin_get_one($entry, (int)$_GET['id']);
}

[$rows, $total, $page, $perPage] = helm_admin_query_list($entry, $_GET);
$pages = max(1, (int)ceil($total / $perPage));
$csrf = $_SESSION['csrf'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtDate(?string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso); if (!$ts) return h($iso);
    return date('Y-m-d H:i', $ts);
}
function fmtCell($v, string $col): string {
    if ($v === null || $v === '') return '—';
    if ($col === 'created_at') return fmtDate((string)$v);
    if ($col === 'challenge') {
        $s = (string)$v;
        return '<span title="' . h($s) . '">' . h(mb_substr($s, 0, 64)) . (mb_strlen($s) > 64 ? '…' : '') . '</span>';
    }
    if ($col === 'email') return '<a href="mailto:' . h((string)$v) . '">' . h((string)$v) . '</a>';
    return h((string)$v);
}

$q = $_GET;
unset($q['form'], $q['id'], $q['page'], $q['export']);
$qsBase = '?form=' . urlencode($formId) . (count($q) ? '&' . http_build_query($q) : '');
?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?=h($entry['label'])?> — Head of AI admin</title>
<style>
  *,*:before,*:after{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#030014;color:#e2e8f0;min-height:100vh}
  header{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:14px 24px;background:rgba(15,23,42,.85);backdrop-filter:blur(12px);border-bottom:1px solid rgba(99,102,241,.18)}
  header .brand{font-weight:700;letter-spacing:-.01em;display:flex;gap:8px;align-items:center}
  header .brand small{color:#94a3b8;font-weight:400;font-size:12px}
  header .who{font-size:13px;color:#cbd5e1;display:flex;gap:14px;align-items:center}
  main{padding:24px;max-width:1280px;margin:0 auto}
  nav.forms{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px}
  nav.forms a{padding:7px 14px;border-radius:999px;font-size:13px;border:1px solid rgba(99,102,241,.25);color:#cbd5e1;text-decoration:none;background:rgba(15,23,42,.4)}
  nav.forms a.active{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-color:transparent}
  h1{margin:0 0 4px;font-size:22px}
  p.sub{margin:0 0 20px;color:#94a3b8;font-size:13px}
  form.filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:end;padding:16px;background:rgba(15,23,42,.6);border:1px solid rgba(99,102,241,.12);border-radius:12px}
  .field{display:flex;flex-direction:column;gap:4px}
  .field label{font-size:11px;color:#94a3b8;letter-spacing:.04em;text-transform:uppercase}
  .field input,.field select{padding:7px 10px;border-radius:7px;border:1px solid #1e293b;background:#0f172a;color:#f1f5f9;font-size:13px;font-family:inherit;min-width:170px}
  form.filters button,.btn{padding:8px 14px;border-radius:7px;border:0;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600;cursor:pointer;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-family:inherit}
  .btn.ghost{background:transparent;border:1px solid rgba(99,102,241,.4);color:#a5b4fc}
  table{width:100%;border-collapse:collapse;background:rgba(15,23,42,.5);border-radius:12px;overflow:hidden;border:1px solid rgba(99,102,241,.12);font-size:13px}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid rgba(99,102,241,.08);vertical-align:top}
  th{font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#cbd5e1;background:rgba(99,102,241,.05);position:sticky;top:65px}
  tr:hover td{background:rgba(99,102,241,.04)}
  td a{color:#a5b4fc}
  .meta{display:flex;justify-content:space-between;align-items:center;margin:14px 0;color:#94a3b8;font-size:13px}
  .pager{display:flex;gap:6px}
  .pager a,.pager span{padding:5px 10px;border-radius:6px;border:1px solid #1e293b;color:#cbd5e1;text-decoration:none;font-size:12px}
  .pager span.cur{background:rgba(99,102,241,.18);color:#fff;border-color:transparent}
  .detail{margin-bottom:18px;padding:18px;background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.18);border-radius:12px}
  .detail h2{margin:0 0 12px;font-size:16px}
  .detail dl{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;font-size:13px;margin:0}
  .detail dt{color:#94a3b8;font-weight:500}
  .detail dd{margin:0;color:#f1f5f9;word-break:break-word;white-space:pre-wrap}
  .empty{text-align:center;padding:48px 16px;color:#64748b}
  form.logout{display:inline}
  form.logout button{background:transparent;border:1px solid rgba(248,113,113,.4);color:#fca5a5;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-family:inherit}
</style>
</head>
<body>
<header>
  <div class="brand">Head of AI <small>· admin</small></div>
  <div class="who">
    <span><?=h((string)($_SESSION['admin_user'] ?? ''))?></span>
    <form class="logout" method="post" action="/admin/logout.php">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <button type="submit">Kijelentkezés</button>
    </form>
  </div>
</header>

<main>
  <nav class="forms">
    <?php foreach ($registry as $id => $e): ?>
      <a href="?form=<?=h($id)?>" class="<?= $id === $formId ? 'active' : '' ?>">
        <?=h($e['icon'] ?? '·')?> <?=h($e['label'])?>
      </a>
    <?php endforeach ?>
  </nav>

  <h1><?=h($entry['label'])?></h1>
  <p class="sub">Összes találat: <?=number_format($total, 0, ',', ' ')?> · oldal <?=$page?>/<?=$pages?></p>

  <?php if ($detailRow): ?>
    <div class="detail">
      <h2>#<?=h((string)$detailRow['id'])?> — részletek</h2>
      <dl>
        <?php foreach ($detailRow as $k => $v):
          if ($v === null || $v === '') continue;
          $display = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        ?>
          <dt><?=h((string)$k)?></dt>
          <dd><?=h((string)$display)?></dd>
        <?php endforeach ?>
      </dl>
      <p style="margin-top:14px"><a class="btn ghost" href="<?=h($qsBase)?>">← vissza a listához</a></p>
    </div>
  <?php endif ?>

  <form class="filters" method="get">
    <input type="hidden" name="form" value="<?=h($formId)?>">
    <?php foreach ($entry['filters'] as $fid => $f):
      $type = $f['type'] ?? 'eq';
      $inputType = ($type === 'date_from' || $type === 'date_to') ? 'date' : 'text';
      $val = (string)($_GET[$fid] ?? '');
    ?>
      <div class="field">
        <label for="f-<?=h($fid)?>"><?=h($f['label'] ?? $fid)?></label>
        <input id="f-<?=h($fid)?>" type="<?=h($inputType)?>" name="<?=h($fid)?>" value="<?=h($val)?>">
      </div>
    <?php endforeach ?>
    <?php if (!empty($entry['search'])): ?>
      <div class="field">
        <label for="f-q">Keresés</label>
        <input id="f-q" type="search" name="q" value="<?=h((string)($_GET['q'] ?? ''))?>" placeholder="<?=h(implode(' / ', $entry['search']))?>">
      </div>
    <?php endif ?>
    <button type="submit">Szűrés</button>
    <a class="btn ghost" href="?form=<?=h($formId)?>">Reset</a>
    <?php
      $exportQ = $_GET; $exportQ['export'] = 'csv';
    ?>
    <a class="btn ghost" href="?<?=h(http_build_query($exportQ))?>">CSV export</a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">Nincs találat. Próbáld más szűréssel.</div>
  <?php else: ?>
    <table>
      <thead><tr>
        <?php foreach ($entry['columns'] as $col): ?>
          <th><?=h($col)?></th>
        <?php endforeach ?>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <?php foreach ($entry['columns'] as $col): ?>
              <td><?= fmtCell($row[$col] ?? null, $col) ?></td>
            <?php endforeach ?>
            <td><a href="?form=<?=h($formId)?>&id=<?=h((string)$row['id'])?>">részletek</a></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>

    <div class="meta">
      <span><?=count($rows)?> sor megjelenítve</span>
      <?php if ($pages > 1): ?>
        <div class="pager">
          <?php
            $win = 2;
            $from = max(1, $page - $win);
            $to   = min($pages, $page + $win);
            for ($p = $from; $p <= $to; $p++):
              $qp = $_GET; $qp['page'] = $p;
          ?>
            <?php if ($p === $page): ?>
              <span class="cur"><?=$p?></span>
            <?php else: ?>
              <a href="?<?=h(http_build_query($qp))?>"><?=$p?></a>
            <?php endif ?>
          <?php endfor ?>
        </div>
      <?php endif ?>
    </div>
  <?php endif ?>
</main>
</body>
</html>
