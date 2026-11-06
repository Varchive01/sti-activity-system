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
 *     - User Information (name, email, role)
 *     - View Profile (with integrated profile-picture viewing & upload)
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
        $fetchedProf = null;
        try {
            $pStmt = $dbProf->prepare('SELECT id, name, email, role, department, profile_picture FROM users WHERE id = ? LIMIT 1');
            $pStmt->execute([(int)$topbarUser['id']]);
            $fetchedProf = $pStmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Fallback if profile_picture column has not been migrated yet
            try {
                $pStmt = $dbProf->prepare('SELECT id, name, email, role, department FROM users WHERE id = ? LIMIT 1');
                $pStmt->execute([(int)$topbarUser['id']]);
                $fetchedProf = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($fetchedProf) {
                    $fetchedProf['profile_picture'] = null;
                }
            } catch (\Throwable $e2) {
                $fetchedProf = null;
            }
        }
        if ($fetchedProf) {
            $topbarUser['name']            = $fetchedProf['name'];
            $topbarUser['email']           = $fetchedProf['email'];
            $topbarUser['role']            = $fetchedProf['role'];
            $topbarUser['dept']            = $fetchedProf['department'] ?? '';
            $topbarUser['profile_picture'] = $fetchedProf['profile_picture'] ?? null;

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['user_name']            = $fetchedProf['name'];
                $_SESSION['user_profile_picture'] = $fetchedProf['profile_picture'] ?? null;
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

.topbar-profile .topbar-avatar-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
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

.topbar-profile .topbar-avatar-indicator {
  position: absolute;
  bottom: -1px;
  right: -1px;
  width: 15px;
  height: 15px;
  border-radius: 50%;
  background: var(--bg-card, #ffffff);
  border: 1.5px solid var(--border, #E2E8F0);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-main, #334155);
  pointer-events: none;
  z-index: 2;
  transition: transform 0.2s ease, border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}

.topbar-profile .topbar-avatar-indicator svg {
  width: 7.5px;
  height: 7.5px;
  display: block;
  stroke: currentColor;
  transition: transform 0.2s ease;
}

.topbar-profile:hover .topbar-avatar-indicator {
  border-color: var(--sti-blue, #0284C7);
  color: var(--sti-blue, #0284C7);
}

.topbar-profile[aria-expanded="true"] .topbar-avatar-indicator {
  border-color: var(--sti-blue, #0284C7);
  background: var(--sti-blue-lt, #E0F2FE);
  color: var(--sti-blue, #0284C7);
}

.topbar-profile[aria-expanded="true"] .topbar-avatar-indicator svg {
  transform: rotate(180deg);
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

@media (max-width: 768px) {
  .topbar-profile .topbar-user-info {
    display: none !important;
  }
  .topbar-profile {
    padding: 0 !important;
  }
}

@media (max-width: 480px) {
  .topbar-profile-popover {
    width: min(280px, calc(100vw - 20px)) !important;
    max-width: calc(100vw - 20px) !important;
    right: 0 !important;
    left: auto !important;
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
  max-height: 92dvh;
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
  flex: 1 1 auto;
  max-height: calc(92dvh - 130px);
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

@media (max-width: 480px) {
  .profile-modal {
    max-width: calc(100vw - 20px) !important;
    margin: 10px auto !important;
  }
  .profile-modal-header {
    padding: 14px 16px !important;
  }
  .profile-modal-body {
    padding: 16px !important;
    gap: 14px !important;
  }
  .profile-avatar-section {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 12px !important;
  }
  .profile-avatar-btn-row {
    width: 100% !important;
  }
  .profile-avatar-btn-row .btn-profile-action {
    flex: 1 1 auto !important;
    justify-content: center !important;
  }
  .profile-modal-footer {
    padding: 12px 16px !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
  }
  .profile-modal-footer .btn-profile-footer {
    flex: 1 1 calc(50% - 4px) !important;
    justify-content: center !important;
  }
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
[data-theme="dark"] .topbar-profile .topbar-avatar-indicator {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
  color: var(--text-main, #F8FAFC);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
}
[data-theme="dark"] .topbar-profile:hover .topbar-avatar-indicator,
[data-theme="dark"] .topbar-profile[aria-expanded="true"] .topbar-avatar-indicator {
  border-color: var(--sti-blue-hover, #38BDF8);
  color: var(--sti-blue-hover, #38BDF8);
  background: var(--bg-base, #070E18);
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
    <div class="topbar-avatar-wrap">
      <div class="topbar-avatar" id="topbarAvatar">
        <?php if (!empty($uPic)): ?>
          <img src="<?= $baseUrl ?>/api/profile-avatar.php?id=<?= $uId ?>" alt="<?= htmlspecialchars($uName) ?>" class="topbar-avatar-img" id="topbarAvatarImg">
        <?php else: ?>
          <span class="topbar-avatar-initial" id="topbarAvatarInitial"><?= htmlspecialchars($uInitial) ?></span>
        <?php endif; ?>
      </div>
      <span class="topbar-avatar-indicator" id="topbarAvatarIndicator" aria-hidden="true">
        <svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M1 1.2l4 3.6 4-3.6"/>
        </svg>
      </span>
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

<?php if (!defined('STI_SYSTEM_MODAL_LOADED')): define('STI_SYSTEM_MODAL_LOADED', true); ?>
<style>
/* ── STI-ARMES Global System Dialog (Alert / Confirm / Prompt) ── */
.sti-sys-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(3px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  opacity: 0;
  transition: opacity 0.18s ease;
}
.sti-sys-modal-backdrop.is-open {
  opacity: 1;
}
.sti-sys-modal-card {
  width: 100%;
  max-width: 440px;
  background: var(--bg-card, #ffffff);
  color: var(--text-main, #0f172a);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25), 0 4px 12px rgba(15, 23, 42, 0.12);
  overflow: hidden;
  transform: translateY(10px) scale(0.98);
  transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.sti-sys-modal-backdrop.is-open .sti-sys-modal-card {
  transform: translateY(0) scale(1);
}
.sti-sys-modal-topstrip {
  height: 5px;
  width: 100%;
  background: var(--sti-blue, #0284c7);
}
.sti-sys-modal-card[data-variant="warning"] .sti-sys-modal-topstrip {
  background: #f59e0b;
}
.sti-sys-modal-card[data-variant="danger"] .sti-sys-modal-topstrip {
  background: #dc2626;
}
.sti-sys-modal-card[data-variant="success"] .sti-sys-modal-topstrip {
  background: #16a34a;
}
.sti-sys-modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 22px 12px;
}
.sti-sys-modal-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  flex-shrink: 0;
  background: rgba(2, 132, 199, 0.12);
  color: var(--sti-blue, #0284c7);
}
.sti-sys-modal-card[data-variant="warning"] .sti-sys-modal-icon {
  background: rgba(245, 158, 11, 0.14);
  color: #d97706;
}
.sti-sys-modal-card[data-variant="danger"] .sti-sys-modal-icon {
  background: rgba(220, 38, 38, 0.14);
  color: #dc2626;
}
.sti-sys-modal-card[data-variant="success"] .sti-sys-modal-icon {
  background: rgba(22, 163, 74, 0.14);
  color: #16a34a;
}
.sti-sys-modal-title {
  margin: 0;
  font-size: 1.02rem;
  font-weight: 700;
  color: var(--text-main, #0f172a);
  line-height: 1.3;
  flex: 1;
}
.sti-sys-modal-close {
  background: transparent;
  border: none;
  color: var(--text-muted, #64748b);
  font-size: 1.35rem;
  line-height: 1;
  padding: 4px 6px;
  border-radius: 6px;
  cursor: pointer;
}
.sti-sys-modal-close:hover {
  background: rgba(148, 163, 184, 0.15);
  color: var(--text-main, #0f172a);
}
.sti-sys-modal-body {
  padding: 4px 22px 18px 22px;
  font-size: 0.9rem;
  line-height: 1.55;
  color: var(--text-muted, #475569);
  max-height: 60vh;
  overflow-y: auto;
}
.sti-sys-modal-body p {
  margin: 0 0 10px 0;
}
.sti-sys-modal-body p:last-child {
  margin-bottom: 0;
}
.sti-sys-modal-list {
  margin: 8px 0 10px 0;
  padding: 10px 14px 10px 28px;
  background: var(--bg-base, #f8fafc);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 8px;
  color: var(--text-main, #1e293b);
  font-size: 0.85rem;
}
.sti-sys-modal-list li {
  margin-bottom: 4px;
}
.sti-sys-modal-list li:last-child {
  margin-bottom: 0;
}
.sti-sys-modal-input {
  width: 100%;
  margin-top: 10px;
  padding: 9px 12px;
  font-size: 0.9rem;
  border-radius: 8px;
  border: 1px solid var(--border, #cbd5e1);
  background: var(--bg-base, #ffffff);
  color: var(--text-main, #0f172a);
  outline: none;
  box-sizing: border-box;
}
.sti-sys-modal-input:focus {
  border-color: var(--sti-blue, #0284c7);
  box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.18);
}
.sti-sys-modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 22px;
  background: var(--bg-base, #f8fafc);
  border-top: 1px solid var(--border, #e2e8f0);
}
.sti-sys-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 18px;
  font-size: 0.86rem;
  font-weight: 600;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
  border: 1px solid transparent;
  font-family: inherit;
}
.sti-sys-btn-cancel {
  background: var(--bg-card, #ffffff);
  color: var(--text-main, #334155);
  border-color: var(--border, #cbd5e1);
}
.sti-sys-btn-cancel:hover {
  background: rgba(148, 163, 184, 0.12);
}
.sti-sys-btn-primary {
  background: var(--sti-blue, #0284c7);
  color: #ffffff;
}
.sti-sys-btn-primary:hover {
  background: #0369a1;
}
.sti-sys-btn-warning {
  background: #d97706;
  color: #ffffff;
}
.sti-sys-btn-warning:hover {
  background: #b45309;
}
.sti-sys-btn-danger {
  background: #dc2626;
  color: #ffffff;
}
.sti-sys-btn-danger:hover {
  background: #b91c1c;
}
@media (max-width: 480px) {
  .sti-sys-modal-footer {
    flex-direction: column-reverse;
  }
  .sti-sys-btn {
    width: 100%;
    padding: 10px 16px;
  }
}
</style>
<script>
(function() {
  if (window.STIModal) return;

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function detectMeta(rawMsg, mode, opts) {
    opts = opts || {};
    var text = String(rawMsg == null ? '' : rawMsg).trim();
    var cleanText = text.replace(/^[⚠️✓✨💡❌🛑]+\s*/u, '').trim();
    var lower = cleanText.toLowerCase();

    var variant = opts.variant || 'info';
    if (!opts.variant) {
      if (text.indexOf('⚠️') !== -1 || lower.indexOf('warning') !== -1 || lower.indexOf('blank') !== -1 || lower.indexOf('replace') !== -1 || lower.indexOf('offline') !== -1 || lower.indexOf('incomplete') !== -1 || lower.indexOf('unavailable') !== -1) {
        variant = 'warning';
      } else if (lower.indexOf('delete') !== -1 || lower.indexOf('remove') !== -1 || lower.indexOf('discard') !== -1 || lower.indexOf('clear ') !== -1 || lower.indexOf('blocked') !== -1 || lower.indexOf('failed') !== -1 || lower.indexOf('error') !== -1 || lower.indexOf('cannot') !== -1) {
        variant = (mode === 'confirm') ? 'danger' : 'danger';
      } else if (text.indexOf('✓') !== -1 || lower.indexOf('success') !== -1) {
        variant = 'success';
      }
    }

    var title = opts.title || '';
    if (!title) {
      var lines = cleanText.split(/\n+/);
      if (lines.length > 1 && lines[0].length <= 55 && !/[.?]$/.test(lines[0])) {
        title = lines[0].trim();
        cleanText = lines.slice(1).join('\n\n').trim();
      } else if (mode === 'confirm') {
        if (lower.indexOf('activity title') !== -1 && lower.indexOf('blank') !== -1) title = 'Activity Title is Blank';
        else if (lower.indexOf('replace') !== -1) title = 'Replace Existing Content?';
        else if (lower.indexOf('delete') !== -1 || lower.indexOf('remove') !== -1) title = 'Confirm Removal';
        else if (lower.indexOf('discard') !== -1 || lower.indexOf('clear') !== -1) title = 'Discard Changes?';
        else if (lower.indexOf('finalize') !== -1) title = 'Finalize Evaluation Analysis?';
        else if (lower.indexOf('collaborator') !== -1) title = 'Send to Collaborators?';
        else title = 'Please Confirm';
      } else if (mode === 'prompt') {
        title = cleanText.replace(/:$/, '') || 'Enter Value';
      } else {
        if (variant === 'danger') title = 'Action Required';
        else if (variant === 'warning') title = 'Attention Needed';
        else if (variant === 'success') title = 'Success';
        else title = 'System Notice';
      }
    }

    var iconMap = {
      info: 'ℹ️',
      warning: '⚠️',
      danger: '⚠️',
      success: '✓'
    };
    var icon = opts.icon || (mode === 'prompt' ? '✏️' : (iconMap[variant] || 'ℹ️'));

    var confirmText = opts.confirmText || 'OK';
    if (!opts.confirmText && mode === 'confirm') {
      if (lower.indexOf('proceed anyway') !== -1 || lower.indexOf('proceed with') !== -1) confirmText = 'Proceed Anyway';
      else if (lower.indexOf('replace') !== -1) confirmText = 'Yes, Replace';
      else if (lower.indexOf('remove') !== -1) confirmText = 'Remove';
      else if (lower.indexOf('delete') !== -1) confirmText = 'Delete';
      else if (lower.indexOf('discard') !== -1) confirmText = 'Discard Draft';
      else if (lower.indexOf('clear') !== -1) confirmText = 'Clear Layout';
      else if (lower.indexOf('finalize') !== -1) confirmText = 'Finalize Analysis';
      else if (lower.indexOf('send ') !== -1) confirmText = 'Send Now';
      else if (lower.indexOf('save ') !== -1) confirmText = 'Save Draft';
      else confirmText = 'Confirm';
    } else if (!opts.confirmText && mode === 'prompt') {
      confirmText = 'Save';
    }

    var cancelText = opts.cancelText || 'Cancel';
    return {
      title: title,
      bodyText: cleanText,
      variant: variant,
      icon: icon,
      confirmText: confirmText,
      cancelText: cancelText
    };
  }

  function formatBodyHtml(text) {
    var blocks = String(text || '').split(/\n\s*\n/);
    var html = '';
    blocks.forEach(function(block) {
      var lines = block.split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
      if (!lines.length) return;
      var bulletLines = lines.filter(function(l) { return /^[•\-*]\s+/.test(l); });
      if (bulletLines.length > 0 && bulletLines.length === lines.length) {
        html += '<ul class="sti-sys-modal-list">';
        bulletLines.forEach(function(b) {
          html += '<li>' + escapeHtml(b.replace(/^[•\-*]\s+/, '')) + '</li>';
        });
        html += '</ul>';
      } else {
        html += '<p>' + lines.map(escapeHtml).join('<br>') + '</p>';
      }
    });
    return html || '<p>' + escapeHtml(text) + '</p>';
  }

  function openDialog(mode, message, opts) {
    return new Promise(function(resolve) {
      var meta = detectMeta(message, mode, opts);
      var backdrop = document.createElement('div');
      backdrop.className = 'sti-sys-modal-backdrop';
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');

      var btnClass = (opts && opts.confirmBtnClass) || 'sti-sys-btn-primary';
      if (!opts || !opts.confirmBtnClass) {
        if (mode === 'confirm' && meta.variant === 'danger') btnClass = 'sti-sys-btn-danger';
        else if (mode === 'confirm' && meta.variant === 'warning') btnClass = 'sti-sys-btn-warning';
      }

      var bodyHtml = (mode === 'prompt')
        ? '<p>' + escapeHtml(meta.bodyText) + '</p><input type="text" class="sti-sys-modal-input" id="stiSysPromptInput" value="' + escapeHtml((opts && opts.defaultValue) || '') + '">'
        : formatBodyHtml(meta.bodyText);

      backdrop.innerHTML =
        '<div class="sti-sys-modal-card" data-variant="' + escapeHtml(meta.variant) + '">' +
          '<div class="sti-sys-modal-topstrip"></div>' +
          '<div class="sti-sys-modal-header">' +
            '<div class="sti-sys-modal-icon" aria-hidden="true">' + meta.icon + '</div>' +
            '<h3 class="sti-sys-modal-title">' + escapeHtml(meta.title) + '</h3>' +
            '<button type="button" class="sti-sys-modal-close" data-action="cancel" aria-label="Close">&times;</button>' +
          '</div>' +
          '<div class="sti-sys-modal-body">' + bodyHtml + '</div>' +
          '<div class="sti-sys-modal-footer">' +
            (mode !== 'alert'
              ? '<button type="button" class="sti-sys-btn sti-sys-btn-cancel" data-action="cancel">' + escapeHtml(meta.cancelText) + '</button>'
              : '') +
            '<button type="button" class="sti-sys-btn ' + btnClass + '" data-action="confirm">' + escapeHtml(meta.confirmText) + '</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(backdrop);
      requestAnimationFrame(function() {
        backdrop.classList.add('is-open');
      });

      var inputEl = backdrop.querySelector('#stiSysPromptInput');
      var confirmBtn = backdrop.querySelector('[data-action="confirm"]');
      var cancelBtn = backdrop.querySelector('[data-action="cancel"]');
      if (inputEl) {
        setTimeout(function() { inputEl.focus(); inputEl.select(); }, 30);
      } else if (opts && opts.focusCancel && cancelBtn) {
        setTimeout(function() { cancelBtn.focus(); }, 30);
      } else if (confirmBtn) {
        setTimeout(function() { confirmBtn.focus(); }, 30);
      }

      var closed = false;
      function close(result) {
        if (closed) return;
        closed = true;
        document.removeEventListener('keydown', onKey);
        backdrop.classList.remove('is-open');
        setTimeout(function() {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        }, 180);
        resolve(result);
      }

      function onConfirm() {
        if (mode === 'prompt') {
          close(inputEl ? inputEl.value : '');
        } else if (mode === 'confirm') {
          close(true);
        } else {
          close();
        }
      }

      function onCancel() {
        if (mode === 'prompt') {
          close(null);
        } else if (mode === 'confirm') {
          close(false);
        } else {
          close();
        }
      }

      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          onCancel();
        } else if (e.key === 'Enter' && mode === 'prompt') {
          e.preventDefault();
          onConfirm();
        }
      }

      document.addEventListener('keydown', onKey);
      backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) {
          onCancel();
          return;
        }
        var actBtn = e.target.closest('[data-action]');
        if (!actBtn) return;
        var act = actBtn.getAttribute('data-action');
        if (act === 'confirm') onConfirm();
        else if (act === 'cancel') onCancel();
      });
    });
  }

  window.STIModal = {
    alert: function(message, opts) {
      return openDialog('alert', message, opts);
    },
    confirm: function(message, opts) {
      return openDialog('confirm', message, opts);
    },
    prompt: function(message, defaultValue, opts) {
      var merged = Object.assign({}, opts || {}, { defaultValue: defaultValue != null ? defaultValue : '' });
      return openDialog('prompt', message, merged);
    },
    confirmUnsavedChanges: function(opts) {
      opts = opts || {};
      var defaultMsg = 'You have unsaved changes in your proposal.\n\nYour progress has been auto-saved locally as a draft, so you can safely resume it later.\n\nAre you sure you want to leave this page?';
      var merged = Object.assign({
        title: 'Unsaved Changes',
        icon: '💾',
        variant: 'warning',
        confirmText: 'Leave Page',
        cancelText: 'Stay & Continue Editing',
        confirmBtnClass: 'sti-sys-btn-warning',
        focusCancel: true
      }, opts);
      return openDialog('confirm', opts.message || defaultMsg, merged);
    }
  };

  // Override native window.alert globally so all alert() calls use the STI system dialog
  window._nativeAlert = window.alert ? window.alert.bind(window) : null;
  window.alert = function(message) {
    return window.STIModal.alert(message);
  };

  // Global Delete Draft Proposal Handler (for Faculty and Program Head)
  window.confirmDeleteDraft = async function(proposalId, proposalTitle, redirectUrl) {
    if (!proposalId) return;
    var displayTitle = proposalTitle ? '"' + proposalTitle + '"' : 'this draft proposal';
    var msg = 'Are you sure you want to delete ' + displayTitle + '?\n\nThis will permanently delete the draft and all its attachments. This action cannot be undone.';

    var confirmed = false;
    if (window.STIModal && typeof window.STIModal.confirm === 'function') {
      confirmed = await window.STIModal.confirm(msg, {
        title: 'Delete Draft Proposal?',
        icon: '🗑️',
        confirmText: 'Yes, Delete Draft',
        cancelText: 'Cancel',
        variant: 'danger'
      });
    } else {
      confirmed = window.confirm(msg);
    }

    if (!confirmed) return;

    try {
      var baseUrl = '<?= defined("BASE_URL") ? BASE_URL : "" ?>';
      var res = await fetch(baseUrl + '/api/proposal-delete.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ id: proposalId })
      });
      var data = await res.json();
      if (data && data.success) {
        try {
          localStorage.removeItem('sti_active_draft_full_srv_' + proposalId);
          localStorage.removeItem('sti_draft_meta_srv_' + proposalId);
          localStorage.removeItem('sti_emergency_srv_' + proposalId);
          if (window.OfflineDrafts && typeof window.OfflineDrafts.deleteDraft === 'function') {
            window.OfflineDrafts.deleteDraft('draft_srv_' + proposalId);
          }
        } catch (e) {}

        if (window.STIModal && typeof window.STIModal.alert === 'function') {
          await window.STIModal.alert('Draft proposal deleted successfully.', { title: 'Deleted', icon: '✅', variant: 'success' });
        } else {
          alert('Draft proposal deleted successfully.');
        }

        if (redirectUrl) {
          window.location.href = redirectUrl;
        } else if (window.location.pathname.indexOf('proposal-view.php') !== -1 || window.location.pathname.indexOf('proposal-edit.php') !== -1) {
          window.location.href = baseUrl + '/faculty/activities.php';
        } else {
          window.location.reload();
        }
      } else {
        var err = (data && data.error) ? data.error : 'Failed to delete draft proposal.';
        if (window.STIModal && typeof window.STIModal.alert === 'function') {
          await window.STIModal.alert(err, { title: 'Delete Failed', icon: '⚠️', variant: 'danger' });
        } else {
          alert(err);
        }
      }
    } catch (err) {
      console.error('Delete draft error:', err);
      if (window.STIModal && typeof window.STIModal.alert === 'function') {
        await window.STIModal.alert('An unexpected error occurred while deleting the draft.', { title: 'Error', variant: 'danger' });
      } else {
        alert('An unexpected error occurred while deleting the draft.');
      }
    }
  };
})();
</script>
<?php include_once __DIR__ . '/universal-archive-modal.php'; ?>
<?php endif; ?>
