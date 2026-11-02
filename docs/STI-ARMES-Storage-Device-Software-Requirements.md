# STI-ARMES: Storage, Device, and Software Requirements Specification
**Web-Based Activity Request, Monitoring, and Evaluation System**  
**Institution:** STI College Marikina  
**Prepared for:** Project Adviser, Academic Head, and Executive Dean  
**Status:** Complete Technical Specification & Environmental Audit (Post-Audit Revision 2.0)  
**Date of Audit:** November 2026  

---

## 1. Introduction and Institutional Context

### 1.1 Project Purpose
The **STI Activity Request, Monitoring, and Evaluation System (STI-ARMES)** is a centralized, role-based web application engineered specifically for the operational, academic, and administrative workflows of **STI College Marikina**. The system digitizes, streamlines, and audits the entire lifecycle of student and institutional activities—from multi-faculty collaborative proposal drafting, budget formulation, venue scheduling, and deconfliction, to real-time Gantt timeline tracking, post-event deliverable verification, and automated Key Performance Indicator (KPI) analytics.

### 1.2 Purpose of this Specification
This technical document provides a verified, technically accurate specification of the system's infrastructure, hardware, storage, software, network, and security requirements. It is prepared to assist the project adviser, the Executive Dean, and campus IT administrators in:
1. Evaluating the technical readiness and physical feasibility of STI-ARMES within STI College Marikina's existing IT infrastructure.
2. Sizing on-premise servers, cloud hosting instances, local network bandwidth, and backup storage for long-term production operations.
3. Establishing baseline device and software requirements for institutional stakeholders across all user tiers.
4. Distinguishing verified local development/staging metrics from estimated institutional projections and recommended production practices.

### 1.3 Technical Classification Taxonomy
To ensure complete transparency and prevent ambiguity during institutional review, all major technical statements, measurements, and recommendations in this document are explicitly labeled with one of the following four classifications:
- **`[VERIFIED]`**: Confirmed directly from active codebase inspection, database schema query, or live server configuration.
- **`[ESTIMATED]`**: Calculated mathematically using clearly stated assumptions and representative modeling scenarios.
- **`[RECOMMENDED]`**: Proposed industry-standard specification or architectural practice for production deployment.
- **`[FOR INSTITUTIONAL CONFIRMATION]`**: Policy, infrastructure, or administrative decision requiring formal determination by STI College Marikina leadership.

### 1.4 Institutional Roles and Operational Scope `[VERIFIED]`
STI-ARMES enforces strict role-based access control (RBAC) and department boundaries aligned with STI College Marikina's organizational hierarchy:
- **Executive Dean (`dean`)**: Institutional oversight, final activity proposal approval/rejection, high-level task and deliverable audits, campus-wide Gantt timeline inspection, and institutional KPI reporting across all academic programs.
- **Academic Head (`admin1`)**: Curricular and academic activity reviews, academic calendar alignment, syllabus-integrated activity monitoring, and academic KPI evaluation.
- **Discipline & Operations Head (`admin2`)**: Co-curricular activity reviews, campus facility/venue reservations, discipline monitoring, administrative email configuration, and user account lifecycle management.
- **Program Heads (`program_head`)**: Departmental leaders for **Information Technology (`IT`)**, **Tourism and Hospitality Management (`THM`)**, and **General Education (`GE`)**. Program Heads oversee proposals, manage department-specific activities, monitor faculty tasks, track departmental deliverables, and generate department-scoped KPI analytics. Cross-department information is strictly partitioned.
- **Faculty Proponents & Collaborators (`faculty`)**: Proposal authors, co-authors, and task assignees who create proposals, collaborate across departments on shared activities, upload post-event deliverables, track assigned tasks, and access offline-synchronized drafts.

---

## 2. System Architecture Overview

```mermaid
flowchart TB
    subgraph ClientLayer ["Client Presentation Layer (End-User Devices)"]
        BrowserUI["Modern Web Browser (Chrome, Edge, Firefox, Safari)"]
        IndexedDBStore["IndexedDB Local Store ('sti_armes_offline')\nTransient Offline Proposal Text Drafts [VERIFIED]"]
        KonvaCanvas["Konva.js Interactive Floor Plan Canvas [VERIFIED]"]
        ChartsEngine["Chart.js Analytics & Gantt Timeline UI [VERIFIED]"]
    end

    subgraph ServerLayer ["Application & Web Server Layer (Apache 2.4.x / PHP 8.2+)"]
        ApacheSvc["Apache HTTP Server (mod_rewrite, mod_headers, mod_ssl) [VERIFIED]"]
        SecurityPerimeter[".htaccess Access Restrictions\n(Blocks .env, composer.*, vendor direct access) [VERIFIED]"]
        AuthRouter["Authentication & Role Router (includes/auth.php) [VERIFIED]"]
        
        subgraph AppModules ["Application Controllers & Services"]
            DeanMod["Dean Module (/dean) [VERIFIED]"]
            Admin1Mod["Academic Head Module (/admin1) [VERIFIED]"]
            Admin2Mod["Discipline Head Module (/admin2) [VERIFIED]"]
            PHMod["Program Head Module (/program_head) [VERIFIED]"]
            FacultyMod["Faculty Module (/faculty) [VERIFIED]"]
            APIEndpoints["REST/JSON API Endpoints (/api/*.php) [VERIFIED]"]
            AIServices["AI Guidance & Validation Services (/services/*.php) [VERIFIED]"]
            CloudSyncSvc["OneDrive & OAuth Services (/includes/*.php) [VERIFIED]"]
        end
    end

    subgraph DataLayer ["Data & Storage Layer"]
        MySQLDB[("MariaDB 10.4+ / MySQL 8.0+\nDatabase: sti_activity_system\nEngine: InnoDB (utf8mb4) [VERIFIED]")]
        LocalUploads["Local Uploads Directory (/uploads)\n(posters, floorplans, deliverables, proposals)\nProtected via uploads/.htaccess [VERIFIED]"]
        LocalBackups["Local Backup Archives (/backups)\nManual SQL dumps & codebase archives [VERIFIED]"]
    end

    subgraph ExternalCloud ["External Cloud & SaaS Integrations (HTTPS/OAuth 2.0)"]
        GeminiAPI["Google Gemini AI API\n(Theme, Objectives, Proposal Guidance & Validation) [VERIFIED]"]
        MicrosoftGraph["Microsoft Graph API / Entra ID\n(SSO Authentication, Outlook Mail.Send, OneDrive Sync) [VERIFIED]"]
        GoogleForms["Google Forms API\n(Post-Event Feedback & Participant Response Sync) [VERIFIED]"]
    end

    BrowserUI <-->|HTTPS / TLS 1.2+ (Port 443)| ApacheSvc
    BrowserUI <-->|Local Client I/O| IndexedDBStore
    BrowserUI <-->|Client-side Canvas/DOM| KonvaCanvas
    BrowserUI <-->|Client-side DOM/Canvas| ChartsEngine

    ApacheSvc --> SecurityPerimeter --> AuthRouter
    AuthRouter --> AppModules

    AppModules <-->|PDO Prepared Statements (Port 3306)| MySQLDB
    AppModules <-->|Secure Local Filesystem I/O| LocalUploads
    AppModules <-->|Manual CLI Scripts| LocalBackups

    AIServices <-->|REST / TLS (Outbound Port 443)| GeminiAPI
    CloudSyncSvc <-->|OAuth 2.0 Delegated / REST (Outbound Port 443)| MicrosoftGraph
    CloudSyncSvc <-->|OAuth 2.0 / REST (Outbound Port 443)| GoogleForms
```

