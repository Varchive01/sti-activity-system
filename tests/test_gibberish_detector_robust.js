const assert = require('assert');

function isGibberishToken(token) {
  token = token.replace(/^[ \t\n\r\v,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+|[ \t\n\r\v,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+$/g, '');
  if (token.length < 3) return false;

  // If token has no letters at all (pure numbers, time like '8:00', '100%', '10,000'), not gibberish
  if (!/[A-Za-z]/.test(token)) return false;

  // Allow standard alphanumeric codes/course codes (e.g. 'CS101', 'ITE314', 'GE102', 'ACT-101')
  if (/^[A-Za-z]{2,5}-?\d{1,4}[A-Za-z]?$/i.test(token)) return false;

  // Allow ordinal numbers (e.g. '1st', '2nd', '3rd', '4th')
  if (/^\d+(st|nd|rd|th)$/i.test(token)) return false;

  // Allow all-uppercase abbreviations and common pluralized acronyms (e.g. 'JPCS', 'AWS', 'STI', 'PDRRMC', 'SSG', 'UNESCO', 'SDGs', 'KPIs')
  if (/^[A-Z]{2,6}s?$/.test(token)) return false;

  // For hyphenated compounds (e.g. 'world-class', 'well-structured', 'peer-to-peer', 'hands-on'),
  // evaluate each hyphen-separated component so letters are not concatenated across word boundaries.
  if (token.includes('-')) {
    const subTokens = token.split('-').filter(p => p.length > 0);
    for (const sub of subTokens) {
      if (sub.length >= 3 && isGibberishToken(sub)) {
        return true;
      }
    }
    return false;
  }

  const lower = token.toLowerCase();
  // Extract letter portion for checks when numbers or symbols are appended (e.g. 'fsdfsd123', 'asdd99')
  const letters = lower.replace(/[^a-z]/g, '');

  // 1. Must contain at least one vowel (a, e, i, o, u, y) if length of letters >= 3.
  // If only 'y' is present (no a, e, i, o, u) and length >= 6, it is gibberish unless whitelisted (e.g. 'rhythm', 'rhythms').
  if (letters.length >= 3 && !/[aeiouy]/.test(letters)) {
    return true;
  }
  if (letters.length >= 6 && !/[aeiou]/.test(letters)) {
    const yWhitelist = ['rhythm', 'rhythms'];
    if (!yWhitelist.includes(letters)) return true;
  }

  // 2. Obvious keyboard walks / mashing runs (3+ char runs)
  if (/asdf|fdsa|qwerty|ytrewq|zxcvb|bvcxz|hjkl|lkjh|qwer|rewq|ghjk|kjhg|dfgh|fghj|poiuy/i.test(lower)) {
    return true;
  }

  // 3. Repeated characters: 3+ identical characters (e.g. 'aaa', 'zzz') or repeated pairs (e.g. 'asdasd', 'fsdfsd')
  if (/(.)\1{2,}/i.test(lower)) return true;
  if (/(.{2,3})\1{2,}/i.test(letters)) {
    return true;
  }

  // 4. Consecutive consonants: 5+ consecutive consonants in a word (unless whitelisted)
  const consonants = 'bcdfghjklmnpqrstvwxz'; // exclude y as semi-vowel
  if (new RegExp('[' + consonants + ']{5,}', 'i').test(letters)) {
    const whitelist = ['angst', 'strengths', 'twelfths', 'catchphrase', 'knights', 'lengths', 'birthplace', 'instinct', 'abstract', 'construct', 'instruct'];
    if (!whitelist.includes(letters)) return true;
  }

  // 5. Pure home-row mash [asdfghjkl] (length >= 3)
  if (letters.length >= 3 && /^[asdfghjkl]+$/.test(letters)) {
    const homeWhitelist = ['add', 'adds', 'all', 'ask', 'asks', 'dash', 'fall', 'falls', 'flag', 'flags', 'flash', 'flask', 'flasks', 'gas', 'glad', 'glass', 'had', 'half', 'hall', 'halls', 'has', 'lass', 'salad', 'salads', 'shall'];
    if (!homeWhitelist.includes(letters)) return true;
  }

  // 6. Pure top-row mash [qwertyuiop] (length >= 3)
  if (letters.length >= 3 && /^[qwertyuiop]+$/.test(letters)) {
    if (['queue', 'queuer'].includes(letters)) return false;
    // Top row contains vowels e, i, o, u, y and consonants q, w, r, t, p.
    // Flag structural keyboard-mash characteristics:
    // - 3+ consecutive top-row consonants (e.g. 'qwtp', 'prtq', 'wrtp' - never occurring in English words)
    // - 'q' not followed by 'u' (e.g. 'qwer', 'qwe', 'tq', 'qpo')
    // - 4+ consecutive vowels (e.g. 'uioe', 'eouy')
    // - top-row keyboard walks
    if (/[wrtpq]{3,}/i.test(letters) || /q(?!u)/i.test(letters) || /[aeiouy]{4,}/i.test(letters) || /werty|trewq|poiuy|uytre/i.test(letters)) {
      return true;
    }
  }

  return false;
}

function hasGibberishText(val) {
  if (!val) return false;
  const words = val.trim().split(/\s+/);
  for (const w of words) {
    if (isGibberishToken(w)) return true;
  }
  return false;
}

// ── TEST CASES ──────────────────────────────────────────────────────────

const validAcademicWords = [
  'report', 'reports', 'reporter', 'reporting', 'reported',
  'output', 'outputs',
  'prototype', 'prototypes', 'prototyping', 'prototyped',
  'require', 'requires', 'required',
  'query', 'queries', 'querying',
  'tutor', 'tutors', 'tutoring',
  'equity', 'equities',
  'writeup', 'writeups',
  'add', 'adds', 'adding', 'added',
  'router', 'routers',
  'prep', 'preps',
  'troop', 'troops',
  'peer', 'peers',
  'power', 'powers',
  'error', 'errors',
  'tier', 'tiers',
  'quote', 'quotes',
  'type', 'types',
  'tree', 'trees',
  'trip', 'trips',
  'tour', 'tours',
  'queue', 'queuer', 'queues', 'queued', 'queuing', 'queueing',
  'Qatar', 'Iraq', 'quiet', 'quote', 'quite'
];

const validCompounds = [
  'world-class',
  'well-structured',
  'peer-to-peer',
  'hands-on',
  'state-of-the-art',
  'skills-based',
  'problem-solving'
];

const validAcronyms = [
  'SDGs', 'KPIs', 'JPCS', 'AWS', 'STI', 'PDRRMC', 'SSG', 'UNESCO'
];

const validProposalTexts = [
  `1. To develop students' technical skills in front-end and back-end web development.
2. To understand modern web technologies including HTML, CSS, JavaScript, and PHP.
3. To collaborate effectively in teams to build a functional prototype.
4. To evaluate project output and prepare a final project report.`,

  `1. To add value to the academic learning experience of STI students.
2. To provide peer tutor assistance in foundational programming subjects.
3. To promote equity in access to educational technology resources.
4. To require active student participation in collaborative workshops.`,

  `1. Align student project goals with the UN SDGs.
2. Build world-class software solutions using modern industry-grade frameworks.
3. Develop well-structured database schemas and optimize SQL query performance.
4. Deliver peer-to-peer mentorship and present comprehensive project writeups.`
];

const obviousGibberish = [
  'asdasdasd',
  'zxzxzx',
  'qwrtypsdf',
  'asdfgh',
  'qwerty',
  'poiuyt',
  'zxcvbn',
  'aaaaa',
  'qwerqwer',
  'lkjhgfdsa',
  'qwtpqwtp',
  'fghjkl',
  'rewqrewq',
  'eouyeouy',
  'uioeuioe'
];

console.log('--- 1. Testing Valid Academic Words ---');
validAcademicWords.forEach(w => {
  const result = isGibberishToken(w);
  assert.strictEqual(result, false, `Expected "${w}" to be ACCEPTED, but was flagged as gibberish.`);
});
console.log(`  -> ALL ${validAcademicWords.length} academic words PASSED!`);

console.log('\n--- 2. Testing Valid Hyphenated Compounds ---');
validCompounds.forEach(w => {
  const result = isGibberishToken(w);
  assert.strictEqual(result, false, `Expected compound "${w}" to be ACCEPTED, but was flagged as gibberish.`);
});
console.log(`  -> ALL ${validCompounds.length} hyphenated compounds PASSED!`);

console.log('\n--- 3. Testing Valid Acronyms ---');
validAcronyms.forEach(w => {
  const result = isGibberishToken(w);
  assert.strictEqual(result, false, `Expected acronym "${w}" to be ACCEPTED, but was flagged as gibberish.`);
});
console.log(`  -> ALL ${validAcronyms.length} acronyms PASSED!`);

console.log('\n--- 4. Testing Complete Proposal Objectives Text ---');
validProposalTexts.forEach((text, i) => {
  const result = hasGibberishText(text);
  assert.strictEqual(result, false, `Expected proposal text #${i + 1} to be ACCEPTED, but was flagged as gibberish.`);
});
console.log(`  -> ALL ${validProposalTexts.length} complete proposal texts PASSED!`);

console.log('\n--- 5. Testing Obvious Gibberish (Must be REJECTED) ---');
obviousGibberish.forEach(w => {
  const result = isGibberishToken(w);
  assert.strictEqual(result, true, `Expected gibberish "${w}" to be REJECTED, but was accepted.`);
});
console.log(`  -> ALL ${obviousGibberish.length} obvious gibberish strings successfully REJECTED!`);

console.log('\nALL TESTS PASSED WITH 100% SUCCESS!');
