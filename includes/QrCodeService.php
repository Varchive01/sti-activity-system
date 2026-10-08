<?php
// includes/QrCodeService.php

/**
 * QrCodeService
 *
 * Provides completely local, dependency-free QR Code generation for Google Forms
 * evaluation URLs without making external API calls or exposing data.
 */
class QrCodeService
{
    /**
     * Validates that the provided URL is a valid, secure HTTP/HTTPS evaluation URL.
     */
    public static function isValidUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $trimmed = trim($url);
        if ($trimmed === '' || strlen($trimmed) > 2048) {
            return false;
        }

        // Must pass standard URL validation
        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Scheme must be strictly http or https
        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (!$scheme || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        // Host must be present and non-empty
        $host = parse_url($trimmed, PHP_URL_HOST);
        if (empty($host) || str_contains($host, ' ')) {
            return false;
        }

        return true;
    }

    /**
     * Generates a local SVG QR code representation for the given URL.
     * Returns null if the URL is invalid, empty, or cannot be encoded.
     */
    public static function generateSvg(?string $url, int $size = 180, string $fgColor = '#000000', string $bgColor = '#ffffff'): ?string
    {
        if (!self::isValidUrl($url)) {
            return null;
        }

        try {
            $encoder = new LocalQrEncoder(trim((string)$url), ['s' => 'qrm']);
            return $encoder->renderSvg($size, $fgColor, $bgColor);
        } catch (\Throwable $e) {
            error_log('QrCodeService error: ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * LocalQrEncoder
 *
 * Lightweight, self-contained, pure-PHP QR Code encoder based on standard
 * QR Code specification. Zero external dependencies, does not require GD.
 */
class LocalQrEncoder
{
    private string $data;
    private array $options;

    public function __construct(string $data, array $options = [])
    {
        $this->data = $data;
        $this->options = array_merge(['s' => 'qrm'], $options);
    }

    public function renderSvg(int $size = 180, string $fgColor = '#000000', string $bgColor = '#ffffff'): string
    {
        $ecl = 1; // Medium error correction by default
        $code = $this->qrEncode($this->data, $ecl);

        $matrix = $code['b'];
        $quiet = $code['q'][0];
        $matrixSize = count($matrix);
        $totalModules = $matrixSize + ($quiet * 2);

        $safeFg = preg_match('/^#[0-9A-Fa-f]{3,6}$/', $fgColor) ? $fgColor : '#000000';
        $safeBg = preg_match('/^#[0-9A-Fa-f]{3,6}$/', $bgColor) ? $bgColor : '#ffffff';

        $rects = [];
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $posX = $x + $quiet;
                    $posY = $y + $quiet;
                    $rects[] = "<rect x=\"{$posX}\" y=\"{$posY}\" width=\"1\" height=\"1\" fill=\"{$safeFg}\" />";
                }
            }
        }

        $rectsStr = implode("\n    ", $rects);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 {$totalModules} {$totalModules}" width="{$size}" height="{$size}" role="img" aria-label="Evaluation Form QR Code">
  <rect width="100%" height="100%" fill="{$safeBg}" />
  <g shape-rendering="crispEdges">
    {$rectsStr}
  </g>
</svg>
SVG;
    }

    /* -------------------------------------------------------------------------
     * QR Code Core Implementation
     * ------------------------------------------------------------------------- */

    private function qrEncode(string $data, int $ecl): array
    {
        [$mode, $vers, $ec, $encodedData] = $this->qrEncodeData($data, $ecl);
        $encodedData = $this->qrEncodeEc($encodedData, $ec, $vers);
        [$size, $mtx] = $this->qrCreateMatrix($vers, $encodedData);
        [$mask, $mtx] = $this->qrApplyBestMask($mtx, $size);
        $mtx = $this->qrFinalizeMatrix($mtx, $size, $ecl, $mask, $vers);
        return [
            'q' => [4, 4, 4, 4],
            's' => [$size, $size],
            'b' => $mtx,
        ];
    }

    private function qrEncodeData(string $data, int $ecl): array
    {
        $mode = $this->qrDetectMode($data);
        $version = $this->qrDetectVersion($data, $mode, $ecl);
        $versionGroup = (($version < 10) ? 0 : (($version < 27) ? 1 : 2));
        $ecParams = self::$qrEcParams[($version - 1) * 4 + $ecl];

        $maxChars = self::$qrCapacity[$version - 1][$ecl][$mode];
        if ($mode == 3) $maxChars <<= 1;
        $data = substr($data, 0, $maxChars);

        switch ($mode) {
            case 0: $code = $this->qrEncodeNumeric($data, $versionGroup); break;
            case 1: $code = $this->qrEncodeAlphanumeric($data, $versionGroup); break;
            case 2: $code = $this->qrEncodeBinary($data, $versionGroup); break;
            case 3: $code = $this->qrEncodeKanji($data, $versionGroup); break;
            default: $code = $this->qrEncodeBinary($data, $versionGroup); break;
        }

        for ($i = 0; $i < 4; $i++) $code[] = 0;
        while (count($code) % 8) $code[] = 0;

        $outData = [];
        for ($i = 0, $n = count($code); $i < $n; $i += 8) {
            $byte = 0;
            if ($code[$i + 0]) $byte |= 0x80;
            if ($code[$i + 1]) $byte |= 0x40;
            if ($code[$i + 2]) $byte |= 0x20;
            if ($code[$i + 3]) $byte |= 0x10;
            if ($code[$i + 4]) $byte |= 0x08;
            if ($code[$i + 5]) $byte |= 0x04;
            if ($code[$i + 6]) $byte |= 0x02;
            if ($code[$i + 7]) $byte |= 0x01;
            $outData[] = $byte;
        }

        for ($i = count($outData), $a = 1, $n = $ecParams[0]; $i < $n; $i++, $a ^= 1) {
            $outData[] = $a ? 236 : 17;
        }

        return [$mode, $version, $ecParams, $outData];
    }

    private function qrDetectMode(string $data): int
    {
        if (preg_match('/^[0-9]*$/', $data)) return 0;
        if (preg_match('/^[0-9A-Z .\/:$%*+-]*$/', $data)) return 1;
        return 2;
    }

    private function qrDetectVersion(string $data, int $mode, int $ecl): int
    {
        $length = strlen($data);
        if ($mode == 3) $length >>= 1;
        for ($v = 0; $v < 40; $v++) {
            if ($length <= self::$qrCapacity[$v][$ecl][$mode]) {
                return $v + 1;
            }
        }
        return 40;
    }

    private function qrEncodeNumeric(string $data, int $versionGroup): array
    {
        $code = [0, 0, 0, 1];
        $length = strlen($data);
        switch ($versionGroup) {
            case 2: $code[] = $length & 0x2000; $code[] = $length & 0x1000;
            case 1: $code[] = $length & 0x0800; $code[] = $length & 0x0400;
            case 0:
                $code[] = $length & 0x0200; $code[] = $length & 0x0100;
                $code[] = $length & 0x0080; $code[] = $length & 0x0040;
                $code[] = $length & 0x0020; $code[] = $length & 0x0010;
                $code[] = $length & 0x0008; $code[] = $length & 0x0004;
                $code[] = $length & 0x0002; $code[] = $length & 0x0001;
        }
        for ($i = 0; $i < $length; $i += 3) {
            $group = substr($data, $i, 3);
            $val = (int)$group;
            switch (strlen($group)) {
                case 3:
                    $code[] = $val & 0x200; $code[] = $val & 0x100; $code[] = $val & 0x080;
                case 2:
                    $code[] = $val & 0x040; $code[] = $val & 0x020; $code[] = $val & 0x010;
                case 1:
                    $code[] = $val & 0x008; $code[] = $val & 0x004; $code[] = $val & 0x002; $code[] = $val & 0x001;
            }
        }
        return $code;
    }

    private function qrEncodeAlphanumeric(string $data, int $versionGroup): array
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
        $code = [0, 0, 1, 0];
        $length = strlen($data);
        switch ($versionGroup) {
            case 2: $code[] = $length & 0x1000; $code[] = $length & 0x0800;
            case 1: $code[] = $length & 0x0400; $code[] = $length & 0x0200;
            case 0:
                $code[] = $length & 0x0100; $code[] = $length & 0x0080;
                $code[] = $length & 0x0040; $code[] = $length & 0x0020;
                $code[] = $length & 0x0010; $code[] = $length & 0x0008;
                $code[] = $length & 0x0004; $code[] = $length & 0x0002;
                $code[] = $length & 0x0001;
        }
        for ($i = 0; $i < $length; $i += 2) {
            $group = substr($data, $i, 2);
            if (strlen($group) > 1) {
                $c1 = strpos($alphabet, substr($group, 0, 1));
                $c2 = strpos($alphabet, substr($group, 1, 1));
                $ch = $c1 * 45 + $c2;
                $code[] = $ch & 0x400; $code[] = $ch & 0x200; $code[] = $ch & 0x100;
                $code[] = $ch & 0x080; $code[] = $ch & 0x040; $code[] = $ch & 0x020;
                $code[] = $ch & 0x010; $code[] = $ch & 0x008; $code[] = $ch & 0x004;
                $code[] = $ch & 0x002; $code[] = $ch & 0x001;
            } else {
                $ch = strpos($alphabet, $group);
                $code[] = $ch & 0x020; $code[] = $ch & 0x010; $code[] = $ch & 0x008;
                $code[] = $ch & 0x004; $code[] = $ch & 0x002; $code[] = $ch & 0x001;
            }
        }
        return $code;
    }

