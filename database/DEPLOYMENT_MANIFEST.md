# STI-ARMES InfinityFree Staging Deployment Manifest

**Target Environment:** InfinityFree Web Hosting (`htdocs/` web root)  
**PHP Runtime Requirement:** PHP 8.2+  
**Database Engine:** MariaDB 10.4+ / MySQL 5.7+ (via InfinityFree phpMyAdmin)  
**Date Generated:** 2026-10-29  

---

## 1. Overview & Upload Strategy

This manifest defines the explicit boundary of files and directories required to run STI-ARMES in a staging environment on InfinityFree. To guarantee security, performance, and confidentiality, the deployment files are partitioned into three distinct classifications:

1. **Upload Payload (`htdocs/`)**: The lean set of runtime PHP controllers, views, APIs, templates, Composer vendor dependencies, static assets, and upload directories required for normal application execution.
2. **Setup References (Handle Separately — External Execution Only)**: Configuration templates, SQL staging schemas, and bootstrap scripts to be run locally or via phpMyAdmin. **These must NOT be placed in the public web root.**
3. **Excluded Items**: Local development configurations, `.env` files with active credentials, automated test suites, development dumps, debug utilities, migration scripts, and all user/test uploads.

---

## 2. Upload Payload (Files & Directories for `htdocs/`)

Upload the following files and directories directly into the InfinityFree account's `htdocs/` folder:

### 2.1 Core Entry Points & Configurations
- `.htaccess`: Root Apache security controller (blocks direct access to `.env*`, `composer.*`, and disables directory browsing via `Options -Indexes`).
- `index.php`: Application router and session-based landing page.
- `.env.example`: Reference configuration template. (Copy to `.env` on host with production values; see Section 5).
- `composer.json` & `composer.lock`: Dependency manifests.

### 2.2 Application Role Controllers & Views
- `admin1/`: Academic Head module (14 files: `activities.php`, `approved.php`, `calendar.php`, `dashboard.php`, `generate-report.php`, `kpi-overview.php`, `kpi-reports.php`, `kpi.php`, `pending.php`, `post-event.php`, `returned-rejected.php`, `returned.php`, `review.php`, `view-activity.php`).
- `admin2/`: Discipline / Operations Admin module (15 files: `activities.php`, `approved.php`, `calendar.php`, `dashboard.php`, `generate-report.php`, `kpi-overview.php`, `kpi-reports.php`, `kpi.php`, `pending.php`, `post-event.php`, `returned.php`, `review.php`, `test-email.php`, `users.php`, `view-activity.php`).
  - *Note on `test-email.php`:* Secured administrative SMTP test utility requiring authenticated `admin2` role session.
- `dean/`: Executive Dean module (15 files: `activities.php`, `approved.php`, `calendar.php`, `dashboard.php`, `generate-report.php`, `kpi-overview.php`, `kpi-reports.php`, `kpi.php`, `pending.php`, `post-event.php`, `returned.php`, `review.php`, `task-monitoring.php`, `users.php`, `view-activity.php`).
- `faculty/`: Faculty Proponent module (16 files: `activities.php`, `approved.php`, `calendar.php`, `dashboard.php`, `generate-report.php`, `kpi-overview.php`, `kpi-reports.php`, `kpi.php`, `pending.php`, `post-event.php`, `proposal-create.php`, `proposal-edit.php`, `proposal-view.php`, `returned.php`, `tasks.php`, `view-activity.php`).
- `auth/`: Authentication module (5 files: `login.php`, `logout.php`, `change-password.php`, `microsoft-login.php`, `microsoft-callback.php`).

### 2.3 Operational APIs & Services
- `api/` (28 active operational endpoints):
  - `check-schedule-conflict.php`
  - `create-google-form.php`
  - `document-download.php`
  - `generate-evaluation.php`
  - `generate-feedback-analysis.php`
  - `generate-institutional-analytics.php`
  - `generate-kpi-analytics.php`
  - `generate-objectives.php`
  - `generate-theme.php`
  - `import-evaluations-csv.php`
  - `mark-ai-reviewed.php`
  - `notifications.php`
  - `onedrive-oauth-callback.php`
  - `post-event-save.php`
  - `profile-avatar.php`
  - `profile-update.php`
  - `proposal-save.php`
  - `proposal-update.php`
  - `review-action.php`
  - `section-comment.php`
  - `sync-google-form-responses.php`
  - `task-assignment.php`
  - `task-deliverables.php`
  - `user-create.php`
  - `user-delete.php`
  - `user-reset-password.php`
  - `user-update.php`
  - `validate-proposal-ai.php`
