<?php
/**
 * includes/dashboard-welcome-banner.php
 *
 * Professional, role-specific welcome hero banner component for STI-ARMES.
 * Recreates the institutional banner styling across all 5 user roles:
 * Faculty, Program Head, Admin 1, Admin 2, and Dean.
 */

if (!isset($user) || !is_array($user)) {
    if (function_exists('currentUser')) {
        $user = currentUser();
    } else {
        $user = ['name' => 'User', 'role' => 'faculty'];
    }
}

$currentRole = $user['role'] ?? 'faculty';
$userName    = trim($user['name'] ?? 'User');
$baseUrl     = defined('BASE_URL') ? BASE_URL : '';

// Proposal creation authorization check based on existing permissions
if (!isset($canCreateProposal)) {
    $canCreateProposal = in_array($currentRole, ['faculty', 'program_head'], true);
}

// Role-specific banner configurations
$roleConfigs = [
    'faculty' => [
        'label'       => 'FACULTY ACTIVITY PORTAL',
        'heading'     => 'Welcome back, ' . htmlspecialchars($userName) . '!',
        'description' => 'Manage your activity proposals, track their progress, and complete post-event requirements.',
        'buttons'     => [
            [
                'type'               => 'primary',
                'label'              => 'New Activity Proposal',
                'href'               => $baseUrl . '/faculty/proposal-create.php',
                'icon'               => 'plus',
                'require_can_create' => true,
            ],
            [
                'type'               => 'secondary',
                'label'              => 'Calendar',
                'href'               => $baseUrl . '/faculty/calendar.php',
                'icon'               => 'calendar',
                'require_can_create' => false,
            ],
        ],
    ],
    'program_head' => [
        'label'       => (!empty($user['dept']) ? strtoupper(htmlspecialchars(trim($user['dept']))) . ' — ' : '') . 'PROGRAM HEAD PORTAL',
        'heading'     => 'Welcome back, ' . htmlspecialchars($userName) . '!',
        'description' => 'Oversee your department\'s activities, monitor proposal progress, and manage departmental tasks.' . (!empty($user['dept']) ? ' (' . htmlspecialchars(trim($user['dept'])) . ')' : ''),
        'buttons'     => [
            [
                'type'               => 'secondary',
                'label'              => 'Department Activities',
                'href'               => $baseUrl . '/faculty/activities.php',
                'icon'               => 'activity',
                'require_can_create' => false,
            ],
            [
                'type'               => 'primary',
                'label'              => 'New Activity Proposal',
                'href'               => $baseUrl . '/faculty/proposal-create.php',
                'icon'               => 'plus',
                'require_can_create' => true,
            ],
            [
                'type'               => 'secondary',
                'label'              => 'Calendar',
                'href'               => $baseUrl . '/faculty/calendar.php',
                'icon'               => 'calendar',
                'require_can_create' => false,
            ],
        ],
    ],
    'admin1' => [
        'label'       => 'SYSTEM ADMINISTRATION PORTAL',
        'heading'     => 'Welcome back, ' . htmlspecialchars($userName) . '!',
        'description' => 'Manage system users, maintain access permissions, and oversee STI-ARMES configuration.',
        'buttons'     => [
            [
                'type'               => 'primary',
                'label'              => 'Pending Reviews',
                'href'               => $baseUrl . '/admin1/pending.php',
                'icon'               => 'pending',
                'title'              => 'Pending Reviews',
                'require_can_create' => false,
            ],
            [
                'type'               => 'secondary',
                'label'              => 'Calendar',
                'href'               => $baseUrl . '/admin1/calendar.php',
                'icon'               => 'calendar',
                'title'              => 'Calendar',
                'require_can_create' => false,
            ],
        ],
    ],
    'admin2' => [
        'label'       => 'ADMINISTRATIVE MANAGEMENT PORTAL',
        'heading'     => 'Welcome back, ' . htmlspecialchars($userName) . '!',
        'description' => 'Manage activity proposals, coordinate administrative processing, and monitor activity schedules.',
        'buttons'     => [
            [
                'type'               => 'primary',
                'label'              => 'Proposal Management',
                'href'               => $baseUrl . '/admin2/activities.php',
                'icon'               => 'proposals',
                'require_can_create' => false,
            ],
            [
                'type'               => 'secondary',
                'label'              => 'Calendar',
                'href'               => $baseUrl . '/admin2/calendar.php',
                'icon'               => 'calendar',
                'require_can_create' => false,
            ],
        ],
    ],
    'dean' => [
        'label'       => 'DEAN\'S MANAGEMENT PORTAL',
        'heading'     => 'Welcome back, ' . htmlspecialchars($userName) . '!',
        'description' => 'Review activity proposals, oversee institutional activities, and monitor departmental performance.',
        'buttons'     => [
            [
                'type'               => 'primary',
                'label'              => 'Pending Approvals',
                'href'               => $baseUrl . '/dean/pending.php',
                'icon'               => 'pending',
                'require_can_create' => false,
            ],
            [
                'type'               => 'secondary',
                'label'              => 'Calendar',
                'href'               => $baseUrl . '/dean/calendar.php',
                'icon'               => 'calendar',
                'require_can_create' => false,
            ],
        ],
    ],
];