    private function qrEncodeBinary(string $data, int $versionGroup): array
    {
        $code = [0, 1, 0, 0];
        $length = strlen($data);
        switch ($versionGroup) {
            case 2:
            case 1: $code[] = $length & 0x8000; $code[] = $length & 0x4000;
                    $code[] = $length & 0x2000; $code[] = $length & 0x1000;
                    $code[] = $length & 0x0800; $code[] = $length & 0x0400;
                    $code[] = $length & 0x0200; $code[] = $length & 0x0100;
            case 0:
                $code[] = $length & 0x0080; $code[] = $length & 0x0040;
                $code[] = $length & 0x0020; $code[] = $length & 0x0010;
                $code[] = $length & 0x0008; $code[] = $length & 0x0004;
                $code[] = $length & 0x0002; $code[] = $length & 0x0001;
        }
        for ($i = 0; $i < $length; $i++) {
            $ch = ord($data[$i]);
            $code[] = $ch & 0x80; $code[] = $ch & 0x40; $code[] = $ch & 0x20; $code[] = $ch & 0x10;
            $code[] = $ch & 0x08; $code[] = $ch & 0x04; $code[] = $ch & 0x02; $code[] = $ch & 0x01;
        }
        return $code;
    }

    private function qrEncodeKanji(string $data, int $versionGroup): array
    {
        return $this->qrEncodeBinary($data, $versionGroup);
    }

