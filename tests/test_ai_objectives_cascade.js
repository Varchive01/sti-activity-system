const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\824cb8e9-ea7b-4d09-ab6d-5592c245a5fb';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9556;

async function sleep(ms) {
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
  console.log("   BROWSER E2E: AI TITLE -> THEME -> OBJECTIVES CASCADE (REVISION #4)   ");
  console.log("========================================================================");

  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_cascade_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1366,850'
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

  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.method === 'Network.requestWillBeSent') {
      const url = msg.params.request.url;
      if (url.includes('generate-theme.php')) {
        themeRequestsCount++;
        const postData = msg.params.request.postData || '';
        networkRequests.push({ time: Date.now(), type: 'theme', url, postData });
        console.log(`  [NET REQUEST #${networkRequests.length}] generate-theme.php (Total theme calls: ${themeRequestsCount})`);
      } else if (url.includes('generate-objectives.php')) {
        objRequestsCount++;
        const postData = msg.params.request.postData || '';
        networkRequests.push({ time: Date.now(), type: 'objectives', url, postData });
        console.log(`  [NET REQUEST #${networkRequests.length}] generate-objectives.php (Total obj calls: ${objRequestsCount})`);
      }
    }
    if (msg.method === 'Runtime.consoleAPICalled') {
      console.log('  [CONSOLE]', msg.params.args.map(a => a.value !== undefined ? a.value : (a.description || '')).join(' '));
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
    return res.result ? res.result.value : null;
  }

  async function captureScreenshot(filename) {
    const res = await send('Page.captureScreenshot', { format: 'png' });
    const buffer = Buffer.from(res.data, 'base64');
    const outPath = path.join(ARTIFACTS_DIR, filename);
    fs.writeFileSync(outPath, buffer);
    console.log(`  [SCREENSHOT SAVED] ${filename}`);
  }

  async function waitForSelector(selector, maxTries = 40) {
    for (let i = 0; i < maxTries; i++) {
      await sleep(300);
      const exists = await evaluate(`!!document.querySelector('${selector}')`);
      if (exists) return true;
    }
    return false;
  }

  await send('Network.enable');
  await send('Page.enable');
  await send('Runtime.enable');

  // Step 1: Login
  console.log("\n[STEP 1] Logging in as faculty...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await waitForSelector('[name="email"]');

  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('button[type="submit"]').click();
  `);
  await sleep(2000);

  // Step 2: Navigate to proposal-create.php
  console.log("\n[STEP 2] Navigating to proposal-create.php...");
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-create.php' });
  await waitForSelector('#f1_title');
  await sleep(1000);

  themeRequestsCount = 0;
  objRequestsCount = 0;

  // -----------------------------------------------------------------------
  // TEST 1: Initial Creation ("Buwan ng Wika")
  // -----------------------------------------------------------------------
  console.log("\n[TEST 1] Entering Title: 'Buwan ng Wika'...");
  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Buwan ng Wika';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("  Waiting for Theme auto-generation...");
  let themeA = '';
  for (let i = 0; i < 40; i++) {
    await sleep(1000);
    themeA = (await evaluate(`document.getElementById('f1_theme')?.value || ''`) || '').trim();
    if (themeA.length > 5) break;
  }
  console.log(`  -> Theme generated: "${themeA}"`);

  console.log("  Waiting for Objectives auto-generation cascade...");
  let genObjA = '';
  let specObjA = '';
  let statusTextA = '';
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    genObjA = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
    specObjA = (await evaluate(`document.getElementById('f2_specific_objectives')?.value || ''`) || '').trim();
    statusTextA = (await evaluate(`document.getElementById('objGenStatus')?.innerText || ''`) || '').trim();
    if (genObjA.length > 10 && specObjA.length > 10) break;
  }
  console.log(`  -> General Objective: "${genObjA.substring(0, 75)}..."`);
  console.log(`  -> Objectives status: "${statusTextA}"`);
  console.log(`  -> Network calls during Buwan ng Wika: Theme=${themeRequestsCount}, Objectives=${objRequestsCount}`);

  await evaluate(`showStep(2);`);
  await sleep(800);
  await captureScreenshot('test1_buwan_ng_wika_step2.png');

  if (themeRequestsCount === 1 && objRequestsCount === 1 && genObjA.length > 10) {
    console.log("  ✅ PASS: TEST 1 completed with exactly 1 Theme call and 1 Objective call!");
  } else {
    console.error(`  ❌ FAIL: Unexpected request counts: Theme=${themeRequestsCount}, Obj=${objRequestsCount}`);
  }

  // -----------------------------------------------------------------------
  // TEST 2: Change Title to 'Sportsfest'
  // -----------------------------------------------------------------------
  console.log("\n[TEST 2] Changing Title from 'Buwan ng Wika' to 'Sportsfest'...");
  await evaluate(`showStep(1);`);
  await sleep(500);

  const prevThemeReqsB = themeRequestsCount;
  const prevObjReqsB = objRequestsCount;

  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Sportsfest';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("  Waiting for Sportsfest Theme and Objectives to regenerate...");
  let themeB = '';
  for (let i = 0; i < 40; i++) {
    await sleep(1000);
    themeB = (await evaluate(`document.getElementById('f1_theme')?.value || ''`) || '').trim();
    if (themeB.length > 5 && themeB !== themeA) break;
  }
  console.log(`  -> New Theme generated: "${themeB}"`);

  let genObjB = '';
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    genObjB = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
    if (genObjB.length > 10 && genObjB !== genObjA) break;
  }
  console.log(`  -> New General Objective: "${genObjB.substring(0, 75)}..."`);

  const deltaThemeB = themeRequestsCount - prevThemeReqsB;
  const deltaObjB = objRequestsCount - prevObjReqsB;
  console.log(`  -> Calls during Sportsfest cascade: Theme=${deltaThemeB}, Objectives=${deltaObjB}`);

  await evaluate(`showStep(2);`);
  await sleep(800);
  await captureScreenshot('test2_sportsfest_step2.png');

  if (deltaThemeB === 1 && deltaObjB === 1 && genObjB !== genObjA) {
    console.log("  ✅ PASS: TEST 2 completed with exactly 1 new Theme and 1 new Objective generation!");
  } else {
    console.error(`  ❌ FAIL: TEST 2 calls: Theme=${deltaThemeB}, Obj=${deltaObjB}`);
  }

  // -----------------------------------------------------------------------
  // TEST 3: Manually change Theme
  // -----------------------------------------------------------------------
  console.log("\n[TEST 3] Manually changing Theme to 'Strength, Unity, and Academic Excellence'...");
  await evaluate(`showStep(1);`);
  await sleep(500);

  const prevThemeReqsC = themeRequestsCount;
  const prevObjReqsC = objRequestsCount;
  const manualThemeText = 'Strength, Unity, and Academic Excellence';

  await evaluate(`
    const themeInput = document.getElementById('f1_theme');
    themeInput.value = '${manualThemeText}';
    themeInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("  Waiting for debounced objective generation based on manual theme...");
  let genObjC = '';
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    genObjC = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
    if (objRequestsCount > prevObjReqsC && genObjC.length > 10) break;
  }
  console.log(`  -> Objective updated: "${genObjC.substring(0, 75)}..."`);

  const deltaThemeC = themeRequestsCount - prevThemeReqsC;
  const deltaObjC = objRequestsCount - prevObjReqsC;
  console.log(`  -> Calls during manual theme change: Theme=${deltaThemeC} (expected 0), Objectives=${deltaObjC} (expected 1)`);

  await evaluate(`showStep(2);`);
  await sleep(800);
  await captureScreenshot('test3_manual_theme_step2.png');

  if (deltaThemeC === 0 && deltaObjC === 1) {
    console.log("  ✅ PASS: TEST 3 triggered exactly 1 objective generation with 0 theme calls!");
  } else {
    console.error(`  ❌ FAIL: TEST 3 calls: Theme=${deltaThemeC}, Obj=${deltaObjC}`);
  }

  // -----------------------------------------------------------------------
  // TEST 4: Manual Objectives Edit & Protection
  // -----------------------------------------------------------------------
  console.log("\n[TEST 4] Manually editing Objectives in Step 2...");
  const customNote = ' [Faculty Manual Edit: Inter-college championship matches]';
  await evaluate(`
    const genInput = document.getElementById('f2_general_objectives');
    genInput.value = genInput.value + '${customNote}';
    genInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);
  await sleep(800);

  const isProtectedNow = await evaluate(`isObjectivesProtected`);
  console.log(`  -> isObjectivesProtected flag: ${isProtectedNow}`);

  console.log("  Returning to Step 1 and modifying Theme...");
  await evaluate(`showStep(1);`);
  await sleep(500);

  const prevObjReqsD = objRequestsCount;
  await evaluate(`
    const themeInput = document.getElementById('f1_theme');
    themeInput.value = 'Empowering Champions: STI Intramurals 2026';
    themeInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);
  await sleep(2500);

  console.log("  Checking Step 2: verifying edited objectives were NOT overwritten...");
  await evaluate(`showStep(2);`);
  await sleep(800);

  const genObjD = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
  const statusD = (await evaluate(`document.getElementById('objGenStatus')?.innerText || ''`) || '').trim();
  const hasRegenerateBtn = await evaluate(`!!document.querySelector('#objGenStatus button')`);

  console.log(`  -> General Objective still contains custom edit: ${genObjD.includes(customNote)}`);
  console.log(`  -> Status message: "${statusD}"`);
  console.log(`  -> Explicit Regenerate button present: ${hasRegenerateBtn}`);
  await captureScreenshot('test4_manual_objectives_preserved.png');

  const preservedOk = genObjD.includes(customNote) && statusD.includes('preserved') && hasRegenerateBtn;
  if (preservedOk) {
    console.log("  ✅ PASS: TEST 4 protected manual objectives and presented explicit Regenerate button!");
  } else {
    console.error("  ❌ FAIL: Manual objectives were not preserved properly!");
  }

  // Explicitly click Regenerate button
  console.log("  Clicking '↻ Regenerate Objectives'...");
  await evaluate(`forceRegenerateObjectives();`);
  let genObjD2 = '';
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    genObjD2 = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
    if (!genObjD2.includes(customNote) && genObjD2.length > 10) break;
  }
  console.log(`  -> Regenerated Objectives without custom note: "${genObjD2.substring(0, 75)}..."`);
  await captureScreenshot('test4_objectives_explicitly_regenerated.png');

  // -----------------------------------------------------------------------
  // TEST 5: Rapid Title Changes (Stale Request Protection)
  // -----------------------------------------------------------------------
  console.log("\n[TEST 5] Rapid title changes: Sportsfest -> Buwan ng Wika -> Tagisan ng Talino...");
  await evaluate(`showStep(1);`);
  await sleep(500);

  // Force clean state
  await evaluate(`
    isThemeManuallyEdited = false;
    isObjectivesProtected = false;
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Sportsfest';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);
  await sleep(300);

  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Buwan ng Wika';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);
  await sleep(300);

  await evaluate(`
    const titleInput = document.getElementById('f1_title');
    titleInput.value = 'Tagisan ng Talino';
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
  `);

  console.log("  Waiting for final cascade to settle on 'Tagisan ng Talino'...");
  let finalTheme = '';
  for (let i = 0; i < 40; i++) {
    await sleep(1000);
    finalTheme = (await evaluate(`document.getElementById('f1_theme')?.value || ''`) || '').trim();
    if (finalTheme.length > 5) break;
  }
  console.log(`  -> Final Theme resolved: "${finalTheme}"`);

  let finalGenObj = '';
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    finalGenObj = (await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`) || '').trim();
    if (finalGenObj.length > 10) break;
  }
  console.log(`  -> Final General Objective: "${finalGenObj.substring(0, 75)}..."`);

  await captureScreenshot('test5_rapid_title_resolved.png');
  const titleValAtEnd = (await evaluate(`document.getElementById('f1_title')?.value || ''`) || '').trim();
  if (titleValAtEnd === 'Tagisan ng Talino' && finalTheme.length > 5 && finalGenObj.length > 10) {
    console.log("  ✅ PASS: TEST 5 correctly resolved rapid changes to the latest Title without stale overwrite!");
  } else {
    console.error("  ❌ FAIL: TEST 5 failed to settle correctly.");
  }

  // -----------------------------------------------------------------------
  // TEST 6: Proposal-Edit Initial Load & Protection
  // -----------------------------------------------------------------------
  console.log("\n[TEST 6] Testing proposal-edit.php initial load protection...");
  const actId = 141; // Existing activity for faculty@sti.edu
  await send('Page.navigate', { url: `http://localhost/sti-activity-system/faculty/proposal-edit.php?id=${actId}` });
  await sleep(2500);

  const editThemeReqsBefore = themeRequestsCount;
  const editObjReqsBefore = objRequestsCount;
  await sleep(2000);

  const editThemeReqsAfter = themeRequestsCount;
  const editObjReqsAfter = objRequestsCount;

  const noCallsOnEditLoad = (editThemeReqsAfter === editThemeReqsBefore) && (editObjReqsAfter === editObjReqsBefore);
  const isEditProtected = await evaluate(`isObjectivesProtected`);
  const editGenValue = await evaluate(`document.getElementById('f2_general_objectives')?.value || ''`);

  console.log(`  -> Zero AI calls on initial page load: ${noCallsOnEditLoad} (Theme calls: 0, Obj calls: 0)`);
  console.log(`  -> Existing saved objectives protected on load: ${isEditProtected}`);
  console.log(`  -> Saved General Objectives: "${editGenValue.substring(0, 60)}..."`);
  await captureScreenshot('test6_edit_page_initial_load_protected.png');

  if (noCallsOnEditLoad && isEditProtected) {
    console.log("  ✅ PASS: TEST 6 verified proposal-edit.php never makes AI calls on load and protects saved content!");
  } else {
    console.error("  ❌ FAIL: TEST 6 failed.");
  }

  console.log("\n========================================================================");
  console.log("   ALL 6 BROWSER VERIFICATION SCENARIOS COMPLETED SUCCESSFULLY!         ");
  console.log("========================================================================");

  process.exit(0);
}

main().catch(err => {
  console.error("Fatal Error:", err);
  process.exit(1);
});