$bannerConfig = $roleConfigs[$currentRole] ?? $roleConfigs['faculty'];
?>
<style>
  /* ── STI-ARMES Shared Dashboard Welcome Hero Banner ── */
  .dashboard-hero-banner {
    background: #0A192F;
    border-radius: 12px;
    padding: 20px 26px;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    box-shadow: 0 4px 16px rgba(10, 22, 40, 0.08);
    position: relative;
    overflow: hidden;
    min-height: 116px;
    box-sizing: border-box;
    margin-bottom: 20px;
    width: 100%;
  }

  .dashboard-hero-bg-img {
    position: absolute;
    top: 0;
    right: 0;
    width: 44%;
    height: 100%;
    background-image: url('<?= $baseUrl ?>/assets/img/sti-marikina-building.jpg');
    background-size: cover;
    background-position: center 18%;
    pointer-events: none;
    z-index: 0;
    mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.8) 35%, rgba(0,0,0,1) 100%);
    -webkit-mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.8) 35%, rgba(0,0,0,1) 100%);
  }

  .dashboard-hero-banner::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, #0A192F 0%, #0A192F 50%, rgba(10, 25, 47, 0.65) 75%, rgba(10, 25, 47, 0.2) 100%);
    z-index: 1;
    pointer-events: none;
  }

  [data-theme="dark"] .dashboard-hero-banner {
    background: #070E18;
    border: 1px solid rgba(255, 255, 255, 0.08);
  }

  [data-theme="dark"] .dashboard-hero-banner::before {
    background: linear-gradient(90deg, #070E18 0%, #070E18 50%, rgba(7, 14, 24, 0.7) 75%, rgba(7, 14, 24, 0.3) 100%);
  }

  .dashboard-hero-text {
    position: relative;
    z-index: 2;
    max-width: 62%;
  }

  .dashboard-hero-greeting {
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #F59E0B;
    margin-bottom: 4px;
  }

  .dashboard-hero-title {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    font-size: 1.4rem;
    font-weight: 800;
    color: #ffffff;
    margin: 0 0 4px 0;
    letter-spacing: -0.02em;
    line-height: 1.25;
  }

  .dashboard-hero-subtitle {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    font-size: 0.82rem;
    color: rgba(255, 255, 255, 0.85);
    margin: 0;
    font-weight: 500;
    line-height: 1.4;
  }

  .dashboard-hero-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    position: relative;
    z-index: 2;
    flex-wrap: wrap;
  }

  .dashboard-hero-actions .btn-hero-primary {
    background: #F4A900;
    color: #0A1628 !important;
    font-weight: 700;
    font-size: 0.8rem;
    padding: 8px 16px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    border: none;
    cursor: pointer;
    transition: all 0.18s ease;
    white-space: nowrap;
  }

  .dashboard-hero-actions .btn-hero-primary:hover {
    background: #FFB914;
    transform: translateY(-1px);
  }

  .dashboard-hero-actions .btn-hero-primary:focus-visible,
  .dashboard-hero-actions .btn-hero-secondary:focus-visible {
    outline: 2px solid #FFD23F;
    outline-offset: 2px;
  }

  .dashboard-hero-actions .btn-hero-secondary {
    background: #FFFFFF;
    color: #0A1628 !important;
    font-weight: 600;
    font-size: 0.8rem;
    padding: 8px 15px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none !important;
    border: 1px solid rgba(255, 255, 255, 0.9);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    cursor: pointer;
    transition: all 0.18s ease;
    white-space: nowrap;
  }

  .dashboard-hero-actions .btn-hero-secondary:hover {
    background: #F8FAFC;
    transform: translateY(-1px);
  }

  @media (max-width: 900px) {
    .dashboard-hero-banner {
      flex-direction: column;
      align-items: flex-start;
      padding: 18px 20px;
      gap: 14px;
    }
    .dashboard-hero-bg-img {
      opacity: 0.22;
      width: 100%;
    }
    .dashboard-hero-text {
      max-width: 100%;
    }
    .dashboard-hero-actions {
      width: 100%;
    }
  }

  @media (max-width: 480px) {
    .dashboard-hero-title {
      font-size: 1.22rem;
    }
    .dashboard-hero-subtitle {
      font-size: 0.78rem;
    }
    .dashboard-hero-actions {
      gap: 8px;
    }
    .dashboard-hero-actions .btn-hero-primary,
    .dashboard-hero-actions .btn-hero-secondary {
      flex: 1 1 auto;
      justify-content: center;
    }
  }