    private function qrEncodeEc(array $data, array $ecParams, int $version): array
    {
        $g = self::$qrPolynom[$ecParams[1]];
        $code = [];
        $d = 0;
        for ($b = 0; $b < $ecParams[2]; $b++) {
            $n = $ecParams[3] - $ecParams[1];
            $t = array_slice($data, $d, $n);
            $d += $n;
            $c = $this->qrDivide($t, $g);
            $code[] = [$t, $c];
        }
        for ($b = 0; $b < $ecParams[4]; $b++) {
            $n = $ecParams[5] - $ecParams[1];
            $t = array_slice($data, $d, $n);
            $d += $n;
            $c = $this->qrDivide($t, $g);
            $code[] = [$t, $c];
        }
        $out = [];
        for ($i = 0; $i < $ecParams[5]; $i++) {
            foreach ($code as $block) {
                if (isset($block[0][$i])) $out[] = $block[0][$i];
            }
        }
        for ($i = 0; $i < $ecParams[1]; $i++) {
            foreach ($code as $block) {
                if (isset($block[1][$i])) $out[] = $block[1][$i];
            }
        }
        return $out;
    }

    private function qrDivide(array $a, array $b): array
    {
        self::initGalois();
        $la = count($a);
        $lb = count($b);
        $r = array_fill(0, $lb - 1, 0);
        for ($i = 0; $i < $la; $i++) {
            $c = $a[$i] ^ $r[0];
            array_shift($r);
            $r[] = 0;
            if ($c == 0) continue;
            $logC = self::$qrLog[$c] ?? 0;
            for ($j = 0; $j < $lb - 1; $j++) {
                if ($b[$j + 1] != 0) {
                    $logB = self::$qrLog[$b[$j + 1]] ?? 0;
                    $r[$j] ^= self::$qrExp[$logB + $logC];
                }
            }
        }
        return $r;
    }

