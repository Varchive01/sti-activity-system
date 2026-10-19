# STI Marikina Activity Proposal & Management System

A web-based activity proposal, review, scheduling, and analytics system designed for STI College Marikina. The system streamlines the submission, multi-tier review, scheduling, and post-event evaluation processes for academic and student activities.

---

## 🌟 Key Features

### 1. Multi-Tier Role-Based Workflow
- **Faculty / Proponent**: Draft, validate, submit, and track activity proposals; upload floorplans, posters, and post-event documentation.
- **Admin 1 (Department / Head)**: Initial review, proposal endorsement, and KPI tracking.
- **Admin 2 (Academic Affairs / QA)**: Schedule coordination, venue availability verification, and compliance assessment.
- **Dean**: Executive review, final approval/return with structured section feedback, and institutional reports.

### 2. Gemini AI Integration
- **Proposal Validation**: Real-time AI evaluation of activity alignment, objectives, and feasibility.
- **KPI & Institutional Analytics**: Automated trend analysis, performance metric computation, and report generation.
- **Post-Event & Feedback Analysis**: AI synthesis of participant feedback and outcomes.
- **Schedule Conflict Detection**: Intelligent detection of venue and date overlaps across pending and approved events.

### 3. Email Notification System
- Powered by PHPMailer with support for standard SMTP and Microsoft 365 OAuth2 authentication.
- Automated email alerts on submission, revision requests, approvals, and post-event deadlines.

### 4. Interactive Floorplans & Asset Management
- Floorplan layout builder and poster uploads.
- Local storage with optional Firebase Storage cloud backup.

---

## 📋 Zero-Cost Participant Evaluation Workflow (Faculty Guide)

STI-ARMES includes a complete, zero-additional-cost evaluation lifecycle that allows Faculty to design questionnaires, distribute QR codes, and import participant feedback without requiring paid software, cloud subscriptions, or Google/Microsoft API credentials.

```
┌─────────────────────────┐     ┌──────────────────────────┐     ┌────────────────────────┐
│ 1. Define Questions     │ ──> │ 2. Create Online Form    │ ──> │ 3. Save Form URL       │
│ (STI-ARMES / Manual)    │     │ (Google / MS Forms)      │     │ (Proposal Field)       │
└─────────────────────────┘     └──────────────────────────┘     └────────────────────────┘
                                                                              │
┌─────────────────────────┐     ┌──────────────────────────┐                  ▼
│ 6. Upload CSV & View    │ <── │ 5. Export CSV Responses  │ <── ┌────────────────────────┐
│ (Deterministic KPIs)    │     │ (Provider CSV Export)    │     │ 4. Local QR Code       │
└─────────────────────────┘     └──────────────────────────┘     │ (Scan at Event Venue)  │
                                                                 └────────────────────────┘
```

### Step-by-Step Workflow

1. **Define Evaluation Questions**:
   - Generate evaluation criteria questions within STI-ARMES, or enter them manually if external AI services are disabled or offline.
2. **Create Questionnaire in Google Forms or Microsoft Forms**:
   - Create a questionnaire on your institutional or free Google Forms or Microsoft Forms account matching your activity's evaluation criteria (e.g., 1–4 Likert rating scales and open feedback fields).
3. **Save Questionnaire URL in Proposal**:
   - Paste the public form link into the proposal's **Evaluation Form Link** field (`evaluation_method`).
   - The URL is saved securely and persists across all activity lifecycle phases.
4. **Generate & Display Local QR Code**:
   - STI-ARMES generates an SVG QR code locally on the server (via `includes/QrCodeService.php`) without making any external API calls.
   - The QR code encodes the exact questionnaire URL.
5. **Distribute QR Code to Participants**:
   - Display the QR code on presentation screens or print event collateral for attendees to scan with mobile devices at the venue.
6. **Export Responses as CSV**:
   - Once participants have submitted responses, export the CSV file directly from the form provider:
     - *Google Forms*: Go to **Responses** &rarr; **⋮** (More options) &rarr; **Download responses (.csv)**.
     - *Microsoft Forms*: Go to **Responses** &rarr; **Open in Excel** / **Download CSV**.
7. **Import CSV into STI-ARMES**:
   - Open the activity page in `faculty/view-activity.php?tab=responses` or `faculty/post-event.php`.
   - Click **Import Evaluation CSV** and select your downloaded `.csv` file.
8. **Review Import Summary & Instant KPI Results**:
   - The system displays an import summary showing total rows processed, imported count, and skipped duplicates.
   - All responses immediately populate the **Evaluation Responses** table (`#responsesTable`).
9. **Automatic Duplicate Prevention (Idempotency)**:
   - The importer checks for existing records by `(activity_id, criteria, evaluator_name)` before insertion.
   - Re-uploading the same CSV safely skips previously imported rows (`0 imported, N skipped`) without duplicate inflation or database errors.