- `config/`: Configuration loaders (6 files: `config.php`, `database.php`, `google_config.php`, `mail_config.php`, `microsoft_config.php`, `onedrive_config.php`).
- `includes/`: Application core, helpers, and UI layouts:
  - `auth.php`, `compliance_helper.php`, `config.php`, `EmailService.php`, `FirebaseStorage.php`, `floorplan-ui.php`, `functions.php`, `gibberish_detector.php`, `GoogleFormsService.php`, `GoogleOAuthService.php`, `MicrosoftAzureProvider.php`, `MicrosoftOAuthService.php`, `notification-topbar-widget.php`, `OneDriveAuthService.php`, `OneDriveService.php`, `ProposalPdfService.php`, `QrCodeService.php`, `sidebar.php`, `topbar-profile.php`, `user-management-view.php`.
  - `includes/ai/`: `kpi_helper.php`, `proposal_validator.php`, `schedule_conflict.php`.
  - `includes/email_templates/`: `notification.html`.
- `services/`: AI services (3 files: `GeminiFeedbackAnalysisService.php`, `GeminiKpiAnalyticsService.php`, `GeminiProposalValidationService.php`).

### 2.4 Frontend Assets
- `assets/`:
  - `assets/css/`: `floorplan.css`, `main.css`, `walkthrough.css`.
  - `assets/js/`: `floorplan.js`, `notifications.js`, `proposal-ai-validator.js`, `schedule-conflict-ui.js`, `walkthrough-admin1.js`, `walkthrough-admin2.js`, `walkthrough-dean.js`, `walkthrough-faculty.js`, `walkthrough.js`.
  - `assets/img/`: `sti-marikina-building.jpg`.

### 2.5 Runtime Dependencies (`vendor/`)
- `vendor/`: Pre-built production Composer dependencies containing `autoload.php` and runtime packages:
  - `vendor/autoload.php`
  - `vendor/composer/`
  - `vendor/greew/`
  - `vendor/guzzlehttp/`
  - `vendor/league/`
  - `vendor/PHPMailer/`
  - `vendor/psr/`
  - `vendor/ralouphie/`
  - `vendor/symfony/`

### 2.6 Uploads Storage Structure
- `uploads/`:
  - `uploads/.htaccess`: Restricts direct browser access to uploaded files (`Require all denied`).
  - Empty target directories (must exist with write permissions 755/777):
    - `uploads/avatars/`
    - `uploads/deliverables/`
    - `uploads/floorplans/`
    - `uploads/post-event/`
    - `uploads/posters/`
    - `uploads/proposals/`
  - *(Zero user files, personal documents, or sample images are included).*

---

## 3. Setup References (Handle Separately — External Execution Only)

The following files are essential for initialization but **must NOT be uploaded or exposed in the public `htdocs/` web directory**:

> [!IMPORTANT]
> **Database Initialization Security:**
> - `database/staging_schema.sql` is strictly an import file for a **new, empty staging database** via phpMyAdmin. It creates 26 tables, 27 foreign keys, and resets auto-increments with zero rows.
> - Never upload `database/staging_schema.sql` to `htdocs/` where it could be probed over HTTP.
> - Import it directly using the phpMyAdmin web interface provided by InfinityFree.

- `database/staging_schema.sql`:
  - Purpose: Full consolidated DDL schema (26 tables).
  - Target: InfinityFree phpMyAdmin import tool.
- `database/FIRST_ADMIN_SETUP.md`:
  - Purpose: Deployment runbook explaining how to safely initialize the first Dean/Admin account without CLI access.
- `scripts/init_admin.php`:
  - Purpose: Cryptographically secure first-admin SQL generator.
  - Usage: Run **locally on your development machine**:
    ```bash
    php scripts/init_admin.php --sql
    ```
    Copy the generated SQL snippet and execute it in InfinityFree phpMyAdmin. **Do not upload `scripts/` to InfinityFree.**

---

## 4. Excluded Items (Must NEVER be Uploaded)

