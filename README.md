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
2. Open phpMyAdmin (`http://localhost/phpmyadmin/`).
3. Create a database named `sti_activity_system`.
4. Import the provided schema: `sti_activity_system.sql`.

### 3. Environment Configuration
Copy `.env.example` to `.env` and fill in your credentials:

```bash
cp .env.example .env
```

Edit `.env` with your Google Gemini API key and SMTP settings:
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

### 4. Install Dependencies
If running with Composer:
```bash
composer install
```

### 5. Access the System
Open your browser and navigate to:
```
http://localhost/sti-activity-system/auth/login.php
```

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
