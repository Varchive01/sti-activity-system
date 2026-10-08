const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\186708d3-e9c4-4c8f-8ce2-f17abbaaa5ca';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9562;

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(e); }
      });
    }).on('error', reject);
  });
}

async function main() {
  console.log("========================================================================");
  console.log("   BROWSER E2E: AI EVALUATION TOOL AUTO-CASCADE & EDIT PARITY TEST      ");
  console.log("========================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_cascade_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1400,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => {
    try { chromeProc.kill(); } catch (e) {}
  });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(500);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch (e) {}
  }

  if (!version || !version.webSocketDebuggerUrl) {
    console.error("Could not connect to Chrome CDP.");
    process.exit(1);
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page');
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
  let idCounter = 1;
  const pending = new Map();

  // Track network requests
  const networkRequests = [];
  let themeRequestsCount = 0;
  let objRequestsCount = 0;
  let evalRequestsCount = 0;

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Network.requestWillBeSent') {
      const url = msg.params.request.url;
      if (url.includes('generate-theme.php')) {
        themeRequestsCount++;
        networkRequests.push({ time: Date.now(), type: 'theme', url });
        console.log(`  [NET REQUEST] generate-theme.php (Total theme calls: ${themeRequestsCount})`);
      } else if (url.includes('generate-objectives.php')) {
        objRequestsCount++;
        networkRequests.push({ time: Date.now(), type: 'objectives', url });
        console.log(`  [NET REQUEST] generate-objectives.php (Total obj calls: ${objRequestsCount})`);
      } else if (url.includes('generate-evaluation.php')) {
        evalRequestsCount++;
        networkRequests.push({ time: Date.now(), type: 'evaluation', url });
        console.log(`  [NET REQUEST] generate-evaluation.php (Total eval calls: ${evalRequestsCount})`);
      }
    }
    if (msg.method === 'Runtime.consoleAPICalled') {
      console.log('  [BROWSER CONSOLE]', msg.params.args.map(a => a.value !== undefined ? a.value : (a.description || '')).join(' '));
    }
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      if (msg.error) reject(msg.error);
      else resolve(msg.result);
    }
  };

  await new Promise(r => ws.onopen = r);

  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = idCounter++;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async function evaluate(expression) {
    const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (res && res.exceptionDetails) {
      console.error('  [EVAL ERROR]', res.exceptionDetails.text, res.exceptionDetails.exception?.description || '');
    }
    if (res && res.result && res.result.value !== undefined) {
      return res.result.value;
    }
    return null;
  }

  async function captureScreenshot(filename) {
    const res = await send('Page.captureScreenshot', { format: 'png' });
    const buffer = Buffer.from(res.data, 'base64');
    const outPath = path.join(ARTIFACTS_DIR, filename);
    fs.writeFileSync(outPath, buffer);
    console.log(`  [SCREENSHOT] Saved: ${filename}`);
  }

  await send('Page.enable');
  await send('Network.enable');
  await send('Runtime.enable');

  // Step 1: Login as Faculty
  console.log("\n[STEP 1] Logging in as faculty...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);

  let curUrl = await evaluate('window.location.href');
  console.log(`  Current page before login: ${curUrl}`);

  await evaluate(`(() => {
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('button[type="submit"]').click();
  })()`);
  await sleep(2500);

  curUrl = await evaluate('window.location.href');
  let curTitle = await evaluate('document.title');
  console.log(`  Current page after login: ${curUrl} (${curTitle})`);

  // -------------------------------------------------------------------------
  // TEST 1: New Proposal Automatic Cascade (Title -> Theme -> Obj -> Eval)
  // -------------------------------------------------------------------------
  console.log("\n[TEST 1] Testing New Proposal Cascade in proposal-create.php...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-create.php' });
  await sleep(2500);

  curUrl = await evaluate('window.location.href');
  curTitle = await evaluate('document.title');
  console.log(`  Current page on create: ${curUrl} (${curTitle})`);

  themeRequestsCount = 0;
  objRequestsCount = 0;
  evalRequestsCount = 0;

  console.log("  Typing title: 'STI Inter-Campus Hackathon 2026'...");
  await evaluate(`(() => {
    const titleInput = document.getElementById('f1_title');
    if (!titleInput) {
      console.error('f1_title not found on page!');
    } else {
      titleInput.value = 'STI Inter-Campus Hackathon 2026';
      titleInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  })()`);

  console.log("  Waiting for automatic cascade to settle (Theme -> Objectives -> Evaluation Tool)...");
  let evalStatus = '';
  let evalQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    evalStatus = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    evalQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    if (evalStatus === 'Auto-generated' && evalQuestions.length > 0) {
      break;
    }
  }

  console.log(`  -> Final Evaluation Status: "${evalStatus}"`);
  console.log(`  -> Questions Count: ${evalQuestions.length}`);
  if (evalQuestions.length > 0) {
    console.log(`  -> Sample Question 1: "${evalQuestions[0].question}"`);
  }
  console.log(`  -> Total Calls: Theme=${themeRequestsCount}, Obj=${objRequestsCount}, Eval=${evalRequestsCount}`);

  await captureScreenshot('test1_create_cascade_ready.png');

  if (evalStatus === 'Auto-generated' && evalQuestions.length > 0 && themeRequestsCount === 1 && objRequestsCount === 1 && evalRequestsCount === 1) {
    console.log("  ✅ PASS: TEST 1 completed with automatic Theme, Objectives, and Evaluation Tool generation!");
  } else {
    console.error(`  ❌ FAIL: TEST 1 did not complete as expected. Status: ${evalStatus}, EvalCount: ${evalRequestsCount}`);
  }

  // -------------------------------------------------------------------------
  // TEST 2: Topic Change Discards Prior Context and Regenerates Eval
  // -------------------------------------------------------------------------
  console.log("\n[TEST 2] Changing Title to 'Inter-Campus Sportsfest 2026'...");
  const oldFirstQuestion = evalQuestions.length > 0 ? evalQuestions[0].question : '';
  const initialThemeCalls = themeRequestsCount;
  const initialObjCalls = objRequestsCount;
  const initialEvalCalls = evalRequestsCount;

  await evaluate(`(() => {
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Inter-Campus Sportsfest 2026';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  })()`);

  console.log("  Waiting for Sportsfest cascade to settle...");
  let sportsEvalQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    evalStatus = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    sportsEvalQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    if (evalStatus === 'Auto-generated' && sportsEvalQuestions.length > 0 && sportsEvalQuestions[0].question !== oldFirstQuestion) {
      break;
    }
  }

  console.log(`  -> New Eval Status: "${evalStatus}"`);
  console.log(`  -> Questions Count: ${sportsEvalQuestions.length}`);
  if (sportsEvalQuestions.length > 0) {
    console.log(`  -> Sample Sportsfest Question: "${sportsEvalQuestions[0].question}"`);
  }

  await captureScreenshot('test2_create_sportsfest_cascade.png');

  const sportsCallsTheme = themeRequestsCount - initialThemeCalls;
  const sportsCallsObj = objRequestsCount - initialObjCalls;
  const sportsCallsEval = evalRequestsCount - initialEvalCalls;
  console.log(`  -> Topic Change Calls: Theme=${sportsCallsTheme}, Obj=${sportsCallsObj}, Eval=${sportsCallsEval}`);

  if (sportsEvalQuestions.length > 0 && sportsEvalQuestions[0].question !== oldFirstQuestion && sportsCallsEval === 1) {
    console.log("  ✅ PASS: TEST 2 Topic change replaced questions with NO stale context!");
  } else {
    console.error("  ❌ FAIL: TEST 2 questions did not update properly.");
  }

  // -------------------------------------------------------------------------
  // TEST 3: Save as Draft & Open in Edit Proposal (Zero Unsolicited AI Calls)
  // -------------------------------------------------------------------------
  console.log("\n[TEST 3] Saving proposal as Draft...");
  await evaluate(`(() => {
    document.getElementById('f1_source').value = 'faculty';
    document.getElementById('f1_venue').value = 'Gymnasium';
    document.getElementById('f1_target_participants').value = '100';
    document.getElementById('f1_venue_address').value = 'STI Academic Center';
    if (typeof serializeEvaluationQuestions === 'function') {
      serializeEvaluationQuestions();
    }

    const form = document.getElementById('proposalForm');
    let actionInput = form.querySelector('input[name="action"]');
    if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
    }
    actionInput.value = 'draft';
    form.submit();
  })()`);
  await sleep(3000);

  // Get the most recent draft ID from activities.php
  const recentActivityId = await evaluate(`
    fetch('/sti-activity-system/faculty/activities.php')
      .then(r => r.text())
      .then(html => {
        const m = html.match(/proposal-edit\\.php\\?id=(\\d+)/);
        return m ? parseInt(m[1], 10) : null;
      })
  `);

  console.log(`  -> Created draft activity ID: ${recentActivityId}`);

  if (!recentActivityId) {
    console.error("  ❌ FAIL: Could not retrieve created draft activity ID.");
    chromeProc.kill();
    process.exit(1);
  }

  // Navigate to Edit page
  console.log(`\n[TEST 3b] Loading proposal-edit.php?id=${recentActivityId}...`);
  themeRequestsCount = 0;
  objRequestsCount = 0;
  evalRequestsCount = 0;

  await send('Page.navigate', { url: `http://localhost/sti-activity-system/faculty/proposal-edit.php?id=${recentActivityId}` });
  await sleep(3000);

  const editQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
  const editEvalStatus = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';

  console.log(`  -> Edit Page Loaded Evaluation Status: "${editEvalStatus}"`);
  console.log(`  -> Edit Page Restored Questions Count: ${editQuestions.length}`);
  console.log(`  -> Calls on Load: Theme=${themeRequestsCount}, Obj=${objRequestsCount}, Eval=${evalRequestsCount}`);

  await captureScreenshot('test3_edit_page_restored.png');

  if (editQuestions.length > 0 && editEvalStatus === 'Auto-generated' && themeRequestsCount === 0 && objRequestsCount === 0 && evalRequestsCount === 0) {
    console.log("  ✅ PASS: Saved questions restored on Edit load with ZERO unsolicited AI calls!");
  } else {
    console.error(`  ❌ FAIL: Edit load made unexpected calls (Theme=${themeRequestsCount}, Obj=${objRequestsCount}, Eval=${evalRequestsCount}) or failed to restore questions.`);
  }

  // -------------------------------------------------------------------------
  // TEST 4: Manual Theme Change in Edit Proposal -> Auto-regenerates Evaluation
  // -------------------------------------------------------------------------
  console.log("\n[TEST 4] Testing Manual Theme Change in Edit Proposal...");
  const beforeManualThemeEvalCalls = evalRequestsCount;
  const beforeManualThemeFirstQ = editQuestions.length > 0 ? editQuestions[0].question : '';

  console.log("  Typing new manual Theme in f1_theme...");
  await evaluate(`(() => {
    const themeInput = document.getElementById('f1_theme');
    themeInput.value = 'Forging Excellence Through Teamwork, Discipline, and Athletic Spirit';
    themeInput.dispatchEvent(new Event('input', { bubbles: true }));
  })()`);

  console.log("  Waiting for manual Theme change to trigger Objectives & Evaluation Tool regeneration...");
  let manualThemeQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    manualThemeQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    const status = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    if (status === 'Auto-generated' && manualThemeQuestions.length > 0 && (evalRequestsCount - beforeManualThemeEvalCalls) >= 1) {
      break;
    }
  }

  const themeChangeEvalCalls = evalRequestsCount - beforeManualThemeEvalCalls;
  console.log(`  -> Eval calls triggered by manual Theme change: ${themeChangeEvalCalls}`);
  console.log(`  -> New Questions Count: ${manualThemeQuestions.length}`);
  if (manualThemeQuestions.length > 0) {
    console.log(`  -> Sample Question: "${manualThemeQuestions[0].question}"`);
  }

  await captureScreenshot('test4_edit_manual_theme_eval.png');

  if (themeChangeEvalCalls >= 1 && manualThemeQuestions.length > 0) {
    console.log("  ✅ PASS: TEST 4 Manual Theme change in Edit Proposal automatically regenerated Evaluation Tool!");
  } else {
    console.error(`  ❌ FAIL: TEST 4 manual Theme change did not automatically regenerate Evaluation Tool. Calls: ${themeChangeEvalCalls}`);
  }

  // -------------------------------------------------------------------------
  // TEST 5: Manual Objective Change in Edit Proposal -> Auto-regenerates Evaluation
  // -------------------------------------------------------------------------
  console.log("\n[TEST 5] Testing Manual Objective Change in Edit Proposal...");
  const beforeManualObjEvalCalls = evalRequestsCount;

  console.log("  Editing General Objective in f2_general_objectives...");
  await evaluate(`(() => {
    const genObjInput = document.getElementById('f2_general_objectives');
    genObjInput.value = 'To cultivate high-performance athletic skills, physical endurance, and strategic teamwork among student athletes across all STI campus clusters.';
    genObjInput.dispatchEvent(new Event('input', { bubbles: true }));
    genObjInput.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);

  console.log("  Waiting for manual Objective edit to trigger Evaluation Tool regeneration...");
  let manualObjQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    manualObjQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    const status = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    if (status === 'Auto-generated' && manualObjQuestions.length > 0 && (evalRequestsCount - beforeManualObjEvalCalls) >= 1) {
      break;
    }
  }

  const objChangeEvalCalls = evalRequestsCount - beforeManualObjEvalCalls;
  console.log(`  -> Eval calls triggered by manual Objective edit: ${objChangeEvalCalls}`);
  console.log(`  -> New Questions Count: ${manualObjQuestions.length}`);
  if (manualObjQuestions.length > 0) {
    console.log(`  -> Sample Question: "${manualObjQuestions[0].question}"`);
  }

  await captureScreenshot('test5_edit_manual_obj_eval.png');

  if (objChangeEvalCalls >= 1 && manualObjQuestions.length > 0) {
    console.log("  ✅ PASS: TEST 5 Manual Objective edit in Edit Proposal automatically regenerated Evaluation Tool!");
  } else {
    console.error(`  ❌ FAIL: TEST 5 manual Objective edit did not automatically regenerate Evaluation Tool. Calls: ${objChangeEvalCalls}`);
  }

  // -------------------------------------------------------------------------
  // TEST 6: Manual KPI Change in Edit Proposal -> Auto-regenerates Evaluation
  // -------------------------------------------------------------------------
  console.log("\n[TEST 6] Testing KPI Change in Edit Proposal...");
  const beforeKpiEvalCalls = evalRequestsCount;

  console.log("  Adding new KPI row with addRow('kpi')...");
  await evaluate(`(() => {
    if (typeof addRow === 'function') {
      addRow('kpi');
    }
    const kpiInputs = document.querySelectorAll('input[name="kpi_indicator[]"], input[name="kpi_criteria[]"]');
    const lastKpi = kpiInputs[kpiInputs.length - 1];
    if (lastKpi) {
      lastKpi.value = '95% student satisfaction with sportsmanship and event safety standards';
      lastKpi.dispatchEvent(new Event('input', { bubbles: true }));
      lastKpi.dispatchEvent(new Event('change', { bubbles: true }));
    }
  })()`);

  console.log("  Waiting for KPI change to trigger Evaluation Tool regeneration...");
  let kpiQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    kpiQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    const status = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    if (status === 'Auto-generated' && kpiQuestions.length > 0 && (evalRequestsCount - beforeKpiEvalCalls) >= 1) {
      break;
    }
  }

  const kpiChangeEvalCalls = evalRequestsCount - beforeKpiEvalCalls;
  console.log(`  -> Eval calls triggered by KPI change: ${kpiChangeEvalCalls}`);
  console.log(`  -> New Questions Count: ${kpiQuestions.length}`);

  await captureScreenshot('test6_edit_kpi_eval.png');

  if (kpiChangeEvalCalls >= 1 && kpiQuestions.length > 0) {
    console.log("  ✅ PASS: TEST 6 KPI change in Edit Proposal automatically regenerated Evaluation Tool!");
  } else {
    console.error(`  ❌ FAIL: TEST 6 KPI change did not automatically regenerate Evaluation Tool. Calls: ${kpiChangeEvalCalls}`);
  }

  // -------------------------------------------------------------------------
  // TEST 7: Manual Question Customization Protection (Minor Edit Protection)
  // -------------------------------------------------------------------------
  console.log("\n[TEST 7] Testing Manual Question Customization Protection...");
  const customQuestionText = "FACULTY CUSTOM QUESTION: How would you evaluate the referee impartiality and medical team responsiveness?";
  
  await evaluate(`(() => {
    startEdit(0);
    const card = document.querySelector('.eval-question-card[data-index="0"]');
    if (card) {
      const input = card.querySelector('.eval-question-input');
      if (input) input.value = ${JSON.stringify(customQuestionText)};
      saveEdit(0);
    }
  })()`);
  await sleep(500);

  let currentFirstQ = await evaluate(`questionsList[0] ? questionsList[0].question : ''`);
  let isCustom = await evaluate(`isEvaluationEdited`);
  let statusBadgeText = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`);

  console.log(`  -> Question 0 after custom edit: "${currentFirstQ}"`);
  console.log(`  -> isEvaluationEdited: ${isCustom}, Status text: "${statusBadgeText}"`);

  // Now perform a minor edit (e.g. change rationale)
  console.log("  Performing minor field edit (modifying rationale)...");

  await evaluate(`(() => {
    const ratEl = document.querySelector('[name="rationale"]');
    if (ratEl) {
      ratEl.value = 'Updated rationale to promote collegiate solidarity and healthy lifestyle.';
      ratEl.dispatchEvent(new Event('input', { bubbles: true }));
      ratEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
  })()`);
  await sleep(2500);

  // Check if customized question was preserved
  currentFirstQ = await evaluate(`questionsList[0] ? questionsList[0].question : ''`);
  isCustom = await evaluate(`isEvaluationEdited`);
  statusBadgeText = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`);

  console.log(`  -> Question 0 after minor field edit: "${currentFirstQ}"`);
  console.log(`  -> isEvaluationEdited: ${isCustom}, Status text: "${statusBadgeText}"`);

  await captureScreenshot('test7_custom_question_preserved.png');

  if (currentFirstQ === customQuestionText && isCustom === true) {
    console.log("  ✅ PASS: TEST 7 Faculty-customized evaluation question was PRESERVED during minor field edits!");
  } else {
    console.error("  ❌ FAIL: TEST 7 Faculty-customized question was unexpectedly overwritten or lost!");
  }

  // -------------------------------------------------------------------------
  // TEST 8: Title Change to New Activity Context Clears Customization & Regenerates
  // -------------------------------------------------------------------------
  console.log("\n[TEST 8] Changing Title to New Activity Context ('Cybersecurity Summit 2026')...");
  const beforeNewTopicCalls = evalRequestsCount;

  await evaluate(`(() => {
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Cybersecurity Summit 2026';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  })()`);

  console.log("  Waiting for new topic cascade to clear manual edits and regenerate fresh questions...");
  let cyberQuestions = [];
  for (let i = 0; i < 90; i++) {
    await sleep(1000);
    cyberQuestions = await evaluate(`typeof questionsList !== 'undefined' ? questionsList : []`) || [];
    const status = await evaluate(`document.getElementById('evalStatusText') ? document.getElementById('evalStatusText').innerText.trim() : ''`) || '';
    if (status === 'Auto-generated' && cyberQuestions.length > 0 && cyberQuestions[0].question !== customQuestionText) {
      break;
    }
  }

  console.log(`  -> New Topic Questions Count: ${cyberQuestions.length}`);
  if (cyberQuestions.length > 0) {
    console.log(`  -> Sample Question 1: "${cyberQuestions[0].question}"`);
  }
  const isCustomCleared = await evaluate(`isEvaluationEdited === false`);
  const newTopicCalls = evalRequestsCount - beforeNewTopicCalls;
  console.log(`  -> New Topic Eval Calls: ${newTopicCalls}, isEvaluationEdited reset to false: ${isCustomCleared}`);

  await captureScreenshot('test8_new_topic_regenerated.png');

  if (cyberQuestions.length > 0 && cyberQuestions[0].question !== customQuestionText && isCustomCleared) {
    console.log("  ✅ PASS: TEST 8 Title change to new activity context cleanly reset manual edits and regenerated fresh questions!");
  } else {
    console.error("  ❌ FAIL: TEST 8 New topic did not properly regenerate questions or clear manual edit flag.");
  }

  // -------------------------------------------------------------------------
  // TEST 9: Verify Edit Page Feature Parity (Modals, Materials Total, Banners)
  // -------------------------------------------------------------------------
  console.log("\n[TEST 9] Testing Feature Parity on Edit Page (Modals, Total, Banners)...");
  const parityChecks = await evaluate(`(() => ({
    hasConflictModal: !!document.getElementById('scheduleConflictModal'),
    hasValidationModal: !!document.getElementById('aiProposalValidationModal'),
    hasConflictBannerStep9: !!document.getElementById('scheduleConflictBannerStep9'),
    hasMaterialsTotal: !!document.getElementById('materials-total'),
    hasBootstrapBundle: typeof bootstrap !== 'undefined',
    hasScheduleConflictUI: typeof ScheduleConflictUI !== 'undefined',
    hasProposalAIValidator: typeof ProposalAIValidator !== 'undefined'
  }))()`);

  console.log("  Parity checks:", JSON.stringify(parityChecks, null, 2));

  // Test dynamic materials calculation in Edit
  console.log("  Testing materials total calculation in Edit...");
  await evaluate(`(() => {
    showStep(3);
    const qtyInput = document.querySelector('#materials-body .mat-qty');
    const costInput = document.querySelector('#materials-body .mat-cost');
    if (qtyInput && costInput) {
      qtyInput.value = '10';
      costInput.value = '250';
      qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  })()`);
  await sleep(500);

  const totalText = await evaluate(`document.getElementById('materials-total') ? document.getElementById('materials-total').innerText.trim() : ''`);
  console.log(`  -> Materials total calculated: "${totalText}"`);

  await captureScreenshot('test9_edit_materials_total.png');

  const isTotalCorrect = totalText.includes('2,500.00');
  const isParityComplete = parityChecks.hasConflictModal && parityChecks.hasValidationModal && parityChecks.hasConflictBannerStep9 && parityChecks.hasMaterialsTotal && parityChecks.hasBootstrapBundle && parityChecks.hasScheduleConflictUI && parityChecks.hasProposalAIValidator;

  if (isTotalCorrect && isParityComplete) {
    console.log("  ✅ PASS: TEST 9 Edit page feature parity & materials calculation verified 100%!");
  } else {
    console.error(`  ❌ FAIL: TEST 9 failed. Total correct: ${isTotalCorrect}, Parity complete: ${isParityComplete}`);
  }

  console.log("\n========================================================================");
  console.log("   ALL EVALUATION CASCADE & EDIT PARITY E2E TESTS COMPLETED!           ");
  console.log("========================================================================");

  chromeProc.kill();
  process.exit(0);
}

main().catch(err => {
  console.error("Test execution error:", err);
  process.exit(1);
});