    private function qrCreateMatrix(int $version, array $data): array
    {
        $size = 17 + 4 * $version;
        $mtx = array_fill(0, $size, array_fill(0, $size, null));

        // Finder patterns
        $this->qrAddFinder($mtx, 0, 0);
        $this->qrAddFinder($mtx, $size - 7, 0);
        $this->qrAddFinder($mtx, 0, $size - 7);

        // Separators
        for ($i = 0; $i < 8; $i++) {
            $mtx[7][$i] = 0; $mtx[$i][7] = 0;
            $mtx[$size - 8][$i] = 0; $mtx[$size - 1 - $i][7] = 0;
            $mtx[7][$size - 1 - $i] = 0; $mtx[$i][$size - 8] = 0;
        }

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $mtx[6][$i] = ($i % 2 == 0) ? 1 : 0;
            $mtx[$i][6] = ($i % 2 == 0) ? 1 : 0;
        }

        // Alignment patterns
        $coords = self::$qrAlignCoords[$version - 1];
        foreach ($coords as $y) {
            foreach ($coords as $x) {
                if ($mtx[$y][$x] === null) {
                    $this->qrAddAlignment($mtx, $x - 2, $y - 2);
                }
            }
        }

        // Dark module
        $mtx[4 * $version + 9][8] = 1;

        // Reserve format info areas
        for ($i = 0; $i < 9; $i++) {
            if ($mtx[8][$i] === null) $mtx[8][$i] = 0;
            if ($mtx[$i][8] === null) $mtx[$i][8] = 0;
        }
        for ($i = 0; $i < 8; $i++) {
            if ($mtx[8][$size - 1 - $i] === null) $mtx[8][$size - 1 - $i] = 0;
            if ($mtx[$size - 1 - $i][8] === null) $mtx[$size - 1 - $i][8] = 0;
        }

        // Fill data bits
        $len = count($data);
        $d = 0; $b = 7;
        for ($x = $size - 1; $x > 0; $x -= 2) {
            if ($x == 6) $x--;
            for ($y = 0; $y < $size; $y++) {
                $y1 = (($x + 1) & 2) ? ($size - 1 - $y) : $y;
                for ($x1 = $x; $x1 > $x - 2; $x1--) {
                    if ($mtx[$y1][$x1] === null) {
                        $bit = 0;
                        if ($d < $len) {
                            $bit = ($data[$d] & (1 << $b)) ? 1 : 0;
                            if (--$b < 0) { $d++; $b = 7; }
                        }
                        $mtx[$y1][$x1] = $bit;
                    }
                }
            }
        }

