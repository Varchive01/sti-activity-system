<?php
/**
 * includes/topbar-profile.php
 *
 * Shared Topbar Profile Component for STI Activity System.
 * Applies consistently to Faculty, Admin1, Admin2, and Dean.
 *
 * Features:
 * - Circular avatar/initial, user name, and role without permanent surrounding box.
 * - Clickable popover menu:
 *     - View Profile
 *     - Change Profile Picture
 *     - Change Password
 *     - Logout
 * - Interactive User Profile Modal:
 *     - Real-time profile picture preview & upload (JPG, PNG, WEBP <= 2 MB)
 *     - Editable Full Name
 *     - Protected Institutional Details (Email, Role, Department)
 *     - Instant topbar avatar & name synchronization without requiring logout
 *     - Dark and light mode compatibility with STI design tokens
 */

if (!isset($topbarUser)) {
    $topbarUser = isset($user) ? $user : (function_exists('currentUser') ? currentUser() : []);
}

// Fetch authoritative profile info from database if available
if (!empty($topbarUser['id'])) {
    $dbProf = (isset($db) && ($db instanceof PDO)) ? $db : (function_exists('getDB') ? getDB() : null);
    if ($dbProf) {
        $pStmt = $dbProf->prepare('SELECT id, name, email, role, department, profile_picture FROM users WHERE id = ? LIMIT 1');
        $pStmt->execute([(int)$topbarUser['id']]);
        $fetchedProf = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($fetchedProf) {
            $topbarUser['name']            = $fetchedProf['name'];
            $topbarUser['email']           = $fetchedProf['email'];
            $topbarUser['role']            = $fetchedProf['role'];
            $topbarUser['dept']            = $fetchedProf['department'] ?? '';
            $topbarUser['profile_picture'] = $fetchedProf['profile_picture'];

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['user_name']            = $fetchedProf['name'];
                $_SESSION['user_profile_picture'] = $fetchedProf['profile_picture'];
                $_SESSION['user_dept']            = $fetchedProf['department'] ?? '';
            }
        }
    }
}

$uName      = $topbarUser['name'] ?? 'User';
$uEmail     = $topbarUser['email'] ?? '';
$uRole      = $topbarUser['role'] ?? 'faculty';
$uRoleTitle = ucwords(str_replace('_', ' ', $uRole));
$uDept      = $topbarUser['dept'] ?? ($topbarUser['department'] ?? 'Not Assigned');
$uPic       = $topbarUser['profile_picture'] ?? null;
$uInitial   = strtoupper(substr(trim($uName) ?: 'U', 0, 1));
$uId        = (int)($topbarUser['id'] ?? 0);
$csrfToken  = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
$baseUrl    = defined('BASE_URL') ? BASE_URL : '/sti-activity-system';
?>

<?php if (!defined('TOPBAR_PROFILE_STYLES_LOADED')): define('TOPBAR_PROFILE_STYLES_LOADED', true); ?>
<style>
/* ── Topbar Profile Wrapper & Trigger ── */
.topbar-profile-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.topbar-profile {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 2px 6px 2px 2px;
  height: 38px;
  background: transparent;
  border: none;
  border-radius: 999px;
  box-shadow: none;
  white-space: nowrap;
  box-sizing: border-box;
  user-select: none;
  cursor: pointer;
  transition: opacity 0.15s ease, transform 0.15s ease;
  outline: none;
}

.topbar-profile:hover {
  background: transparent;
  border: none;
  box-shadow: none;
  opacity: 0.9;
}