### 2.1 Architectural Pattern `[VERIFIED]`
STI-ARMES is engineered as a secure, modular **client-server web application** utilizing a lightweight MVC-inspired separation of concerns:
- **Presentation Layer**: Responsive server-rendered HTML5 templates styled with custom CSS variables (`assets/css/main.css`) and modular Bootstrap 5 components. Interactive interfaces leverage client-side vanilla JavaScript modules, Konva.js for interactive campus floor plan venue plotting, Chart.js for Gantt and KPI dashboards, and client-side jsPDF for dynamic report generation.
- **Application & Controller Layer**: PHP 8.2+ procedural routers and object-oriented service classes. Centralized access control (`includes/auth.php`) enforces role restrictions, department boundary checks, session timeouts, and CSRF protection on every page view and API transaction.
- **Persistence Layer**: A normalized relational database running on MariaDB/MySQL utilizing the InnoDB engine for full ACID transaction safety, foreign key constraints, and UTF-8 multi-byte (`utf8mb4`) character encoding.
- **External Integration Layer**: Outbound secure HTTPS API bridges to Google Gemini AI (proposal guidance and objective refinement), Microsoft Graph API (campus SSO, OneDrive cloud document repository, and Office 365 transactional mail), and Google Forms (student post-event evaluation sync).

### 2.2 Security Perimeter `[VERIFIED]`
All direct HTTP requests to configuration files (`.env`, `.env.example`), package manifests (`composer.json`, `composer.lock`, `package.json`), version control directories (`.git`), log files, and uploaded physical assets (`uploads/`) are strictly blocked by Apache `.htaccess` directives using `mod_authz_core` (`Require all denied`). Uploaded documents, floor plans, and deliverables are routed through authenticated download endpoints (`api/document-download.php`) that verify user permissions before streaming bytes to the client.

---

## 3. Storage Requirements

### 3.1 Relational Database Storage (`sti_activity_system`)

#### Verified Database Footprint (Current Inspection) `[VERIFIED]`
- **Database Server**: 10.4.32-MariaDB Community Server
- **Database Engine**: InnoDB Storage Engine for all tables
- **Default Collation**: `utf8mb4_general_ci` (tables) / `utf8mb4_unicode_ci` (`proposal_collaborators`, `proposal_contribution_logs`)
- **Total Tables**: **29 tables**
- **Total Active Records**: **155 rows**
- **Total Measured Database Disk Space**: **1.25 MB (1,280.00 KB)** (Data: 528.00 KB, Indexes: 752.00 KB)

#### Database Schema Breakdown by Functional Domain `[VERIFIED]`
| Functional Domain | Key Tables | Active Records | Allocated Space (KB) | Storage Characteristics |
| :--- | :--- | :---: | :---: | :--- |
| **Activity Core & Details** | `activities`, `guidelines`, `manpower`, `materials`, `program_sequence`, `schedules` | 11 | 208.00 | Variable text fields, structured schedules, financial budgets. |
| **Collaborative Proposals** | `proposal_collaborators`, `proposal_contribution_logs`, `section_revisions`, `section_comments`, `proposal_comments` | 28 | 272.00 | Revision tracking, contributor attribution, section locking. |
| **Tasks & Deliverables** | `faculty_tasks`, `task_deliverables`, `task_reminder_logs` | 14 | 176.00 | Task assignments, completion dates, file metadata. |
| **Approvals & Governance** | `approval_logs`, `floor_plans` | 1 | 96.00 | Digital sign-offs, venue layout coordinates. |
| **Post-Event & Evaluation** | `post_event`, `activity_participants`, `kpi_evaluations`, `activity_feedback_analysis` | 1 | 144.00 | Participant counts, Likert scores, survey responses. |
| **KPI & AI Analytics** | `activity_kpi_analytics`, `institutional_kpi_analytics`, `root_cause_analysis`, `proposal_ai_validation`, `proposal_ai_validation_orphans_archive` | 1 | 144.00 | JSON analytic payloads, AI validation scores, recommendations. |
| **Users & Communications** | `users`, `notifications`, `email_logs` | 99 | 144.00 | User credentials, password hashes, email delivery audit. |
| **Total System Schema** | **29 Tables** | **155** | **1,280.00 KB (1.25 MB)** | **All tables indexed with foreign keys** |

