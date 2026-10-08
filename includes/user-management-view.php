<?php
/**
 * includes/user-management-view.php
 *
 * Unified User Management interface for Dean and Admin2.
 * Contains single user directory table, modal creation/editing, and password management.
 * Enforces role hierarchy, permission-aware role dropdowns, and strict output escaping.
 */

if (!defined('BASE_URL')) {
    exit('Direct script access denied.');
}

$user = currentUser();
$db   = getDB();

// Fetch all users in directory
$userQuery = $db->query("SELECT id, name, email, role, department, created_at FROM users ORDER BY created_at DESC, id ASC");
$userList  = $userQuery->fetchAll(PDO::FETCH_ASSOC);

// Friendly Error Messages
$errorMessage = '';
if (isset($_GET['error'])) {
    $errCode = $_GET['error'];
    $errorMap = [
        'duplicate_email'          => 'An account with this email address already exists. Please use a unique email.',
        'missing_name'             => 'Full Name is required.',
        'invalid_email'            => 'Please provide a valid email address.',
        'missing_password'         => 'Default password is required.',
        'invalid_password'         => 'Password must be at least 6 characters long.',
        'forbidden_role'           => 'You are not authorized to assign or create this role.',
        'forbidden_target'         => 'You are not authorized to modify this user account.',
        'forbidden_self_demotion'  => 'You cannot modify your own role.',
        'forbidden_role_change'    => 'You are not authorized to change this user to that role.',
        'invalid_role'             => 'The requested role is invalid.',
        'user_not_found'           => 'The specified user account was not found.',
        'database_error'           => 'A database error occurred. Please try again.',
        'invalid_method'           => 'Invalid request method.',
    ];
    $errorMessage = $errorMap[$errCode] ?? 'An unexpected error occurred. Please try again.';
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'User Management – STI Activity System') ?></title>
  <script>
    (function() {
      try {
        var savedTheme = localStorage.getItem('sti-theme');
        if (savedTheme === 'dark') {
          document.documentElement.dataset.theme = 'dark';
        } else {
          document.documentElement.dataset.theme = 'light';
        }
      } catch (e) {}
    })();
  </script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($themeClass ?? 'theme-dean') ?>">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">User Management</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
        <?php include __DIR__ . '/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <!-- ── UNIFIED HEADER WITH ADD USER ACTION ── -->
      <div class="um-header">
        <div class="um-header-text">
          <h1>Manage System Accounts</h1>
          <p>Institutional user directory, account provisioning, and access permissions.</p>
        </div>
        <div>
          <button type="button" class="btn btn-primary" onclick="openAddModal()" id="btnAddUser">
            <i class="fa-solid fa-user-plus me-1"></i> Add User
          </button>
        </div>
      </div>

      <!-- ── FEEDBACK ALERTS ── -->
      <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
        <div class="alert alert-success" style="margin-bottom:20px;">
          <i class="fa-solid fa-circle-check"></i>
          <div><strong>Success:</strong> User account created successfully with default password! The user must change their password on first login.</div>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="alert alert-success" style="margin-bottom:20px;">
          <i class="fa-solid fa-circle-check"></i>
          <div><strong>Success:</strong> User account updated successfully!</div>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['password_reset']) && $_GET['password_reset'] === '1'): ?>
        <div class="alert alert-success" style="margin-bottom:20px;">
          <i class="fa-solid fa-circle-check"></i>
          <div><strong>Success:</strong> New default password set successfully. The user will be required to change it upon next login.</div>
        </div>
      <?php endif; ?>

      <?php if ($errorMessage): ?>
        <div class="alert alert-danger" style="margin-bottom:20px;">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <div><strong>Error:</strong> <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <!-- ── UNIFIED USER DIRECTORY TABLE ── -->
      <div class="card" style="overflow:hidden;">
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table id="userDirectoryTable">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Department</th>
                  <th>Created</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody id="userTableBody">
                <?php if (empty($userList)): ?>
                  <tr>
                    <td colspan="6" class="um-empty-state">
                      <i class="fa-solid fa-users-slash"></i>
                      <p>No users found.</p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($userList as $u): ?>
                    <?php
                      // Determine role label and styling
                      $roleKey = $u['role'];
                      $roleLabels = [
                          'faculty' => 'Faculty',
                          'admin1'  => 'Admin 1',
                          'admin2'  => 'Admin 2',
                          'dean'    => 'Dean'
                      ];
                      $roleLabel = $roleLabels[$roleKey] ?? ucfirst($roleKey);
                      $badgeClass = 'role-' . $roleKey;

                      // Authority check for editing & resetting password:
                      // Dean can edit Faculty, Admin1, Admin2 (and edit own name/dept, but not own role).
                      // Admin2 can ONLY edit Faculty accounts.
                      $canEdit = false;
                      $canResetPassword = false;

                      if ($user['role'] === 'dean') {
                          $canEdit = true;
                          $canResetPassword = ($u['role'] !== 'dean' || (int)$u['id'] === (int)$user['id']);
                      } elseif ($user['role'] === 'admin2') {
                          $canEdit = ($u['role'] === 'faculty');
                          $canResetPassword = ($u['role'] === 'faculty');
                      }
                    ?>
                    <tr>
                      <td>
                        <strong style="color:var(--text-main);"><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                      </td>
                      <td style="color:var(--text-muted);">
                        <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td>
                        <span class="role-badge <?= $badgeClass ?>"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                      </td>
                      <td style="color:var(--text-muted);">
                        <?= htmlspecialchars($u['department'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td style="font-size:0.825rem; color:var(--text-muted); white-space:nowrap;">
                        <?= !empty($u['created_at']) ? htmlspecialchars(date('M j, Y', strtotime($u['created_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                      </td>
                      <td style="text-align:right;">
                        <div class="action-group" style="justify-content: flex-end;">
                          <?php if ($canEdit): ?>
                            <button type="button" class="btn-action" id="btnEditUser_<?= (int)$u['id'] ?>"
                              onclick="openEditModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($u['email']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($u['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>')">
                              <i class="fa-regular fa-pen-to-square" style="color:var(--sti-blue, #0284C7);"></i> Edit
                            </button>
                          <?php endif; ?>

                          <?php if ($canResetPassword): ?>
                            <button type="button" class="btn-action" id="btnResetPass_<?= (int)$u['id'] ?>"
                              onclick="openResetModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($u['email']), ENT_QUOTES, 'UTF-8') ?>')">
                              <i class="fa-solid fa-key" style="color:var(--sti-gold, #D97706);"></i> Set Default Password
                            </button>
                          <?php endif; ?>

                          <?php if (!$canEdit && !$canResetPassword): ?>
                            <span class="badge-restricted" id="restrictedBadge_<?= (int)$u['id'] ?>"><i class="fa-solid fa-lock me-1"></i> Restricted</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── 1. ADD USER MODAL ── -->
  <div class="um-modal-backdrop" id="addModalBackdrop" onclick="handleBackdropClick(event, 'addModalBackdrop')">
    <div class="um-modal" role="dialog" aria-labelledby="addModalTitle" aria-modal="true">
      <div class="um-modal-header">
        <h3 id="addModalTitle"><i class="fa-solid fa-user-plus" style="color:var(--sti-blue, #0284C7);"></i> Add User</h3>
        <button type="button" class="um-modal-close" onclick="closeModal('addModalBackdrop')" aria-label="Close modal">&times;</button>
      </div>
      <form method="POST" action="<?= BASE_URL ?>/api/user-create.php" id="addUserForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <div class="um-modal-body">
          <div class="form-group">
            <label for="add_name">Full Name <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. Maria Santos" required autocomplete="off">
          </div>

          <div class="form-group">
            <label for="add_email">Email Address <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="email" name="email" id="add_email" class="form-control" placeholder="e.g. msantos@sti.edu" required autocomplete="off">
          </div>

          <div class="form-group">
            <label for="add_department">Department</label>
            <select name="department" id="add_department" class="form-control">
              <option value="">Select Department (Optional)...</option>
              <option value="IT Department">IT Department</option>
              <option value="Computer Science">Computer Science</option>
              <option value="Business Administration">Business Administration</option>
              <option value="Hospitality Management">Hospitality Management</option>
              <option value="Tourism Management">Tourism Management</option>
              <option value="Engineering">Engineering</option>
              <option value="General Education">General Education</option>
              <option value="Administration">Administration</option>
              <option value="Student Affairs">Student Affairs</option>
              <option value="Events Committee">Events Committee</option>
            </select>
          </div>

          <div class="form-group">
            <label for="add_role">Role <span style="color:var(--danger, #DC2626);">*</span></label>
            <select name="role" id="add_role" class="form-control" required>
              <?php if ($user['role'] === 'dean'): ?>
                <option value="faculty">Faculty</option>
                <option value="admin1">Admin 1</option>
                <option value="admin2">Admin 2</option>
              <?php else: ?>
                <option value="faculty" selected>Faculty</option>
              <?php endif; ?>
            </select>
            <div class="form-help">
              <?php if ($user['role'] === 'dean'): ?>
                Dean can create Faculty, Admin 1, and Admin 2 accounts.
              <?php else: ?>
                Admin 2 is authorized to create Faculty accounts only.
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label for="add_password">Default Password <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="password" name="password" id="add_password" class="form-control" placeholder="Minimum 6 characters" minlength="6" required autocomplete="new-password">
            <div class="um-callout-warning" style="margin-top:8px;">
              <i class="fa-solid fa-circle-info me-1"></i>
              <span>The user will be required to change this password upon first login.</span>
            </div>
          </div>
        </div>

        <div class="um-modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('addModalBackdrop')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSubmitAddUser">
            <i class="fa-solid fa-check me-1"></i> Create User
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── 2. EDIT USER MODAL ── -->
  <div class="um-modal-backdrop" id="editModalBackdrop" onclick="handleBackdropClick(event, 'editModalBackdrop')">
    <div class="um-modal" role="dialog" aria-labelledby="editModalTitle" aria-modal="true">
      <div class="um-modal-header">
        <h3 id="editModalTitle"><i class="fa-solid fa-user-pen" style="color:var(--sti-blue, #0284C7);"></i> Edit User</h3>
        <button type="button" class="um-modal-close" onclick="closeModal('editModalBackdrop')" aria-label="Close modal">&times;</button>
      </div>
      <form method="POST" action="<?= BASE_URL ?>/api/user-update.php" id="editUserForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="user_id" id="edit_user_id" value="">

        <div class="um-modal-body">
          <div class="form-group">
            <label for="edit_name">Full Name <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="text" name="name" id="edit_name" class="form-control" required autocomplete="off">
          </div>

          <div class="form-group">
            <label for="edit_email">Email Address <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="email" name="email" id="edit_email" class="form-control" required autocomplete="off">
          </div>

          <div class="form-group">
            <label for="edit_department">Department</label>
            <select name="department" id="edit_department" class="form-control">
              <option value="">Select Department (Optional)...</option>
              <option value="IT Department">IT Department</option>
              <option value="Computer Science">Computer Science</option>
              <option value="Business Administration">Business Administration</option>
              <option value="Hospitality Management">Hospitality Management</option>
              <option value="Tourism Management">Tourism Management</option>
              <option value="Engineering">Engineering</option>
              <option value="General Education">General Education</option>
              <option value="Administration">Administration</option>
              <option value="Student Affairs">Student Affairs</option>
              <option value="Events Committee">Events Committee</option>
            </select>
          </div>

          <div class="form-group">
            <label for="edit_role">Role <span style="color:var(--danger, #DC2626);">*</span></label>
            <select name="role" id="edit_role" class="form-control" required>
              <?php if ($user['role'] === 'dean'): ?>
                <option value="faculty">Faculty</option>
                <option value="admin1">Admin 1</option>
                <option value="admin2">Admin 2</option>
                <option value="dean" id="optEditDean" disabled>Dean (Cannot assign)</option>
              <?php else: ?>
                <option value="faculty" selected>Faculty</option>
              <?php endif; ?>
            </select>
            <div class="form-help" id="editRoleHelp">
              <?php if ($user['role'] === 'dean'): ?>
                Dean can modify roles between Faculty, Admin 1, and Admin 2.
              <?php else: ?>
                Admin 2 can manage Faculty accounts only.
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="um-modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('editModalBackdrop')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSubmitEditUser">
            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── 3. SET DEFAULT PASSWORD MODAL ── -->
  <div class="um-modal-backdrop" id="resetModalBackdrop" onclick="handleBackdropClick(event, 'resetModalBackdrop')">
    <div class="um-modal" role="dialog" aria-labelledby="resetModalTitle" aria-modal="true">
      <div class="um-modal-header">
        <h3 id="resetModalTitle"><i class="fa-solid fa-key" style="color:var(--sti-gold, #D97706);"></i> Set Default Password</h3>
        <button type="button" class="um-modal-close" onclick="closeModal('resetModalBackdrop')" aria-label="Close modal">&times;</button>
      </div>
      <form method="POST" action="<?= BASE_URL ?>/api/user-reset-password.php" id="resetPasswordForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="user_id" id="reset_user_id" value="">

        <div class="um-modal-body">
          <div style="background:var(--bg-base); border:1px solid var(--border); border-radius:var(--radius-sm, 8px); padding:12px 16px; margin-bottom:18px;">
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--text-muted); margin-bottom:4px;">Setting Password For</div>
            <strong id="reset_user_display" style="color:var(--text-main); font-size:0.92rem;"></strong>
          </div>

          <div class="form-group">
            <label for="reset_new_password">New Default Password <span style="color:var(--danger, #DC2626);">*</span></label>
            <input type="password" name="new_password" id="reset_new_password" class="form-control" placeholder="Minimum 6 characters" minlength="6" required autocomplete="new-password">
            <div class="um-callout-warning" style="margin-top:8px;">
              <i class="fa-solid fa-triangle-exclamation me-1"></i>
              <span>The user will be required to change this password on their next login.</span>
            </div>
          </div>
        </div>

        <div class="um-modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('resetModalBackdrop')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSubmitResetPassword">
            <i class="fa-solid fa-key me-1"></i> Set Default Password
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openAddModal() {
      const form = document.getElementById('addUserForm');
      if (form) form.reset();
      const modal = document.getElementById('addModalBackdrop');
      if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
      }
    }

    function openEditModal(userId, name, email, department, role) {
      const editForm = document.getElementById('editUserForm');
      if (!editForm) return;
      document.getElementById('edit_user_id').value = userId;
      document.getElementById('edit_name').value = name;
      document.getElementById('edit_email').value = email;
      document.getElementById('edit_department').value = department;
      
      const roleSelect = document.getElementById('edit_role');
      if (roleSelect) {
        // If target is Dean, lock role change
        const currentUserId = <?= (int)$user['id'] ?>;
        if (role === 'dean' || userId === currentUserId) {
          roleSelect.value = role;
          roleSelect.disabled = true;
          // Add hidden input if disabled so form still submits role
          let hiddenRole = document.getElementById('hidden_edit_role');
          if (!hiddenRole) {
            hiddenRole = document.createElement('input');
            hiddenRole.type = 'hidden';
            hiddenRole.name = 'role';
            hiddenRole.id = 'hidden_edit_role';
            editForm.appendChild(hiddenRole);
          }
          hiddenRole.value = role;
        } else {
          roleSelect.disabled = false;
          roleSelect.value = role;
          const hiddenRole = document.getElementById('hidden_edit_role');
          if (hiddenRole) hiddenRole.remove();
        }
      }

      const modal = document.getElementById('editModalBackdrop');
      if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
      }
    }

    function openResetModal(userId, name, email) {
      const form = document.getElementById('resetPasswordForm');
      if (form) form.reset();
      document.getElementById('reset_user_id').value = userId;
      document.getElementById('reset_user_display').innerText = name + ' (' + email + ')';
      const modal = document.getElementById('resetModalBackdrop');
      if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
      }
    }

    function closeModal(backdropId) {
      const modal = document.getElementById(backdropId);
      if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
      }
    }

    function handleBackdropClick(e, backdropId) {
      if (e.target.id === backdropId) {
        closeModal(backdropId);
      }
    }

    document.getElementById('btnAddUser')?.addEventListener('click', openAddModal);

    function checkHash() {
      const h = window.location.hash;
      if (h === '#openAddUser' || h === '#addUser' || h === '#addUserModal') {
        openAddModal();
      } else if (h.startsWith('#edit_')) {
        const id = h.replace('#edit_', '');
        document.getElementById('btnEditUser_' + id)?.click();
      } else if (h.startsWith('#reset_')) {
        const id = h.replace('#reset_', '');
        document.getElementById('btnResetPass_' + id)?.click();
      }
    }
    window.addEventListener('DOMContentLoaded', checkHash);
    window.addEventListener('hashchange', checkHash);

    // Client-side quick password validation
    document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
      const p = document.getElementById('add_password')?.value || '';
      if (p.length < 6) {
        alert('Default password must be at least 6 characters long.');
        e.preventDefault();
      }
    });

    document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
      const p = document.getElementById('reset_new_password')?.value || '';
      if (p.length < 6) {
        alert('New default password must be at least 6 characters long.');
        e.preventDefault();
      }
    });
  </script>
</body>
</html>