10. **Zero OAuth & Zero Billing Dependency**:
    - Google OAuth credentials and Google Forms API keys are **not required** for this manual CSV workflow. Automated API sync remains an optional add-on, but the CSV workflow is 100% free and credential-free.

---

### 📊 Deterministic KPI Calculations vs. Optional AI Features

- **Deterministic Local KPIs (100% Offline & Free)**:
  - Average ratings, satisfaction scores, attendance percentages, and criteria breakdown graphs are computed locally via PHP/SQL with zero external API calls and zero operational cost.
- **Optional AI Feedback Analysis**:
  - The **AI Feedback Analysis** tab provides automated thematic summarization using the Gemini API when a valid API key is configured.
  - If the API key is not configured or unavailable, STI-ARMES displays a clean unavailable message and continues to provide full access to all deterministic KPI metrics and qualitative response logs.

---

## 🛠️ Tech Stack & Requirements

- **Backend**: PHP 7.4+ or 8.x (PDO MySQL)
- **Database**: MySQL 5.7+ / MariaDB 10.4+
- **Frontend**: HTML5, CSS3, JavaScript (Vanilla ES6+), FullCalendar / Chart.js
- **Dependency Management**: Composer
- **AI Engine**: Google Gemini API
- **Web Server**: Apache (XAMPP recommended)

---

## 🚀 Setup & Installation

### 1. Clone Repository & Setup Virtual Host / Directory
Clone or copy this repository into your web server root (e.g., `c:\xampp\htdocs\sti-activity-system`).

```bash
git clone https://github.com/Varchive01/sti-activity-system.git
```

### 2. Database Configuration
1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin (`http://localhost/phpmyadmin/`) or access MySQL via CLI.
3. Create a database named `sti_activity_system` (with `utf8mb4` charset).
4. Import the clean production database schema:
   ```bash
   mysql -u root -p sti_activity_system < sti_activity_system.sql
   ```
   *Note: `sti_activity_system.sql` contains only the production table schemas, constraints, and indexes. It contains no hardcoded or default user accounts.*

### 3. Environment & Application Configuration
Copy `.env.example` to `.env` and configure your credentials:

```bash
cp .env.example .env
```

Configure `.env` with your Google Gemini API key, database settings (`config/database.php`), and SMTP credentials:
```env
GEMINI_API_KEY="your_gemini_api_key_here"

SMTP_HOST="smtp.office365.com"
SMTP_PORT=587
SMTP_USERNAME="your_email@domain.com"
SMTP_PASSWORD="your_password"
SMTP_ENCRYPTION="tls"
FROM_EMAIL="your_email@domain.com"
FROM_NAME="STI Activity System"
```

### 4. Initialize First Dean Account (Secure Initial Setup)
Run the secure CLI initializer from the command line to provision the initial administrator (Dean):

```bash
php scripts/init_admin.php
```

- You will be prompted interactively for the initial Dean's Full Name, STI Email, and Department.
- The script automatically generates a cryptographically secure, random one-time password and enforces password reset (`must_change_password = 1`) on first sign-in.
- Once created, the initializer refuses subsequent runs to prevent unauthorized privileged account provisioning.

### 5. Install Dependencies (Optional)
If running with Composer:
```bash
composer install
```

### 6. Access the System
Open your browser and navigate to:
```
http://localhost/sti-activity-system/auth/login.php
```
Sign in using the Dean email and the generated temporary one-time password. You will be prompted to change your password immediately.

---

## 🧪 Testing & Test Fixtures
Automated integration and regression test suites require dedicated seed accounts located in `tests/fixtures/test_seed.sql`.

> **⚠️ WARNING**: `tests/fixtures/test_seed.sql` is strictly intended for **isolated test environments** (e.g. CI/CD or dedicated test databases). **Never** import test fixtures into a production environment.


---

## 📁 Project Structure

```
├── admin1/          # Admin 1 dashboard, reviews, KPI reports
├── admin2/          # Admin 2 dashboard, reviews, schedule tracking
├── api/             # RESTful API endpoints (validation, scheduling, AI analytics)
├── assets/          # CSS stylesheets, JavaScript files, and images
├── auth/            # Login, session management, and access control
├── config/          # Database, application, and mail configuration
├── dean/            # Dean dashboard, executive reviews, reporting
├── faculty/         # Faculty dashboard, proposal creation & editing
├── includes/        # Shared templates, AI services, and mail handlers
│   └── ai/          # Gemini AI validation & scheduling logic
├── services/        # Service layer for AI analytics & evaluations
├── tests/           # Integration and unit test scripts
├── uploads/         # Storage for floorplans, posters, and documents
└── sti_activity_system.sql  # Database schema & initial data
```

---

## 🔒 Security Notice
Never commit sensitive credentials (such as `.env`, secret API keys, or private tokens) to version control. Keep `.env` included in `.gitignore`.
