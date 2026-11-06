<?php
/**
 * Dark Mode Token & Parity Enhancer
 * Ensures admin2/review.php, dean/review.php, scheduleConflictModal,
 * and main.css all maintain high contrast and parity in dark mode.
 */

$rootDir = dirname(__DIR__);

// 1. Update main.css with --text-body token
$mainCssFile = $rootDir . '/assets/css/main.css';
$mainCss = file_get_contents($mainCssFile);
if (strpos($mainCss, '--text-body:') === false) {
    $mainCss = str_replace(
        "--text:                var(--text-main);",
        "--text:                var(--text-main);\n  --text-body:           var(--text-secondary);",
        $mainCss
    );
    file_put_contents($mainCssFile, $mainCss);
    echo "[UPDATED] assets/css/main.css: Added --text-body token\n";
} else {
    echo "[SKIPPED] assets/css/main.css: --text-body token already present\n";
}

// 2. Update admin2/review.php
$admin2ReviewFile = $rootDir . '/admin2/review.php';
$admin2Review = file_get_contents($admin2ReviewFile);

// Replace hardcoded white in sticky action bar
$admin2Review = str_replace(
    "background: rgba(255, 255, 255, 0.96);",
    "background: var(--bg-card);",
    $admin2Review
);

// Replace hardcoded white in return button
$admin2Review = str_replace(
    ".review-action-bar button[onclick*=\"return\"] {\n  border-color: var(--border);\n  color: var(--sti-navy);\n  background: #fff;\n}",
    ".review-action-bar button[onclick*=\"return\"] {\n  border-color: var(--border);\n  color: var(--text-main);\n  background: var(--bg-card);\n}",
    $admin2Review
);

// Replace hardcoded white in review-section
$admin2Review = str_replace(
    ".review-section {\n  margin-bottom: 24px;\n  border-radius: var(--radius);\n  border: 1px solid var(--border);\n  background: #fff;",
    ".review-section {\n  margin-bottom: 24px;\n  border-radius: var(--radius);\n  border: 1px solid var(--border);\n  background: var(--bg-card);",
    $admin2Review
);

// Replace hardcoded white in btn-request-revision
$admin2Review = str_replace(
    ".btn-request-revision {\n  background: #fff;\n  border: 1.5px solid var(--border);",
    ".btn-request-revision {\n  background: var(--bg-card);\n  border: 1.5px solid var(--border);",
    $admin2Review
);

// Replace comment box background
$admin2Review = str_replace(
    "background: #FFFDF5;\n  border-top: 1px solid rgba(244, 169, 0, 0.3);",
    "background: var(--bg-base);\n  border-top: 1px solid var(--border);",
    $admin2Review
);

