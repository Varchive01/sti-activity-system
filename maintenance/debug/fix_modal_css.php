<?php
$modalCss = <<<CSS

    .notif-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 902;
      background: rgba(0, 0, 0, .4);
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .notif-modal-overlay.open {
      display: flex;
    }

    .notif-modal {
      background: #fff;
      width: 100%;
      max-width: 440px;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
      transform: scale(.95);
      opacity: 0;
      transition: all .2s cubic-bezier(.16, 1, .3, 1);
      overflow: hidden;
    }

    .notif-modal-overlay.open .notif-modal {
      transform: scale(1);
      opacity: 1;
    }

    .notif-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-bottom: 1px solid #F3F4F6;
      background: #F9FAFB;
    }

    .notif-modal-header h3 {
      font-size: 1rem;
      font-weight: 700;
      color: #111;
      margin: 0;
    }

    .notif-modal-close {
      background: #E5E7EB;
      border: none;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: #4B5563;
      transition: .15s;
    }

    .notif-modal-close:hover {
      background: #D1D5DB;
      color: #111;
    }

    .notif-modal-close svg {
      width: 16px;
      height: 16px;
    }

    .notif-modal-from {
      background: #fff;
      padding: 16px 20px;
      border-bottom: 1px solid #F3F4F6;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .from-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .from-label {
      font-size: .8rem;
      color: #6B7280;
      width: 60px;
    }

    .from-avatar {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #3B82F6;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      font-weight: 700;
    }

    .from-name {
      font-size: .9rem;
      font-weight: 600;
      color: #111;
    }

    .from-time {
      font-size: .75rem;
      color: #9CA3AF;
      margin-left: 70px;
      margin-top: -6px;
    }

    .subject-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-top: 4px;
    }

    .subject-val {
      font-size: .9rem;
      font-weight: 600;
      color: #111;
      line-height: 1.4;
      flex: 1;
    }

    .notif-modal-body {
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .notif-modal-icon-wrap {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin: 0 auto;
    }

    .notif-modal-message {
      font-size: .95rem;
      color: #374151;
      line-height: 1.6;
      margin: 0;
      text-align: center;
    }

    .notif-modal-actions {
      display: flex;
      gap: 10px;
      margin-top: 10px;
      justify-content: center;
    }

    .notif-modal-btn {
      padding: 10px 16px;
      border-radius: 8px;
      font-size: .85rem;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: .15s;
      flex: 1;
      max-width: 140px;
    }

    .notif-modal-btn.primary {
      background: #3B82F6;
      color: #fff;
    }

    .notif-modal-btn.danger {
      background: #EF4444;
      color: #fff;
    }

    .notif-modal-btn.ghost {
      background: #F3F4F6;
      color: #6B7280;
    }
CSS;

$files = [
    'c:/xampp/htdocs/sti-activity-system/faculty/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/dean/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin2/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin1/dashboard.php',
];

foreach ($files as $fp) {
    if (file_exists($fp)) {
        $content = file_get_contents($fp);
        // Ensure we don't duplicate it if it's already there
        if (strpos($content, '.notif-modal-overlay {') === false) {
            $content = preg_replace('/<\/style>/', $modalCss . "\n  </style>", $content);
            file_put_contents($fp, $content);
        }
    }
}
echo "Modal CSS restored!\n";
?>