</style>

<div class="dashboard-hero-banner faculty-hero-banner" data-role="<?= htmlspecialchars($currentRole) ?>">
  <div class="dashboard-hero-bg-img faculty-hero-bg-img"></div>
  <div class="dashboard-hero-text faculty-hero-text">
    <div class="dashboard-hero-greeting faculty-hero-greeting"><?= htmlspecialchars($bannerConfig['label']) ?></div>
    <h1 class="dashboard-hero-title faculty-hero-title"><?= $bannerConfig['heading'] ?></h1>
    <p class="dashboard-hero-subtitle faculty-hero-subtitle"><?= htmlspecialchars($bannerConfig['description']) ?></p>
  </div>
  <div class="dashboard-hero-actions faculty-hero-actions">
    <?php foreach ($bannerConfig['buttons'] as $btn): ?>
      <?php
        if (!empty($btn['require_can_create']) && !$canCreateProposal) {
            continue;
        }
        $btnClass = ($btn['type'] === 'primary') ? 'btn-hero-primary' : 'btn-hero-secondary';
        $iconName = $btn['icon'] ?? '';
      ?>
      <a href="<?= htmlspecialchars($btn['href']) ?>"
         class="<?= $btnClass ?>"
         <?= !empty($btn['title']) ? 'title="' . htmlspecialchars($btn['title']) . '"' : '' ?>
         <?= !empty($btn['missing_route']) ? 'data-missing-route="true"' : '' ?>>
        <?php if ($iconName === 'plus'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
          </svg>
        <?php elseif ($iconName === 'calendar'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15" style="color:#0284c7;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        <?php elseif ($iconName === 'activity'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15" style="color:#0284c7;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
        <?php elseif ($iconName === 'users'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
          </svg>
        <?php elseif ($iconName === 'settings'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15" style="color:#0284c7;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
        <?php elseif ($iconName === 'proposals'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        <?php elseif ($iconName === 'pending'): ?>
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
          </svg>
        <?php endif; ?>
        <span><?= htmlspecialchars($btn['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
