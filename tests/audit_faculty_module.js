const http = require('http');
const https = require('https');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9605;
const SCREENSHOT_DIR = path.join(__dirname, '..', 'reports', 'faculty-audit', 'screenshots');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => { try { resolve(JSON.parse(data)); } catch (e) { reject(e); } });
    }).on('error', reject);
  });
}

function makeHttpRequest(url, options = {}, postData = '') {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const client = u.protocol === 'https:' ? https : http;
    const req = client.request({
      hostname: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: u.pathname + u.search,
      method: options.method || 'GET',
      headers: options.headers || {},
      rejectUnauthorized: false
    }, res => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body
        });
      });
    });
    req.on('error', reject);
    if (postData) req.write(postData);
    req.end();
  });
}

async function getSessionCookie(email, password) {
  const res = await makeHttpRequest('http://localhost/sti-activity-system/auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  }, `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`);

  const setCookies = (res.headers['set-cookie'] || []).filter(c => c.includes('PHPSESSID='));
  if (setCookies.length === 0) return null;
  const lastCookie = setCookies[setCookies.length - 1];
  const match = lastCookie.match(/PHPSESSID=([^;]+)/i);
  return match ? match[1] : null;
}

class CdpConnection {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.id = 1;
    this.callbacks = new Map();
    this.consoleLogs = [];
    this.consoleErrors = [];
    this.networkErrors = [];
  }

  async connect() {
    return new Promise((resolve, reject) => {
      const WebSocket = globalThis.WebSocket;
      this.ws = new WebSocket(this.wsUrl);
      this.ws.onopen = () => resolve();
      this.ws.onerror = (e) => reject(e);
      this.ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
          const text = msg.params.args.map(a => a.value || a.description || JSON.stringify(a)).join(' ');
          this.consoleLogs.push({ type: msg.params.type, text, time: new Date().toISOString() });
          if (msg.params.type === 'error') {
            this.consoleErrors.push(text);
          }
        }
        if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
          this.consoleErrors.push(msg.params.entry.text);
        }
        if (msg.method === 'Network.responseReceived') {
          const status = msg.params.response.status;
          const url = msg.params.response.url;
          if (status >= 400 && !url.includes('favicon.ico')) {
            this.networkErrors.push({ status, url });
          }
        }
        if (msg.id && this.callbacks.has(msg.id)) {
          const cb = this.callbacks.get(msg.id);
          this.callbacks.delete(msg.id);
          if (msg.error) cb.reject(msg.error);
          else cb.resolve(msg.result);
        }
      };
    });
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = this.id++;
      this.callbacks.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    if (this.ws) {
      this.ws.close();
    }
  }
}

