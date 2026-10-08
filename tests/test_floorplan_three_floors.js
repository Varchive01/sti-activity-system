const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACTS_DIR = 'C:\\Users\\Varchive\\.gemini\\antigravity-ide\\brain\\637a8325-60a2-4cee-b9be-d5292da69056';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9444;

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

async function runTest() {
  console.log('=== RUNNING FLOOR PLAN 3-FLOOR MAX E2E TEST ===');
  const userDataDir = path.join(ARTIFACTS_DIR, 'scratch', 'chrome_fp_' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chromeProc = spawn(CHROME_PATH, [
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${userDataDir}`,
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--window-size=1280,900'
  ], { stdio: 'ignore' });

  process.on('exit', () => {
    try { chromeProc.kill(); } catch(e) {}
  });

  let version = null;
  for (let i = 0; i < 30; i++) {
    await sleep(400);
    try {
      version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
      if (version && version.webSocketDebuggerUrl) break;
    } catch(e) {}
  }

  if (!version) {
    console.error('Failed to start Chrome on port', PORT);
    process.exit(1);
  }

  const targets = await getJson(`http://127.0.0.1:${PORT}/json/list`);
  const pageTarget = targets.find(t => t.type === 'page') || targets[0];
  const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

  let idCounter = 1;
  const pending = new Map();
  ws.onmessage = (event) => {
    const msg = JSON.parse(event.data);
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

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');

  // STEP 1: Login
  console.log('1. Logging in as faculty...');
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/auth/login.php' });
  await sleep(1500);

  await evaluate(`
    document.querySelector('[name="email"]').value = 'faculty@sti.edu';
    document.querySelector('[name="password"]').value = 'password';
    document.querySelector('form').submit();
  `);
  await sleep(2000);

  // STEP 2: Navigate to proposal-create.php
  console.log('2. Navigating to proposal-create.php...');
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-create.php' });
  await sleep(2000);

  // STEP 3: Switch to Step 4 (Floor Plan)
  console.log('3. Switching to Step 4 (Floor Plan)...');
  await evaluate(`
    showStep(4);
  `);
  await sleep(1000);

  // STEP 4: Verify Ground Floor is present initially
  const initialTabs = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => ({
      floor: t.dataset.floor,
      text: t.innerText.trim(),
      active: t.classList.contains('active')
    }))
  `);
  console.log('Initial tabs:', initialTabs);
  if (initialTabs.length !== 1 || initialTabs[0].floor !== 'ground' || !initialTabs[0].active) {
    throw new Error('Initial floor state failed: expected exactly 1 active Ground Floor tab');
  }

  const addBtnState = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    const badge = document.getElementById('fpFloorMaxBadge');
    return {
      btnVisible: btn ? btn.style.display !== 'none' : false,
      btnText: btn ? btn.innerText.trim() : '',
      badgeVisible: badge ? badge.style.display !== 'none' : false
    };
  })()`);
  console.log('Add button state (1 floor):', addBtnState);
  if (!addBtnState.btnVisible || !addBtnState.btnText.includes('2nd Floor') || addBtnState.badgeVisible) {
    throw new Error('Add button state failed for 1 floor');
  }

  // STEP 5: Drop a Room/Rect on Ground Floor
  console.log('4. Drawing a room rectangle on Ground Floor...');
  await evaluate(`(() => {
    const btn = document.querySelector('[data-tool="preset-stage"]');
    if (btn) btn.click();
  })()`);
  await sleep(500);

  let groundData = await evaluate(`
    JSON.parse(document.getElementById('fp-canvas-data').value)
  `);
  console.log('Ground floor shapes count:', groundData.floors.ground.shapes.length);
  if (groundData.floors.ground.shapes.length !== 1) {
    throw new Error('Expected 1 shape on Ground Floor');
  }

  // STEP 6: Add 2nd Floor
  console.log('5. Clicking + Add Floor to create 2nd Floor...');
  await evaluate(`
    document.getElementById('fpAddFloorBtn').click();
  `);
  await sleep(500);

  const twoFloorTabs = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => ({
      floor: t.dataset.floor,
      text: t.innerText.trim(),
      active: t.classList.contains('active')
    }))
  `);
  console.log('Tabs after adding 2nd floor:', twoFloorTabs);
  if (twoFloorTabs.length !== 2 || twoFloorTabs[1].floor !== 'second' || !twoFloorTabs[1].active) {
    throw new Error('Expected 2 tabs with 2nd Floor active');
  }

  const addBtnState2 = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    const badge = document.getElementById('fpFloorMaxBadge');
    return {
      btnVisible: btn ? btn.style.display !== 'none' : false,
      btnText: btn ? btn.innerText.trim() : '',
      badgeVisible: badge ? badge.style.display !== 'none' : false
    };
  })()`);
  console.log('Add button state (2 floors):', addBtnState2);
  if (!addBtnState2.btnVisible || !addBtnState2.btnText.includes('3rd Floor') || addBtnState2.badgeVisible) {
    throw new Error('Add button state failed for 2 floors');
  }

  // Add Table preset on 2nd Floor
  console.log('6. Dropping Table on 2nd Floor...');
  await evaluate(`(() => {
    const btn = document.querySelector('[data-tool="preset-table"]');
    if (btn) btn.click();
  })()`);
  await sleep(500);

  // STEP 7: Add 3rd Floor
  console.log('7. Clicking + Add Floor to create 3rd Floor (reaching MAX 3 FLOORS)...');
  await evaluate(`
    document.getElementById('fpAddFloorBtn').click();
  `);
  await sleep(500);

  const threeFloorTabs = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => ({
      floor: t.dataset.floor,
      text: t.innerText.trim(),
      active: t.classList.contains('active')
    }))
  `);
  console.log('Tabs after adding 3rd floor:', threeFloorTabs);
  if (threeFloorTabs.length !== 3 || threeFloorTabs[2].floor !== 'third' || !threeFloorTabs[2].active) {
    throw new Error('Expected 3 tabs with 3rd Floor active');
  }

  // Verify MAX 3 FLOORS CEILING is enforced!
  const addBtnState3 = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    const badge = document.getElementById('fpFloorMaxBadge');
    return {
      btnVisible: btn ? btn.style.display !== 'none' : false,
      btnDisabled: btn ? btn.disabled : false,
      badgeVisible: badge ? badge.style.display !== 'none' : false
    };
  })()`);
  console.log('Add button state (3 floors ceiling):', addBtnState3);
  if (addBtnState3.btnVisible || !addBtnState3.btnDisabled || !addBtnState3.badgeVisible) {
    throw new Error('Ceiling failed: Add button should be hidden/disabled and Max 3 Floors badge visible');
  }

  // Add Booth on 3rd Floor
  console.log('8. Dropping Booth on 3rd Floor...');
  await evaluate(`(() => {
    const btn = document.querySelector('[data-tool="preset-booth"]');
    if (btn) btn.click();
  })()`);
  await sleep(500);

  // STEP 8: Verify payload and composite image
  const fullData = await evaluate(`(() => {
    const dataVal = document.getElementById('fp-canvas-data').value;
    const imgVal = document.getElementById('fp-canvas-image').value;
    return {
      data: JSON.parse(dataVal),
      imgLen: imgVal.length,
      isPng: imgVal.startsWith('data:image/png;base64,')
    };
  })()`);
  console.log('Full data payload check:');
  console.log('- Floors in payload:', Object.keys(fullData.data.floors));
  console.log('- Ground shapes:', fullData.data.floors.ground.shapes.length);
  console.log('- 2nd shapes:', fullData.data.floors.second.shapes.length);
  console.log('- 3rd shapes:', fullData.data.floors.third.shapes.length);
  console.log('- Composite image is PNG:', fullData.isPng, 'length:', fullData.imgLen);

  if (Object.keys(fullData.data.floors).length !== 3) {
    throw new Error('Expected 3 floors in payload');
  }
  if (!fullData.isPng || fullData.imgLen < 1000) {
    throw new Error('Composite image is invalid or empty');
  }

  // STEP 9: Test floor switching and isolation
  console.log('9. Testing floor switching and layout isolation...');
  await evaluate(`
    document.querySelector('[data-floor="ground"]').click();
  `);
  await sleep(300);
  const groundActive = await evaluate(`
    document.querySelector('[data-floor="ground"]').classList.contains('active')
  `);
  if (!groundActive) throw new Error('Failed to switch back to Ground Floor');

  await evaluate(`
    document.querySelector('[data-floor="second"]').click();
  `);
  await sleep(300);
  const secondActive = await evaluate(`
    document.querySelector('[data-floor="second"]').classList.contains('active')
  `);
  if (!secondActive) throw new Error('Failed to switch to 2nd Floor');

  // STEP 10: Test removing a floor
  console.log('10. Testing floor removal (remove 3rd Floor)...');
  await evaluate(`
    // Mock window.confirm to return true
    window.confirm = () => true;
    const removeBtn = document.querySelector('[data-remove="third"]');
    if (removeBtn) removeBtn.click();
  `);
  await sleep(500);

  const tabsAfterRemove = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => t.dataset.floor)
  `);
  console.log('Tabs after removing 3rd floor:', tabsAfterRemove);
  if (tabsAfterRemove.length !== 2 || tabsAfterRemove.includes('third')) {
    throw new Error('Expected 2 floors remaining after removing 3rd floor');
  }

  const addBtnStateAfterRemove = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    const badge = document.getElementById('fpFloorMaxBadge');
    return {
      btnVisible: btn ? btn.style.display !== 'none' : false,
      btnText: btn ? btn.innerText.trim() : '',
      badgeVisible: badge ? badge.style.display !== 'none' : false
    };
  })()`);
  console.log('Add button after removal:', addBtnStateAfterRemove);
  if (!addBtnStateAfterRemove.btnVisible || !addBtnStateAfterRemove.btnText.includes('3rd Floor') || addBtnStateAfterRemove.badgeVisible) {
    throw new Error('Add button should be visible again after removing 3rd floor');
  }

  // STEP 11: Re-add 3rd Floor so we have 3 floors to save
  console.log('11. Re-adding 3rd Floor before saving...');
  await evaluate(`
    document.getElementById('fpAddFloorBtn').click();
  `);
  await sleep(300);
  await evaluate(`(() => {
    const btn = document.querySelector('[data-tool="preset-exit"]');
    if (btn) btn.click();
  })()`);
  await sleep(500);

  // STEP 12: Save as draft and verify persistence in proposal-edit.php
  console.log('12. Filling required draft fields and saving proposal...');
  await evaluate(`(() => {
    document.getElementById('f1_title').value = 'Automated 3-Floor Plan Test Activity';
    if (document.getElementById('f1_theme')) document.getElementById('f1_theme').value = 'Testing 3 Floors Theme';
    if (document.getElementById('f2_general_objectives')) document.getElementById('f2_general_objectives').value = 'Test objective for 3-floor plan';
    if (document.getElementById('f2_specific_objectives')) document.getElementById('f2_specific_objectives').value = 'Specific objectives for 3 floors';
    document.getElementById('f1_event_date').value = '2026-10-15';
    document.getElementById('f1_source').value = 'faculty';
    document.getElementById('f1_start_time').value = '09:00';
    document.getElementById('f1_end_time').value = '12:00';
    document.getElementById('f1_venue').value = 'Main Campus Gymnasium';
    document.getElementById('f1_target_participants').value = '100';
    const addr = document.getElementById('f1_venue_address');
    if (addr) addr.value = 'Campus';
    const poster = document.getElementById('f1_poster_file');
    if (poster) poster.removeAttribute('required');
    document.getElementById('f3_floor_plan_notes').value = 'Detailed 3-floor emergency exit and staging plan';
    
    if (window.fpSaveToHidden) {
      window.fpSaveToHidden();
    }
    document.getElementById('proposalFormAction').value = 'draft';
    document.getElementById('proposalForm').submit();
  })()`);
  await sleep(3500);

  const currentUrl = await evaluate(`window.location.href`);
  console.log('Current URL after save:', currentUrl);

  // Query database for our newly saved draft ID
  console.log('13. Verifying saved draft in database...');
  const { execSync } = require('child_process');
  const tempScript = path.join(__dirname, '..', 'scratch', 'get_draft_temp.php');
  fs.writeFileSync(tempScript, `<?php
require_once __DIR__ . '/../config/database.php';
$row = getDB()->query("SELECT a.id, fp.canvas_json, fp.file_path FROM activities a JOIN floor_plans fp ON a.id=fp.activity_id WHERE a.title LIKE '%Automated 3-Floor Plan%' ORDER BY a.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row);
`);
  const phpCheck = execSync(`php "${tempScript}"`).toString();
  try { fs.unlinkSync(tempScript); } catch(e) {}
  const savedRow = JSON.parse(phpCheck);
  console.log('Saved draft ID:', savedRow.id);
  console.log('Saved file path:', savedRow.file_path);

  const parsedSaved = JSON.parse(savedRow.canvas_json);
  console.log('Saved floors keys:', Object.keys(parsedSaved.floors));
  if (Object.keys(parsedSaved.floors).length !== 3) {
    throw new Error('Database does not have exactly 3 floors saved');
  }

  // STEP 13: Open proposal-edit.php for this draft and verify roundtrip restoration!
  console.log(`14. Opening proposal-edit.php?id=${savedRow.id} to test Edit mode persistence...`);
  await send('Page.navigate', { url: `http://localhost/sti-activity-system/faculty/proposal-edit.php?id=${savedRow.id}` });
  await sleep(2500);

  // Switch to Step 4
  await evaluate(`showStep(4);`);
  await sleep(1000);

  const editTabs = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => ({
      floor: t.dataset.floor,
      text: t.innerText.trim(),
      active: t.classList.contains('active')
    }))
  `);
  console.log('Tabs in Edit mode:', editTabs);
  if (editTabs.length !== 3) {
    throw new Error('Edit mode did not restore all 3 floor tabs');
  }

  const editCeiling = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    const badge = document.getElementById('fpFloorMaxBadge');
    return {
      btnVisible: btn ? btn.style.display !== 'none' : false,
      badgeVisible: badge ? badge.style.display !== 'none' : false
    };
  })()`);
  console.log('Edit mode ceiling check:', editCeiling);
  if (editCeiling.btnVisible || !editCeiling.badgeVisible) {
    throw new Error('Edit mode ceiling not enforced: button should be hidden and badge visible');
  }

  // STEP 14: Test Backwards Compatibility with legacy proposal (ID 141)
  console.log('15. Testing backwards compatibility by loading single-floor proposal (ID 141)...');
  await send('Page.navigate', { url: 'http://localhost/sti-activity-system/faculty/proposal-edit.php?id=141' });
  await sleep(2500);
  await evaluate(`showStep(4);`);
  await sleep(1000);

  const legacyTabs = await evaluate(`
    Array.from(document.querySelectorAll('.fp-floor-tab')).map(t => ({
      floor: t.dataset.floor,
      text: t.innerText.trim(),
      active: t.classList.contains('active')
    }))
  `);
  console.log('Legacy proposal tabs:', legacyTabs);
  if (legacyTabs.length !== 1 || legacyTabs[0].floor !== 'ground') {
    throw new Error('Legacy single-floor proposal did not load cleanly as Ground Floor');
  }

  const legacyAddBtn = await evaluate(`(() => {
    const btn = document.getElementById('fpAddFloorBtn');
    return btn ? btn.innerText.trim() : '';
  })()`);
  console.log('Legacy proposal Add button text:', legacyAddBtn);
  if (!legacyAddBtn.includes('2nd Floor')) {
    throw new Error('Legacy proposal Add button should allow adding 2nd Floor');
  }

  // Clean up test draft from database
  console.log('16. Cleaning up test activity from database...');
  const delScript = path.join(__dirname, '..', 'scratch', 'del_draft_temp.php');
  fs.writeFileSync(delScript, `<?php require_once __DIR__ . '/../config/database.php'; getDB()->prepare('DELETE FROM activities WHERE id=?')->execute([${savedRow.id}]);`);
  execSync(`php "${delScript}"`);
  try { fs.unlinkSync(delScript); } catch(e) {}

  console.log('\n===========================================');
  console.log(' ALL 15 VERIFICATION CHECKS PASSED! SUCCESS! ');
  console.log('===========================================\n');

  try { chromeProc.kill(); } catch(e) {}
  process.exit(0);
}

runTest().catch(err => {
  console.error('\n❌ TEST FAILED:', err);
  process.exit(1);
});