// Add Dark Mode block if not already present
if (strpos($admin2Review, '[data-theme="dark"]') === false) {
    $darkBlock = <<<CSS

/* ── Dark Mode Token Overrides & Contrast Protection ── */
[data-theme="dark"] .review-action-bar {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-action-bar .btn-outline {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .review-action-bar .btn-outline:hover {
  background: rgba(2, 132, 199, 0.15);
  border-color: var(--accent, var(--sti-blue));
  color: #38BDF8;
}
[data-theme="dark"] .review-action-bar button[onclick*="return"] {
  border-color: rgba(245, 158, 11, 0.35);
  color: #FBBF24;
  background: rgba(245, 158, 11, 0.12);
}
[data-theme="dark"] .review-action-bar button[onclick*="return"]:hover {
  background: rgba(245, 158, 11, 0.22);
  color: #FDE68A;
}
[data-theme="dark"] .review-action-bar .btn-danger {
  background: rgba(239, 68, 68, 0.2);
  border-color: rgba(239, 68, 68, 0.45);
  color: #FCA5A5;
}
[data-theme="dark"] .review-action-bar .btn-danger:hover {
  background: rgba(239, 68, 68, 0.3);
  color: #FFF;
}
[data-theme="dark"] .review-action-bar .btn-success {
  background: rgba(22, 163, 74, 0.25);
  border-color: rgba(34, 197, 94, 0.45);
  color: #86EFAC;
}
[data-theme="dark"] .review-action-bar .btn-success:hover {
  background: rgba(22, 163, 74, 0.35);
  color: #FFF;
}
[data-theme="dark"] .review-section {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-section-header {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-section.flagged {
  border-color: var(--sti-gold, #F59E0B);
  box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35);
}
[data-theme="dark"] .btn-request-revision {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-muted);
}
[data-theme="dark"] .btn-request-revision:hover {
  background: rgba(245, 158, 11, 0.18);
  border-color: rgba(245, 158, 11, 0.5);
  color: #FBBF24;
}
[data-theme="dark"] .btn-request-revision.has-comment {
  background: rgba(245, 158, 11, 0.2);
  border-color: rgba(245, 158, 11, 0.5);
  color: #FBBF24;
}
[data-theme="dark"] .section-flagged-banner {
  background: rgba(245, 158, 11, 0.14);
  border-color: rgba(245, 158, 11, 0.3);
  color: #FBBF24;
}
[data-theme="dark"] .btn-remove-comment {
  color: #FBBF24 !important;
}
[data-theme="dark"] .btn-remove-comment:hover {
  background: rgba(245, 158, 11, 0.25);
  color: #FDE68A !important;
}
[data-theme="dark"] .flagged-badge {
  background: rgba(245, 158, 11, 0.2);
  color: #FBBF24;
}
[data-theme="dark"] .info-block {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .review-table th {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .review-table td {
  border-color: var(--border);
}
[data-theme="dark"] .review-table tbody tr:hover td {
  background: rgba(255, 255, 255, 0.02);
}
[data-theme="dark"] .budget-total {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .floor-badge {
  background: rgba(56, 189, 248, 0.12);
  color: #38BDF8;
}
[data-theme="dark"] .section-comment-box {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .section-comment-box textarea {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .modal {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .modal .form-control {
  background: var(--bg-base);
  border-color: var(--border);
}
CSS;
    $admin2Review = str_replace("</style>", $darkBlock . "\n</style>", $admin2Review);
    file_put_contents($admin2ReviewFile, $admin2Review);
    echo "[UPDATED] admin2/review.php: Added dark mode tokens & overrides\n";
} else {
    file_put_contents($admin2ReviewFile, $admin2Review);
    echo "[UPDATED] admin2/review.php: Cleaned up hardcoded white values\n";
}

// 3. Update dean/review.php Return Button in Dark Mode
$deanReviewFile = $rootDir . '/dean/review.php';
$deanReview = file_get_contents($deanReviewFile);
if (strpos($deanReview, '[data-theme="dark"] .review-action-bar button[onclick*="return"]') === false) {
    $deanDarkRules = <<<CSS

[data-theme="dark"] .review-action-bar button[onclick*="return"] {
  border-color: rgba(245, 158, 11, 0.35) !important;
  color: #FBBF24 !important;
  background: rgba(245, 158, 11, 0.12) !important;
}
[data-theme="dark"] .review-action-bar button[onclick*="return"]:hover {
  background: rgba(245, 158, 11, 0.22) !important;
  color: #FDE68A !important;
}
[data-theme="dark"] .review-action-bar .btn-outline {
  background: var(--bg-card) !important;
  border-color: var(--border) !important;
  color: var(--text-main) !important;
}
CSS;
    $deanReview = str_replace("</style>", $deanDarkRules . "\n</style>", $deanReview);
    file_put_contents($deanReviewFile, $deanReview);
    echo "[UPDATED] dean/review.php: Added return button & action bar dark mode protection\n";
} else {
    echo "[SKIPPED] dean/review.php: Already has action bar dark mode rules\n";
}

// 4. Update faculty/proposal-create.php & faculty/proposal-edit.php conflict modal
foreach (['faculty/proposal-create.php', 'faculty/proposal-edit.php'] as $relFile) {
    $filePath = $rootDir . '/' . $relFile;
    $fileContent = file_get_contents($filePath);
    if (strpos($fileContent, '#scheduleConflictModal #scuConfTitle') === false) {
        $conflictDarkRules = <<<CSS
    [data-theme="dark"] #scheduleConflictModal #scuConfTitle {
      color: #F8FAFC !important;
    }
    [data-theme="dark"] #scheduleConflictModal [style*="color: #4a5568"],
    [data-theme="dark"] #scheduleConflictModal [style*="color:#4a5568"] {
      color: #CBD5E1 !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-body div[style*="background: #fff"],
    [data-theme="dark"] #scheduleConflictModal .modal-body div[style*="background:#fff"] {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-footer {
      background: var(--bg-card, #0F1B2E) !important;
      border-top: 1px solid var(--border, #1E2D45) !important;
    }
CSS;
        $fileContent = str_replace(
            "[data-theme=\"dark\"] #scheduleConflictModal .modal-footer {\n      background: var(--bg-card, #0F1B2E) !important;\n    }",
            "[data-theme=\"dark\"] #scheduleConflictModal .modal-footer {\n      background: var(--bg-card, #0F1B2E) !important;\n    }\n" . $conflictDarkRules,
            $fileContent
        );
        file_put_contents($filePath, $fileContent);
        echo "[UPDATED] $relFile: Enhanced #scheduleConflictModal dark mode contrast\n";
    } else {
        echo "[SKIPPED] $relFile: #scheduleConflictModal already enhanced\n";
    }
}

echo "\nDone applying dark mode enhancements!\n";

