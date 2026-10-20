/**
 * meaningful-validation.js
 *
 * Client-side meaningful-input validation for Activity Proposals.
 * Detects placeholder text, keyboard mash (e.g. asdf, qwerty, 12345),
 * single characters (a, 7), excessive repetition, and insufficient length
 * while permitting legitimate single-word answers (Hackathon, Auditorium).
 */

const MeaningfulValidation = (function () {
  'use strict';

  // Field configurations: min length, min words, min letters, error message
  const fieldRules = {
    // Step 1
    f1_title: {
      key: 'title',
      errId: 'e_title',
      minLen: 3,
      minWords: 1,
      minLetters: 2,
      label: 'Event title',
      msg: 'Please enter a meaningful event title (at least 3 characters; avoid keyboard mash or random numbers).'
    },
    f1_venue: {
      key: 'venue',
      errId: 'e_venue',
      minLen: 3,
      minWords: 1,
      minLetters: 2,
      label: 'Venue',
      msg: 'Please enter a valid venue name (avoid keyboard mash or random numbers).'
    },
    f1_target_participants: {
      key: 'target_participants',
      errId: 'e_target_participants',
      isNumeric: true,
      minVal: 1,
      label: 'Target participants',
      msg: 'Please enter a valid positive number of target participants (e.g. 100).'
    },
    f1_venue_address: {
      key: 'venue_address',
      errId: 'e_venue_address',
      minLen: 5,
      minWords: 1,
      minLetters: 3,
      label: 'Venue address',
      msg: 'Please enter a complete and substantive venue address.'
    },
    f1_theme: {
      key: 'theme',
      errId: 'e_theme',
      minLen: 4,
      minWords: 1,
      minLetters: 3,
      label: 'Event theme',
      msg: 'Please enter a meaningful event theme (at least 4 characters).'
    },

    // Step 2 (Descriptive Fields)
    f2_involved_subjects: {
      key: 'involved_subjects',
      errId: 'e_involved_subjects',
      minLen: 2,
      minWords: 1,
      minLetters: 1,
      label: 'Involved subjects',
      msg: 'Please enter valid subject codes (e.g. ITE314, GE102).'
    },
    f2_general_objectives: {
      key: 'general_objectives',
      errId: 'e_general_objectives',
      minLen: 20,
      minWords: 3,
      minLetters: 15,
      label: 'General objectives',
      msg: 'General objectives must be substantive (at least 20 characters and 3 words; avoid placeholder text).'
    },
    f2_specific_objectives: {
      key: 'specific_objectives',
      errId: 'e_specific_objectives',
      minLen: 20,
      minWords: 3,
      minLetters: 15,
      label: 'Specific objectives',
      msg: 'Specific objectives must be substantive (at least 20 characters and 3 words; avoid placeholder text).'
    },
    f2_rationale: {
      key: 'rationale',
      errId: 'e_rationale',
      minLen: 20,
      minWords: 3,
      minLetters: 15,
      label: 'Rationale',
      msg: 'Activity rationale must provide substantive justification (at least 20 characters and 3 words).'
    },

    // Step 4
    f3_floor_plan_notes: {
      key: 'floor_plan_notes',
      errId: 'e_floor_plan_notes',
      minLen: 15,
      minWords: 2,
      minLetters: 10,
      label: 'Floor plan notes',
      msg: 'Floor plan notes must describe the layout and facilities (at least 15 characters).'
    },

    // Step 7
    f7_mechanics: {
      key: 'guidelines_mechanics',
      errId: 'e_mechanics',
      minLen: 15,
      minWords: 2,
      minLetters: 10,
      label: 'Guidelines / Mechanics',
      msg: 'Event mechanics must describe rules and procedures (at least 15 characters).'
    },
    f7_criteria: {
      key: 'guidelines_criteria',
      errId: 'e_criteria',
      minLen: 15,
      minWords: 2,
      minLetters: 10,
      label: 'Judging criteria',
      msg: 'Judging criteria must be substantive (at least 15 characters).'
    },
    f7_scoring: {
      key: 'guidelines_scoring',
      errId: 'e_scoring',
      minLen: 15,
      minWords: 2,
      minLetters: 10,
      label: 'Scoring system',
      msg: 'Scoring system description must be substantive (at least 15 characters).'
    },
    f7_awards: {
      key: 'guidelines_awards',
      errId: 'e_awards',
      minLen: 10,
      minWords: 2,
      minLetters: 8,
      label: 'Special awards',
      msg: 'Special awards details must be substantive (at least 10 characters).'
    }
  };

  /**
   * Recognizes legitimate address patterns, including street numbers,
   * street names, city names, building names, and common address abbreviations.
   */
  function isPlausibleAddress(text) {
    if (!text || typeof text !== 'string') return false;
    const trimmed = text.trim();
    if (trimmed.length < 5) return false;

    const letters = trimmed.match(/[a-zA-Z]/g);
    if (!letters || letters.length < 3) return false;

    if (/^[0-9\W_]+$/.test(trimmed)) return false;

    const addressPatterns = [
      // Street indicators and common abbreviations
      /\b(?:street|st\.?|avenue|ave\.?|boulevard|blvd\.?|road|rd\.?|highway|hway\.?|drive|dr\.?|lane|ln\.?|way|court|ct\.?|circle|cir\.?|alley)\b/i,
      // Administrative divisions, barangays, subdivisions, cities, provinces
      /\b(?:barangay|brgy\.?|bgy\.?|barrio|bo\.?|subdivision|subd\.?|village|vil\.?|phase|ph\.?|block|blk\.?|lot|zone|district|dist\.?|city|municipality|metro|manila|province)\b/i,
      // Building, venue, campus, and institutional keywords
      /\b(?:building|bldg\.?|tower|center|centre|ctr\.?|hall|complex|compound|campus|college|school|university|univ\.?|academy|institute|institution|sti|room|rm\.?|floor|flr\.?|unit|suite|plaza)\b/i,
      // Major Philippine cities and municipalities
      /\b(?:marikina|quezon|pasig|manila|makati|taguig|mandaluyong|pasay|paranaque|parañaque|las\s+pinas|las\s+piñas|muntinlupa|caloocan|malabon|navotas|valenzuela|san\s+juan|antipolo|cainta|taytay|angono|binangonan|san\s+mateo|rodriguez|montalban|rizal|cavite|laguna|bulacan|pampanga|cebu|davao)\b/i,
      // Street number + street name (e.g. "123 Rizal", "289 L. de Guzman")
      /\b(?:no\.?|#)?\s*\d{1,5}\s+[A-Za-z]/i,
      // Postal / ZIP code (e.g. 1807, 1800)
      /\b(?:1[0-9]{3}|2[0-9]{3})\b/
    ];

    return addressPatterns.some(pattern => pattern.test(trimmed));
  }

  /**
   * Recognizes keyboard walk sequences, home-row mashing, and random letter strings
   * (e.g. asd, asdd, asfasdas, asdasjfss, qwerty, zxcvb).
   */
  function isKeyboardMashWord(word) {
    if (!word || typeof word !== 'string') return false;
    const clean = word.toLowerCase().replace(/[^a-z]/g, '');
    const len = clean.length;
    if (len < 3) return false;

    // 1. Direct keyboard walk sequences
    if (/(?:asd|qwe|zxc|wsx|edc|rfv|tgb|yhn|ujm|ghjk|hjkl|lkjh)/i.test(clean)) {
      return true;
    }

    // 2. Composed exclusively of home-row keys [asdfghjkl] (length >= 4)
    if (len >= 4 && /^[asdfghjkl]+$/i.test(clean)) {
      const allowedHome = [
        'dash', 'fall', 'falls', 'flag', 'flags', 'flash', 'flask',
        'glad', 'glass', 'half', 'hall', 'halls', 'salad', 'shall', 'slash', 'hash'
      ];
      if (!allowedHome.includes(clean)) {
        return true;
      }
    }

    // 3. Composed exclusively of top-row keys [qwertyuiop] (length >= 4)
    if (len >= 4 && /^[qwertyuiop]+$/i.test(clean)) {
      const allowedTop = [
        'type', 'tree', 'root', 'pure', 'poor', 'power', 'tower', 'route',
        'write', 'true', 'trip', 'quiet', 'quote', 'port', 'rope', 'wire',
        'tire', 'peer', 'prop', 'pour', 'weep', 'wipe', 'riot', 'tour',
        'troop', 'upper', 'writer', 'equity', 'output', 'input', 'prior', 'report', 'outer'
      ];
      if (!allowedTop.includes(clean)) {
        return true;
      }
    }

    // 4. Composed exclusively of bottom-row keys [zxcvbnm] (length >= 3)
    if (len >= 3 && /^[zxcvbnm]+$/i.test(clean)) {
      return true;
    }

    // 5. 3+ identical consecutive characters
    if (/(.)\1{2,}/.test(clean)) {
      return true;
    }

    return false;
  }

  /**
   * Evaluates text against meaningfulness heuristics.
   */
  function evaluateText(text, rule) {
    const trimmed = (text || '').trim();
    if (!trimmed) {
      // Empty input is handled by required-field validation
      return { valid: true, message: '' };
    }

    // Numeric field handling (e.g. target participants, counts)
    if (rule.isNumeric) {
      const num = Number(trimmed);
      if (!Number.isFinite(num) || num < (rule.minVal !== undefined ? rule.minVal : 1)) {
        return {
          valid: false,
          message: rule.msg || 'Please enter a valid positive number.'
        };
      }
      return { valid: true, message: '' };
    }

    const lower = trimmed.toLowerCase();
    const isSubjectField = Boolean(rule && (rule.key === 'involved_subjects' || rule.key === 'f2_involved_subjects'));
    const allowedSubjectCodes = ['dsa', 'pe', 'cs', 'it', 'mis', 'hrm', 'nstp', 'cwts', 'rotc', 'ge', 'ite'];

    // Whitelist legitimate subject abbreviations like DSA, CS, PE
    if (isSubjectField && allowedSubjectCodes.includes(lower)) {
      return { valid: true, message: '' };
    }

    // Plausible venue address check
    const isAddressField = Boolean(rule && (rule.key === 'venue_address' || rule.key === 'f1_venue_address'));
    if (isAddressField && isPlausibleAddress(trimmed)) {
      const addressWords = trimmed.split(/\s+/).filter(w => w.length > 0);
      let addressHasMash = false;
      for (const w of addressWords) {
        const wClean = w.replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
        if (/^\d+$/.test(wClean)) continue;
        if (isKeyboardMashWord(wClean)) {
          addressHasMash = true;
          break;
        }
      }
      if (!addressHasMash) {
        return { valid: true, message: '' };
      }
    }

    const len = trimmed.length;
    const minLen = rule.minLen || 3;
    const minWords = rule.minWords || 1;
    const minLetters = rule.minLetters || 2;

    // Check minimum length
    if (len < minLen) {
      return {
        valid: false,
        message: rule.msg || `Content is too short (minimum ${minLen} characters required).`
      };
    }

    // Count letters
    const letterMatches = trimmed.match(/[a-zA-Z]/g);
    const letterCount = letterMatches ? letterMatches.length : 0;
    if (letterCount < minLetters) {
      return {
        valid: false,
        message: rule.msg || `Input must contain meaningful text and letters (at least ${minLetters} letters).`
      };
    }

    // Check pure digits or symbols
    if (/^[0-9\W_]+$/.test(trimmed)) {
      return {
        valid: false,
        message: rule.msg || 'Input cannot be composed only of numbers or symbols.'
      };
    }

    const lower = trimmed.toLowerCase();

    // 1. Placeholder words
    const placeholders = [
      'test', 'testing', 'sample', 'placeholder', 'lorem ipsum',
      'none', 'n/a', 'na', 'tbd', 'tba', 'null', 'undefined',
      'xxx', 'xxxx', 'asd', 'asdf', 'qwerty', 'qwe', 'zxc', 'zxcv', '123', '1234', '12345', '54321', 'blah', 'bla', 'temp', 'draft', 'abc', 'xyz'
    ];
    if (placeholders.includes(lower)) {
      return {
        valid: false,
        message: 'Placeholder text is not accepted. Please provide substantive information.'
      };
    }

    // 2. Exact or prominent keyboard mash patterns
    const mashPatterns = [
      /^(?:asd|asdf|asdfg|asdfgh|asdfghjkl|asdfghjk|qwe|qwert|qwerty|qwertyuiop|zxc|zxcv|zxcvb|zxcvbnm|123|1234|12345|123456|1234567890|012345|54321|321|qaz|wsx|edc|rfv|tgb|yhn|ujm|qazwsx|wsxedc|ghj|ghjkl|yui|yuiop|wasd|ytrewq|fdsa|dsa|gfdsa|lkjhg|lkj|mnbvc|bvcxz|cxz)+$/i,
      /(.)\1{3,}/,       // 4+ repeated identical characters: e.g. "aaaa", "1111"
      /^(.)\1{2,}$/,      // string composed solely of 3+ repeated characters: e.g. "aaa", "777"
      /^(..)\1{2,}$/,     // repeated 2-char pattern: "ababab"
      /^(...)\1{2,}$/,    // repeated 3-char pattern: "abcabcabc"
      /^(....)\1+$/       // repeated 4-char pattern: "asdfasdf"
    ];

    for (const pat of mashPatterns) {
      if (pat.test(lower)) {
        return {
          valid: false,
          message: 'Input contains keyboard sequences or repetitive patterns. Please provide substantive text.'
        };
      }
    }

    // Prominent keyboard mash substrings in short inputs
    const seqSubstrings = ['asdf', 'qwerty', 'zxcvb', '12345', 'qazwsx', '54321', 'fdsa', 'ytrewq'];
    for (const seq of seqSubstrings) {
      if (lower.includes(seq)) {
        const cleanLetters = lower.replace(/[^a-z0-9]/g, '');
        if (cleanLetters.length < 25 || new RegExp(`^(?:${seq})+$`, 'i').test(cleanLetters)) {
          return {
            valid: false,
            message: 'Input appears to contain keyboard mash or sequential patterns.'
          };
        }
      }
    }

    // 3. Word count check
    const words = trimmed.split(/\s+/).filter(w => w.length > 0);
    if (words.length < minWords) {
      return {
        valid: false,
        message: rule.msg || `Content must contain at least ${minWords} word(s).`
      };
    }

    // Check individual words for known gibberish or keyboard mash words
    const knownGibberishWords = isAddressField
      ? ['asd', 'asdf', 'qwerty', 'zxcv', 'qwe', 'zxc']
      : ['asd', 'asdf', 'qwerty', 'zxcv', 'qwe', 'zxc', '123', '1234', '12345'];

    for (const w of words) {
      const wClean = w.replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
      if (isAddressField && /^\d+$/.test(wClean)) {
        // In addresses, numeric tokens (e.g. 123, 289, 1807) are street/unit numbers or postal codes
        continue;
      }
      if (isSubjectField && allowedSubjectCodes.includes(wClean)) {
        continue;
      }
      if (isKeyboardMashWord(wClean) || knownGibberishWords.includes(wClean)) {
        return {
          valid: false,
          message: 'Input contains meaningless keyboard sequence or placeholder text.'
        };
      }
    }

    // 4. Word repetition spam check
    if (words.length >= 3) {
      const uniqueWords = new Set(words.map(w => w.toLowerCase()));
      if (uniqueWords.size === 1 || (words.length >= 4 && uniqueWords.size < Math.ceil(words.length / 2))) {
        return {
          valid: false,
          message: 'Text contains repetitive word spam. Please write meaningful content.'
        };
      }
    }

    // 5. Vowels and consonant check for words of 3+ characters
    const allowedNoVowelWords = ['bldg', 'blk', 'flr', 'str', 'brgy', 'subd', 'dept', 'pkg', 'hwy', 'pkwy', 'apt', 'ste', 'rm', 'ctr', 'dist', 'ph'];
    if (letterCount >= 3) {
      for (const w of words) {
        const cleanWord = w.replace(/[^a-zA-Z]/g, '');
        const wLen = cleanWord.length;
        if (wLen >= 3) {
          const cleanLower = cleanWord.toLowerCase();
          // Check for complete lack of vowels (a, e, i, o, u, y)
          if (!/[aeiouyAEIOUY]/.test(cleanWord)) {
            // Allow uppercase acronyms and common address abbreviations (e.g. Bldg, Blk, Flr)
            if (!allowedNoVowelWords.includes(cleanLower) && (cleanWord !== cleanWord.toUpperCase() || wLen > 5)) {
              return {
                valid: false,
                message: `Word "${cleanWord}" appears to be meaningless (no vowels).`
              };
            }
          }
          // Check for 5+ consecutive consonants
          if (/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{5,}/.test(cleanWord)) {
            return {
              valid: false,
              message: `Word "${cleanWord}" contains meaningless consonant sequences.`
            };
          }
        }
      }
    }

    // 6. Character diversity check for inputs of length >= 6
    const cleanChars = lower.replace(/[^a-z0-9]/g, '');
    if (cleanChars.length >= 6) {
      const uniqueCount = new Set(cleanChars).size;
      if (uniqueCount <= 2) {
        return {
          valid: false,
          message: 'Input has too few distinct characters.'
        };
      }
    }

    return { valid: true, message: '' };
  }

  function isMonitoredField(fieldId) {
    return Boolean(fieldRules[fieldId]);
  }

  function getFieldRule(fieldId) {
    return fieldRules[fieldId] || null;
  }

  /**
   * Validates a field element by ID and returns { valid: bool, message: string }
   */
  function checkField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return { valid: true, message: '' };
    const rule = fieldRules[fieldId];
    if (!rule) return { valid: true, message: '' };
    return evaluateText(el.value, rule);
  }

  /**
   * Validates a monitored field and updates its warning UI and is-invalid class.
   * If value is empty, meaningful warning is hidden (empty-field error handles it).
   */
  function validateFieldUI(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return true;
    const rule = fieldRules[fieldId];
    if (!rule) return true;

    const val = (el.value || '').trim();
    const meaningfulErrId = rule.errId + '_meaningful';
    const meaningfulErrEl = document.getElementById(meaningfulErrId);
    const requiredErrEl = document.getElementById(rule.errId);

    if (!val) {
      // Empty: hide meaningful warning; let required-field validation handle empty state
      if (meaningfulErrEl) {
        meaningfulErrEl.classList.remove('visible');
        meaningfulErrEl.style.display = 'none';
      }
      return false; // Still empty
    }

    const result = evaluateText(val, rule);

    if (!result.valid) {
      // Meaningless content: flag field and show warning beside field
      el.classList.add('is-invalid');
      if (requiredErrEl) {
        requiredErrEl.classList.remove('visible'); // Hide "required" error to avoid duplicate messages
        requiredErrEl.style.display = 'none';
      }
      if (meaningfulErrEl) {
        meaningfulErrEl.textContent = result.message || rule.msg;
        meaningfulErrEl.classList.add('visible');
        meaningfulErrEl.style.display = 'block';
      }
      return false;
    } else {
      // Valid meaningful content: clear meaningful warning
      if (meaningfulErrEl) {
        meaningfulErrEl.classList.remove('visible');
        meaningfulErrEl.style.display = 'none';
      }
      if (requiredErrEl) {
        requiredErrEl.classList.remove('visible');
        requiredErrEl.style.display = 'none';
      }
      el.classList.remove('is-invalid');
      return true;
    }
  }

  /**
   * Clears the meaningful warning for a field.
   */
  function clearFieldUI(fieldId) {
    const el = document.getElementById(fieldId);
    const rule = fieldRules[fieldId];
    if (rule) {
      const meaningfulErrEl = document.getElementById(rule.errId + '_meaningful');
      if (meaningfulErrEl) {
        meaningfulErrEl.classList.remove('visible');
        meaningfulErrEl.style.display = 'none';
      }
    }
    if (el) {
      el.classList.remove('is-invalid');
    }
  }

  /**
   * Initializes real-time input and blur event listeners.
   */
  function init() {
    // 1. Delegated listener for real-time validation as the user types
    document.addEventListener('input', function (e) {
      const fieldId = e.target.id;
      if (isMonitoredField(fieldId)) {
        validateFieldUI(fieldId);
      }
    });

    // 2. Delegated listener when user leaves a field (blur)
    document.addEventListener('blur', function (e) {
      const fieldId = e.target.id;
      if (isMonitoredField(fieldId)) {
        validateFieldUI(fieldId);
      }
    }, true);

    // 3. Direct element event listeners for maximum reliability on all monitored fields
    for (const fieldId of Object.keys(fieldRules)) {
      const el = document.getElementById(fieldId);
      if (el) {
        el.addEventListener('input', function () {
          validateFieldUI(fieldId);
        });
        el.addEventListener('blur', function () {
          validateFieldUI(fieldId);
        });
      }
    }
  }

  return {
    init: init,
    checkField: checkField,
    validateFieldUI: validateFieldUI,
    clearFieldUI: clearFieldUI,
    isMonitoredField: isMonitoredField,
    getFieldRule: getFieldRule,
    evaluateText: evaluateText,
    isPlausibleAddress: isPlausibleAddress,
    fieldRules: fieldRules
  };
})();

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', MeaningfulValidation.init);
} else {
  MeaningfulValidation.init();
}
