<?php
/**
 * meaningful_validation.php
 *
 * Authoritative server-side meaningful-input validation for Activity Proposals.
 * Detects placeholder text, keyboard mash, excessive repetition, insufficient
 * length, and meaningless sequences while permitting legitimate short answers.
 */

if (!function_exists('isPlausibleAddress')) {
    /**
     * Recognizes legitimate address patterns, including street numbers,
     * street names, city names, building names, and common address abbreviations.
     *
     * @param string $text
     * @return bool
     */
    function isPlausibleAddress(string $text): bool {
        $trimmed = trim($text);
        if (strlen($trimmed) < 5) {
            return false;
        }

        preg_match_all('/[a-zA-Z]/', $trimmed, $m);
        if (count($m[0] ?? []) < 3) {
            return false;
        }

        if (preg_match('/^[0-9\W_]+$/', $trimmed)) {
            return false;
        }

        $addressPatterns = [
            // Street indicators and common abbreviations
            '/\b(?:street|st\.?|avenue|ave\.?|boulevard|blvd\.?|road|rd\.?|highway|hway\.?|drive|dr\.?|lane|ln\.?|way|court|ct\.?|circle|cir\.?|alley)\b/i',
            // Administrative divisions, barangays, subdivisions, cities, provinces
            '/\b(?:barangay|brgy\.?|bgy\.?|barrio|bo\.?|subdivision|subd\.?|village|vil\.?|phase|ph\.?|block|blk\.?|lot|zone|district|dist\.?|city|municipality|mun\.?|metro|manila|province|prov\.?)\b/i',
            // Building, venue, campus, and institutional keywords
            '/\b(?:building|bldg\.?|tower|center|centre|ctr\.?|hall|complex|compound|campus|college|school|university|univ\.?|academy|institute|institution|sti|room|rm\.?|floor|flr\.?|unit|suite|plaza)\b/i',
            // Major Philippine cities and municipalities
            '/\b(?:marikina|quezon|pasig|manila|makati|taguig|mandaluyong|pasay|paranaque|parañaque|las\s+pinas|las\s+piñas|muntinlupa|caloocan|malabon|navotas|valenzuela|san\s+juan|antipolo|cainta|taytay|angono|binangonan|san\s+mateo|rodriguez|montalban|rizal|cavite|laguna|bulacan|pampanga|cebu|davao)\b/i',
            // Street number + street name (e.g. "123 Rizal", "289 L. de Guzman")
            '/\b(?:no\.?|#)?\s*\d{1,5}\s+[A-Za-z]/i',
            // Postal / ZIP code (e.g. 1807, 1800)
            '/\b(?:1[0-9]{3}|2[0-9]{3})\b/'
        ];

        foreach ($addressPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('isKeyboardMashWord')) {
    /**
     * Recognizes keyboard walk sequences, home-row mashing, and random letter strings
     * (e.g. asd, asdd, asfasdas, asdasjfss, qwerty, zxcvb).
     *
     * @param string $word
     * @return bool
     */
    function isKeyboardMashWord(string $word): bool {
        $clean = strtolower(preg_replace('/[^a-zA-Z]/', '', $word));
        $len = strlen($clean);
        if ($len < 3) {
            return false;
        }

        // 1. Direct keyboard walk sequences (e.g. asd, qwe, zxc, wsx, edc, rfv, etc.)
        if (preg_match('/(?:asd|qwe|zxc|wsx|edc|rfv|tgb|yhn|ujm|ghjk|hjkl|lkjh)/i', $clean)) {
            return true;
        }

        // 2. Composed exclusively of home-row keys [asdfghjkl] (length >= 4)
        if ($len >= 4 && preg_match('/^[asdfghjkl]+$/i', $clean)) {
            $allowedHome = [
                'dash', 'fall', 'falls', 'flag', 'flags', 'flash', 'flask',
                'glad', 'glass', 'half', 'hall', 'halls', 'salad', 'shall', 'slash', 'hash'
            ];
            if (!in_array($clean, $allowedHome, true)) {
                return true;
            }
        }

        // 3. Composed exclusively of top-row keys [qwertyuiop] (length >= 4)
        if ($len >= 4 && preg_match('/^[qwertyuiop]+$/i', $clean)) {
            $allowedTop = [
                'type', 'tree', 'root', 'pure', 'poor', 'power', 'tower', 'route',
                'write', 'true', 'trip', 'quiet', 'quote', 'port', 'rope', 'wire',
                'tire', 'peer', 'prop', 'pour', 'weep', 'wipe', 'riot', 'tour',
                'troop', 'upper', 'writer', 'equity', 'output', 'input', 'prior', 'report', 'outer'
            ];
            if (!in_array($clean, $allowedTop, true)) {
                return true;
            }
        }

        // 4. Composed exclusively of bottom-row keys [zxcvbnm] (length >= 3)
        if ($len >= 3 && preg_match('/^[zxcvbnm]+$/i', $clean)) {
            return true;
        }

        // 5. 3+ identical consecutive characters
        if (preg_match('/(.)\1{2,}/', $clean)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('isMeaninglessInput')) {
    /**
     * Checks if a string appears to be meaningless text.
     *
     * @param string $text The input string to check
     * @param int $minLen Minimum character length (excluding whitespace)
     * @param int $minWords Minimum word count
     * @param int $minLetters Minimum alphabetic character count
     * @param bool $allowPureNumbers Whether pure numeric inputs are allowed
     * @param string $fieldKey Optional field key for context-specific heuristics
     * @return array ['meaningful' => bool, 'reason' => string]
     */
    function isMeaninglessInput(
        string $text,
        int $minLen = 3,
        int $minWords = 1,
        int $minLetters = 2,
        bool $allowPureNumbers = false,
        string $fieldKey = ''
    ): array {
        $trimmed = trim($text);
        $len = strlen($trimmed);

        if ($len < $minLen) {
            return [
                'meaningful' => false,
                'reason' => "Content is too short (minimum {$minLen} characters required)."
            ];
        }

        // Count alphabetic characters
        preg_match_all('/[a-zA-Z]/', $trimmed, $letterMatches);
        $letterCount = count($letterMatches[0] ?? []);

        if (!$allowPureNumbers && $letterCount < $minLetters) {
            return [
                'meaningful' => false,
                'reason' => "Content must contain at least {$minLetters} letter(s)."
            ];
        }

        // Reject pure digits or pure punctuation where letters are expected
        if (!$allowPureNumbers && preg_match('/^[0-9\W_]+$/', $trimmed)) {
            return [
                'meaningful' => false,
                'reason' => "Input cannot be composed only of numbers or symbols."
            ];
        }

        $lower = strtolower($trimmed);

        // Subject codes whitelist (e.g. DSA, PE, CS, IT)
        if (($fieldKey === 'involved_subjects' || $fieldKey === 'f2_involved_subjects') &&
            in_array($lower, ['dsa', 'pe', 'cs', 'it', 'mis', 'hrm', 'nstp', 'cwts', 'rotc', 'ge', 'ite'], true)) {
            return ['meaningful' => true, 'reason' => ''];
        }

        // 1. Exact common placeholder words or gibberish
        $placeholders = [
            'test', 'testing', 'sample', 'placeholder', 'lorem ipsum',
            'none', 'n/a', 'na', 'tbd', 'tba', 'null', 'undefined',
            'xxx', 'xxxx', 'asd', 'asdf', 'qwerty', 'qwe', 'zxc', 'zxcv', '123', '1234', '12345', '54321', 'blah', 'bla', 'temp', 'draft', 'abc', 'xyz'
        ];
        if (in_array($lower, $placeholders, true)) {
            return [
                'meaningful' => false,
                'reason' => "Placeholder text is not accepted."
            ];
        }

        // 2. Exact or prominent keyboard mash patterns
        $mashPatterns = [
            '/^(?:asd|asdf|asdfg|asdfgh|asdfghjkl|asdfghjk|qwe|qwert|qwerty|qwertyuiop|zxc|zxcv|zxcvb|zxcvbnm|123|1234|12345|123456|1234567890|012345|54321|321|qaz|wsx|edc|rfv|tgb|yhn|ujm|qazwsx|wsxedc|ghj|ghjkl|yui|yuiop|wasd|ytrewq|fdsa|dsa|gfdsa|lkjhg|lkj|mnbvc|bvcxz|cxz)+$/i',
            '/(.)\1{3,}/u',       // 4+ repeated identical characters: e.g. "aaaa", "1111", "...."
            '/^(.)\1{2,}$/u',      // string composed solely of 3+ repeated characters: e.g. "aaa", "777"
            '/^(..)\1{2,}$/u',     // repeated 2-char pattern: "ababab", "qwqwqw"
            '/^(...)\1{2,}$/u',    // repeated 3-char pattern: "abcabcabc"
            '/^(....)\1+$/u'       // repeated 4-char pattern: "asdfasdf"
        ];

        foreach ($mashPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return [
                    'meaningful' => false,
                    'reason' => "Input contains keyboard sequences or repetitive patterns."
                ];
            }
        }

        // Check if string contains prominent keyboard mash sequences without enough surrounding substance
        $seqSubstrings = ['asdf', 'qwerty', 'zxcvb', '12345', 'qazwsx', '54321', 'fdsa', 'ytrewq'];
        foreach ($seqSubstrings as $seq) {
            if (strpos($lower, $seq) !== false) {
                // If it's a short input (< 25 chars) or composed largely of the sequence
                $cleanLetters = preg_replace('/[^a-z0-9]/', '', $lower);
                if (strlen($cleanLetters) < 25 || preg_match('/^(?:' . preg_quote($seq, '/') . ')+$/i', $cleanLetters)) {
                    return [
                        'meaningful' => false,
                        'reason' => "Input appears to contain keyboard mash or sequential patterns."
                    ];
                }
            }
        }

        // 3. Word count check
        $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = count($words);
        if ($wordCount < $minWords) {
            return [
                'meaningful' => false,
                'reason' => "Text must contain at least {$minWords} word(s)."
            ];
        }

        // Check individual words for known gibberish, keyboard mash, or random strings
        $isAddressField = ($fieldKey === 'venue_address' || $fieldKey === 'f1_venue_address');
        $isSubjectField = ($fieldKey === 'involved_subjects' || $fieldKey === 'f2_involved_subjects');
        $allowedSubjectCodes = ['dsa', 'pe', 'cs', 'it', 'mis', 'hrm', 'nstp', 'cwts', 'rotc', 'ge', 'ite'];

        $knownGibberishWords = ['asd', 'asdf', 'qwerty', 'zxcv', 'qwe', 'zxc'];
        if (!$isAddressField) {
            $knownGibberishWords = array_merge($knownGibberishWords, ['123', '1234', '12345']);
        }
        foreach ($words as $w) {
            $wClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $w));
            if ($isAddressField && ctype_digit($wClean)) {
                // In addresses, pure numeric tokens (e.g. 123, 289, 1807) are street/unit numbers or zip codes, not gibberish
                continue;
            }
            if ($isSubjectField && in_array($wClean, $allowedSubjectCodes, true)) {
                // Legitimate subject code abbreviations like DSA, PE, CS, IT
                continue;
            }
            if (isKeyboardMashWord($wClean) || in_array($wClean, $knownGibberishWords, true)) {
                return [
                    'meaningful' => false,
                    'reason' => "Input contains meaningless keyboard sequence or random text '{$w}'."
                ];
            }
        }

        // 4. Repeated word spam check (e.g. "test test test test")
        if ($wordCount >= 3) {
            $uniqueWords = array_unique(array_map('strtolower', $words));
            if (count($uniqueWords) === 1 || ($wordCount >= 4 && count($uniqueWords) < ($wordCount / 2))) {
                return [
                    'meaningful' => false,
                    'reason' => "Text contains repetitive word spam."
                ];
            }
        }

        // 5. Vowel and consonant pattern check for words
        $allowedNoVowelWords = ['bldg', 'blk', 'flr', 'str', 'brgy', 'subd', 'dept', 'pkg', 'hwy', 'pkwy', 'apt', 'ste', 'rm', 'ctr', 'dist', 'ph'];
        if ($letterCount >= 3) {
            foreach ($words as $w) {
                $cleanWord = preg_replace('/[^a-zA-Z]/', '', $w);
                $wLen = strlen($cleanWord);
                if ($wLen >= 3) {
                    $cleanLower = strtolower($cleanWord);
                    // Check if word has NO vowels (a, e, i, o, u, y)
                    if (!preg_match('/[aeiouyAEIOUY]/', $cleanWord)) {
                        // Allow common uppercase acronyms (e.g. HTML, CSS, STI) and common address abbreviations (e.g. Bldg, Blk, Flr)
                        if (!in_array($cleanLower, $allowedNoVowelWords, true) && (!ctype_upper($cleanWord) || $wLen > 5)) {
                            return [
                                'meaningful' => false,
                                'reason' => "Word '{$cleanWord}' appears to be meaningless (no vowels)."
                            ];
                        }
                    }
                    // Check for 5+ consecutive consonants (e.g. "sdfghj", "qwrtyp")
                    if (preg_match('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{5,}/', $cleanWord)) {
                        return [
                            'meaningful' => false,
                            'reason' => "Word '{$cleanWord}' contains meaningless consonant sequences."
                        ];
                    }
                }
            }
        }

        // 6. Character diversity check for strings of length >= 6
        $cleanCharsOnly = preg_replace('/[^a-zA-Z0-9]/', '', $lower);
        if (strlen($cleanCharsOnly) >= 6) {
            $uniqueChars = count(count_chars($cleanCharsOnly, 1));
            if ($uniqueChars <= 2) {
                return [
                    'meaningful' => false,
                    'reason' => "Text has too few distinct characters."
                ];
            }
        }

        return ['meaningful' => true, 'reason' => ''];
    }
}

if (!function_exists('validateProposalFieldMeaningful')) {
    /**
     * Validates an individual field by name according to field-specific rules.
     *
     * @param string $fieldKey The form field key (e.g. title, venue, rationale)
     * @param mixed $value The field value
     * @return string|null Returns error message string if invalid, or null if valid/empty
     */
    function validateProposalFieldMeaningful(string $fieldKey, $value): ?string {
        $val = is_string($value) ? trim($value) : (is_numeric($value) ? (string)$value : '');
        if ($val === '') {
            return null; // Empty-field validation is handled separately by required-field checks
        }

        switch ($fieldKey) {
            // STEP 1 FIELDS
            case 'title':
            case 'f1_title':
                // Legitimate one-word titles allowed: "Orientation", "Intramurals", "Hackathon", "Webinar", "Summit", "Expo"
                $check = isMeaninglessInput($val, 3, 1, 2, false);
                return $check['meaningful'] ? null : 'Event title is too short or appears meaningless (avoid keyboard mash or random numbers).';

            case 'venue':
            case 'f1_venue':
                // Legitimate one-word venues allowed: "Auditorium", "Gym", "AVR", "Library", "Cafeteria", "Online", "Zoom"
                $check = isMeaninglessInput($val, 3, 1, 2, false);
                return $check['meaningful'] ? null : 'Venue name is too short or appears meaningless (avoid random characters or numbers).';

            case 'venue_address':
            case 'f1_venue_address':
                if (isPlausibleAddress($val)) {
                    $words = preg_split('/\s+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($words as $w) {
                        $wClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $w));
                        if (isKeyboardMashWord($wClean)) {
                            return 'Please enter a complete and substantive venue address.';
                        }
                    }
                    return null;
                }
                $check = isMeaninglessInput($val, 5, 1, 3, false, 'venue_address');
                return $check['meaningful'] ? null : 'Please enter a complete and substantive venue address.';

            case 'target_participants':
            case 'f1_target_participants':
                if (!is_numeric($val) || (int)$val < 1) {
                    return 'Please enter a valid positive number of target participants (e.g. 100).';
                }
                return null;

            case 'theme':
            case 'f1_theme':
                $check = isMeaninglessInput($val, 4, 1, 3, false);
                return $check['meaningful'] ? null : 'Event theme is too short or appears meaningless.';

            // STEP 2 FIELDS (Descriptive Fields)
            case 'involved_subjects':
            case 'f2_involved_subjects':
                $check = isMeaninglessInput($val, 2, 1, 1, false, 'involved_subjects');
                return $check['meaningful'] ? null : 'Involved subjects must be valid course/subject codes (e.g. ITE314, GE102).';

            case 'general_objectives':
            case 'f2_general_objectives':
                $check = isMeaninglessInput($val, 20, 3, 15, false);
                return $check['meaningful'] ? null : 'General objectives must be substantive (at least 20 characters and 3 words; avoid placeholder text).';

            case 'specific_objectives':
            case 'f2_specific_objectives':
                $check = isMeaninglessInput($val, 20, 3, 15, false);
                return $check['meaningful'] ? null : 'Specific objectives must be substantive (at least 20 characters and 3 words; avoid placeholder text).';

            case 'rationale':
            case 'f2_rationale':
                $check = isMeaninglessInput($val, 20, 3, 15, false);
                return $check['meaningful'] ? null : 'Activity rationale must provide substantive justification (at least 20 characters and 3 words).';

            // STEP 4 FIELDS
            case 'floor_plan_notes':
            case 'f3_floor_plan_notes':
                $check = isMeaninglessInput($val, 15, 2, 10, false);
                return $check['meaningful'] ? null : 'Floor plan notes must describe the layout and facilities (at least 15 characters).';

            // STEP 7 FIELDS
            case 'guidelines_mechanics':
            case 'f7_mechanics':
                $check = isMeaninglessInput($val, 15, 2, 10, false);
                return $check['meaningful'] ? null : 'Event mechanics must describe rules and procedures (at least 15 characters).';

            case 'guidelines_criteria':
            case 'f7_criteria':
                $check = isMeaninglessInput($val, 15, 2, 10, false);
                return $check['meaningful'] ? null : 'Judging criteria must be substantive (at least 15 characters).';

            case 'guidelines_scoring':
            case 'f7_scoring':
                $check = isMeaninglessInput($val, 15, 2, 10, false);
                return $check['meaningful'] ? null : 'Scoring system description must be substantive (at least 15 characters).';

            case 'guidelines_awards':
            case 'f7_awards':
                $check = isMeaninglessInput($val, 10, 2, 8, false);
                return $check['meaningful'] ? null : 'Special awards details must be substantive (at least 10 characters).';

            case 'description':
                $check = isMeaninglessInput($val, 20, 3, 15, false);
                return $check['meaningful'] ? null : 'Activity description must be substantive (at least 20 characters and 3 words).';

            default:
                return null;
        }
    }
}