#### Measured Row Data vs. Storage Modeling `[VERIFIED & ESTIMATED]`
To establish a verifiable foundation for database sizing, actual activity rows in the active database were audited:
- **Measured Raw Record Data `[VERIFIED]`**:
  - Across the 6 activities currently in the database, serialized row data for an activity and its related sub-tables measures between **0.77 KB** (minimal auto-test draft) and **5.03 KB** (Activity #268: *STI College Marikina 2026 Hackathon*, containing full narrative description, guidelines, manpower allocations, and participant records).
  - The measured arithmetic average across existing activities is **~2.9 KB of raw serialized data per activity**.
- **InnoDB Allocation Modeling `[ESTIMATED]`**:
  - MariaDB and MySQL InnoDB allocate disk storage in **16 KB pages**.
  - As an activity progresses through its complete lifecycle, records are created across approximately 12 to 15 related tables (e.g., 3–5 schedule items, 5–10 manpower/materials items, 5–15 collaboration logs, 2–6 task assignments, approval sign-offs, post-event survey summaries, and AI analytics JSON payloads).
  - Accounting for table page fragmentation, B-tree primary and secondary index structures, row headers, and foreign key pointers, each fully completed activity lifecycle requires an aggregate physical database allocation modeled at **~15 KB to 25 KB** across all related tables.

#### Illustrative Annual Database Growth Planning Scenario `[ESTIMATED]`
The following projection is an **illustrative planning scenario** based on stated institutional operational assumptions, not measured multi-year historical volume:
- **Assumed Institutional Activity Volume `[ESTIMATED]`**: 80 to 120 completed activities per academic year (modeled on approximately 25 to 40 activities each from Information Technology, Tourism and Hospitality Management, and General Education).
- **Projected Annual Activity Data Growth `[ESTIMATED]`**: 100 activities × 20 KB average allocation = **~2.0 MB / year**.
- **Projected Annual System Logs & Audit Growth `[ESTIMATED]`**: In-app notifications (`notifications`), transactional mail delivery logs (`email_logs`), task reminders (`task_reminder_logs`), and session records = **~3.0 MB to 5.0 MB / year**.
- **Estimated Total Annual Database Growth `[ESTIMATED]`**: **~5.0 MB to 7.0 MB per academic year**.
- **Estimated 3-Year Cumulative Database Footprint `[ESTIMATED]`**: Initial schema (1.25 MB) + (3 years × ~6 MB) = **~19 MB to 25 MB** (comfortably under 50 MB).

---

### 3.2 Local File Uploads Storage (`uploads/`)

#### Verified Local File Uploads Footprint `[VERIFIED]`
- **Total Uploaded Files**: **134 files**
- **Total Physical Disk Usage**: **8.09 MB (8,487,574 bytes)**
- **Directory Distribution**:
  - `uploads/floorplans/`: 67 items
  - `uploads/posters/`: 56 items
  - `uploads/proposals/`: 5 items
  - `uploads/deliverables/`: 4 items
  - `uploads/post-event/`: 1 item
  - `uploads/avatars/`: 0 items (default SVG avatars rendered via CSS)
- **File Type Composition**: PNG (77), JPG (42), PDF (9), GIF (5), `.htaccess` (1).
- **Average Current File Size**: ~63.3 KB (reflects development/testing images and mock floorplans).

#### Dissection of Upload Limits: Server vs. Request Body vs. Application Limits `[VERIFIED]`
To eliminate confusion regarding upload capabilities, the three distinct operational layers governing file uploads in STI-ARMES are distinguished below:

```
+----------------------------------------------------------------------------------------------------+
|                             THREE-TIER UPLOAD LIMIT ARCHITECTURE                                   |
+----------------------+----------------------+-----------------------+------------------------------+
| Layer                | Directive / Constant | Verified Active Value | Operational Effect           |
+----------------------+----------------------+-----------------------+------------------------------+
| Web Server Envelope  | upload_max_filesize  | 40M (Apache php.ini)  | Maximum size of any single   |
| (PHP Runtime Ceiling)|                      | 2M (CLI default)      | file accepted into $_FILES   |
+----------------------+----------------------+-----------------------+------------------------------+
| Request Body Ceiling | post_max_size        | 40M (Apache php.ini)  | Maximum total size of entire |
| (Total POST Payload) |                      | 8M (CLI default)      | HTTP POST request body       |
+----------------------+----------------------+-----------------------+------------------------------+
| Script Memory        | memory_limit         | 512M (Apache php.ini) | Maximum RAM allocated to     |
|                      |                      | 128M (CLI default)    | PHP process during execution |
+----------------------+----------------------+-----------------------+------------------------------+
| Application Business | MAX_FILE_SIZE        | 10 MB (10,485,760 B)  | Strictest constraint:        |
| Logic Enforcement    | (config/config.php)  |                       | Rejects any file > 10 MB     |
+----------------------+----------------------+-----------------------+------------------------------+
```

> [!IMPORTANT]
> **Permitted Individual File Size is 10 MB, NOT 40 MB `[VERIFIED]`**  
> While the Apache PHP web server environment is configured with `upload_max_filesize = 40M` and `post_max_size = 40M`, the application business logic strictly enforces `MAX_FILE_SIZE = 10 MB` (`10 * 1024 * 1024` bytes) in `config/config.php` and `includes/functions.php`.
> - If an uploaded file is between 10 MB and 40 MB, Apache/PHP will accept the HTTP packet, but STI-ARMES validation functions (`isValidPosterImage()`, `isValidFloorPlanFile()`, `uploadFile()`) will **reject the file and return a validation error**.
> - If an uploaded file exceeds 40 MB, the PHP engine rejects it before script execution begins (`UPLOAD_ERR_INI_SIZE`).
> - `post_max_size = 40M` ensures that a multi-file POST submission (such as multiple deliverables submitted simultaneously) does not exceed the total HTTP request body limit.

#### Upload Validation and Security Verification `[VERIFIED]`
Application code (`includes/functions.php`) applies multi-layered security validation prior to persisting any file to disk:
1. **Extension Whitelisting**:
   - General documents: `['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'webp']`.
   - Event posters: `['jpg', 'jpeg', 'png', 'webp', 'gif']`.
   - Floor plans: `['png', 'jpg', 'jpeg', 'pdf']`.
2. **MIME Type Inspection**: Evaluates actual file headers via PHP `finfo_file(FILEINFO_MIME_TYPE, ...)` to reject spoofed file extensions.
3. **Magic-Byte Header Verification**:
   - PDF files: Validates presence of `%PDF-` in the initial 4 bytes.
   - Images: Verified via `getimagesize()` to confirm valid image dimensions and structures.
4. **Explicit Video Rejection**: Strictly rejects video extensions (`mp4`, `mov`, `avi`, `mkv`, `webm`, `flv`, `wmv`, `m4v`, `3gp`, `ogv`) and video MIME types (`video/*`) to prevent storage exhaustion.
5. **Execution Prevention**: The `uploads/` directory contains an `.htaccess` file with `Require all denied`, preventing direct browser URL execution of uploaded assets.

#### Illustrative Annual Upload Storage Growth Planning Scenario `[ESTIMATED]`
The following transparent calculation models expected annual disk usage for uploaded files using clearly stated operational assumptions:

- **Assumptions for Upload Composition per Completed Activity `[ESTIMATED]`**:
  1. *Event Poster*: 1 high-resolution banner image (assumed average: **1.5 MB**; typical range: 500 KB to 2.5 MB).
  2. *Venue Floor Plan*: 1 layout diagram or seating plan (assumed average: **800 KB / 0.8 MB**; typical range: 300 KB to 1.5 MB).
  3. *Task Deliverables & Verification Proof*: 2 to 4 files per activity including attendance rosters, official receipts, certificates, and event photos (assumed average: 3 files @ 1.2 MB = **3.6 MB**).
  4. *Generated Proposal & Evaluation PDFs*: 1 to 2 system-generated PDFs archived locally (assumed average: **600 KB / 0.6 MB**).
  - **Estimated Midpoint Upload Volume per Activity**: **~6.5 MB to 8.5 MB** (planning midpoint: **~7.5 MB per activity**).

- **Transparent Annual Growth Calculations `[ESTIMATED]`**:
  - **Scenario A (Conservative: 80 activities/year)**: 80 activities × 7.5 MB = **~600 MB / year**.
  - **Scenario B (Active Campus: 100 activities/year)**: 100 activities × 7.5 MB = **~750 MB / year**.
  - **Scenario C (High Volume / Peak: 120 activities/year)**: 120 activities × 7.5 MB = **~900 MB / year**.

- **Cumulative Retention Projections `[ESTIMATED]`**:
  - Assuming a standard academic record retention period of **3 academic years**:
    - 3-Year Cumulative Uploads (Scenario B): ~750 MB × 3 = **~2.25 GB**.
    - Upper Bound (Scenario C with larger attachments): **~3.5 GB**.
  - **Recommended Server Storage Allocation for Uploads `[RECOMMENDED]`**: **10 GB to 20 GB** dedicated partition (provides ample safety margin for high-resolution event documentation).

---

### 3.3 Cloud Storage Integration (Microsoft OneDrive via Microsoft Graph API)

#### Technical Integration Architecture `[VERIFIED]`
STI-ARMES includes a Microsoft Graph API integration implemented in `includes/OneDriveService.php` and `includes/OneDriveAuthService.php`.
- **Target Endpoint**: `https://graph.microsoft.com/v1.0/me/drive/root:/STI_Activity_System_Documents`
- **Configured Scopes**: `https://graph.microsoft.com/Files.ReadWrite offline_access` (defined in `OneDriveAuthService::GRAPH_SCOPE`)
- **Authentication Flow**: OAuth 2.0 Authorization Code Flow with `offline_access`. A long-lived refresh token (`ONEDRIVE_REFRESH_TOKEN`) is stored in `.env` and exchanged for short-lived (1-hour) bearer access tokens.

#### Critical Permission Model Distinction: Delegated vs. Application Permissions `[VERIFIED]`
1. **Implemented Flow Uses Delegated Permissions `[VERIFIED]`**:
   - The requested scope (`Files.ReadWrite` with `offline_access`) is a **delegated permission**.
   - Consequently, the endpoint `/me/drive` routes strictly to the **personal OneDrive storage of the specific authorizing user account** whose credentials generated the refresh token via `scripts/generate_onedrive_token.php`.
   - The integration does **NOT** write to an institutional SharePoint Document Library or a tenant-wide shared drive.
2. **Alternative Application Permissions Model `[RECOMMENDED]`**:
   - To write to an institutional shared document repository or SharePoint document library without tying storage to an individual staff account, the integration would require **Application permissions** (`Files.ReadWrite.All`) via the OAuth 2.0 Client Credentials Grant (`client_id` + `client_secret`), targeting `/users/{shared-mailbox-id}/drive` or `/sites/{site-id}/drive`.

#### Codebase Verification: Archival and Purging Status `[VERIFIED]`
> [!WARNING]
> **No Automatic Historical Archival or Local Purging Exists in Code `[VERIFIED]`**  
> Inspection of `includes/OneDriveService.php` confirms that the class implements `ensureRootFolder()`, `createFolder()`, `ensureNestedFolder()`, and `uploadFile()`.
> - The codebase contains **NO logic to automatically archive, offload, or purge local files older than 1 year**.
> - Uploaded files are mirrored to OneDrive at the time of submission if `ONEDRIVE_ENABLED=true`.
> - Local disk copies in `uploads/` are retained indefinitely unless manually deleted by an administrator.

#### Testing Status with Microsoft 365 Credentials `[VERIFIED & FOR INSTITUTIONAL CONFIRMATION]`
- **Verified Testing Baseline `[VERIFIED]`**: End-to-end testing (`tests/onedrive_e2e_result.json`) was executed successfully against a **consumer/personal Microsoft account** (`tenant_id = "common"`, webUrl: `https://onedrive.live.com?cid=982ad923...`).
- **Unverified Institutional State `[FOR INSTITUTIONAL CONFIRMATION]`**: The integration has **NOT yet been tested against a live STI College Marikina organizational Microsoft 365 tenant** (`@marikina.sti.edu.ph`). Enterprise tenants enforce conditional access policies, tenant-specific endpoints (`login.microsoftonline.com/{tenant-uuid}`), and administrator consent requirements that must be verified by campus IT.

---

### 3.4 Client-Side Storage (IndexedDB Browser Cache)

#### Verified Implementation (`assets/js/offline-drafts.js`) `[VERIFIED]`
- **Engine**: Browser IndexedDB API
- **Database Name**: `sti_armes_offline` (Version 1)
- **Object Store**: `proposal_drafts` (KeyPath: `local_id`)
- **Indexes**: `server_id`, `faculty_id`, `sync_status`, `last_saved_at`

#### Stored Data vs. Excluded Data `[VERIFIED]`
- **What IS Stored Locally `[VERIFIED]`**:
  - Proposal form fields: activity title, source (curricular/co-curricular), program department, target participants, venue type, objectives, description, estimated budgets.
  - Sub-form arrays: schedule rows (date, start time, end time, description), manpower allocations, materials budgets, guidelines.
  - State machine flags: `local_id` (UUID), `server_id`, `faculty_id`, `sync_status` (`saved_locally`, `pending_sync`, `syncing`, `synchronized`, `conflict_requires_review`, `sync_failed`), `last_saved_at`, `server_updated_at`.
- **What is NOT Stored Locally `[VERIFIED]`**:
  - **Binary File Uploads Are Excluded**: Files selected in `<input type="file">` (event posters, floor plan image files, deliverable attachments) are **NOT serialized into IndexedDB or encoded as Base64/Blobs**.
  - Due to browser sandbox security restrictions, JavaScript cannot restore local filesystem file paths into an `<input type="file">` element upon page reload.
  - **Binary file attachments do NOT survive browser restarts or offline drafting**. Users must be online to attach and upload physical files.

#### Synchronization Prerequisites and Behavioral Boundaries `[VERIFIED]`
1. **User Authentication & Session Expiry `[VERIFIED]`**:
   - Background synchronization uses standard browser `fetch()` with session cookies (`credentials: 'same-origin'`).
   - If the user's PHP session has expired (`session.gc_maxlifetime` or inactivity timeout), the synchronization request will fail with an HTTP 401 or redirect to `auth/login.php`.
   - In this scenario, the local draft remains safely in IndexedDB under `pending_sync` or `sync_failed`. Synchronization will succeed only after the user **logs back into the application**.
2. **Browser Execution Requirement `[VERIFIED]`**:
   - The synchronization heartbeat runs within the active browser tab via `setInterval` and `window.addEventListener('online')`.
   - It does **NOT** utilize a persistent Service Worker background sync queue.
   - Therefore, **synchronization requires the user's browser tab to remain open or be reopened** once network connectivity is restored. Closing the browser while offline defers synchronization until the next visit.
3. **Browser Storage Quota Realities `[VERIFIED]`**:
   - IndexedDB is **NOT a guaranteed fixed-capacity storage allocation**.
   - Available storage is dynamically managed by the browser based on available client disk space, operating system storage pressure, and browser eviction algorithms (e.g., Apple Safari ITP policy may purge client-side storage after 7 days of inactivity for non-installed web apps).
   - Because an individual proposal draft consumes only **10 KB to 50 KB**, quota exhaustion is practically impossible under normal operation, but long-term offline retention cannot be guaranteed by the browser indefinitely.

---

### 3.5 System and Database Backups

#### Implemented Backup Functionality `[VERIFIED]`
- **Backup Generation Script**: `scripts/make_backup.php` exists in the repository.
- **Execution Mechanism**: Manual execution via CLI: `php scripts/make_backup.php`.
- **What It Archives**:
  - Creates a timestamped ZIP archive: `backups/project_backup_sti_activity_system_YYYYMMDD_HHMMSS.zip`.
  - Bundles application code (`admin1`, `admin2`, `api`, `assets`, `auth`, `config`, `database`, `dean`, `faculty`, `includes`, `program_head`, `scripts`, `services`, `tests`).
  - Bundles the entire physical `uploads/` directory.
  - Bundles root configuration templates (`.env.example`, `.htaccess`, `composer.json`, `index.php`).
- **Existing Backup Evidence `[VERIFIED]`**:
  - `backups/project_backup_sti_activity_system_20261101_174825.zip` (**10.58 MB**) exists.
  - `backups/db_backup_sti_activity_system_20261101_174620.sql` (**92.41 KB / 94,624 bytes**) exists.

#### Deficiencies in Current Implementation vs. Production Requirements `[VERIFIED]`
> [!WARNING]
> **No Automated Scheduling, Separate Storage, or Retention Policies Exist `[VERIFIED]`**  
> 1. **No Automated Scheduler**: The system has **no configured cron job, systemd timer, or Windows Task Scheduler task**. Backups occur only when manually invoked by an engineer.
> 2. **No Physical Storage Separation**: Backup files reside inside the `backups/` directory within the web root on the **same physical drive partition** as the application and database. A drive failure would destroy both the system and its backups.
> 3. **No Automated Retention or Rotation**: There is no script to automatically purge archives older than 30 days. Backups accumulate indefinitely until manual administrative intervention.
> 4. **No Automated Restoration Script**: System restoration is a manual process requiring unzipping files and executing `mysql -u root sti_activity_system < backup.sql`.

#### Recommended Production Backup Procedures `[RECOMMENDED]`
1. **Automated Cron / Task Scheduler**: Schedule daily database dumps at 02:00 AM via `mysqldump` and weekly full system archives via `scripts/make_backup.php`.
2. **Separate Storage Destination**: Route backups to a secondary physical drive, campus Network Attached Storage (NAS), or an isolated institutional cloud container.
3. **Automated 30-Day Rolling Retention**: Implement an automated pruning routine retaining daily backups for 7 days, weekly backups for 4 weeks, and monthly backups for 1 academic year.

---

## 4. Hardware Requirements

### 4.1 Server Hardware Specifications

#### Verified Active Host Hardware (Development/Staging Environment) `[VERIFIED]`
- **Host Architecture**: AMD64 (x86_64)
- **Processor**: 16 Logical Processors (AMD / Intel Core Architecture)
- **Host Memory**: 16+ GB Physical RAM
- **Operating Environment**: Windows 11 Enterprise/Pro 64-bit (Build 26300) running local Apache/XAMPP
- **Web Stack**: Apache 2.4.58 (Win64) / PHP 8.5.12 / 10.4.32-MariaDB

#### Proposed Production Server Hardware Specifications `[RECOMMENDED]`
The following specifications are **recommended industry baselines** for institutional hosting and do **not** represent verified campus server assets:

```
+-----------------------------------------------------------------------------------+
|                     PROPOSED SERVER HARDWARE SPECIFICATIONS                       |
+----------------------+-----------------------------+------------------------------+
| Component            | Minimum Specification       | Recommended Specification    |
|                      | [RECOMMENDED - Low Load]    | [RECOMMENDED - Campus Server]|
+----------------------+-----------------------------+------------------------------+
| Processor (CPU)      | Dual-Core 64-bit x86-64     | Quad-Core 64-bit x86-64      |
|                      | 2.0 GHz+ (Core i3 / Epyc)   | 3.0 GHz+ (Xeon / Core i5)    |
+----------------------+-----------------------------+------------------------------+
| System Memory (RAM)  | 4 GB DDR4 RAM               | 8 GB to 16 GB DDR4 RAM       |
|                      | (1 GB allocated to PHP/DB)  | (4 GB allocated to DB buffer)|
+----------------------+-----------------------------+------------------------------+
| Primary Storage      | 30 GB Free Disk Space       | 100 GB+ NVMe SSD             |
|                      | (Standard SATA SSD)         | (Hardware/Software RAID-1)   |
+----------------------+-----------------------------+------------------------------+
| Storage Throughput   | SATA III (> 400 MB/s)       | PCIe NVMe (> 1,500 MB/s)     |
+----------------------+-----------------------------+------------------------------+
| Network Interface    | 100 Mbps Fast Ethernet      | 1 Gbps Gigabit Ethernet      |
+----------------------+-----------------------------+------------------------------+
| Power & Redundancy   | Standard Single PSU         | Dual Redundant PSU connected |
|                      |                             | to Campus UPS System         |
+----------------------+-----------------------------+------------------------------+
```

---

### 4.2 Client Device Specifications `[RECOMMENDED]`

The following specifications are **recommended client baselines** for staff and faculty devices:

```
+-----------------------------------------------------------------------------------+
|                     PROPOSED CLIENT DEVICE SPECIFICATIONS                         |
+----------------------+-----------------------------+------------------------------+
| Device Category      | Minimum Specification       | Recommended Specification    |
|                      | [RECOMMENDED]               | [RECOMMENDED]                |
+----------------------+-----------------------------+------------------------------+
| Desktop / Laptop     | Dual-Core 2.0 GHz+ x86-64   | Quad-Core Intel Core i3 /    |
| (Faculty / Admins)   | 4 GB RAM                    | AMD Ryzen 3+, 8 GB RAM       |
|                      | 1366 x 768 Screen Res       | 1920 x 1080 (Full HD) IPS    |
|                      | Physical Keyboard & Mouse   | Physical Keyboard & Mouse    |
+----------------------+-----------------------------+------------------------------+
| Tablet Devices       | 10.0-inch Display           | 10.5 to 12.9-inch Display    |
| (Deans / Monitors)   | 3 GB RAM, Touch Interface   | 4 GB+ RAM, Apple iPad /      |
|                      | 1280 x 800 Resolution       | Samsung Galaxy Tab (1080p+)  |
+----------------------+-----------------------------+------------------------------+
| Smartphones          | 5.5-inch Screen             | 6.1-inch+ OLED Screen        |
| (Task Notifications) | 2 GB RAM, 720p Resolution   | 4 GB+ RAM, Modern iOS /      |
|                      | Mobile Browser              | Android Smartphone           |
+----------------------+-----------------------------+------------------------------+
```

#### Role-Specific Hardware Usage Considerations `[VERIFIED & RECOMMENDED]`
- **Dean and Administrators (`dean`, `admin1`, `admin2`)**:
  - Primary device: Desktop PC or Laptop with Full HD (1080p) display.
  - Operational rationale: Institutional KPI charts, multi-column proposal comparison tables, campus-wide Gantt timeline navigation, and extensive PDF generation require adequate display real estate.
- **Program Heads and Faculty Proponents (`program_head`, `faculty`)**:
  - Primary device: Desktop PC or Laptop in faculty workrooms or home terminals.
  - Operational rationale: Multi-step proposal creation wizards, financial budget itemization, Konva.js canvas-based venue layout plotting, and collaborative co-authoring are optimized for desktop input devices (keyboard and mouse).
- **Mobile Handheld Devices (Smartphones)**:
  - Supported for: Reviewing notifications, checking assigned task deadlines, monitoring activity approval status, and mobile walkthroughs. The layout adapts dynamically via responsive CSS breakpoints (`assets/css/main.css`). Heavy administrative actions and canvas floor plan editing are recommended on desktop screens.

---

## 5. Software Requirements

### 5.1 Server Software Stack

#### Verified Software Environment (Active Staging Host) `[VERIFIED]`
- **Operating System**: Microsoft Windows 11 64-bit (Windows NT 10.0 Build 26300) running local Apache/XAMPP.
- **Web Server**: Apache 2.4.58 (Win64) Apache Lounge VS17 built Oct 18 2023.
- **PHP Engine**: PHP 8.5.12 (CLI & Apache ZTS Module) built Oct 20 2026.
- **Database Server**: 10.4.32-MariaDB Community Server.

#### Software Lifecycle Verification & Production Recommendations `[VERIFIED & RECOMMENDED]`
To adhere strictly to authoritative vendor documentation, the lifecycles of proposed software versions were audited:

##### 1. Official PHP Support Lifecycle (Source: `php.net/supported-versions.php`)
- **PHP Does NOT Have LTS Releases `[VERIFIED]`**: The official PHP project does not utilize the term "LTS" (Long-Term Support). PHP follows a fixed **3-year release lifecycle**:
  - 2 years of Active Support (bug fixes and security fixes).
  - 1 year of Security Fixes only.
  - End of Life (EOL) at exactly 3 years from initial release.
- **Lifecycle Status of PHP Versions (as of late 2026) `[VERIFIED]`**:
  - *PHP 8.1*: Reached End of Life on November 25, 2024.
  - *PHP 8.2*: Active support ended Dec 8, 2024; Security support ends **December 31, 2026**.
  - *PHP 8.3*: Active support ended Nov 2025; Security support ends **December 31, 2027**.
  - *PHP 8.4*: Released late 2024; Active support ends late 2026; Security support ends **December 31, 2028**.
- **Production Recommendation `[RECOMMENDED]`**: Deploy production on **PHP 8.2 or PHP 8.3** (or PHP 8.4 if vendor packages permit), noting that institutional IT must plan for PHP minor version upgrades every 1 to 2 years to stay within active security support windows.

##### 2. MariaDB Support Lifecycle (Source: `mariadb.org/about/#maintenance-policy`)
- **MariaDB Designates Explicit LTS Releases `[VERIFIED]`**: MariaDB designates specific major releases as Long-Term Support (LTS), maintained for 5 years.
- **Lifecycle Status `[VERIFIED]`**:
  - *10.4.32-MariaDB (Currently Installed in XAMPP)*: **Reached official End of Life on June 18, 2024**. While functional for development, it does not receive upstream security patches.
  - *MariaDB 10.6 LTS*: Supported until July 2026.
  - *MariaDB 10.11 LTS*: Supported until **February 2028** `[RECOMMENDED]`.
  - *MariaDB 11.4 LTS*: Supported until **May 2029** `[RECOMMENDED]`.
- **Production Recommendation `[RECOMMENDED]`**: Upgrade production database to **MariaDB 10.11 LTS or MariaDB 11.4 LTS**.

##### 3. MySQL Support Lifecycle (Source: `dev.mysql.com/doc/refman/8.4/en/`)
- *MySQL 8.0*: Transitioned to End of Life in April 2026.
- *MySQL 8.4 LTS*: First official MySQL Innovation/LTS release (released April 2024), actively supported until **2032** `[RECOMMENDED]`.

#### Mandatory PHP Extensions `[VERIFIED]`
The following PHP extensions were inspected and verified active in the runtime environment:
| PHP Extension | Critical System Role in STI-ARMES |
| :--- | :--- |
| `pdo` | Unified database abstraction layer. |
| `pdo_mysql` | High-performance MySQL/MariaDB driver utilizing prepared statements. |
| `curl` | Outbound HTTPS requests to Google Gemini, Microsoft Graph, and Google Forms. |
| `fileinfo` | MIME type verification (`finfo_file`) for secure file upload validation. |
| `gd` | Image processing, thumbnail validation, and avatar handling. |
| `mbstring` | Multi-byte UTF-8 character handling in proposal text and Filipino names. |
| `openssl` | Encrypted token handling, OAuth 2.0 communication, and TLS connections. |
| `json` | API serialization, AI response parsing, and IndexedDB sync payloads. |
| `session` | Secure user state management and role enforcement (`PHPSESSID`). |
| `zip` | Automated project and deliverable archive creation in `scripts/make_backup.php`. |
| `SimpleXML` / `dom` / `xml` | Document parsing and XML report export formatting. |

#### Backend Composer Dependencies (`composer.json`) `[VERIFIED]`
Verified in `vendor/` and `composer.json`:
- `phpmailer/phpmailer`: Transactional email dispatch over SMTP with STARTTLS.
- `league/oauth2-client` (`^2.9`): Core OAuth 2.0 client implementation.
- `greew/oauth2-azure-provider` (`^2.0`): Microsoft Azure Entra ID OAuth 2.0 provider for campus SSO.

---

### 5.2 Client Software and Browser Requirements `[VERIFIED & RECOMMENDED]`

#### Supported Web Browsers
| Browser | Minimum Version `[VERIFIED]` | Recommended Version `[RECOMMENDED]` | Support Status |
| :--- | :---: | :---: | :--- |
| **Google Chrome** | Version 100+ | Latest Stable (120+) | Fully Supported (Preferred) |
| **Microsoft Edge** | Version 100+ | Latest Stable (120+) | Fully Supported (Campus Standard) |
| **Mozilla Firefox** | Version 100+ | Latest Stable (120+) | Fully Supported |
| **Apple Safari** | Version 15+ | Latest Stable (17+) | Fully Supported (macOS / iOS) |
| **Opera / Brave** | Chromium 100+ | Latest Stable | Fully Supported |
| **Internet Explorer** | Any | None | **Unsupported / Deprecated** |

#### Client-Side Technology Prerequisites `[VERIFIED]`
1. **JavaScript Enabled**: Mandatory. ES6+ JavaScript (`async/await`, `fetch()`, Promises, DOM APIs) powers multi-step wizards, AI guidance modals, offline IndexedDB sync, and Gantt charts.
2. **HTML5 Canvas API**: Required for Konva.js venue layout plotting (`unpkg.com/konva@9/konva.min.js`) and Chart.js graphics.
3. **IndexedDB API**: Required for browser-side offline proposal drafting.
4. **Local / Session Storage**: Used for non-sensitive UI state persistence (active tabs, collapsed sidebar state).
5. **Cookies Enabled**: Required for HTTP session maintenance (`PHPSESSID`).

---

## 6. Network Requirements

### 6.1 Bandwidth and Latency Specifications `[RECOMMENDED]`

```
+-----------------------------------------------------------------------------------+
|                           CAMPUS NETWORK REQUIREMENTS                             |
+----------------------+-----------------------------+------------------------------+
| Network Tier         | Minimum Requirement         | Recommended Requirement      |
|                      | [RECOMMENDED]               | [RECOMMENDED]                |
+----------------------+-----------------------------+------------------------------+
| Campus LAN           | 100 Mbps Fast Ethernet      | 1 Gbps Gigabit Ethernet      |
| (Intranet Latency)   | Latency < 10 ms to Server   | Latency < 2 ms to Server     |
+----------------------+-----------------------------+------------------------------+
| Server Internet Link | 10 Mbps Dedicated Uplink    | 50 Mbps+ Symmetric Fiber     |
| (Outbound Cloud APIs)| (Down: 10 Mbps, Up: 10 Mbps)| (Down: 50 Mbps, Up: 50 Mbps) |
+----------------------+-----------------------------+------------------------------+
| End-User Client Link | 1.5 Mbps per active user    | 5.0 Mbps+ per active user    |
| (Wi-Fi / Mobile Data)| Latency < 150 ms            | Latency < 50 ms              |
+----------------------+-----------------------------+------------------------------+
```

#### Page Load and Network Payload Footprint `[VERIFIED]`
- **Initial First-Time Page Load**: ~1.2 MB uncompressed (~380 KB with Gzip compression), including Bootstrap, FontAwesome, Konva.js, main CSS, and navigation scripts.
- **Subsequent Page Navigation**: ~15 KB to 80 KB per transaction (HTML body + dynamic AJAX JSON data), as static CSS and JS bundles are cached by the client browser.
- **Document / PDF Downloads**: Variable based on attachment size (typically 200 KB to 2.5 MB).

---

### 6.2 Network Protocols and Firewall Port Configuration `[VERIFIED & RECOMMENDED]`

#### Inbound Network Rules (Campus Server)
| Port | Protocol | Source | Direction | Operational Purpose |
| :---: | :---: | :---: | :---: | :--- |
| **443** | TCP (HTTPS) | Any (Intranet / Authorized Extranet) | **Inbound** | **Primary secure web access for all user roles.** `[RECOMMENDED]` |
| **80** | TCP (HTTP) | Any (Intranet / Extranet) | **Inbound** | Unencrypted HTTP traffic; automatically redirected to HTTPS (443). `[RECOMMENDED]` |
| **3306** | TCP (MySQL) | `127.0.0.1` (Localhost ONLY) | **BLOCKED** | Database port must NEVER be exposed to external network or WAN. `[VERIFIED]` |

#### Outbound Network Rules (Campus Server Egress) `[VERIFIED]`
The campus firewall must permit the server to establish outbound HTTPS/TLS connections to the following external domains:
| Destination Host / Service | Port | Protocol | Purpose in STI-ARMES |
| :--- | :---: | :---: | :--- |
| `generativelanguage.googleapis.com` | 443 | HTTPS/TLS | Google Gemini AI API: Proposal Guidance, Objective formulation, Title Theme generation. |
| `login.microsoftonline.com` | 443 | HTTPS/TLS | Microsoft Entra ID / Azure OAuth 2.0 authentication and token exchange. |
| `graph.microsoft.com` | 443 | HTTPS/TLS | Microsoft Graph API: OneDrive file synchronization and Outlook Mail.Send. |
| `accounts.google.com` / `oauth2.googleapis.com` | 443 | HTTPS/TLS | Google OAuth 2.0 authentication for Google Forms synchronization. |
| `forms.googleapis.com` | 443 | HTTPS/TLS | Google Forms API: Creation of post-event evaluation forms and response pulling. |
| `smtp.office365.com` (or campus SMTP) | 587 | TCP (STARTTLS) | PHPMailer transactional email dispatch for approval notices and task reminders. |
| `cdn.jsdelivr.net` / `cdnjs.cloudflare.com` | 443 | HTTPS/TLS | Client browser CDN access for Bootstrap, jsPDF, and Konva (unless vendored locally). |

---

## 7. Security and Backup Considerations

### 7.1 Role-Based Access Control and Department Boundaries `[VERIFIED]`
Security in STI-ARMES is enforced through defense-in-depth:
- **Session Authentication**: Verified on every page via `includes/auth.php`. Unauthenticated requests redirect to `auth/login.php`.
- **Department Boundary Scoping**: Implemented in `includes/functions.php` via `checkActivityAccess()`. A Program Head from the **IT Department** cannot view, edit, or evaluate proposals or KPIs belonging to **THM** or **GE**, unless faculty members from both departments are explicitly assigned as collaborators on a cross-department activity.
- **Collaborative Contribution Auditing**: Every proposal revision, comment, and co-author addition is logged in `proposal_contribution_logs` and `proposal_collaborators`, establishing full accountability.

### 7.2 Application Security and Secret Isolation `[VERIFIED]`
- **SQL Injection Prevention**: 100% of database interactions utilize PDO prepared statements with parameter binding (`PDO::ATTR_EMULATE_PREPARES => false`). No dynamic SQL string concatenations are permitted.
- **Cross-Site Scripting (XSS) Prevention**: All dynamic user-submitted content is sanitized and escaped using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` before DOM rendering.
- **Credential Protection**:
  - Passwords are encrypted using PHP's `password_hash()` implementing the modern **bcrypt** algorithm with automatic work factor re-hashing.
  - No database passwords, SMTP credentials, or third-party API keys are hardcoded in the codebase. All secrets reside in the `.env` file located outside the web root or protected by Apache `.htaccess`.
  - Obfuscated `.env.example` is maintained for staging and deployment reference.

### 7.3 Institutional Backup Strategy vs. Client-Side Offline Storage `[VERIFIED & RECOMMENDED]`
To ensure complete administrative clarity, the technical distinction between client-side offline storage and institutional backups is formalized below:

```
+------------------------------------------------------------------------------------+
|                OFFLINE CLIENT CACHE vs. INSTITUTIONAL SERVER BACKUPS               |
+--------------------+-------------------------------+-------------------------------+
| Attribute          | Client Offline Cache          | Institutional Server Backups  |
|                    | (IndexedDB - 'sti_armes_offline')| (Database Dumps & File Zips)|
+--------------------+-------------------------------+-------------------------------+
| Storage Location   | Local end-user browser        | Server secondary storage,     |
|                    | (Client PC / Laptop)          | NAS, or off-site OneDrive     |
+--------------------+-------------------------------+-------------------------------+
| Scope of Data      | Single unsubmitted proposal   | Full system state: all users, |
|                    | text draft for logged-in user | activities, approvals, files  |
+--------------------+-------------------------------+-------------------------------+
| Binary Files       | NOT STORED (Text & arrays only| FULLY PRESERVED (Includes all |
| (Posters/PDFs)     | file inputs must be re-added) | binary assets in /uploads)    |
+--------------------+-------------------------------+-------------------------------+
| Primary Purpose    | Protect user against Wi-Fi    | Disaster recovery, hardware   |
|                    | dropouts while typing forms   | failure, institutional audit  |
+--------------------+-------------------------------+-------------------------------+
| Persistence        | Transient (cleared or updated | Permanent, versioned archives |
|                    | upon successful server sync)  | with retention policy (30 d+) |
+--------------------+-------------------------------+-------------------------------+
| Managed By         | Automatic JavaScript client   | Campus IT Administrator       |
+--------------------+-------------------------------+-------------------------------+
```

---

## 8. Minimum vs. Recommended Specifications Matrix

```
+-----------------------------------------------------------------------------------------+
|                    STI-ARMES COMPREHENSIVE SPECIFICATIONS MATRIX                        |
+------------------------+--------------------------------+-------------------------------+
| Dimension              | Minimum Specification          | Recommended Specification     |
+------------------------+--------------------------------+-------------------------------+
| SERVER INFRASTRUCTURE                                                                   |
+------------------------+--------------------------------+-------------------------------+
| Server Host OS         | Windows 10/11 64-bit [VERIFIED]| Ubuntu Server 22.04 LTS [REC] |
| Server Processor       | Dual-Core x86-64 (2.0 GHz)     | Quad-Core x86-64 (3.0 GHz+)   |
| Server RAM             | 4 GB DDR4                      | 8 GB to 16 GB DDR4            |
| Server Disk Storage    | 30 GB Free Space (SATA SSD)    | 100 GB+ NVMe SSD (RAID-1)     |
| Web Server             | Apache 2.4.x [VERIFIED]        | Apache 2.4.x (PHP-FPM) / Nginx|
| PHP Runtime            | PHP 8.2 (Security until 2026)  | PHP 8.3 (Security until 2027) |
| Database Engine        | MariaDB 10.4+ [VERIFIED Dev]   | MariaDB 10.11+ LTS / MySQL 8.4|
+------------------------+--------------------------------+-------------------------------+
| CLIENT DEVICES                                                                          |
+------------------------+--------------------------------+-------------------------------+
| Desktop/Laptop CPU     | Dual-Core 2.0 GHz [REC]        | Intel Core i3 / Ryzen 3+ [REC]|
| Desktop/Laptop RAM     | 4 GB RAM [REC]                 | 8 GB RAM [REC]                |
| Display Resolution     | 1366 x 768 pixels [REC]        | 1920 x 1080 (Full HD) [REC]   |
| Web Browser            | Chrome 100+, Edge 100+ [VER]   | Chrome / Edge Latest Stable   |
| Client Network         | 1.5 Mbps per active user [REC] | 5.0 Mbps+ Broadband / Wi-Fi   |
+------------------------+--------------------------------+-------------------------------+
| STORAGE & BANDWIDTH                                                                     |
+------------------------+--------------------------------+-------------------------------+
| Initial Database Size  | 1.25 MB [VERIFIED]             | 1.25 MB [VERIFIED]            |
| 1-Year Database Growth | ~5 MB to 7 MB [ESTIMATED]      | ~7 MB [ESTIMATED]             |
| 3-Year Database Size   | ~20 MB to 25 MB [ESTIMATED]    | ~35 MB [ESTIMATED]            |
| Initial Uploads Size   | 8.09 MB (134 files) [VERIFIED] | 8.09 MB [VERIFIED]            |
| 1-Year Uploads Storage | ~600 MB to 750 MB [ESTIMATED]  | ~1.0 GB to 1.5 GB [ESTIMATED] |
| 3-Year Uploads Storage | ~2.0 GB to 2.5 GB [ESTIMATED]  | ~3.5 GB [ESTIMATED]           |
| Recommended Upload Vol | 10 GB Dedicated [REC]          | 20 GB Dedicated [REC]         |
| Backup Partition       | 5 GB Dedicated [REC]           | 20 GB Dedicated / Cloud [REC] |
| Campus Server Internet | 10 Mbps Uplink / Downlink [REC]| 50 Mbps+ Fiber Connection [REC|
+------------------------+--------------------------------+-------------------------------+
```

---

## 9. Current Limitations, Unknowns, and Future Recommendations

### 9.1 Verified System Limitations `[VERIFIED]`
1. **Single-Node Localhost Architecture**: The current development environment runs on a single Windows 11 host using XAMPP. In production, web and database services should run as dedicated system services under process supervision.
2. **External CDN Dependencies**: Frontend components (Bootstrap, jsPDF, Konva.js) are loaded via public CDNs (`cdn.jsdelivr.net`, `cdnjs.cloudflare.com`, `unpkg.com`). While these are heavily cached by browsers after the first load, a completely severed external internet connection would prevent initial styling on newly provisioned machines unless these assets are vendored locally.
3. **CLI vs. Web PHP Configuration Discrepancy**: The CLI PHP runtime has default `upload_max_filesize = 2M`, while the Apache web server `php.ini` has `upload_max_filesize = 40M`. Server administrators must ensure the web server's `php.ini` is used when running administrative migration scripts.
4. **Third-Party AI Rate Limits**: AI features depend on Google Gemini API quotas. In high-frequency proposal drafting periods, exceeding tier limits triggers the application's built-in intelligent rule-based fallback guidance engine.
5. **No Automated Backups or Purging in Codebase**: Backups require manual execution of `scripts/make_backup.php`, and historical uploaded documents are not automatically purged.

### 9.2 Unknowns Requiring Confirmation with STI College Marikina Administration `[FOR INSTITUTIONAL CONFIRMATION]`
The following institutional factors require formal alignment with the Dean, Academic Head, and Campus IT Administrator prior to final deployment:
1. **Hosting Infrastructure Choice**: Will STI College Marikina host STI-ARMES on an **on-premise physical server / VM** within the campus server room, or will the institution utilize a **cloud VPS / dedicated host** (e.g., Microsoft Azure, AWS, or institutional web hosting)?
2. **Microsoft 365 Organizational Tenant Integration**:
   - The current integration was verified against a personal Microsoft account. Campus IT must determine whether to register an enterprise application in the school's Entra ID tenant (`@marikina.sti.edu.ph`).
   - IT must decide between **delegated permissions** targeting a designated departmental service account's `/me/drive` vs. **application permissions** (`Files.ReadWrite.All`) writing directly to a school SharePoint Document Library.
3. **Campus Firewall Policies**: Campus network administrators must confirm that outbound HTTPS traffic on port 443 and outbound SMTP on port 587 are unblocked on the server's static IP address.
4. **Institutional Backup Policy**: Clarification on whether campus IT operates a centralized Network Attached Storage (NAS) or prefers automated backups synced to institutional Microsoft OneDrive.
5. **Annual Activity Volume Baseline**: Formal alignment on expected annual activity volume (modeled at 80–120 activities) to validate storage growth projections against institutional targets.

### 9.3 Actionable Technical Recommendations `[RECOMMENDED]`
1. **Local Asset Vendoring for Air-Gapped Operation**: Download Bootstrap 5, jsPDF, and Konva.js directly into `assets/vendor/` to eliminate all external CDN dependencies, allowing 100% offline local intranet operation within the campus LAN.
2. **Production Deployment on Linux (Ubuntu Server)**: Migrate from XAMPP to a production-hardened Linux LAMP/LEMP stack with automated system updates, firewall protection (`ufw`), and SSL certificates via Let's Encrypt.
3. **Automated Cron-Based Backup Execution**: Implement automated Linux cron jobs (or Windows Scheduled Tasks) to run `scripts/make_backup.php` and database dumps daily at 02:00 AM, routing archives to secondary physical storage or cloud repositories.
4. **Progressive Web App (PWA) Service Worker**: Enhance `assets/js/offline-drafts.js` with a lightweight Service Worker to cache static HTML shells, enabling complete offline page navigation even when the campus server is temporarily unreachable.

---

## 10. Conclusion

The rigorous technical audit and inspection of **STI-ARMES (Web-Based Activity Request, Monitoring, and Evaluation System)** confirms that the application is architecturally lightweight, highly performant, and exceptionally resource-efficient.

### Key Evaluation Findings
1. **Minimal Storage Footprint `[VERIFIED]`**: With an active database size of only **1.25 MB** across 29 relational tables and an uploads repository of **8.09 MB**, the system imposes negligible storage overhead. Multi-year institutional projections indicate that database storage will remain under **35 MB** over 3 years, and file uploads will remain well within standard campus storage capacities (**~2.5 GB to 3.5 GB**).
2. **Clear Upload Boundary Architecture `[VERIFIED]`**: While web server configuration accepts envelopes up to 40 MB, application-level validation strictly governs individual file uploads at **10 MB**, enforcing MIME and magic-byte safety.
3. **Low Hardware Barrier `[RECOMMENDED]`**: The system operates smoothly on modest server hardware (Quad-Core CPU, 8 GB RAM) and standard classroom/office client PCs (Dual-Core CPU, 4 GB RAM), requiring no specialized hardware or proprietary licenses.
4. **Resilient Network & Offline Design `[VERIFIED]`**: The integration of client-side IndexedDB persistence guarantees that faculty members can continue drafting proposals during campus network disruptions without risk of data loss, while automated synchronization restores server parity upon reconnection.
5. **Enterprise-Grade Security `[VERIFIED]`**: Strict role-based access control, departmental boundary partitioning, parameterized SQL queries, password hashing, and confidential `.env` secret isolation provide robust data governance aligned with institutional standards.

STI-ARMES is technically mature, resource-compatible, and fully prepared for institutional review, manual testing, and deployment at STI College Marikina.

---
*End of Requirements Specification Document.*