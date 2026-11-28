const fs = require('fs');
const path = require('path');
const vm = require('vm');

const code = fs.readFileSync(path.join(__dirname, '../assets/js/file-upload-validator.js'), 'utf8');

// Minimal DOM mock
const listeners = {};
let appendedToBody = [];
const mockDoc = {
  addEventListener: (ev, fn) => { listeners[ev] = fn; },
  getElementById: () => null,
  createElement: (tag) => ({
    tagName: tag.toUpperCase(),
    style: { setProperty: () => {}, removeProperty: () => {} },
    children: [],
    setAttribute: () => {},
    appendChild(el) { this.children.push(el); },
    remove() {}
  }),
  body: {
    appendChild(el) { appendedToBody.push(el); }
  }
};

const sandbox = { window: {}, document: mockDoc, setTimeout: () => {} };
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

const V = sandbox.window.STIFileUploadValidator;
let pass = 0, fail = 0;
function check(name, cond) {
  if (cond) { pass++; console.log(' [PASS]', name); }
  else { fail++; console.error(' [FAIL]', name); }
}

function makeInput(accept, fileNames) {
  let warningBanner = null;
  const parent = {
    querySelector: () => warningBanner,
    insertBefore: (el) => { warningBanner = el; }
  };
  return {
    tagName: 'INPUT',
    type: 'file',
    value: fileNames[0] || '',
    files: fileNames.map(n => ({ name: n })),
    parentElement: parent,
    parentNode: parent,
    nextSibling: null,
    style: { setProperty: () => {}, removeProperty: () => {} },
    getAttribute: (a) => (a === 'accept' ? accept : null),
    setAttribute: () => {},
    removeAttribute: () => {},
    getWarning: () => warningBanner
  };
}

let stopped = false;
const ev = { stopImmediatePropagation: () => { stopped = true; } };

// 1. PDF uploaded to CSV input -> must reject & warn
const csvWithPdf = makeInput('.csv,text/csv', ['report.pdf']);
stopped = false;
const r1 = V.validateFileInput(csvWithPdf, ev);
check('Rejects .pdf attached to .csv input', r1 === false && csvWithPdf.value === '' && stopped === true && csvWithPdf.getWarning() !== null);

// 2. Word (.docx) uploaded to CSV input -> must reject & warn
const csvWithDocx = makeInput('.csv,text/csv', ['survey.docx']);
stopped = false;
const r2 = V.validateFileInput(csvWithDocx, ev);
check('Rejects .docx attached to .csv input', r2 === false && csvWithDocx.value === '' && stopped === true && csvWithDocx.getWarning() !== null);

// 3. Valid .csv uploaded to CSV input -> must accept & clear warning
const csvValid = makeInput('.csv,text/csv', ['responses.CSV']);
stopped = false;
const r3 = V.validateFileInput(csvValid, ev);
check('Accepts valid .CSV attached to .csv input', r3 === true && csvValid.value === 'responses.CSV' && stopped === false && csvValid.getWarning() === null);

// 4. Word (.docx) uploaded to PDF-only input -> must reject & warn
const pdfWithDocx = makeInput('.pdf,application/pdf', ['notes.docx']);
stopped = false;
const r4 = V.validateFileInput(pdfWithDocx, ev);
check('Rejects .docx attached to .pdf input', r4 === false && pdfWithDocx.value === '' && stopped === true);

// 5. Image (.png) uploaded to MIME-only image input -> must accept
const imgValid = makeInput('image/jpeg,image/png,image/webp', ['avatar.png']);
stopped = false;
const r5 = V.validateFileInput(imgValid, ev);
check('Accepts .png on MIME-only image accept attribute', r5 === true && imgValid.value === 'avatar.png');

// 6. PDF uploaded to MIME-only image input -> must reject
const imgWithPdf = makeInput('image/jpeg,image/png,image/webp', ['doc.pdf']);
stopped = false;
const r6 = V.validateFileInput(imgWithPdf, ev);
check('Rejects .pdf on MIME-only image accept attribute', r6 === false && imgWithPdf.value === '');

console.log(`\nSummary: ${pass} Passed, ${fail} Failed.`);
process.exit(fail === 0 ? 0 : 1);