if (!function_exists('validateProposalMeaningfulInput')) {
    /**
     * Validates all relevant proposal fields in a submitted payload.
     *
     * @param array $data Form submission data ($_POST)
     * @param bool $checkSection1 Whether Section 1 fields should be checked (false if Section 1 is locked during revision)
     * @return array ['valid' => bool, 'errors' => array, 'first_error' => ?string]
     */
    function validateProposalMeaningfulInput(array $data, bool $checkSection1 = true): array {
        $errors = [];

        $fieldsToTest = [
            'general_objectives',
            'specific_objectives',
            'rationale',
            'involved_subjects'
        ];

        if ($checkSection1) {
            array_unshift($fieldsToTest, 'title', 'venue', 'venue_address', 'theme');
            if (isset($data['target_participants'])) {
                $fieldsToTest[] = 'target_participants';
            }
        }

        if (isset($data['floor_plan_notes'])) {
            $fieldsToTest[] = 'floor_plan_notes';
        }
        if (isset($data['guidelines_mechanics'])) {
            $fieldsToTest[] = 'guidelines_mechanics';
        }
        if (isset($data['guidelines_criteria'])) {
            $fieldsToTest[] = 'guidelines_criteria';
        }
        if (isset($data['guidelines_scoring'])) {
            $fieldsToTest[] = 'guidelines_scoring';
        }
        if (isset($data['guidelines_awards'])) {
            $fieldsToTest[] = 'guidelines_awards';
        }
        if (isset($data['description'])) {
            $fieldsToTest[] = 'description';
        }

        foreach ($fieldsToTest as $fKey) {
            if (isset($data[$fKey])) {
                $err = validateProposalFieldMeaningful($fKey, $data[$fKey]);
                if ($err !== null) {
                    $errors[$fKey] = $err;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'first_error' => !empty($errors) ? reset($errors) : null
        ];
    }
}
