const http = require('http');
const https = require('https');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9608;
const SCREENSHOT_DIR = path.join(__dirname, '..', 'reports', 'program-head-it-audit', 'screenshots');

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
  console.log(' STI-ARMES IT PROGRAM HEAD MODULE COMPREHENSIVE BROWSER AUDIT   ');
  console.log('================================================================\n');

  const auditResults = {
    startTime: new Date().toISOString(),
    accountAudited: 'Dave Lord Rubaya (dave@sti.edu | Role: program_head | Department: IT Department | ID: 599)',
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

  // 1. Authenticate IT Program Head
  console.log('[Phase 1] Authenticating IT Program Head test account (dave@sti.edu)...');
  const phSession = await getSessionCookie('dave@sti.edu', 'password');
  if (!phSession) {
    console.error('CRITICAL: Failed to authenticate IT Program Head user (dave@sti.edu)');
    process.exit(1);
  }
  console.log('✓ IT Program Head Authenticated successfully. Session ID obtained.\n');

  // 2. Launch Headless Chrome
  const userDataDir = path.join(__dirname, '..', 'scratch', 'chrome_ph_audit_' + Date.now());
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
      value: phSession,
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
          hasFatalError: document.body.innerText.includes('Fatal error') || document.body.innerText.includes('Parse error'),
          hasWarningNotice: document.body.innerText.includes('PHP Warning:') || document.body.innerText.includes('PHP Notice:')
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
        hasFatalError: info.hasFatalError,
        hasWarningNotice: info.hasWarningNotice,
        consoleErrors: newErrors,
        networkErrors: newNetErrors,
        status: 'PASSED'
      };

      if (info.hasFatalError) {
        pageRecord.status = 'FAILED';
        auditResults.failed++;
        auditResults.issues.push({
          page: name,
          url,
          severity: 'Critical',
          description: `Fatal PHP Error or Parse Error detected in rendered HTML of ${name}`,
          actual: 'Fatal error displayed',
          expected: 'Clean page rendering with 0 PHP errors',
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
    // AUDIT EXECUTION ACROSS ALL IT PROGRAM HEAD PAGES
    // ============================================================

    // 1. Auth Login Page
    await inspectAndTestPage('Auth Login Page', 'http://localhost/sti-activity-system/auth/login.php', {
      screenshot: { id: 'PH-IT-01', filename: 'PH-IT-01-AUTH-LOGIN.png', caption: 'STI-ARMES Authentication Login Page' },
      customTest: async (cdp, rec) => {
        const formCheck = await cdp.send('Runtime.evaluate', {
          expression: `!!document.querySelector('form[action*="login.php"], form')`,
          returnByValue: true
        });
        console.log('   ✓ Login form element present:', formCheck.result.value);
      }
    });

    // 2. IT Program Head Dashboard
    await inspectAndTestPage('IT Program Head Dashboard', 'http://localhost/sti-activity-system/program_head/dashboard.php', {
      screenshot: { id: 'PH-IT-02', filename: 'PH-IT-02-DASHBOARD-OVERVIEW.png', caption: 'IT Program Head Dashboard with Department Overview Statistics' },
      customTest: async (cdp, rec) => {
        const stats = await cdp.send('Runtime.evaluate', {
          expression: `({
            pageTitle: (document.querySelector('h1, .page-title') || {}).innerText || '',
            deptBadge: (document.querySelector('.badge-info, .dept-badge') || {}).innerText || '',
            statCards: document.querySelectorAll('.stat-card, a.stat-card').length,
            tableRows: document.querySelectorAll('table tbody tr').length,
            userName: (document.querySelector('.topbar-user-name, .user-name') || {}).innerText || ''
          })`,
          returnByValue: true
        });
        console.log('   ✓ IT Dashboard verification:', stats.result.value);

        // Switch to Dark Theme & Capture
        await cdp.send('Runtime.evaluate', { expression: `document.documentElement.dataset.theme = 'dark';` });
        await capturePageScreenshot('PH-IT-03', 'PH-IT-03-DASHBOARD-DARKMODE.png', 'IT Program Head Dashboard rendered in Dark Theme');
        await cdp.send('Runtime.evaluate', { expression: `document.documentElement.dataset.theme = 'light';` });
      }
    });

    // 3. Faculty Contributors List
    await inspectAndTestPage('Faculty Contributors List', 'http://localhost/sti-activity-system/program_head/contributors.php', {
      screenshot: { id: 'PH-IT-04', filename: 'PH-IT-04-CONTRIBUTORS-LIST.png', caption: 'IT Department Faculty Contributors, Proposal Totals & Task Counts' },
      customTest: async (cdp, rec) => {
        const contrib = await cdp.send('Runtime.evaluate', {
          expression: `({
            tableTitle: (document.querySelector('h1, .page-title') || {}).innerText || '',
            facultyCount: document.querySelectorAll('table tbody tr').length,
            searchBar: !!document.querySelector('input[name="q"], input[type="search"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Faculty contributors verification:', contrib.result.value);
      }
    });

    // 4. Department Activities List
    await inspectAndTestPage('Department Activities List', 'http://localhost/sti-activity-system/faculty/activities.php', {
      screenshot: { id: 'PH-IT-05', filename: 'PH-IT-05-DEPT-ACTIVITIES.png', caption: 'IT Department Activities Master Table with Status Badges' },
      customTest: async (cdp, rec) => {
        const acts = await cdp.send('Runtime.evaluate', {
          expression: `({
            searchBox: !!document.querySelector('input[name="q"]'),
            statusSelect: !!document.querySelector('select[name="status"]'),
            tableRows: document.querySelectorAll('table tbody tr').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ Department activities verification:', acts.result.value);
      }
    });

    // 5. Proposal Create - Step 1 Details
    await inspectAndTestPage('Proposal Create - Step 1 Details', 'http://localhost/sti-activity-system/faculty/proposal-create.php', {
      screenshot: { id: 'PH-IT-06', filename: 'PH-IT-06-PROPOSAL-CREATE-STEP1.png', caption: 'Program Head Proposal Creation Wizard - Step 1: Event Information' },
      customTest: async (cdp, rec) => {
        const step1 = await cdp.send('Runtime.evaluate', {
          expression: `({
            titleField: !!document.querySelector('#title, input[name="title"]'),
            dateField: !!document.querySelector('#event_date, input[name="event_date"]')
          })`,
          returnByValue: true
        });
        console.log('   ✓ Step 1 interactive fields:', step1.result.value);
      }
    });

    // 6. Proposal Create - Step 2 Schedule & Budget
    await inspectAndTestPage('Proposal Create - Step 2 Schedule & Budget', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=2', {
      screenshot: { id: 'PH-IT-07', filename: 'PH-IT-07-PROPOSAL-CREATE-STEP2.png', caption: 'Program Head Proposal Creation Wizard - Step 2: Schedule & Budget' }
    });

    // 7. Proposal Create - Step 3 Manpower & Tasks
    await inspectAndTestPage('Proposal Create - Step 3 Manpower & Tasks', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=3', {
      screenshot: { id: 'PH-IT-08', filename: 'PH-IT-08-PROPOSAL-CREATE-STEP3.png', caption: 'Program Head Proposal Creation Wizard - Step 3: Working Committees & Tasks' }
    });

    // 8. Proposal Create - Step 4 Review & Submit
    await inspectAndTestPage('Proposal Create - Step 4 Review & Submit', 'http://localhost/sti-activity-system/faculty/proposal-create.php?step=4', {
      screenshot: { id: 'PH-IT-09', filename: 'PH-IT-09-PROPOSAL-CREATE-STEP4.png', caption: 'Program Head Proposal Creation Wizard - Step 4: Summary & Submit' }
    });

    // 9. Pending Proposals
    await inspectAndTestPage('Pending Proposals', 'http://localhost/sti-activity-system/faculty/pending.php', {
      screenshot: { id: 'PH-IT-10', filename: 'PH-IT-10-PENDING-PROPOSALS.png', caption: 'IT Program Head Proponent Pending Proposals Queue' }
    });

    // 10. Approved Activities
    await inspectAndTestPage('Approved Activities', 'http://localhost/sti-activity-system/faculty/approved.php', {
      screenshot: { id: 'PH-IT-11', filename: 'PH-IT-11-APPROVED-ACTIVITIES.png', caption: 'IT Department Approved Activities Oversight' }
    });

    // 11. Returned for Revision
    await inspectAndTestPage('Returned for Revision', 'http://localhost/sti-activity-system/faculty/returned.php', {
      screenshot: { id: 'PH-IT-12', filename: 'PH-IT-12-RETURNED-REVISIONS.png', caption: 'IT Department Returned for Revision Table with Approver Feedback' }
    });

    // 12. Rejected Proposals
    await inspectAndTestPage('Rejected Proposals', 'http://localhost/sti-activity-system/faculty/rejected.php', {
      screenshot: { id: 'PH-IT-13', filename: 'PH-IT-13-REJECTED-PROPOSALS.png', caption: 'IT Department Rejected Proposals History with Stated Reasons' }
    });

    // 13. Department Task Monitoring
    await inspectAndTestPage('Department Task Monitoring', 'http://localhost/sti-activity-system/faculty/tasks.php', {
      screenshot: { id: 'PH-IT-14', filename: 'PH-IT-14-TASK-MONITORING.png', caption: 'Department Task Monitoring Grid with Gantt Timeline & Deadlines' },
      customTest: async (cdp, rec) => {
        const taskCheck = await cdp.send('Runtime.evaluate', {
          expression: `({
            h1: (document.querySelector('h1, .page-title') || {}).innerText || '',
            filterBar: !!document.querySelector('.filters-card, form.filters'),
            tableRows: document.querySelectorAll('table tbody tr').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ Task monitoring inspection:', taskCheck.result.value);
      }
    });

    // 14. Calendar & Scheduling
    await inspectAndTestPage('Faculty & Department Calendar', 'http://localhost/sti-activity-system/faculty/calendar.php', {
      screenshot: { id: 'PH-IT-15', filename: 'PH-IT-15-CALENDAR-VIEW.png', caption: 'IT Department & Institutional Scheduled Activities Calendar Grid' }
    });

    // 15. Post-Event Module
    await inspectAndTestPage('Post-Event Module', 'http://localhost/sti-activity-system/faculty/post-event.php', {
      screenshot: { id: 'PH-IT-16', filename: 'PH-IT-16-POST-EVENT-MODULE.png', caption: 'Post-Event Reporting & Deliverable Uploads Module' }
    });

    // 16. KPI and Evaluation Reports
    await inspectAndTestPage('KPI and Evaluation Reports', 'http://localhost/sti-activity-system/faculty/kpi.php', {
      screenshot: { id: 'PH-IT-17', filename: 'PH-IT-17-KPI-REPORTS.png', caption: 'IT Department Aggregate KPI Performance & Evaluation Ratings' },
      customTest: async (cdp, rec) => {
        const kpiCheck = await cdp.send('Runtime.evaluate', {
          expression: `({
            pageTitle: (document.querySelector('h1, .page-title') || {}).innerText || '',
            summaryCards: document.querySelectorAll('.summary-card, .kpi-card').length,
            tableRows: document.querySelectorAll('table tbody tr').length
          })`,
          returnByValue: true
        });
        console.log('   ✓ KPI reports inspection:', kpiCheck.result.value);
      }
    });

    // 17. Generate Report (Locked to IT Department)
    await inspectAndTestPage('Generate Institutional / Department Report', 'http://localhost/sti-activity-system/dean/generate-report.php', {
      screenshot: { id: 'PH-IT-18', filename: 'PH-IT-18-GENERATE-REPORT.png', caption: 'Institutional & Departmental Report Generator (Server-Locked to IT Department)' },
      customTest: async (cdp, rec) => {
        const genCheck = await cdp.send('Runtime.evaluate', {
          expression: `({
            pageTitle: (document.querySelector('h1, .page-title') || {}).innerText || '',
            deptSelectorDisabled: !!document.querySelector('select[name="department"][disabled]'),
            deptSelectedVal: (document.querySelector('select[name="department"]') || {}).value || ''
          })`,
          returnByValue: true
        });
        console.log('   ✓ Generate report department lock:', genCheck.result.value);
      }
    });

    // 18. Proposal Detail View (Activity ID: 1218)
    await inspectAndTestPage('Proposal Detail View', 'http://localhost/sti-activity-system/faculty/proposal-view.php?id=1218', {
      screenshot: { id: 'PH-IT-19', filename: 'PH-IT-19-PROPOSAL-VIEW-TIMELINE.png', caption: 'Proposal Detail Dossier with Review History & Signatures' }
    });

    // 19. Activity Detail View (Activity ID: 1233)
    await inspectAndTestPage('Activity Master Detail View', 'http://localhost/sti-activity-system/faculty/view-activity.php?id=1233', {
      screenshot: { id: 'PH-IT-20', filename: 'PH-IT-20-ACTIVITY-VIEW-DETAILS.png', caption: 'Activity Master Detail View with Tabbed Panels & Deliverables' }
    });

    // 20. Notifications Center
    await inspectAndTestPage('Notifications Center', 'http://localhost/sti-activity-system/notifications.php', {
      screenshot: { id: 'PH-IT-21', filename: 'PH-IT-21-NOTIFICATIONS-VIEW.png', caption: 'Notifications Center with Filter Tabs & Read Controls' }
    });

    // 21. Profile Modal Trigger
    await inspectAndTestPage('User Profile Modal Test', 'http://localhost/sti-activity-system/program_head/dashboard.php', {
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
        let modalFound = profileTriggered && profileTriggered.result && profileTriggered.result.value;
        if (!modalFound) {
          // Alternative: click profile dropdown/btn directly
          await cdp.send('Runtime.evaluate', {
            expression: `(() => {
              const pBtn = document.querySelector('a[href*="profile"], button[data-bs-target*="profile"], .user-profile, .dropdown-user');
              if (pBtn) { pBtn.click(); return true; }
              return false;
            })()`
          });
        }
        await sleep(600);
        await capturePageScreenshot('PH-IT-22', 'PH-IT-22-PROFILE-MODAL.png', 'IT Program Head User Profile & Account Settings Dialog');
      }
    });

    // 22. RBAC Security Boundary Check
    console.log('\n------------------------------------------------------------');
    console.log('[RBAC Security Boundary Tests]');
    console.log('Testing unauthorized route access as IT Program Head...');
    console.log('------------------------------------------------------------');

    const unauthorizedRoutes = [
      { name: 'Admin 1 Dashboard', url: 'http://localhost/sti-activity-system/admin1/dashboard.php' },
      { name: 'Admin 2 Dashboard', url: 'http://localhost/sti-activity-system/admin2/dashboard.php' },
      { name: 'Dean Dashboard', url: 'http://localhost/sti-activity-system/dean/dashboard.php' },
      { name: 'Admin 1 Proposal Review', url: 'http://localhost/sti-activity-system/admin1/review.php?id=1218' },
      { name: 'Admin 2 User Management', url: 'http://localhost/sti-activity-system/admin2/users.php' },
      { name: 'Dean User Management', url: 'http://localhost/sti-activity-system/dean/users.php' }
    ];

    let rbacPassed = 0;
    for (const r of unauthorizedRoutes) {
      await cdp.send('Page.navigate', { url: r.url });
      await sleep(1000);
      const rbacCheck = await cdp.send('Runtime.evaluate', {
        expression: `({
          url: window.location.href,
          title: document.title,
          isDenied: document.body.innerText.includes('Access Denied') || document.body.innerText.includes('Forbidden') || document.body.innerText.includes('Unauthorized') || !window.location.href.includes('${r.url}')
        })`,
        returnByValue: true
      });
      const res = (rbacCheck && rbacCheck.result && rbacCheck.result.value) ? rbacCheck.result.value : {};
      const curUrl = res.url || '';
      const protectedProperly = res.isDenied || curUrl.includes('/program_head/') || curUrl.includes('/auth/login.php') || (curUrl !== r.url && !curUrl.includes(r.url.replace('http://localhost', '')));
      if (protectedProperly) {
        console.log(`   [PASS] RBAC Boundary Enforced: ${r.name} -> Blocked/Redirected (${curUrl})`);
        rbacPassed++;
      } else {
        console.error(`   [CRITICAL FAIL] RBAC Breach: Program Head accessed ${r.name}! URL: ${curUrl}`);
        auditResults.issues.push({
          page: r.name,
          url: r.url,
          severity: 'Critical',
          description: `Role-based access violation: IT Program Head can access ${r.name}`,
          actual: 'Page loaded without restriction',
          expected: '403 Forbidden or redirect to /program_head/dashboard.php',
          sourceFile: r.url.replace('http://localhost/sti-activity-system/', ''),
          status: 'FAILED'
        });
      }
    }
    await capturePageScreenshot('PH-IT-23', 'PH-IT-23-RBAC-DENIED-ADMIN1.png', 'RBAC Boundary Enforcement: Access Denied / Safe Redirection Screen');

    // 23. Live Production Endpoint Inspection
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
      await capturePageScreenshot('PH-IT-24', 'PH-IT-24-LIVE-ENDPOINT-PRODUCTION.png', 'Live Production Endpoint State at https://sti-armes.freedev.app/auth/login.php');
    } catch (liveErr) {
      console.warn('   ⚠️ Live endpoint navigation notice:', liveErr.message);
    }

    // Save Raw Audit Result JSON
    auditResults.endTime = new Date().toISOString();
    auditResults.totalConsoleErrors = cdp.consoleErrors.length;
    auditResults.totalNetworkErrors = cdp.networkErrors.length;
    auditResults.allConsoleErrors = cdp.consoleErrors;
    auditResults.allNetworkErrors = cdp.networkErrors;

    const auditJsonPath = path.join(__dirname, '..', 'reports', 'program-head-it-audit', 'audit_execution_log.json');
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
