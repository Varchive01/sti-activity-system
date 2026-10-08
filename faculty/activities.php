<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();

$search     = sanitize($_GET['q']       ?? '');
$filterStat = sanitize($_GET['status']  ?? '');
$page       = max(1,(int)($_GET['page'] ?? 1));
$perPage    = 10;
$offset     = ($page-1)*$perPage;

$where  = "a.faculty_id=?";
$params = [$user['id']];
if ($search)     { $where .= " AND a.title LIKE ?"; $params[] = "%$search%"; }
if ($filterStat) { $where .= " AND a.status=?";     $params[] = $filterStat; }

$total = $db->prepare("SELECT COUNT(*) FROM activities a WHERE $where");
$total->execute($params); $totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount/$perPage);

$acts = $db->prepare("SELECT a.* FROM activities a WHERE $where ORDER BY a.updated_at DESC LIMIT $perPage OFFSET $offset");
$acts->execute($params);
$activities = $acts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Activities – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
  /* ── Filter Controls ── */
  .filters {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }

  .filters .form-control,
  .filters input[type="text"],
  .filters input[type="search"],
  .filters select,
  .filters select.form-control {
    height: 38px;
    padding: 8px 14px;
    font-size: 0.84rem;
    border-radius: var(--radius-sm, 8px);
    border: 1.5px solid var(--border);
    background: var(--bg-card);
    color: var(--text-main);
    box-sizing: border-box;
    transition: border-color .18s ease, box-shadow .18s ease;
    outline: none;
  }

  .filters .form-control::placeholder,
  .filters input[type="text"]::placeholder {
    color: var(--text-muted);
    opacity: 0.85;
  }

  .filters .form-control:focus,
  .filters input[type="text"]:focus,
  .filters select:focus,
  .filters select.form-control:focus {
    border-color: var(--accent, var(--sti-blue));
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
  }

  .filters select option,
  .filters select.form-control option {
    background: var(--bg-card);
    color: var(--text-main);
  }

  .filters .btn {
    height: 38px;
    padding: 0 16px;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: var(--radius-sm, 8px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-sizing: border-box;
    white-space: nowrap;
    text-decoration: none;
    transition: all 0.15s ease;
  }

  .filters .btn-primary {
    background: var(--accent, var(--sti-blue));
    border: 1px solid var(--accent, var(--sti-blue));
    color: #fff;
  }

  .filters .btn-primary:hover {
    filter: brightness(1.08);
    box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
  }

  .filters .btn-outline {
    border: 1.5px solid var(--border);
    background: transparent;
    color: var(--text-secondary);
  }

  .filters .btn-outline:hover {
    border-color: var(--accent, var(--sti-blue));
    color: var(--accent, var(--sti-blue));
    background: var(--accent-lt, rgba(2, 132, 199, 0.08));
  }

  .filters-count {
    margin-left: auto;
    font-size: 0.82rem;
    color: var(--text-muted);
    font-weight: 500;
    white-space: nowrap;
  }

  /* ── Activities Card & Table Refinements ── */
  .activities-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }

  .activities-card .table-wrap {
    border: none;
    border-radius: 0;
    background: transparent;
    overflow-x: auto;
    scrollbar-width: thin;
  }

  .activity-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    box-sizing: border-box;
  }

  .activity-table th,
  .activity-table td {
    box-sizing: border-box;
    overflow: hidden;
  }

  .activity-table th {
    padding: 11px 14px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    background: var(--border-light, #F8FAFD);
    text-align: left;
    white-space: nowrap;
  }

  [data-theme="dark"] .activity-table th {
    background: rgba(255, 255, 255, 0.03);
  }

  .activity-table td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    color: var(--text-main);
    font-size: 0.80rem;
    transition: background 0.12s ease;
  }

  .activity-table tbody tr:last-child td {
    border-bottom: none;
  }

  .activity-table tbody tr:hover td {
    background: rgba(2, 132, 199, 0.03);
  }

  [data-theme="dark"] .activity-table tbody tr:hover td {
    background: rgba(255, 255, 255, 0.03);
  }

  /* Flexible Column Sizing:
     The Activity Title column is flexible (width: auto) so it absorbs all available
     space when the sidebar is collapsed and compresses naturally when the sidebar is open.
     Non-title columns have defined widths tailored to their content.
     The Actions column is guaranteed enough room for both View and Post-Event buttons. */
  .col-title   { width: auto; min-width: 170px; }
  .col-date    { width: 110px; }
  .col-venue   { width: 125px; }
  .col-source  { width: 95px; }
  .col-status  { width: 115px; }
  .col-updated { width: 105px; }
  .col-actions { width: 160px; min-width: 155px; text-align: right; }

  .activity-table th.col-actions,
  .activity-table td.col-actions {
    text-align: right;
    padding-left: 4px;
    padding-right: 14px;
    overflow: visible;
  }

  /* When sidebar is collapsed, distribute additional width gracefully */
  body.sidebar-collapsed .col-venue { width: 145px; }
  body.sidebar-collapsed .col-actions { width: 165px; }

  /* Content typography inside cells */
  .act-title {
    font-weight: 600;
    color: var(--text-main);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    text-decoration: none;
    transition: color 0.12s ease;
  }

  .act-title:hover {
    color: var(--accent, var(--sti-blue));
  }

  .act-date {
    color: var(--text-secondary);
    font-size: 0.78rem;
    font-weight: 500;
    white-space: nowrap;
  }

  .act-venue {
    color: var(--text-muted);
    font-size: 0.78rem;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .act-updated {
    color: var(--text-muted);
    font-size: 0.78rem;
    white-space: nowrap;
  }

  /* Badges */
  .activity-table .badge {
    padding: 3px 8px;
    font-size: 0.67rem;
    font-weight: 700;
    border-radius: 999px;
    white-space: nowrap;
    line-height: 1.35;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  [data-theme="dark"] .badge-dark {
    background: rgba(255, 255, 255, 0.12);
    color: #F8FAFC;
    border: 1px solid rgba(255, 255, 255, 0.18);
  }

  /* Actions column */
  .act-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
    white-space: nowrap;
    flex-wrap: nowrap;
  }

  .activity-table td .btn {
    padding: 4px 9px;
    height: 27px;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    box-sizing: border-box;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all 0.15s ease;
  }

  .activity-table td .btn-outline {
    border: 1px solid var(--border);
    color: var(--text-main);
    background: transparent;
  }

  .activity-table td .btn-outline:hover {
    border-color: var(--accent, var(--sti-blue));
    color: var(--accent, var(--sti-blue));
    background: var(--accent-lt, rgba(2, 132, 199, 0.08));
  }

  .activity-table td .btn-primary {
    background: var(--accent, var(--sti-blue));
    border: 1px solid var(--accent, var(--sti-blue));
    color: #fff;
  }

  .activity-table td .btn-primary:hover {
    filter: brightness(1.08);
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
  }

  .activity-table td .btn-success {
    background: #16A34A;
    border: 1px solid #16A34A;
    color: #fff;
  }

  .activity-table td .btn-success:hover {
    filter: brightness(1.08);
    box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25);
  }

  [data-theme="dark"] .activity-table td .btn-success {
    background: rgba(34, 197, 94, 0.18);
    border-color: rgba(74, 222, 128, 0.35);
    color: #4ADE80;
  }

  [data-theme="dark"] .activity-table td .btn-success:hover {
    background: rgba(34, 197, 94, 0.28);
    color: #86EFAC;
  }

  /* Pagination dark mode refinements */
  [data-theme="dark"] .pagination a,
  [data-theme="dark"] .pagination span {
    background: var(--bg-card);
    border-color: var(--border);
    color: var(--text-main);
  }

  [data-theme="dark"] .pagination a:hover {
    background: rgba(2, 132, 199, 0.18);
    border-color: var(--sti-blue);
    color: #38BDF8;
  }

  /* ── Typography Consistency: All Visible Text on Faculty My Activities uses Plus Jakarta Sans ── */
  body.theme-faculty,
  body.theme-faculty *,
  body.theme-faculty *::before,
  body.theme-faculty *::after,
  body,
  h1, h2, h3, h4, h5, h6,
  .page-title,
  .topbar .page-title,
  .brand,
  .sidebar-logo .brand,
  .topbar-profile,
  .topbar-user-name,
  .topbar-user-role,
  .topbar-avatar,
  .filters,
  .filters .form-control,
  .filters input,
  .filters select,
  .filters button,
  .filters-count,
  .activities-card,
  .activity-table,
  .activity-table th,
  .activity-table td,
  .act-title,
  .act-date,
  .act-venue,
  .act-updated,
  .badge,
  .btn,
  .pagination,
  .pagination a,
  .pagination span,
  .notif-dropdown,
  .notif-header h3,
  .notif-modal h3,
  .notif-msg,
  .notif-time,
  label,
  input,
  button,
  select,
  textarea {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">My Activities</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">+ New Proposal</a>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <form method="GET" class="filters">
      <input type="text" name="q" class="form-control" placeholder="Search activities…" value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
      <select name="status" class="form-control" style="max-width:180px;">
        <option value="">All Statuses</option>
        <?php foreach(['draft','submitted','under_review','endorsed','pending_final_approval','returned_for_revision','approved','completed','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStat===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filter</button>
      <?php if ($search||$filterStat): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
      <span class="filters-count"><?= $totalCount ?> activities found</span>
    </form>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">✓ Proposal saved successfully!</div>
    <?php endif; ?>

    <div class="card activities-card">
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table class="activity-table">
            <thead>
              <tr>
                <th class="col-title">Title</th>
                <th class="col-date">Date</th>
                <th class="col-venue">Venue</th>
                <th class="col-source">Source</th>
                <th class="col-status">Status</th>
                <th class="col-updated">Last Updated</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
              <?= $search||$filterStat ? 'No activities match your search.' : 'No activities yet.' ?>
              <?php if (!$search && !$filterStat): ?><br><a href="<?= BASE_URL ?>/faculty/proposal-create.php" style="color:var(--accent);">Create your first proposal →</a><?php endif; ?>
            </td></tr>
            <?php else: foreach($activities as $a): ?>
            <tr>
              <td class="col-title">
                <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $a['id'] ?>" class="act-title">
                  <?= htmlspecialchars($a['title']) ?>
                </a>
                <?php if ($a['revision_notes']): ?><br><span class="text-sm" style="color:var(--warning);">⚠ Revision notes</span><?php endif; ?>
              </td>
              <td class="col-date"><span class="act-date"><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></span></td>
              <td class="col-venue"><span class="act-venue" title="<?= htmlspecialchars($a['venue']??'') ?>"><?= htmlspecialchars($a['venue']??'—') ?></span></td>
              <td class="col-source"><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
              <td class="col-status"><?= getStatusBadge($a['status']) ?></td>
              <td class="col-updated"><span class="act-updated"><?= date('M j, Y',strtotime($a['updated_at'])) ?></span></td>
              <td class="col-actions">
                <div class="act-actions">
                  <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                  <?php if (in_array($a['status'],['draft','returned_for_revision'])): ?>
                  <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                  <?php endif; ?>
                  <?php if ($a['status']==='approved'): ?>
                  <a href="<?= BASE_URL ?>/faculty/post-event.php?activity_id=<?= $a['id'] ?>" class="btn btn-success btn-sm">Post-Event</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">← Prev</a><?php endif; ?>
      <?php for ($p=1;$p<=$totalPages;$p++): ?>
      <?php if (abs($p-$page)<=2||$p===1||$p===$totalPages): ?>
      <<?= $p===$page?'span class="active"':'a href="?page='.$p.'&q='.urlencode($search).'&status='.urlencode($filterStat).'"' ?>><?= $p ?></<?= $p===$page?'span':'a' ?>>
      <?php elseif (abs($p-$page)===3): echo '<span>…</span>'; endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