.topbar-profile:focus-visible {
  outline: 2px solid var(--sti-blue, #0284C7);
  outline-offset: 2px;
}

.topbar-profile .topbar-avatar {
  width: 38px;
  height: 38px;
  min-width: 38px;
  border-radius: 50%;
  background: var(--accent, var(--sti-blue, #0284C7));
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.88rem;
  line-height: 1;
  flex-shrink: 0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
  overflow: hidden;
  position: relative;
}

.topbar-profile .topbar-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 50%;
  display: block;
}

.topbar-profile .topbar-user-info {
  display: flex;
  flex-direction: column;
  justify-content: center;
  line-height: 1.2;
  text-align: left;
}

.topbar-profile .topbar-user-name {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--text-main, #0A1628);
  white-space: nowrap;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  letter-spacing: -0.01em;
}

.topbar-profile .topbar-user-role {
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--text-muted, #64748B);
  white-space: nowrap;
  text-transform: capitalize;
}

@media (max-width: 520px) {
  .topbar-profile .topbar-user-info {
    display: none;
  }
  .topbar-profile {
    padding: 0;
  }
}

/* ── Profile Popover Dropdown Menu ── */
.topbar-profile-popover {
  display: none;
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 260px;
  background: var(--bg-card, #FFFFFF);
  border: 1px solid var(--border, #E2E8F0);
  border-radius: var(--radius-sm, 10px);
  box-shadow: 0 12px 32px rgba(10, 22, 40, 0.18);
  z-index: 1050;
  padding: 8px 0;
  font-family: 'Plus Jakarta Sans', sans-serif;
  animation: profilePopoverSlide 0.18s cubic-bezier(0.16, 1, 0.3, 1);
  box-sizing: border-box;
}

.topbar-profile-popover.open {
  display: block;
}

@keyframes profilePopoverSlide {
  from { opacity: 0; transform: translateY(-6px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.tpp-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px 12px;
  border-bottom: 1px solid var(--border, #E2E8F0);
}

.tpp-header-avatar {
  width: 42px;
  height: 42px;
  min-width: 42px;
  border-radius: 50%;
  background: var(--sti-blue, #0284C7);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  font-weight: 700;
  overflow: hidden;
  flex-shrink: 0;
}

.tpp-header-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 50%;
}

.tpp-header-meta {
  flex: 1;
  min-width: 0;
}

.tpp-header-name {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--text-main, #0A1628);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}

.tpp-header-email {
  font-size: 0.72rem;
  color: var(--text-muted, #64748B);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}

.tpp-divider {
  height: 1px;
  background: var(--border, #E2E8F0);
  margin: 6px 0;
}

.tpp-menu {
  list-style: none;
  margin: 0;
  padding: 0 6px;
}

.tpp-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 12px;
  background: transparent;
  border: none;
  border-radius: 6px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-main, #0A1628);
  cursor: pointer;
  text-decoration: none;
  box-sizing: border-box;
  font-family: inherit;
  transition: background 0.15s ease, color 0.15s ease;
  text-align: left;
}

.tpp-item:hover {
  background: var(--bg-base, #F4F6FB);
  color: var(--sti-blue, #0284C7);
}

.tpp-item.tpp-danger {
  color: var(--sti-red, #C1121F);
}

.tpp-item.tpp-danger:hover {
  background: var(--danger-lt, #FEE2E2);
  color: var(--sti-red, #C1121F);
}

.tpp-icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}

/* ── Profile Modal Backdrop & Container ── */
.profile-modal-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(10, 22, 40, 0.65);
  backdrop-filter: blur(4px);
  z-index: 9999;
  align-items: center;
  justify-content: center;
  padding: 16px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  box-sizing: border-box;
}

.profile-modal-backdrop.active {
  display: flex !important;
}

.profile-modal {
  background: var(--bg-card, #FFFFFF);
  border-radius: var(--radius, 14px);
  border: 1px solid var(--border, #E2E8F4);
  width: 100%;
  max-width: 480px;
  box-shadow: 0 20px 48px rgba(10, 22, 40, 0.25);
  overflow: hidden;
  animation: profileModalAnim 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  position: relative;
  display: flex;
  flex-direction: column;
}

@keyframes profileModalAnim {
  from { opacity: 0; transform: translateY(-12px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.profile-modal-header {
  padding: 18px 22px;
  border-bottom: 1px solid var(--border, #E2E8F4);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg-card, #FFFFFF);
}

.profile-modal-header h3 {
  margin: 0;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text-main, #0A1628);
  letter-spacing: -0.01em;
  display: flex;
  align-items: center;
  gap: 8px;
}

.profile-modal-close {
  background: transparent;
  border: none;
  font-size: 1.4rem;
  color: var(--text-muted, #64748B);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
  line-height: 1;
  transition: all 0.15s ease;
}

.profile-modal-close:hover {
  background: var(--bg-base, #F4F6FB);
  color: var(--text-main, #0A1628);
}

.profile-modal-body {
  padding: 22px;
  background: var(--bg-card, #FFFFFF);
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-height: calc(85vh - 130px);
  overflow-y: auto;
}

/* ── Avatar Edit Card ── */
.profile-avatar-section {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 14px 16px;
  background: var(--bg-base, #F8FAFD);
  border: 1px solid var(--border, #E2E8F4);
  border-radius: var(--radius-sm, 10px);
}

.profile-avatar-preview-wrap {
  position: relative;
  flex-shrink: 0;
}

.profile-avatar-preview {
  width: 76px;
  height: 76px;
  border-radius: 50%;
  background: var(--sti-blue, #0284C7);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.85rem;
  font-weight: 800;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
  border: 2px solid var(--bg-card, #FFFFFF);
}

.profile-avatar-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.profile-avatar-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}

.profile-avatar-btn-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.btn-profile-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.76rem;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: var(--radius-sm, 6px);
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
  border: 1.5px solid transparent;
}

.btn-profile-action.btn-upload {
  background: var(--sti-blue, #0284C7);
  color: #FFFFFF;
  border-color: var(--sti-blue, #0284C7);
}

.btn-profile-action.btn-upload:hover {
  background: var(--sti-blue-hover, #0369A1);
  border-color: var(--sti-blue-hover, #0369A1);
}

.btn-profile-action.btn-remove {
  background: transparent;
  color: var(--sti-red, #C1121F);
  border-color: var(--border, #E2E8F4);
}

.btn-profile-action.btn-remove:hover {
  background: var(--danger-lt, #FEE2E2);
  border-color: var(--sti-red, #C1121F);
}

.profile-avatar-help {
  font-size: 0.72rem;
  color: var(--text-muted, #64748B);
  line-height: 1.3;
}

/* ── Form Controls ── */
.profile-form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.profile-form-label {
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--text-main, #0A1628);
}

.profile-form-control {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 14px;
  height: 40px;
  border: 1.5px solid var(--border, #E2E8F4);
  border-radius: var(--radius-sm, 8px);
  font-size: 0.85rem;
  font-family: inherit;
  background: var(--bg-card, #FFFFFF);
  color: var(--text-main, #0A1628);
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
  outline: none;
}

.profile-form-control:focus {
  border-color: var(--sti-blue, #0284C7);
  box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}

.profile-form-help {
  font-size: 0.72rem;
  color: var(--text-muted, #64748B);
  line-height: 1.3;
}

/* ── Institutional Account Details Box (Read-Only) ── */
.profile-account-card {
  background: var(--bg-base, #F8FAFD);
  border: 1px solid var(--border, #E2E8F4);
  border-radius: var(--radius-sm, 10px);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.profile-account-card-title {
  font-size: 0.76rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted, #64748B);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  border-bottom: 1px solid var(--border, #E2E8F4);
  padding-bottom: 6px;
}

.profile-locked-tag {
  font-size: 0.65rem;
  font-weight: 700;
  color: var(--text-muted, #64748B);
  background: var(--bg-card, #FFFFFF);
  border: 1px solid var(--border, #E2E8F4);
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.profile-meta-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.profile-meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.8rem;
}

.profile-meta-label {
  color: var(--text-muted, #64748B);
  font-weight: 600;
  flex-shrink: 0;
}

.profile-meta-value {
  color: var(--text-main, #0A1628);
  font-weight: 600;
  text-align: right;
  word-break: break-all;
}

.profile-meta-note {
  font-size: 0.70rem;
  color: var(--text-muted, #64748B);
  font-style: italic;
  margin-top: 2px;
}

/* ── Alert Messages ── */
.profile-alert {
  padding: 10px 14px;
  border-radius: var(--radius-sm, 8px);
  font-size: 0.8rem;
  line-height: 1.4;
  display: flex;
  align-items: center;
  gap: 8px;
  box-sizing: border-box;
}

.profile-alert.danger {
  background: var(--danger-lt, #FEE2E2);
  color: var(--sti-red, #C1121F);
  border: 1px solid rgba(193, 18, 31, 0.25);
}

.profile-alert.success {
  background: var(--success-lt, #DCFCE7);
  color: var(--success, #16A34A);
  border: 1px solid rgba(22, 163, 74, 0.25);
}

/* ── Modal Footer ── */
.profile-modal-footer {
  padding: 14px 22px;
  border-top: 1px solid var(--border, #E2E8F4);
  background: var(--bg-base, #F8FAFD);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
}

.btn-profile-footer {
  padding: 9px 18px;
  border-radius: var(--radius-sm, 8px);
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
  border: 1.5px solid transparent;
}

.btn-profile-footer.btn-cancel {
  background: var(--bg-card, #FFFFFF);
  color: var(--text-main, #0A1628);
  border-color: var(--border, #E2E8F4);
}

.btn-profile-footer.btn-cancel:hover {
  background: var(--bg-base, #F4F6FB);
  border-color: var(--border, #E2E8F4);
}

.btn-profile-footer.btn-save {
  background: var(--sti-blue, #0284C7);
  color: #FFFFFF;
  border-color: var(--sti-blue, #0284C7);
}

.btn-profile-footer.btn-save:hover {
  background: var(--sti-blue-hover, #0369A1);
  border-color: var(--sti-blue-hover, #0369A1);
}

.btn-profile-footer:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

/* ── Dark Mode Overrides (Explicit Token Continuity) ── */
[data-theme="dark"] .topbar-profile:focus-visible {
  outline-color: var(--sti-blue, #0284C7);
}
[data-theme="dark"] .topbar-profile-popover {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
  box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
}
[data-theme="dark"] .tpp-header {
  border-bottom-color: var(--border, #1E2D45);
}
[data-theme="dark"] .tpp-divider {
  background: var(--border, #1E2D45);
}
[data-theme="dark"] .tpp-item {
  color: var(--text-main, #F8FAFC);
}
[data-theme="dark"] .tpp-item:hover {
  background: var(--bg-base, #070E18);
  color: var(--sti-blue-hover, #38BDF8);
}
[data-theme="dark"] .profile-modal {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
  box-shadow: 0 24px 50px rgba(0, 0, 0, 0.55);
}
[data-theme="dark"] .profile-modal-header {
  background: var(--bg-card, #0F1B2E);
  border-bottom-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-modal-body {
  background: var(--bg-card, #0F1B2E);
}
[data-theme="dark"] .profile-modal-footer {
  background: var(--bg-base, #070E18);
  border-top-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-avatar-section {
  background: var(--bg-base, #070E18);
  border-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-avatar-preview {
  border-color: var(--bg-card, #0F1B2E);
}
[data-theme="dark"] .profile-account-card {
  background: var(--bg-base, #070E18);
  border-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-account-card-title {
  border-bottom-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-locked-tag {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
}
[data-theme="dark"] .profile-form-control {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
  color: var(--text-main, #F8FAFC);
}
[data-theme="dark"] .btn-profile-footer.btn-cancel {
  background: var(--bg-card, #0F1B2E);
  color: var(--text-main, #F8FAFC);
  border-color: var(--border, #1E2D45);
}
[data-theme="dark"] .btn-profile-footer.btn-cancel:hover {
  background: var(--bg-base, #070E18);
}
</style>
<?php endif; ?>

<!-- ── Topbar User Profile Component ── -->
<div class="topbar-profile-wrapper" id="topbarProfileWrapper">
  <!-- Profile Trigger -->
  <div class="topbar-profile" id="topbarProfile" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" title="Account settings for <?= htmlspecialchars($uName) ?>">
    <div class="topbar-avatar" id="topbarAvatar">
      <?php if (!empty($uPic)): ?>
        <img src="<?= $baseUrl ?>/api/profile-avatar.php?id=<?= $uId ?>" alt="<?= htmlspecialchars($uName) ?>" class="topbar-avatar-img" id="topbarAvatarImg">
      <?php else: ?>
        <span class="topbar-avatar-initial" id="topbarAvatarInitial"><?= htmlspecialchars($uInitial) ?></span>
      <?php endif; ?>
    </div>
    <div class="topbar-user-info">
      <span class="topbar-user-name" id="topbarUserName" title="<?= htmlspecialchars($uName) ?>"><?= htmlspecialchars($uName) ?></span>
      <span class="topbar-user-role" id="topbarUserRole"><?= htmlspecialchars($uRoleTitle) ?></span>
    </div>
  </div>

  <!-- Popover Menu -->
  <div class="topbar-profile-popover" id="topbarProfilePopover" role="menu" aria-label="User profile menu">
    <div class="tpp-header">
      <div class="tpp-header-avatar" id="tppHeaderAvatar">
        <?php if (!empty($uPic)): ?>
          <img src="<?= $baseUrl ?>/api/profile-avatar.php?id=<?= $uId ?>" alt="<?= htmlspecialchars($uName) ?>" class="topbar-avatar-img">
        <?php else: ?>
          <span><?= htmlspecialchars($uInitial) ?></span>
        <?php endif; ?>
      </div>
      <div class="tpp-header-meta">
        <div class="tpp-header-name" id="tppHeaderName" title="<?= htmlspecialchars($uName) ?>"><?= htmlspecialchars($uName) ?></div>
        <div class="tpp-header-email" title="<?= htmlspecialchars($uEmail) ?>"><?= htmlspecialchars($uEmail) ?></div>
        <span class="role-badge role-<?= htmlspecialchars($uRole) ?>"><?= htmlspecialchars($uRoleTitle) ?></span>
      </div>
    </div>
    <div class="tpp-divider"></div>
    <div class="tpp-menu">
      <button type="button" class="tpp-item" id="tppBtnViewProfile" role="menuitem">
        <svg class="tpp-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        <span>View Profile</span>
      </button>
      <button type="button" class="tpp-item" id="tppBtnChangePicture" role="menuitem">
        <svg class="tpp-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <span>Change Profile Picture</span>
      </button>
      <a href="<?= $baseUrl ?>/auth/change-password.php" class="tpp-item" role="menuitem">
        <svg class="tpp-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
        <span>Change Password</span>
      </a>
      <div class="tpp-divider"></div>
      <a href="<?= $baseUrl ?>/auth/logout.php" class="tpp-item tpp-danger" role="menuitem">
        <svg class="tpp-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        <span>Logout</span>
      </a>
    </div>
  </div>
</div>

<!-- ── User Profile Modal ── -->
<div class="profile-modal-backdrop" id="profileModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
  <div class="profile-modal">
    <div class="profile-modal-header">
      <h3 id="profileModalTitle">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        User Profile
      </h3>
      <button type="button" class="profile-modal-close" id="profileModalCloseBtn" aria-label="Close modal">&times;</button>
    </div>

    <form id="profileForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="remove_picture" id="profileRemovePicInput" value="0">
      <input type="file" id="profilePictureFileInput" name="profile_picture" accept="image/jpeg,image/png,image/webp" style="display:none;">

      <div class="profile-modal-body">
        <div id="profileAlertBox" class="profile-alert" style="display:none;"></div>

        <!-- Avatar Picture Card Section -->
        <div class="profile-avatar-section">
          <div class="profile-avatar-preview-wrap">
            <div class="profile-avatar-preview" id="profileAvatarPreview">
              <?php if (!empty($uPic)): ?>
                <img src="<?= $baseUrl ?>/api/profile-avatar.php?id=<?= $uId ?>" alt="<?= htmlspecialchars($uName) ?>" id="profilePreviewImg">
              <?php else: ?>
                <span id="profilePreviewInitial"><?= htmlspecialchars($uInitial) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="profile-avatar-actions">
            <div class="profile-avatar-btn-row">
              <button type="button" class="btn-profile-action btn-upload" id="profileBtnUploadPic">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Change Picture
              </button>
              <button type="button" class="btn-profile-action btn-remove" id="profileBtnRemovePic" style="<?= empty($uPic) ? 'display:none;' : '' ?>">
                Remove
              </button>
            </div>
            <div class="profile-avatar-help">Accepts JPG, PNG, or WEBP &bull; Max size 2 MB</div>
          </div>
        </div>

        <!-- Editable: Full Name -->
        <div class="profile-form-group">
          <label for="profileInputName" class="profile-form-label">Full Name <span style="color:var(--sti-red);">*</span></label>
          <input type="text" id="profileInputName" name="name" class="profile-form-control" value="<?= htmlspecialchars($uName) ?>" required maxlength="150" placeholder="Enter your full name">
          <div class="profile-form-help">This display name appears on your proposals, reviews, and activity logs.</div>
        </div>

        <!-- Non-editable Account Information -->
        <div class="profile-account-card">
          <div class="profile-account-card-title">
            <span>Account Details</span>
            <span class="profile-locked-tag">Protected</span>
          </div>

          <div class="profile-meta-grid">
            <div class="profile-meta-row">
              <span class="profile-meta-label">Email:</span>
              <span class="profile-meta-value"><?= htmlspecialchars($uEmail) ?></span>
            </div>
            <div class="profile-meta-row">
              <span class="profile-meta-label">Department:</span>
              <span class="profile-meta-value"><?= htmlspecialchars($uDept ?: 'Not Assigned') ?></span>
            </div>
            <div class="profile-meta-row">
              <span class="profile-meta-label">Role:</span>
              <span class="profile-meta-value">
                <span class="role-badge role-<?= htmlspecialchars($uRole) ?>"><?= htmlspecialchars($uRoleTitle) ?></span>
              </span>
            </div>
          </div>
          <div class="profile-meta-note">Email, department, and role are institutional account properties managed by system administrators.</div>
        </div>
      </div>

      <div class="profile-modal-footer">
        <button type="button" class="btn-profile-footer btn-cancel" id="profileBtnCancel">Cancel</button>
        <button type="submit" class="btn-profile-footer btn-save" id="profileBtnSave">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Profile Component Script ── -->
<script>
(function() {
  // Elements
  const topbarProfile = document.getElementById('topbarProfile');
  const profilePopover = document.getElementById('topbarProfilePopover');
  const btnViewProfile = document.getElementById('tppBtnViewProfile');
  const btnChangePic = document.getElementById('tppBtnChangePicture');
  const profileBackdrop = document.getElementById('profileModalBackdrop');
  const btnCloseModal = document.getElementById('profileModalCloseBtn');
  const btnCancelModal = document.getElementById('profileBtnCancel');
  const btnUploadPic = document.getElementById('profileBtnUploadPic');
  const btnRemovePic = document.getElementById('profileBtnRemovePic');
  const fileInput = document.getElementById('profilePictureFileInput');
  const removePicInput = document.getElementById('profileRemovePicInput');
  const profileForm = document.getElementById('profileForm');
  const alertBox = document.getElementById('profileAlertBox');
  const avatarPreview = document.getElementById('profileAvatarPreview');
  const btnSave = document.getElementById('profileBtnSave');

  const topbarAvatar = document.getElementById('topbarAvatar');
  const topbarUserName = document.getElementById('topbarUserName');
  const tppHeaderAvatar = document.getElementById('tppHeaderAvatar');
  const tppHeaderName = document.getElementById('tppHeaderName');

  // Baseline user state for cancel/reset
  let currentInitial = "<?= addslashes($uInitial) ?>";
  let hasActivePicture = <?= !empty($uPic) ? 'true' : 'false' ?>;

  function showAlert(message, type) {
    if (!alertBox) return;
    alertBox.className = 'profile-alert ' + (type || 'danger');
    alertBox.textContent = message;
    alertBox.style.display = 'block';
  }

  function hideAlert() {
    if (!alertBox) return;
    alertBox.style.display = 'none';
    alertBox.textContent = '';
  }

  // Popover Toggling
  function togglePopover(e) {
    if (e) e.stopPropagation();
    const isOpen = profilePopover.classList.contains('open');
    if (isOpen) {
      closePopover();
    } else {
      openPopover();
    }
  }

  function openPopover() {
    // Close notification panel if open
    const notifDrop = document.getElementById('notifDropdown');
    const notifBack = document.getElementById('notifBackdrop');
    if (notifDrop && notifDrop.classList.contains('open')) {
      notifDrop.classList.remove('open');
      if (notifBack) notifBack.classList.remove('open');
    }

    profilePopover.classList.add('open');
    topbarProfile.setAttribute('aria-expanded', 'true');
  }

  function closePopover() {
    if (profilePopover) {
      profilePopover.classList.remove('open');
      topbarProfile.setAttribute('aria-expanded', 'false');
    }
  }

  // Modal Open/Close
  function openProfileModal(focusPhoto) {
    closePopover();
    hideAlert();
    if (profileBackdrop) {
      profileBackdrop.classList.add('active');
      document.body.style.overflow = 'hidden';
      if (focusPhoto && fileInput) {
        setTimeout(function() { fileInput.click(); }, 120);
      } else {
        const nameInput = document.getElementById('profileInputName');
        if (nameInput) setTimeout(function() { nameInput.focus(); }, 120);
      }
    }
  }

  function closeProfileModal() {
    if (profileBackdrop) {
      profileBackdrop.classList.remove('active');
      document.body.style.overflow = '';
      hideAlert();
      // Reset uncommitted file selection
      fileInput.value = '';
      removePicInput.value = '0';
      if (hasActivePicture) {
        btnRemovePic.style.display = 'inline-flex';
      }
    }
  }

  // Click outside listener for Popover
  document.addEventListener('click', function(e) {
    if (profilePopover && profilePopover.classList.contains('open')) {
      if (!profilePopover.contains(e.target) && !topbarProfile.contains(e.target)) {
        closePopover();
      }
    }
  });

  // Escape key listener
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (profilePopover && profilePopover.classList.contains('open')) {
        closePopover();
      } else if (profileBackdrop && profileBackdrop.classList.contains('active')) {
        closeProfileModal();
      }
    }
  });

  // Profile click and keyboard trigger
  if (topbarProfile) {
    topbarProfile.addEventListener('click', togglePopover);
    topbarProfile.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        togglePopover(e);
      }
    });
  }

  // Menu items click handlers
  if (btnViewProfile) {
    btnViewProfile.addEventListener('click', function(e) {
      e.stopPropagation();
      openProfileModal(false);
    });
  }

  if (btnChangePic) {
    btnChangePic.addEventListener('click', function(e) {
      e.stopPropagation();
      openProfileModal(true);
    });
  }

  // Modal close handlers
  if (btnCloseModal) btnCloseModal.addEventListener('click', closeProfileModal);
  if (btnCancelModal) btnCancelModal.addEventListener('click', closeProfileModal);
  if (profileBackdrop) {
    profileBackdrop.addEventListener('click', function(e) {
      if (e.target === profileBackdrop) {
        closeProfileModal();
      }
    });
  }

  // Upload button triggers hidden file input
  if (btnUploadPic && fileInput) {
    btnUploadPic.addEventListener('click', function() {
      fileInput.click();
    });
  }

  // Client-side file validation and real-time preview
  if (fileInput) {
    fileInput.addEventListener('change', function() {
      hideAlert();
      if (!fileInput.files || !fileInput.files[0]) return;

      const file = fileInput.files[0];
      const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
      const validExts = ['jpg', 'jpeg', 'png', 'webp'];
      const ext = (file.name.split('.').pop() || '').toLowerCase();

      // Check extension & MIME
      if (!validExts.includes(ext) || !validTypes.includes(file.type)) {
        showAlert('Unsupported file format. Please select a JPG, PNG, or WEBP image.');
        fileInput.value = '';
        return;
      }

      // Check file size (2 MB = 2 * 1024 * 1024 bytes)
      if (file.size > 2 * 1024 * 1024) {
        showAlert('File size exceeds the 2 MB limit. Please select a smaller image.');
        fileInput.value = '';
        return;
      }

      // Generate preview
      const reader = new FileReader();
      reader.onload = function(ev) {
        avatarPreview.innerHTML = '<img src="' + ev.target.result + '" alt="Avatar Preview">';
        removePicInput.value = '0';
        if (btnRemovePic) btnRemovePic.style.display = 'inline-flex';
      };
      reader.readAsDataURL(file);
    });
  }

  // Remove profile picture handler
  if (btnRemovePic) {
    btnRemovePic.addEventListener('click', function() {
      hideAlert();
      fileInput.value = '';
      removePicInput.value = '1';
      avatarPreview.innerHTML = '<span>' + currentInitial + '</span>';
      btnRemovePic.style.display = 'none';
    });
  }

  // Form Submission via AJAX
  if (profileForm) {
    profileForm.addEventListener('submit', function(e) {
      e.preventDefault();
      hideAlert();

      const nameInput = document.getElementById('profileInputName');
      const newName = (nameInput?.value || '').trim();
      if (!newName) {
        showAlert('Full Name is required.');
        nameInput?.focus();
        return;
      }

      btnSave.disabled = true;
      const originalBtnText = btnSave.innerHTML;
      btnSave.innerHTML = 'Saving…';

      const formData = new FormData(profileForm);

      fetch('<?= $baseUrl ?>/api/profile-update.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      })
      .then(function(res) {
        return res.json().then(function(data) {
          return { status: res.status, ok: res.ok, data: data };
        });
      })
      .then(function(result) {
        btnSave.disabled = false;
        btnSave.innerHTML = originalBtnText;

        if (!result.ok || !result.data.success) {
          showAlert(result.data.error || 'Failed to update profile. Please try again.');
          return;
        }

        const user = result.data.user;
        currentInitial = user.initial || currentInitial;
        hasActivePicture = !!user.profile_picture;

        // 1. Immediately update topbar avatar (Requirement 9)
        if (topbarAvatar) {
          if (user.avatar_url) {
            topbarAvatar.innerHTML = '<img src="' + user.avatar_url + '" alt="' + user.name + '" class="topbar-avatar-img" id="topbarAvatarImg">';
          } else {
            topbarAvatar.innerHTML = '<span class="topbar-avatar-initial" id="topbarAvatarInitial">' + user.initial + '</span>';
          }
        }

        // 2. Immediately update topbar name
        if (topbarUserName) {
          topbarUserName.textContent = user.name;
          topbarUserName.title = user.name;
        }

        // 3. Immediately update popover header avatar & name
        if (tppHeaderAvatar) {
          if (user.avatar_url) {
            tppHeaderAvatar.innerHTML = '<img src="' + user.avatar_url + '" alt="' + user.name + '" class="topbar-avatar-img">';
          } else {
            tppHeaderAvatar.innerHTML = '<span>' + user.initial + '</span>';
          }
        }
        if (tppHeaderName) {
          tppHeaderName.textContent = user.name;
          tppHeaderName.title = user.name;
        }

        // 4. Update preview in modal
        if (avatarPreview) {
          if (user.avatar_url) {
            avatarPreview.innerHTML = '<img src="' + user.avatar_url + '" alt="' + user.name + '">';
            if (btnRemovePic) btnRemovePic.style.display = 'inline-flex';
          } else {
            avatarPreview.innerHTML = '<span>' + user.initial + '</span>';
            if (btnRemovePic) btnRemovePic.style.display = 'none';
          }
        }

        // Reset file input & remove state
        fileInput.value = '';
        removePicInput.value = '0';

        showAlert('Profile updated successfully!', 'success');
        setTimeout(function() {
          closeProfileModal();
        }, 900);
      })
      .catch(function(err) {
        btnSave.disabled = false;
        btnSave.innerHTML = originalBtnText;
        showAlert('A network error occurred while updating your profile.');
      });
    });
  }
})();
</script>