        return [$size, $mtx];
    }

    private function qrAddFinder(array &$mtx, int $x, int $y): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                if ($r == 0 || $r == 6 || $c == 0 || $c == 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                    $mtx[$y + $r][$x + $c] = 1;
                } else {
                    $mtx[$y + $r][$x + $c] = 0;
                }
            }
        }
    }

    private function qrAddAlignment(array &$mtx, int $x, int $y): void
    {
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                if ($r == 0 || $r == 4 || $c == 0 || $c == 4 || ($r == 2 && $c == 2)) {
                    $mtx[$y + $r][$x + $c] = 1;
                } else {
                    $mtx[$y + $r][$x + $c] = 0;
                }
            }
        }
    }

    private function qrApplyBestMask(array $mtx, int $size): array
    {
        $bestMask = 0;
        $minPenalty = PHP_INT_MAX;
        $bestMtx = $mtx;

        for ($mask = 0; $mask < 8; $mask++) {
            $testMtx = $mtx;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    // Only mask non-function modules
                    if ($this->qrIsDataModule($x, $y, $size)) {
                        if ($this->qrMaskCondition($mask, $x, $y)) {
                            $testMtx[$y][$x] ^= 1;
                        }
                    }
                }
            }
            $penalty = $this->qrCalculatePenalty($testMtx, $size);
            if ($penalty < $minPenalty) {
                $minPenalty = $penalty;
                $bestMask = $mask;
                $bestMtx = $testMtx;
            }
        }

        return [$bestMask, $bestMtx];
    }

    private function qrIsDataModule(int $x, int $y, int $size): bool
    {
        // Finder + separators
        if ($x <= 8 && $y <= 8) return false;
        if ($x >= $size - 8 && $y <= 8) return false;
        if ($x <= 8 && $y >= $size - 8) return false;
        // Timing
        if ($x == 6 || $y == 6) return false;
        return true;
    }

    private function qrMaskCondition(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0: return (($x + $y) % 2 == 0);
            case 1: return ($y % 2 == 0);
            case 2: return ($x % 3 == 0);
            case 3: return (($x + $y) % 3 == 0);
            case 4: return ((floor($y / 2) + floor($x / 3)) % 2 == 0);
            case 5: return ((($x * $y) % 2) + (($x * $y) % 3) == 0);
            case 6: return (((($x * $y) % 2) + (($x * $y) % 3)) % 2 == 0);
            case 7: return (((($x + $y) % 2) + (($x * $y) % 3)) % 2 == 0);
            default: return false;
        }
    }

    private function qrCalculatePenalty(array $mtx, int $size): int
    {
        $penalty = 0;
        // Feature 1: Runs of 5+ same color
        for ($y = 0; $y < $size; $y++) {
            $count = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($mtx[$y][$x] === $mtx[$y][$x - 1]) {
                    $count++;
                    if ($count == 5) $penalty += 3;
                    elseif ($count > 5) $penalty += 1;
                } else {
                    $count = 1;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $count = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($mtx[$y][$x] === $mtx[$y - 1][$x]) {
                    $count++;
                    if ($count == 5) $penalty += 3;
                    elseif ($count > 5) $penalty += 1;
                } else {
                    $count = 1;
                }
            }
        }
        return $penalty;
    }

    private function qrFinalizeMatrix(array $mtx, int $size, int $ecl, int $mask, int $version): array
    {
        // Format info (15 bits)
        $formatInfo = self::$qrFormatInfo[$ecl * 8 + $mask];
        for ($i = 0; $i < 6; $i++) $mtx[8][$i] = ($formatInfo >> $i) & 1;
        $mtx[8][7] = ($formatInfo >> 6) & 1;
        $mtx[8][8] = ($formatInfo >> 7) & 1;
        $mtx[7][8] = ($formatInfo >> 8) & 1;
        for ($i = 9; $i < 15; $i++) $mtx[14 - $i][8] = ($formatInfo >> $i) & 1;

        for ($i = 0; $i < 8; $i++) $mtx[$size - 1 - $i][8] = ($formatInfo >> $i) & 1;
        for ($i = 8; $i < 15; $i++) $mtx[8][$size - 15 + $i] = ($formatInfo >> $i) & 1;

        return $mtx;
    }

    /* -------------------------------------------------------------------------
     * Constants & Tables
     * ------------------------------------------------------------------------- */

    private static array $qrFormatInfo = [
        0x77C4, 0x72F3, 0x7DAA, 0x789D, 0x662F, 0x6318, 0x6C41, 0x6976,
        0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0,
        0x355F, 0x3068, 0x3F31, 0x3A06, 0x24B4, 0x2183, 0x2EDA, 0x2BED,
        0x1689, 0x13BE, 0x1CE7, 0x19D0, 0x0762, 0x0255, 0x0D0C, 0x083B
    ];

    private static array $qrAlignCoords = [
        [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62],
        [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78], [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90],
        [6, 28, 50, 72, 94], [6, 26, 50, 74, 98], [6, 30, 54, 78, 102], [6, 28, 54, 80, 106], [6, 32, 58, 84, 110], [6, 30, 58, 86, 114], [6, 34, 62, 90, 118],
        [6, 26, 50, 74, 98, 122], [6, 30, 54, 78, 102, 126], [6, 26, 52, 78, 104, 130], [6, 30, 56, 82, 108, 134], [6, 34, 60, 86, 112, 138], [6, 30, 58, 86, 114, 142], [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150], [6, 24, 50, 76, 102, 128, 154], [6, 28, 54, 80, 106, 132, 158], [6, 32, 58, 84, 110, 136, 162], [6, 26, 54, 82, 110, 138, 166], [6, 30, 58, 86, 114, 142, 170]
    ];

    private static array $qrCapacity = [
        [[41, 25, 17, 10], [34, 20, 14, 8], [27, 16, 11, 7], [17, 10, 7, 4]],
        [[77, 47, 32, 20], [63, 38, 26, 16], [48, 29, 20, 12], [34, 20, 14, 8]],
        [[127, 77, 53, 32], [101, 61, 42, 26], [77, 47, 32, 20], [58, 35, 24, 15]],
        [[187, 114, 78, 48], [149, 90, 62, 38], [111, 67, 46, 28], [82, 50, 34, 21]],
        [[255, 154, 106, 65], [202, 122, 84, 52], [144, 87, 60, 37], [106, 64, 44, 27]],
        [[322, 195, 134, 82], [255, 154, 106, 65], [178, 108, 74, 45], [139, 84, 58, 36]],
        [[370, 224, 154, 95], [293, 178, 122, 75], [207, 125, 86, 53], [154, 93, 64, 39]],
        [[461, 279, 192, 118], [365, 221, 152, 93], [259, 157, 108, 66], [202, 122, 84, 52]],
        [[552, 335, 230, 141], [432, 262, 180, 111], [312, 189, 130, 80], [235, 143, 98, 60]],
        [[652, 395, 271, 167], [513, 311, 213, 131], [364, 221, 151, 93], [288, 174, 119, 74]],
    ];

    private static array $qrEcParams = [
        [19, 7, 1, 26, 0, 0], [16, 10, 1, 26, 0, 0], [13, 13, 1, 26, 0, 0], [9, 17, 1, 26, 0, 0],
        [34, 10, 1, 44, 0, 0], [28, 16, 1, 44, 0, 0], [22, 22, 1, 44, 0, 0], [16, 28, 1, 44, 0, 0],
        [55, 15, 1, 70, 0, 0], [44, 26, 1, 70, 0, 0], [34, 18, 2, 35, 0, 0], [26, 22, 2, 35, 0, 0],
        [80, 20, 1, 100, 0, 0], [64, 18, 2, 50, 0, 0], [48, 26, 2, 50, 0, 0], [36, 16, 4, 25, 0, 0],
        [108, 26, 1, 134, 0, 0], [86, 24, 2, 67, 0, 0], [62, 18, 2, 33, 2, 34], [46, 22, 2, 33, 2, 34],
        [136, 18, 2, 86, 0, 0], [108, 16, 4, 43, 0, 0], [76, 24, 4, 43, 0, 0], [60, 28, 4, 43, 0, 0],
        [156, 20, 2, 98, 0, 0], [124, 18, 4, 49, 0, 0], [88, 18, 2, 32, 4, 33], [66, 26, 4, 39, 1, 40],
        [194, 24, 2, 121, 0, 0], [154, 22, 2, 60, 2, 61], [110, 22, 4, 40, 2, 41], [86, 26, 4, 40, 2, 41],
        [232, 30, 2, 146, 0, 0], [182, 22, 3, 58, 2, 59], [132, 20, 4, 36, 4, 37], [100, 24, 4, 36, 4, 37],
        [274, 18, 2, 86, 2, 87], [216, 26, 4, 69, 1, 70], [154, 24, 6, 43, 2, 44], [122, 28, 6, 43, 2, 44],
    ];

    private static array $qrPolynom = [
        0 => [1],
        7 => [1, 127, 122, 154, 164, 11, 68, 117],
        10 => [1, 216, 194, 159, 111, 199, 94, 95, 113, 157, 193],
        13 => [1, 137, 73, 227, 17, 177, 17, 52, 13, 46, 43, 83, 132, 120],
        15 => [1, 29, 196, 111, 163, 112, 74, 10, 105, 105, 139, 132, 151, 32, 134, 26],
        16 => [1, 59, 13, 86, 218, 56, 249, 178, 36, 242, 246, 17, 213, 145, 35, 184, 125],
        17 => [1, 119, 66, 83, 120, 119, 22, 197, 83, 249, 41, 143, 134, 85, 53, 125, 99, 79],
        18 => [1, 153, 102, 15, 214, 149, 229, 79, 175, 45, 120, 106, 132, 219, 175, 100, 138, 145, 184],
        20 => [1, 152, 185, 240, 5, 111, 99, 6, 220, 112, 150, 69, 36, 187, 22, 228, 198, 121, 121, 165, 174],
        22 => [1, 64, 113, 154, 107, 224, 172, 254, 28, 62, 85, 233, 12, 88, 199, 124, 76, 121, 247, 21, 86, 100, 160],
        24 => [1, 111, 95, 90, 77, 98, 74, 240, 36, 18, 143, 50, 177, 247, 140, 70, 9, 71, 15, 162, 85, 160, 156, 112, 187],
        26 => [1, 239, 251, 252, 182, 214, 225, 26, 78, 74, 244, 211, 215, 130, 110, 234, 160, 64, 16, 202, 13, 145, 87, 86, 177, 129, 67],
        28 => [1, 18, 200, 24, 72, 139, 54, 84, 172, 120, 242, 244, 230, 26, 191, 193, 139, 98, 62, 165, 251, 233, 205, 16, 91, 57, 114, 109, 124],
        30 => [1, 212, 246, 77, 73, 195, 89, 32, 221, 245, 31, 74, 124, 43, 59, 228, 133, 47, 141, 36, 82, 133, 126, 78, 84, 11, 94, 242, 34, 96, 59]
    ];

    private static ?array $qrExp = null;
    private static ?array $qrLog = null;

    private static function initGalois(): void
    {
        if (self::$qrExp !== null) {
            return;
        }
        self::$qrExp = [];
        self::$qrLog = [];
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$qrExp[$i] = $x;
            self::$qrLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$qrExp[$i] = self::$qrExp[$i - 255];
        }
    }
}
