<?php
/**
 * includes/gibberish_detector.php
 *
 * Lightweight, deterministic gibberish and keyboard mash detector.
 * Protects proposal creation and editing without false positives on legitimate names and addresses.
 */

if (!function_exists('isGibberishToken')) {
    function isGibberishToken(string $token): bool {
        $token = trim($token, " \t\n\r\0\x0B,.:;!?\"'()[]{}/*-+_=@#$%^&<>~`|");
        if (strlen($token) < 3) return false;

        // If token has no letters at all (pure numbers, time like '8:00', '100%', '10,000'), not gibberish
        if (!preg_match('/[A-Za-z]/', $token)) return false;

        // Allow standard alphanumeric codes/course codes (e.g. 'CS101', 'ITE314', 'GE102', 'ACT-101')
        if (preg_match('/^[A-Z]{2,5}\-?\d{1,4}[A-Z]?$/i', $token)) return false;

        // Allow ordinal numbers (e.g. '1st', '2nd', '3rd', '4th')
        if (preg_match('/^\d+(st|nd|rd|th)$/i', $token)) return false;

        // Allow all-uppercase abbreviations up to 6 characters and common pluralized acronyms (e.g. 'JPCS', 'AWS', 'STI', 'PDRRMC', 'SSG', 'UNESCO', 'SDGs', 'KPIs')
        if (preg_match('/^[A-Z]{2,6}s?$/', $token)) return false;

        // For hyphenated compounds (e.g. 'world-class', 'well-structured', 'peer-to-peer', 'hands-on'),
        // evaluate each hyphen-separated component so letters are not concatenated across word boundaries.
        if (strpos($token, '-') !== false) {
            $subTokens = array_filter(explode('-', $token), fn($p) => strlen($p) > 0);
            foreach ($subTokens as $sub) {
                if (strlen($sub) >= 3 && isGibberishToken($sub)) {
                    return true;
                }
            }
            return false;
        }

        $lower = strtolower($token);
        // Extract letter portion for checks when numbers or symbols are appended (e.g. 'fsdfsd123', 'asdd99')
        $letters = preg_replace('/[^a-z]/', '', $lower);

        // 1. Must contain at least one vowel (a, e, i, o, u, y) if length of letters >= 3.
        // If only 'y' is present (no a, e, i, o, u) and length >= 6, it is gibberish unless whitelisted (e.g. 'rhythm', 'rhythms').
        if (strlen($letters) >= 3 && !preg_match('/[aeiouy]/', $letters)) {
            return true;
        }
        if (strlen($letters) >= 6 && !preg_match('/[aeiou]/', $letters)) {
            $yWhitelist = ['rhythm', 'rhythms'];
            if (!in_array($letters, $yWhitelist, true)) return true;
        }

        // 2. Obvious keyboard walks / mashing runs (3+ char runs)
        if (preg_match('/asdf|fdsa|qwerty|ytrewq|zxcvb|bvcxz|hjkl|lkjh|qwer|rewq|ghjk|kjhg|dfgh|fghj|poiuy/i', $lower)) {
            return true;
        }

        // 3. Repeated characters: 3+ identical characters (e.g. 'aaa', 'zzz') or repeated pairs (e.g. 'asdasd', 'fsdfsd')
        if (preg_match('/(.)\1{2,}/i', $lower)) return true;
        if (preg_match('/(.{2,3})\1{2,}/i', $letters)) {
            return true;
        }

        // 4. Consecutive consonants: 5+ consecutive consonants in a word (unless whitelisted)
        $consonants = 'bcdfghjklmnpqrstvwxz'; // exclude y as semi-vowel
        if (preg_match('/[' . $consonants . ']{5,}/i', $letters)) {
            $whitelist = ['angst', 'strengths', 'twelfths', 'catchphrase', 'knights', 'lengths', 'birthplace', 'instinct', 'abstract', 'construct', 'instruct'];
            if (!in_array($letters, $whitelist, true)) return true;
        }

        // 5. Pure home-row letters [asdfghjkl] (length >= 3)
        if (strlen($letters) >= 3 && preg_match('/^[asdfghjkl]+$/', $letters)) {
            $homeWhitelist = ['add', 'adds', 'all', 'ask', 'asks', 'dash', 'fall', 'falls', 'flag', 'flags', 'flash', 'flask', 'flasks', 'gas', 'glad', 'glass', 'had', 'half', 'hall', 'halls', 'has', 'lass', 'salad', 'salads', 'shall'];
            if (!in_array($letters, $homeWhitelist, true)) return true;
        }

        // 6. Pure top-row letters [qwertyuiop] (length >= 3)
        if (strlen($letters) >= 3 && preg_match('/^[qwertyuiop]+$/', $letters)) {
            if (in_array($letters, ['queue', 'queuer'], true)) return false;
            // Flag structural keyboard-mash characteristics:
            // - 3+ consecutive top-row consonants (e.g. 'qwtp', 'prtq', 'wrtp' - never occurring in English words)
            // - 'q' not followed by 'u' (e.g. 'qwer', 'qwe', 'tq', 'qpo')
            // - 4+ consecutive vowels (e.g. 'uioe', 'eouy')
            // - top-row keyboard walks
            if (preg_match('/[wrtpq]{3,}/i', $letters) || preg_match('/q(?!u)/i', $letters) || preg_match('/[aeiouy]{4,}/i', $letters) || preg_match('/werty|trewq|poiuy|uytre/i', $letters)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('validateProposalFieldText')) {
    function validateProposalFieldText(string $text, string $type = 'general'): ?string {
        $trimmed = trim($text);
        if ($trimmed === '') return null; // handled by empty/required checks

        $words = preg_split('/\s+/', $trimmed);
        foreach ($words as $w) {
            if (isGibberishToken($w)) {
                return 'Please enter valid, meaningful text (avoid random characters or keyboard mash).';
            }
        }

        if ($type === 'descriptive') {
            if (strlen($trimmed) < 10 || count($words) < 2) {
                return 'Please provide a substantive description (at least 10 characters and 2 words).';
            }
        }

        return null;
    }
}

if (!function_exists('validateProposalGibberish')) {
    function validateProposalGibberish(array $data, bool $checkSection1 = true): array {
        $scalarFields = [
            'general_objectives'   => 'descriptive',
            'specific_objectives'  => 'descriptive',
            'involved_subjects'    => 'general',
            'rationale'            => 'descriptive',
            'floor_plan_notes'     => 'descriptive',
            'guidelines_mechanics' => 'descriptive',
            'guidelines_criteria'  => 'descriptive',
            'guidelines_scoring'   => 'descriptive',
            'guidelines_awards'    => 'general',
            'description'          => 'descriptive',
        ];

        if ($checkSection1) {
            $scalarFields = array_merge([
                'title'         => 'general',
                'venue'         => 'general',
                'venue_address' => 'general',
                'theme'         => 'general',
            ], $scalarFields);
        }

        $errors = [];
        foreach ($scalarFields as $field => $type) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $err = validateProposalFieldText($data[$field], $type);
                if ($err !== null) {
                    $errors[$field] = $err;
                }
            }
        }

        // Array / table fields
        $arrayFields = [
            'mat_item', 'mat_desc', 'mat_provider',
            'prog_segment', 'prog_desc', 'prog_pic',
            'mp_role', 'mp_person',
            'sched_event', 'sched_venue', 'sched_organizer',
            'ft_name', 'ft_task', 'ft_contribution', 'ft_role'
        ];

        foreach ($arrayFields as $arrKey) {
            if (!empty($data[$arrKey]) && is_array($data[$arrKey])) {
                foreach ($data[$arrKey] as $idx => $val) {
                    if (is_string($val) && trim($val) !== '') {
                        $err = validateProposalFieldText($val, 'general');
                        if ($err !== null) {
                            $errors["{$arrKey}_{$idx}"] = $err;
                        }
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
