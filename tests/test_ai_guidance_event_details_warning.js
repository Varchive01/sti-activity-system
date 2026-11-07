/**
 * Test AI Guidance Event Details Warning
 * Verifies that clicking "Request AI Guidance" when Event Details are not filled up
 * shows a pop-up warning requiring the user to fill up Event Details first.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let passed = 0;
let failed = 0;

function assert(condition, testName, details = '') {
  if (condition) {
    console.log(`[PASS] ${testName}`);
    passed++;
  } else {
    console.error(`[FAIL] ${testName}: ${details}`);
    failed++;
  }
}

console.log('=== STARTING AI GUIDANCE EVENT DETAILS WARNING TESTS ===\n');

// 1. Create a simulated DOM environment
const scriptCode = fs.readFileSync(path.join(__dirname, '../assets/js/ai-proposal-guidance.js'), 'utf8');

function createMockEnvironment(formData = {}, onStepChange = null) {
  let alertCall = null;
  let currentStep = 1;

  const mockElements = {
    f1_title: { value: formData.title || '', focus: () => { mockElements.f1_title.focused = true; } },
    f1_event_date: { value: formData.event_date || '', focus: () => { mockElements.f1_event_date.focused = true; } },
    f1_source: { value: formData.source || '', focus: () => { mockElements.f1_source.focused = true; } },
    f1_start_time: { value: formData.start_time || '', focus: () => { mockElements.f1_start_time.focused = true; } },
    f1_end_time: { value: formData.end_time || '', focus: () => { mockElements.f1_end_time.focused = true; } },
    f1_theme: { value: formData.theme || '', focus: () => { mockElements.f1_theme.focused = true; } },
    f1_venue: { value: formData.venue || '', focus: () => { mockElements.f1_venue.focused = true; } },
    f1_venue_address: { value: formData.venue_address || '', focus: () => { mockElements.f1_venue_address.focused = true; } },
    f1_target_participants: { value: formData.target_participants || '0' }
  };

  const mockForm = {
    querySelector: (selector) => {
      const match = selector.match(/\[name="([^"]+)"\]/);
      if (match && mockElements[`f1_${match[1]}`]) {
        return mockElements[`f1_${match[1]}`];
      }
      return null;
    },
    querySelectorAll: (selector) => {
      if (selector === '.part-section-input') {
        if (formData.part_section) {
          return [{ value: formData.part_section }];
        }
        return [{ value: '' }];
      }
      return [];
    }
  };

  const mockDoc = {
    readyState: 'complete',
    head: { appendChild: () => {} },
    body: { style: {}, appendChild: () => {}, removeChild: () => {} },
    getElementById: (id) => {
      if (id === 'proposalForm') return mockForm;
      if (mockElements[id]) return mockElements[id];
      return null;
    },
    querySelector: (selector) => {
      if (selector === '#proposalForm') return mockForm;
      return null;
    },
    querySelectorAll: () => [],
    createElement: (tag) => ({
      style: {},
      setAttribute: () => {},
      classList: { add: () => {}, remove: () => {} },
      addEventListener: () => {},
      removeEventListener: () => {}
    }),
    addEventListener: () => {}
  };

  const sandbox = {
    document: mockDoc,
    window: {
      location: { pathname: '/sti-activity-system/faculty/proposal-create.php' },
      BASE_URL: 'http://localhost/sti-activity-system',
      showStep: (n) => {
        currentStep = n;
        if (onStepChange) onStepChange(n);
      },
      STIModal: {
        alert: async (message, opts) => {
          alertCall = { message, opts };
          return true;
        }
      },
      setTimeout: (fn) => fn(),
      console: console
    },
    alert: (msg) => { alertCall = { message: msg }; },
    setTimeout: (fn) => fn(),
    clearTimeout: () => {}
  };
  sandbox.window.document = mockDoc;

  vm.createContext(sandbox);
  vm.runInContext(scriptCode, sandbox);

  const guidance = vm.runInContext('AIProposalGuidance', sandbox);

  return {
    AIProposalGuidance: guidance,
    getAlertCall: () => alertCall,
    getCurrentStep: () => currentStep,
    mockElements
  };
}

(async () => {
  // Test Case 1: When user clicks Request AI Guidance on an empty proposal form
  {
    const env = createMockEnvironment({});
    const missing = env.AIProposalGuidance.getMissingEventDetails();
    assert(missing.length >= 8, 'Empty form correctly detects all missing Event Details', `Found ${missing.length} missing fields`);

    await env.AIProposalGuidance.requestGuidance();
    const alert = env.getAlertCall();
    assert(alert !== null, 'Warning alert was triggered when Event Details are empty');
    assert(
      alert && alert.message.includes('You need to fill up the event details first before you can use the request ai guidance feature'),
      'Alert contains the exact required warning message',
      alert?.message
    );
    assert(alert && alert.opts && alert.opts.title === 'Event Details Required', 'Alert modal title is set to "Event Details Required"');
    assert(alert && alert.opts && alert.opts.variant === 'warning', 'Alert modal variant is set to "warning"');
    assert(env.mockElements.f1_title.focused === true, 'First missing field (title) was automatically focused');
  }

  // Test Case 2: When user is on another step (e.g. Step 3) and clicks Request AI Guidance
  {
    let stepChangedTo = null;
    const env = createMockEnvironment({}, (step) => { stepChangedTo = step; });
    await env.AIProposalGuidance.requestGuidance();
    assert(stepChangedTo === 1, 'User is automatically navigated back to Step 1 (Event Details)');
  }

  // Test Case 3: When form is partially filled (e.g. only title entered, but venue and theme missing)
  {
    const env = createMockEnvironment({
      title: 'Tech Summit 2026',
      source: 'faculty'
      // event_date, times, venue, theme missing
    });
    const missing = env.AIProposalGuidance.getMissingEventDetails();
    assert(missing.some(m => m.name === 'venue'), 'Partially filled form detects missing venue');
    assert(missing.some(m => m.name === 'theme'), 'Partially filled form detects missing theme');

    await env.AIProposalGuidance.requestGuidance();
    const alert = env.getAlertCall();
    assert(alert !== null, 'Warning alert triggered for partially filled event details');
  }

  // Test Case 4: When all Event Details are fully filled
  {
    const env = createMockEnvironment({
      title: 'STI College Annual Hackathon 2026',
      event_date: '2026-11-20',
      source: 'faculty',
      start_time: '08:00',
      end_time: '17:00',
      theme: 'Innovating for Tomorrow',
      venue: 'Gymnasium & Multipurpose Hall',
      venue_address: '289 L. de Guzman Street, Marikina City',
      target_participants: '150',
      part_section: 'BSIT 301'
    });

    const missing = env.AIProposalGuidance.getMissingEventDetails();
    assert(missing.length === 0, 'Fully filled form reports 0 missing Event Details', JSON.stringify(missing));

    await env.AIProposalGuidance.requestGuidance();
    const alert = env.getAlertCall();
    assert(alert === null, 'No warning alert triggered when Event Details are complete');
  }

  // Test Case 5: Verify window.showStep is exposed on proposal-create.php and proposal-edit.php
  {
    const createCode = fs.readFileSync(path.join(__dirname, '../faculty/proposal-create.php'), 'utf8');
    const editCode = fs.readFileSync(path.join(__dirname, '../faculty/proposal-edit.php'), 'utf8');

    assert(createCode.includes('window.showStep = showStep;'), 'faculty/proposal-create.php exposes window.showStep');
    assert(editCode.includes('window.showStep = showStep;'), 'faculty/proposal-edit.php exposes window.showStep');
  }

  console.log('\n=== TEST RESULTS SUMMARY ===');
  console.log(`Passed: ${passed}`);
  console.log(`Failed: ${failed}`);

  if (failed === 0) {
    console.log('>>> ALL AI GUIDANCE WARNING TESTS PASSED! <<<');
    process.exit(0);
  } else {
    console.error('>>> SOME TESTS FAILED! <<<');
    process.exit(1);
  }
})();