| Path / Pattern | Reason for Exclusion |
|---|---|
| `.env` | Contains active development/production credentials and private API keys. |
| `.git/`, `.gitignore` | Version control metadata, history, and internal repository structures. |
| `.vscode/` | IDE settings and workspace configurations. |
| `tests/` | Unit and integration test suites, browser automation scripts, screenshots, and fixtures. |
| `maintenance/debug/` | Debug scripts (`debug.php`, `setup_env.php`, etc.), UI migration tools, and log checkers. |
| `database/migrations/` | Legacy migration endpoints (already consolidated into `staging_schema.sql`). |
| `scratch/` | Temporary files, transcripts, and validation scripts. |
| `scripts/` | Workstation CLI tools, DOM parsers, and topbar injection scripts. |
| `node_modules/`, `package.json`, `package-lock.json` | Unused local NPM packages. |
| `composer.phar` | 3.6 MB CLI executable; Composer is run locally, not on the web host. |
| `phpmailer.zip` | 146 KB redundant archive. |
| `sti_activity_system.sql` | Obsolete development database dump containing test records and old schema. |
| `api/test.php`, `api/test2.php`, `api/test3.php` | Disabled development stub endpoints. |
| `uploads/*/*.*` (existing files) | Local test floorplans, test posters, and test deliverables containing development data. |
| `includes/config.php` | Inactive legacy configuration file with old development constants (replaced by `config/config.php` and `config/database.php`). |
| `*.log` | Local server, application, and error log files. |

---

## 5. Host Setup & Environment Configuration Steps

1. **Database Import**:
   - Log into the InfinityFree Control Panel (VistaPanel) and open **phpMyAdmin**.
   - Select your newly created staging database (e.g., `if0_xxxxxxx_sti`).
   - Go to the **Import** tab, choose [`database/staging_schema.sql`](file:///c:/xampp/htdocs/sti-activity-system/database/staging_schema.sql), and click **Go**.
2. **First Administrator Bootstrap**:
   - On your local machine, run `php scripts/init_admin.php --sql`.
   - Copy the output SQL statement, paste it into the **SQL** tab in phpMyAdmin, and run it.
   - Note the temporary password displayed on your local terminal.
3. **Upload Files**:
   - Using FileZilla or an FTP client, connect to InfinityFree using your FTP credentials.
   - Navigate to the remote `htdocs/` folder.
   - Upload the contents of the staging package (or the directories listed in Section 2).
4. **Configure `.env`**:
   - In `htdocs/`, create a new `.env` file based on `.env.example`.
   - Populate the production database settings:
     ```ini
     DB_HOST="sqlxxx.infinityfree.com"
     DB_NAME="if0_xxxxxxx_sti"
     DB_USER="if0_xxxxxxx"
     DB_PASS="your_infinityfree_password"
     DB_PORT=3306

     APP_URL="https://yourdomain.infinityfreeapp.com"

     GEMINI_API_KEY="your_production_gemini_key"

     SMTP_HOST="smtp.office365.com"
     SMTP_PORT=587
     SMTP_USERNAME="your_sti_email@domain.com"
     SMTP_PASSWORD="your_sti_app_password"
     SMTP_ENCRYPTION="tls"
     FROM_EMAIL="your_sti_email@domain.com"
     FROM_NAME="STI Activity System"
     ```
   - Set `.env` file permissions to `600` or `640` if supported.
5. **Initial Login & Password Rotation**:
   - Open your site URL in a web browser: `https://yourdomain.infinityfreeapp.com`.
   - Log in using the administrator email and the temporary password.
   - The application will enforce `must_change_password = 1` and redirect immediately to `/auth/change-password.php`.
   - Change the password immediately to complete setup.

---

## 6. Pre-Built Staging Distribution Package

- **Archive File:** `sti-armes-staging.zip`
- **Location:** Persisted outside web root at `<scratch>/sti-armes-staging.zip`
- **Archive Size:** 1.54 MB (1,615,808 bytes)
- **Total Entries:** 512 entries
- **Format:** Standard PKZIP with POSIX forward-slash paths (`/`) for Linux web server unzip compatibility.
- **Verification Status:** PASS (All required entry points, controllers, views, assets, and vendor autoload present; zero credentials, tests, debug scripts, or user uploads).