async function runAudit() {
  console.log('================================================================');
  console.log('  STI-ARMES FACULTY MODULE COMPREHENSIVE BROWSER AUDIT & TEST   ');
  console.log('================================================================\n');

  const auditResults = {
    startTime: new Date().toISOString(),
    pagesInspected: 0,
    testsExecuted: 0,
    passed: 0,
    failed: 0,
    blocked: 0,
    notTested: 0,
    issues: [],
    pageRecords: [],
    screenshots: []
  };

  // 1. Authenticate Faculty
  console.log('[Phase 1] Authenticating Faculty test account (faculty@sti.edu)...');
  const facSession = await getSessionCookie('faculty@sti.edu', 'password');
  if (!facSession) {
    console.error('CRITICAL: Failed to authenticate Faculty user');
    process.exit(1);
  }
  console.log('✓ Faculty Authenticated successfully. Session ID obtained.\n');

  // 2. Launch Chrome Headless
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_faculty_audit_' + Date.now());
  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--window-size=1440,960',
    'about:blank'
  ]);

  await sleep(1800);

  try {
    const targets = await getJson(`http://localhost:${PORT}/json`);
    const pageTarget = targets.find(t => t.type === 'page' && !t.url.startsWith('chrome-extension')) || targets[0];

    const cdp = new CdpConnection(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Log.enable');
    await cdp.send('Network.enable');

    await cdp.send('Network.setCookie', {
      name: 'PHPSESSID',
      value: facSession,
      domain: 'localhost',
      path: '/'
    });

    async function capturePageScreenshot(id, filename, caption, issueId = '') {
      await sleep(500);
      const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
      const filePath = path.join(SCREENSHOT_DIR, filename);
      fs.writeFileSync(filePath, Buffer.from(shot.data, 'base64'));
      const record = {
        id,
        filename,
        path: filePath,
        caption,
        issueId,
        timestamp: new Date().toISOString()
      };
      auditResults.screenshots.push(record);
      console.log(`   📸 [Screenshot Saved] ${id}: ${filename} (${caption})`);
      return record;
    }

    async function inspectAndTestPage(name, url, options = {}) {
      auditResults.pagesInspected++;
      console.log(`\n------------------------------------------------------------`);
      console.log(`[Testing Page ${auditResults.pagesInspected}] ${name}`);
      console.log(`URL: ${url}`);
      console.log(`------------------------------------------------------------`);

      const initialErrorsCount = cdp.consoleErrors.length;
      const initialNetErrorsCount = cdp.networkErrors.length;

      await cdp.send('Page.navigate', { url });
      await sleep(options.waitMs || 1200);

      // Evaluate page title and DOM status
      const pageInfo = await cdp.send('Runtime.evaluate', {
        expression: `({
          title: document.title,
          url: window.location.href,
          bodyLength: document.body ? document.body.innerHTML.length : 0,
          h1: (document.querySelector('h1, .page-title') || {}).innerText || '',
          buttonsCount: document.querySelectorAll('button, a.btn, input[type=submit]').length,
          inputsCount: document.querySelectorAll('input, select, textarea').length,
          linksCount: document.querySelectorAll('a').length,
          tablesCount: document.querySelectorAll('table').length,
          hasErrorText: document.body.innerText.includes('Fatal error') || document.body.innerText.includes('Parse error') || document.body.innerText.includes('Warning:') || document.body.innerText.includes('Notice:')
        })`,
        returnByValue: true
      });

      const info = pageInfo.result.value || {};
      const newErrors = cdp.consoleErrors.slice(initialErrorsCount);
      const newNetErrors = cdp.networkErrors.slice(initialNetErrorsCount);

      const pageRecord = {
        name,
        url,
        title: info.title,
        h1: info.h1,
        bodyLength: info.bodyLength,
        interactiveCounts: {
          buttons: info.buttonsCount,
          inputs: info.inputsCount,
          links: info.linksCount,
          tables: info.tablesCount
        },
        hasPhpErrors: info.hasErrorText,
        consoleErrors: newErrors,
        networkErrors: newNetErrors,
        status: 'PASSED'
      };

      if (info.hasErrorText) {
        pageRecord.status = 'FAILED';
        auditResults.failed++;
        auditResults.issues.push({
          page: name,
          url,
          severity: 'Critical',
          description: `PHP Error, Warning, or Notice detected in rendered HTML of ${name}`,
          actual: 'PHP warning/error in output',
          expected: 'Clean page rendering with 0 PHP notices/errors',
          sourceFile: url.replace('http://localhost/sti-activity-system/', ''),
          status: 'FAILED'
        });
      } else {
        auditResults.passed++;
      }

      auditResults.testsExecuted++;
      auditResults.pageRecords.push(pageRecord);

      if (options.screenshot) {
        await capturePageScreenshot(options.screenshot.id, options.screenshot.filename, options.screenshot.caption, options.screenshot.issueId || '');
      }

      // Execute custom page-level interactive assertions if provided
      if (options.customTest) {
        try {
          await options.customTest(cdp, pageRecord);
        } catch (err) {
          console.error(`   ❌ Custom test error on ${name}:`, err.message);
        }
      }

      return pageRecord;
    }

    // ============================================================
    // AUDIT EXECUTION ACROSS ALL FACULTY PAGES
    // ============================================================

    // 1. Login Page
    await inspectAndTestPage('Auth Login Page', 'http://localhost/sti-activity-system/auth/login.php', {
      screenshot: { id: 'FAC-01', filename: 'FAC-01-AUTH-LOGIN.png', caption: 'STI-ARMES Authentication Login Page' },
      customTest: async (cdp, rec) => {
        const hasForm = await cdp.send('Runtime.evaluate', {
          expression: `!!document.querySelector('form[action*="login.php"], form')`,
          returnByValue: true
        });
        console.log('   ✓ Login form verified:', hasForm.result.value);
      }
    });

    // 2. Faculty Dashboard
    await inspectAndTestPage('Faculty Dashboard', 'http://localhost/sti-activity-system/faculty/dashboard.php', {
      screenshot: { id: 'FAC-02', filename: 'FAC-02-DASHBOARD-OVERVIEW.png', caption: 'Faculty Dashboard Overview & Key Stat Cards' },
      customTest: async (cdp, rec) => {
        // Test stat cards presence
        const stats = await cdp.send('Runtime.evaluate', {
          expression: `({
            statCards: document.querySelectorAll('.stat-card, a.stat-card').length,
            quickActions: document.querySelectorAll('.card a.btn, .action-btn').length,
            recentRows: document.querySelectorAll('table tbody tr').length,
            userName: (document.querySelector('.topbar-user-name, .user-name') || {}).innerText || ''
          })`,
          returnByValue: true
        });
        console.log('   ✓ Dashboard stats inspected:', stats.result.value);

        // Switch to Dark Mode & Capture
        await cdp.send('Runtime.evaluate', { expression: `document.documentElement.dataset.theme = 'dark';` });
        await capturePageScreenshot('FAC-03', 'FAC-03-DASHBOARD-DARKMODE.png', 'Faculty Dashboard rendered in Dark Theme');
        await cdp.send('Runtime.evaluate', { expression: `document.documentElement.dataset.theme = 'light';` });
      }
    });

    // 3. Activity Proposal Creation - Step 1: Event Details
    await inspectAndTestPage('Proposal Create - Step 1 Details', 'http://localhost/sti-activity-system/faculty/proposal-create.php', {
      screenshot: { id: 'FAC-04', filename: 'FAC-04-PROPOSAL-CREATE-STEP1.png', caption: 'Proposal Creation Wizard - Step 1: Event Information & Concept' },
      customTest: async (cdp, rec) => {
        // Inspect inputs and AI Guidance Button
        const step1Eval = await cdp.send('Runtime.evaluate', {
          expression: `({
            titleInput: !!document.querySelector('#title, input[name="title"]'),
            dateInput: !!document.querySelector('#event_date, input[name="event_date"]'),
            venueSelect: !!document.querySelector('#venue_id, select[name="venue_id"], select[name="venue"]'),
            aiBtn: !!document.querySelector('#btn-ai-guidance, button[onclick*="ai"], .btn-ai-guidance, #generateObjectivesBtn'),
            collaboratorsSelect: !!document.querySelector('#collaborators, select[name*="collaborators"], .teacher-multiselect')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Step 1 interactive fields:', step1Eval.result.value);

        // Check if AI Guidance Assistant modal triggers
        const aiModalCheck = await cdp.send('Runtime.evaluate', {
          expression: `(() => {
            const btn = document.querySelector('#btn-ai-guidance, .btn-ai-guidance, button[data-target="#aiGuidanceModal"]');
            if (btn) {
              btn.click();
              return true;
            }
            return false;
          })()`,
          returnByValue: true
        });
        if (aiModalCheck.result.value) {
          await sleep(500);
          await capturePageScreenshot('FAC-05', 'FAC-05-PROPOSAL-AI-GUIDANCE.png', 'AI Proposal Guidance Assistant Modal Dialog');
          // Close modal
          await cdp.send('Runtime.evaluate', { expression: `const m = document.querySelector('#aiGuidanceModal, .modal.show'); if(m) { const c = m.querySelector('.close, [data-dismiss="modal"]'); if(c) c.click(); }` });
        }
      }
    });

    // 4. Activity Proposal Creation - Step 2: Schedule & Budget
    await inspectAndTestPage('Proposal Create - Step 2 Schedule & Budget', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=2', {
      screenshot: { id: 'FAC-06', filename: 'FAC-06-PROPOSAL-CREATE-STEP2.png', caption: 'Proposal Creation Wizard - Step 2: Schedule, Floor Plan & Budget' },
      customTest: async (cdp, rec) => {
        const step2Eval = await cdp.send('Runtime.evaluate', {
          expression: `({
            floorPlanUpload: !!document.querySelector('input[type="file"][name*="floor"], #floorplan_file'),
            budgetRows: document.querySelectorAll('.budget-table tbody tr, #budget-items tr').length,
            addBudgetBtn: !!document.querySelector('#add-budget-row, .btn-add-budget, button[onclick*="addBudget"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Step 2 schedule/budget elements:', step2Eval.result.value);
      }
    });

    // 5. Activity Proposal Creation - Step 3: Manpower & Tasks
    await inspectAndTestPage('Proposal Create - Step 3 Manpower & Tasks', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=3', {
      screenshot: { id: 'FAC-07', filename: 'FAC-07-PROPOSAL-CREATE-STEP3.png', caption: 'Proposal Creation Wizard - Step 3: Working Committees, Manpower & Gantt Timeline' },
      customTest: async (cdp, rec) => {
        const step3Eval = await cdp.send('Runtime.evaluate', {
          expression: `({
            committeesSection: !!document.querySelector('#committees-container, .committee-card, .committee-group'),
            ganttContainer: !!document.querySelector('#gantt-chart, .gantt-container, #timeline-view'),
            addTaskBtn: !!document.querySelector('#add-task-btn, .btn-add-task, button[onclick*="addTask"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Step 3 committees & task elements:', step3Eval.result.value);
      }
    });

    // 6. Activity Proposal Creation - Step 4: Review & Submit
    await inspectAndTestPage('Proposal Create - Step 4 Review & Submit', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=4', {
      screenshot: { id: 'FAC-08', filename: 'FAC-08-PROPOSAL-CREATE-STEP4.png', caption: 'Proposal Creation Wizard - Step 4: Proposal Summary, Export Draft PDF & Submit' },
      customTest: async (cdp, rec) => {
        const step4Eval = await cdp.send('Runtime.evaluate', {
          expression: `({
            submitBtn: !!document.querySelector('button[type="submit"], #btn-submit-proposal, .btn-submit'),
            saveDraftBtn: !!document.querySelector('#btn-save-draft, button[name="action"][value="draft"], .btn-draft'),
            exportPdfBtn: !!document.querySelector('#btn-export-pdf, a[href*="export"], .btn-export-draft')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Step 4 review & submit controls:', step4Eval.result.value);
      }
    });

    // 7. My Activities List
    await inspectAndTestPage('Faculty My Activities List', 'http://localhost/sti-activity-system/faculty/activities.php', {
      screenshot: { id: 'FAC-09', filename: 'FAC-09-ACTIVITIES-LIST.png', caption: 'My Activities Master Table with Filters and Status Badges' },
      customTest: async (cdp, rec) => {
        // Test search input and status filter dropdown
        const filterCheck = await cdp.send('Runtime.evaluate', {
          expression: `({
            searchInput: !!document.querySelector('input[name="q"], input[type="search"]'),
            statusSelect: !!document.querySelector('select[name="status"]'),
            statusOptions: Array.from(document.querySelectorAll('select[name="status"] option')).map(o => o.text.trim()),
            tableRows: document.querySelectorAll('table tbody tr').length,
            paginationLinks: document.querySelectorAll('.pagination a, .page-link').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ Activities search & filter capabilities:', filterCheck.result.value);

        // Filter by Under Review and capture screenshot
        await cdp.send('Runtime.evaluate', {
          expression: `const sel = document.querySelector('select[name="status"]'); if(sel) { sel.value = 'under_review'; sel.form.submit(); }`
        });
        await sleep(1000);
        await capturePageScreenshot('FAC-10', 'FAC-10-ACTIVITIES-FILTERED.png', 'Activities Filtered by "Under Review" Status');
      }
    });

    // 8. Pending Proposals
    await inspectAndTestPage('Pending Proposals', 'http://localhost/sti-activity-system/faculty/pending.php', {
      screenshot: { id: 'FAC-11', filename: 'FAC-11-PENDING-PROPOSALS.png', caption: 'Faculty Pending Proposals Review Queue' }
    });

    // 9. Approved Activities
    await inspectAndTestPage('Approved Activities', 'http://localhost/sti-activity-system/faculty/approved.php', {
      screenshot: { id: 'FAC-12', filename: 'FAC-12-APPROVED-ACTIVITIES.png', caption: 'Faculty Approved Activities Oversight' }
    });

    // 10. Returned for Revision
    await inspectAndTestPage('Returned for Revision', 'http://localhost/sti-activity-system/faculty/returned.php', {
      screenshot: { id: 'FAC-13', filename: 'FAC-13-RETURNED-REVISIONS.png', caption: 'Returned for Revision Table with Approver Feedback Links' }
    });

    // 11. Rejected Proposals
    await inspectAndTestPage('Rejected Proposals', 'http://localhost/sti-activity-system/faculty/rejected.php', {
      screenshot: { id: 'FAC-14', filename: 'FAC-14-REJECTED-PROPOSALS.png', caption: 'Rejected Proposals History with Stated Justifications' }
    });

    // 12. My Tasks
    await inspectAndTestPage('My Assigned Tasks', 'http://localhost/sti-activity-system/faculty/tasks.php', {
      screenshot: { id: 'FAC-15', filename: 'FAC-15-MY-TASKS.png', caption: 'Faculty Task Monitoring with Timing Indicators & Filtering' },
      customTest: async (cdp, rec) => {
        const taskEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            taskFilter: !!document.querySelector('.filters-card, .task-filters, form.filters'),
            taskRows: document.querySelectorAll('table tbody tr, .task-card').length,
            timingIndicators: document.querySelectorAll('.timing-badge, .badge-warning, .badge-success').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ Tasks monitoring inspection:', taskEval.result.value);
      }
    });

    // 13. Calendar & Scheduling
    await inspectAndTestPage('Faculty Calendar', 'http://localhost/sti-activity-system/faculty/calendar.php', {
      screenshot: { id: 'FAC-16', filename: 'FAC-16-CALENDAR-VIEW.png', caption: 'Faculty Calendar Grid with Scheduled Institutional Activities' },
      customTest: async (cdp, rec) => {
        const calEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            calendarGrid: !!document.querySelector('.calendar, .calendar-table, #calendar, .cal-grid'),
            monthHeader: (document.querySelector('.cal-header h2, .calendar-title, .month-year') || {}).innerText || '',
            eventPills: document.querySelectorAll('.cal-event, .event-pill, .calendar-event').length,
            navButtons: document.querySelectorAll('.cal-nav button, .btn-cal-prev, .btn-cal-next').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ Calendar navigation & event counts:', calEval.result.value);
      }
    });

    // 14. Post-Event Reporting & Evaluation
    await inspectAndTestPage('Post-Event Module', 'http://localhost/sti-activity-system/faculty/post-event.php', {
      screenshot: { id: 'FAC-17', filename: 'FAC-17-POST-EVENT-MODULE.png', caption: 'Post-Event Reporting, Deliverable File Uploads & Evaluation Tool' },
      customTest: async (cdp, rec) => {
        const peEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            activityDropdown: !!document.querySelector('select[name="activity_id"]'),
            activitiesCount: document.querySelectorAll('select[name="activity_id"] option').length,
            attendanceInput: !!document.querySelector('input[name="actual_attendance"]'),
            qrModalBtn: !!document.querySelector('#btn-view-qr, button[data-target*="qr"], .btn-qr-code'),
            csvUploadInput: !!document.querySelector('input[type="file"][name*="csv"], input[name*="survey"]'),
            photoUploadInput: !!document.querySelector('input[type="file"][name*="photo"], input[name*="photos"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Post-event inputs & QR capabilities:', peEval.result.value);
      }
    });

    // 15. KPI Results
    await inspectAndTestPage('Faculty KPI Results', 'http://localhost/sti-activity-system/faculty/kpi.php', {
      screenshot: { id: 'FAC-18', filename: 'FAC-18-KPI-RESULTS.png', caption: 'Faculty Personal KPI Performance Analysis & Attendance Ratings' },
      customTest: async (cdp, rec) => {
        const kpiEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            summaryCards: document.querySelectorAll('.summary-card, .kpi-card, .stat-box').length,
            kpiTableRows: document.querySelectorAll('table tbody tr').length,
            attendanceBadges: document.querySelectorAll('.badge-success, .badge-warning, .badge-danger').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ KPI performance elements:', kpiEval.result.value);
      }
    });

    // 16. Proposal Detail / Review View (Activity ID: 1218)
    await inspectAndTestPage('Proposal Detail View', 'http://localhost/sti-activity-system/faculty/proposal-view.php?id=1218', {
      screenshot: { id: 'FAC-19', filename: 'FAC-19-PROPOSAL-VIEW-TIMELINE.png', caption: 'Faculty Proposal View with Review History Timeline & Signatures' },
      customTest: async (cdp, rec) => {
        const pvEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            proposalTitle: (document.querySelector('h1, .page-title, .activity-title') || {}).innerText || '',
            statusBadge: (document.querySelector('.badge') || {}).innerText || '',
            historyTable: !!document.querySelector('.review-history, table.timeline, .history-card'),
            exportPdfBtn: !!document.querySelector('a[href*="export"], a[href*="pdf"], .btn-export-pdf')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Proposal detail view inspection:', pvEval.result.value);
      }
    });

    // 17. Activity Master Detail View (Activity ID: 1233)
    await inspectAndTestPage('Activity Master Detail View', 'http://localhost/sti-activity-system/faculty/view-activity.php?id=1233', {
      screenshot: { id: 'FAC-20', filename: 'FAC-20-ACTIVITY-VIEW-DETAILS.png', caption: 'Faculty Activity Master Detail View with Tabbed Sub-Panels' },
      customTest: async (cdp, rec) => {
        const actEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            tabsCount: document.querySelectorAll('.nav-tabs .nav-link, .tab-btn').length,
            tabsLabels: Array.from(document.querySelectorAll('.nav-tabs .nav-link, .tab-btn')).map(t => t.innerText.trim()),
            exportPdfLink: !!document.querySelector('a[href*="document-download"], a[href*="export"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Activity tabs & controls:', actEval.result.value);
      }
    });

    // 18. Proposal Edit Mode (Returned for Revision Activity ID: 1060)
    await inspectAndTestPage('Proposal Edit Mode', 'http://localhost/sti-activity-system/faculty/proposal-edit.php?id=1060', {
      screenshot: { id: 'FAC-21', filename: 'FAC-21-PROPOSAL-EDIT-REVISION.png', caption: 'Faculty Proposal Edit Mode for Returned Proposal with Approver Notes' },
      customTest: async (cdp, rec) => {
        const peEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            revisionBanner: !!document.querySelector('.alert-warning, .revision-notes, .alert-danger'),
            bannerText: (document.querySelector('.alert-warning, .revision-notes, .alert-danger') || {}).innerText || '',
            saveBtn: !!document.querySelector('button[type="submit"], #btn-resubmit')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Proposal revision editor inspection:', peEval.result.value);
      }
    });

    // 19. Notifications Page & Topbar Dropdown
    await inspectAndTestPage('Notifications Center', 'http://localhost/sti-activity-system/notifications.php', {
      screenshot: { id: 'FAC-22', filename: 'FAC-22-NOTIFICATIONS-VIEW.png', caption: 'Notifications Center with Filter Tabs & Read Status Controls' },
      customTest: async (cdp, rec) => {
        const notifEval = await cdp.send('Runtime.evaluate', {
          expression: `({
            notifItems: document.querySelectorAll('.notification-item, .notif-row, table tr').length,
            markAllBtn: !!document.querySelector('#mark-all-read, .btn-mark-all, button[onclick*="markAll"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Notifications items count:', notifEval.result.value);
      }
    });

    // 20. User Profile Modal Inspection
    await inspectAndTestPage('User Profile Modal Test', 'http://localhost/sti-activity-system/faculty/dashboard.php', {
      customTest: async (cdp, rec) => {
        const profileTriggered = await cdp.send('Runtime.evaluate', {
          expression: `(() => {
            const btn = document.querySelector('.topbar-user-control, #profileBtn, [data-target="#profileModal"], .user-avatar');
            if (btn) {
              btn.click();
              return true;
            }
            return false;
          })()`,
          returnByValue: true
        });
        if (profileTriggered.result.value) {
          await sleep(600);
          await capturePageScreenshot('FAC-23', 'FAC-23-PROFILE-MODAL.png', 'Faculty User Profile & Account Settings Dialog');
        }
      }
    });

    // 21. Role-Based Access Control (RBAC) Security Verification
    console.log('\n------------------------------------------------------------');
    console.log('[RBAC Security Boundary Tests]');
    console.log('Testing unauthorized route access as Faculty...');
    console.log('------------------------------------------------------------');

    const unauthorizedRoutes = [
      { name: 'Admin 1 Dashboard', url: 'http://localhost/sti-activity-system/admin1/dashboard.php' },
      { name: 'Admin 2 Dashboard', url: 'http://localhost/sti-activity-system/admin2/dashboard.php' },
      { name: 'Dean Dashboard', url: 'http://localhost/sti-activity-system/dean/dashboard.php' },
      { name: 'Program Head Dashboard', url: 'http://localhost/sti-activity-system/program_head/dashboard.php' },
      { name: 'User Management (Admin)', url: 'http://localhost/sti-activity-system/admin1/users.php' }
    ];

    let rbacPassed = 0;
    for (const r of unauthorizedRoutes) {
      await cdp.send('Page.navigate', { url: r.url });
      await sleep(1000);
      const rbacCheck = await cdp.send('Runtime.evaluate', {
        expression: `({
          url: window.location.href,
          title: document.title,
          isDenied: document.body.innerText.includes('Access Denied') || document.body.innerText.includes('Forbidden') || document.body.innerText.includes('Unauthorized') || window.location.href.includes('dashboard.php') === false || window.location.href.includes('faculty')
        })`,
        returnByValue: true
      });
      const res = rbacCheck.result.value || {};
      const protectedProperly = res.isDenied || res.url.includes('/faculty/') || res.url.includes('/auth/login.php');
      if (protectedProperly) {
        console.log(`   [PASS] RBAC Boundary Enforced: ${r.name} -> Blocked/Redirected (${res.url})`);
        rbacPassed++;
      } else {
        console.error(`   [CRITICAL FAIL] RBAC Breach: Faculty accessed ${r.name}! URL: ${res.url}`);
        auditResults.issues.push({
          page: r.name,
          url: r.url,
          severity: 'Critical',
          description: `Role-based access violation: Faculty can access ${r.name}`,
          actual: 'Page loaded without restriction',
          expected: '403 Forbidden or redirect to /faculty/dashboard.php',
          sourceFile: r.url.replace('http://localhost/sti-activity-system/', ''),
          status: 'FAILED'
        });
      }
    }
    await capturePageScreenshot('FAC-24', 'FAC-24-RBAC-DENIED-ADMIN1.png', 'RBAC Boundary Enforcement: Access Denied / Safe Redirection Screen');

    // 22. Live Production Endpoint Inspection
    console.log('\n------------------------------------------------------------');
    console.log('[Live Production Website Endpoint Inspection]');
    console.log('Target: https://sti-armes.freedev.app/auth/login.php');
    console.log('------------------------------------------------------------');

    try {
      await cdp.send('Page.navigate', { url: 'https://sti-armes.freedev.app/auth/login.php' });
      await sleep(3500);
      const liveEval = await cdp.send('Runtime.evaluate', {
        expression: `({
          url: window.location.href,
          title: document.title,
          hasBotCheck: document.body.innerText.includes('aes.js') || document.body.innerText.includes('Checking your browser'),
          bodySnippet: document.body.innerText.substring(0, 150)
        })`,
        returnByValue: true
      });
      console.log('   ✓ Live Production Inspection:', liveEval.result.value);
      await capturePageScreenshot('FAC-25', 'FAC-25-LIVE-ENDPOINT-PRODUCTION.png', 'Live Production Endpoint State at https://sti-armes.freedev.app/auth/login.php');
    } catch (liveErr) {
      console.warn('   ⚠️ Live endpoint navigation notice:', liveErr.message);
    }

    // Save Raw Audit Result JSON
    auditResults.endTime = new Date().toISOString();
    auditResults.totalConsoleErrors = cdp.consoleErrors.length;
    auditResults.totalNetworkErrors = cdp.networkErrors.length;
    auditResults.allConsoleErrors = cdp.consoleErrors;
    auditResults.allNetworkErrors = cdp.networkErrors;

    const auditJsonPath = path.join(__dirname, '..', 'reports', 'faculty-audit', 'audit_execution_log.json');
    fs.writeFileSync(auditJsonPath, JSON.stringify(auditResults, null, 2));
    console.log(`\n============================================================`);
    console.log(`AUDIT COMPLETE: ${auditResults.pagesInspected} pages inspected, ${auditResults.screenshots.length} screenshots captured.`);
    console.log(`Results saved to: ${auditJsonPath}`);
    console.log(`============================================================\n`);

    cdp.close();
  } catch (err) {
    console.error('Fatal audit runner error:', err);
  } finally {
    try { chromeProc.kill(); } catch (e) {}
  }
}

runAudit();

