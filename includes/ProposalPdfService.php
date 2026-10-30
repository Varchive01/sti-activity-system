<?php
/**
 * includes/ProposalPdfService.php
 *
 * Generates structurally valid, formal PDF snapshots for STI-ARMES activity proposals
 * and coordinates non-blocking archive synchronization to Microsoft OneDrive.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

class ProposalPdfService
{
    /**
     * Generates a formal, professional PDF-1.4 binary snapshot from proposal data.
     */
    public static function generatePdf(
        array $act,
        array $mats = [],
        array $prog = [],
        array $mp = [],
        array $scheds = [],
        ?array $guide = null,
        string $versionLabel = 'Submitted',
        ?array $facultyUser = null
    ): string {
        $title = trim($act['title'] ?? 'Untitled Proposal');
        $proposalId = (string)($act['id'] ?? '0');
        $theme = trim($act['theme'] ?? '');
        $source = trim($act['source'] ?? 'faculty');
        $status = trim($act['status'] ?? 'submitted');
        $venue = trim($act['venue'] ?? 'TBA');
        
        $eventDate = !empty($act['event_date']) ? date('F j, Y', strtotime($act['event_date'])) : 'TBA';
        $startTime = !empty($act['start_time']) ? date('h:i A', strtotime($act['start_time'])) : '';
        $endTime   = !empty($act['end_time'])   ? date('h:i A', strtotime($act['end_time']))   : '';
        $timeStr   = ($startTime && $endTime) ? "{$startTime} - {$endTime}" : ($startTime ?: 'TBA');

        $facultyName = $facultyUser['name'] ?? ($act['faculty_name'] ?? 'Faculty Member');
        $facultyDept = $facultyUser['department'] ?? ($act['faculty_dept'] ?? 'Academic Department');

        $doc = new ProposalPdfLayout($title, $proposalId, $versionLabel);

        // Document Title Banner
        $doc->addTitleBanner($title, $theme, $source, $status);

        // 1. Activity Information Grid
        $doc->addSectionHeader("1. Activity General Information");
        $doc->addGridRow([
            'Venue / Location'  => $venue,
            'Target Event Date' => $eventDate
        ]);
        $doc->addGridRow([
            'Time Schedule'     => $timeStr,
            'Lead Proponent'    => $facultyName
        ]);
        $doc->addGridRow([
            'Department / Unit' => $facultyDept,
            'Proposal Source'   => ($source === 'student_org') ? 'Student Organization' : 'Faculty Initiated'
        ]);

        // 2. Objectives & Rationale
        $doc->addSectionHeader("2. Objectives & Rationale");
        $doc->addParagraphBlock("General Objectives", $act['general_objectives'] ?? 'None specified.');
        $doc->addParagraphBlock("Specific Objectives", $act['specific_objectives'] ?? 'None specified.');
        $doc->addParagraphBlock("Rationale & Strategic Justification", $act['rationale'] ?? 'None specified.');

        // 3. Schedule of Activities
        if (!empty($scheds)) {
            $doc->addSectionHeader("3. Schedule of Activities");
            $sHeaders = ['Date', 'Time Slot', 'Activity Description'];
            $sWidths  = [95, 115, 322];
            $sRows    = [];
            foreach ($scheds as $s) {
                $d = !empty($s['sched_date']) ? date('M j, Y', strtotime($s['sched_date'])) : '—';
                $st = !empty($s['start_time']) ? date('h:i A', strtotime($s['start_time'])) : '';
                $et = !empty($s['end_time'])   ? date('h:i A', strtotime($s['end_time']))   : '';
                $t  = ($st && $et) ? "{$st} - {$et}" : ($st ?: '—');
                $desc = trim($s['activity_description'] ?? '');
                $sRows[] = [$d, $t, $desc];
            }
            $doc->addTable($sHeaders, $sWidths, $sRows);
        }

        // 4. Program Sequence
        if (!empty($prog)) {
            $doc->addSectionHeader("4. Program Sequence / Flow");
            $pHeaders = ['Time Slot', 'Program Segment / Activity', 'Person-in-Charge'];
            $pWidths  = [110, 240, 182];
            $pRows    = [];
            foreach ($prog as $p) {
                $ts  = trim($p['time_slot'] ?? '—');
                $seg = trim(($p['segment'] ?? '') . ' ' . ($p['description'] ?? ''));
                $pic = trim($p['person_ic'] ?? ($p['person_in_charge'] ?? 'Unassigned'));
                $pRows[] = [$ts, $seg, $pic];
            }
            $doc->addTable($pHeaders, $pWidths, $pRows);
        }

        // 5. Budget & Materials Breakdown
        if (!empty($mats)) {
            $doc->addSectionHeader("5. Budget & Materials Breakdown");
            $mHeaders = ['Item Description', 'Quantity', 'Estimated Cost'];
            $mWidths  = [332, 85, 115];
            $mRows    = [];
            $totalEst = 0.0;
            $totalQty = 0;
            foreach ($mats as $m) {
                $cost = (float)($m['est_cost'] ?? 0);
                $qty  = (int)($m['quantity'] ?? 1);
                $totalEst += $cost;
                $totalQty += $qty;
                $mRows[] = [
                    trim($m['item_name'] ?? 'Item'),
                    (string)$qty,
                    'PHP ' . number_format($cost, 2)
                ];
            }
            $doc->addTable($mHeaders, $mWidths, $mRows, [
                'TOTAL ESTIMATED BUDGET',
                (string)$totalQty,
                'PHP ' . number_format($totalEst, 2)
            ]);
        }

        // 6. Committees & Manpower
        if (!empty($mp)) {
            $doc->addSectionHeader("6. Working Committees & Responsibilities");
            $mpHeaders = ['Committee / Role Assignment', 'Assigned Person / In-Charge'];
            $mpWidths  = [230, 302];
            $mpRows    = [];
            foreach ($mp as $m) {
                $mpRows[] = [
                    trim($m['role_name'] ?? 'Committee'),
                    trim($m['assigned_person'] ?? 'Unassigned')
                ];
            }
            $doc->addTable($mpHeaders, $mpWidths, $mpRows);
        }

        // 7. Guidelines & Safety Protocols
        if (!empty($guide['special_requirements']) || !empty($guide['safety_protocols'])) {
            $doc->addSectionHeader("7. Activity Guidelines & Protocols");
            if (!empty($guide['special_requirements'])) {
                $doc->addParagraphBlock("Special Requirements & Setup:", $guide['special_requirements']);
            }
            if (!empty($guide['safety_protocols'])) {
                $doc->addParagraphBlock("Health, Safety & Campus Protocols:", $guide['safety_protocols']);
            }
        }

        // 8. Signatories & Approval Routing
        $doc->addSectionHeader("8. Institutional Endorsement & Approvals");
        $doc->addSignatories($facultyName, $facultyDept, $status);

        return $doc->renderPdf();
    }

    /**
     * Creates local PDF snapshot and synchronizes to OneDrive proposals/ folder.
     */
    public static function syncProposalSnapshot(
        PDO $db,
        int $activityId,
        string $trigger = 'submitted',
        ?int $userId = null,
        ?OneDriveService $service = null
    ): ?array {
        // Fetch full activity details
        $actStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
        $actStmt->execute([$activityId]);
        $act = $actStmt->fetch(PDO::FETCH_ASSOC);
        if (!$act) {
            return null;
        }

        $userId = $userId ?: (int)$act['faculty_id'];

        // Retrieve faculty user details
        $facStmt = $db->prepare("SELECT name, email, department FROM users WHERE id = ?");
        $facStmt->execute([(int)$act['faculty_id']]);
        $facultyUser = $facStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $matsStmt = $db->prepare("SELECT * FROM materials WHERE activity_id = ?");
        $matsStmt->execute([$activityId]);
        $mats = $matsStmt->fetchAll(PDO::FETCH_ASSOC);

        $progStmt = $db->prepare("SELECT * FROM program_sequence WHERE activity_id = ? ORDER BY sort_order, time_slot");
        $progStmt->execute([$activityId]);
        $prog = $progStmt->fetchAll(PDO::FETCH_ASSOC);

        $mpStmt = $db->prepare("SELECT * FROM manpower WHERE activity_id = ?");
        $mpStmt->execute([$activityId]);
        $mp = $mpStmt->fetchAll(PDO::FETCH_ASSOC);

        $schedStmt = $db->prepare("SELECT * FROM schedules WHERE activity_id = ? ORDER BY sched_date");
        $schedStmt->execute([$activityId]);
        $scheds = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

        $guideStmt = $db->prepare("SELECT * FROM guidelines WHERE activity_id = ?");
        $guideStmt->execute([$activityId]);
        $guide = $guideStmt->fetch(PDO::FETCH_ASSOC);

        // Count previous proposal documents for this activity to version reliably
        $vStmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE activity_id = ? AND doc_type = 'proposal'");
        $vStmt->execute([$activityId]);
        $prevCount = (int)$vStmt->fetchColumn();
        $versionNum = $prevCount + 1;

        $timestamp = time();
        if ($trigger === 'approved') {
            $versionLabel = "Final Approved";
            $fileName = "proposal_{$activityId}_FINAL_APPROVED_{$timestamp}.pdf";
        } elseif ($trigger === 'resubmitted') {
            $versionLabel = "v{$versionNum} Resubmitted";
            $fileName = "proposal_{$activityId}_v{$versionNum}_resubmitted_{$timestamp}.pdf";
        } else {
            $versionLabel = "v{$versionNum} Submitted";
            $fileName = "proposal_{$activityId}_v{$versionNum}_submitted_{$timestamp}.pdf";
        }

        // Generate formal PDF
        $pdfBinary = self::generatePdf($act, $mats, $prog, $mp, $scheds, $guide ?: null, $versionLabel, $facultyUser);

        // Ensure target directory exists
        $proposalsDir = UPLOAD_PATH . 'proposals/';
        if (!is_dir($proposalsDir)) {
            mkdir($proposalsDir, 0755, true);
        }

        $fullLocalPath = $proposalsDir . $fileName;
        $localRelPath  = 'proposals/' . $fileName;

        if (file_put_contents($fullLocalPath, $pdfBinary) === false) {
            error_log("Failed to write proposal PDF to disk: {$fullLocalPath}");
            return null;
        }

        // Record in documents table
        $ins = $db->prepare("
            INSERT INTO documents (
                activity_id, doc_type, file_name, file_path, uploaded_by, onedrive_status, uploaded_at
            ) VALUES (?, 'proposal', ?, ?, ?, 'pending', NOW())
        ");
        $ins->execute([$activityId, $fileName, $localRelPath, $userId]);
        $docId = (int)$db->lastInsertId();

        // Attempt non-blocking OneDrive archive synchronization
        $syncMeta = attemptOneDriveArchiveCopy($fullLocalPath, $fileName, 'proposals', $activityId, $service);

        if ($syncMeta) {
            // Update document row with sync metadata
            $up = $db->prepare("
                UPDATE documents
                SET onedrive_item_id = ?,
                    onedrive_web_url = ?,
                    onedrive_status  = ?,
                    onedrive_synced_at = ?,
                    onedrive_error   = ?
                WHERE id = ?
            ");
            $up->execute([
                $syncMeta['item_id'] ?? null,
                $syncMeta['web_url'] ?? null,
                $syncMeta['status'] ?? 'pending',
                $syncMeta['synced_at'] ?? null,
                $syncMeta['error'] ?? null,
                $docId
            ]);
        }

        return [
            'document_id' => $docId,
            'file_name'   => $fileName,
            'file_path'   => $localRelPath,
            'status'      => $syncMeta['status'] ?? 'pending',
            'sync_meta'   => $syncMeta,
        ];
    }
}

/**
 * Internal multi-page layout engine for STI-ARMES proposal documents.
 */
class ProposalPdfLayout
{
    private array $pages = [];
    private int $currentPage = -1;
    private float $y = 710;
    private float $leftMargin = 40;
    private float $contentWidth = 532;
    private string $proposalTitle = '';
    private string $proposalId = '';
    private string $versionLabel = '';
    private string $generatedAt = '';

    public function __construct(string $title, string $id, string $version)
    {
        $this->proposalTitle = $title;
        $this->proposalId = $id;
        $this->versionLabel = $version;
        $this->generatedAt = date('Y-m-d H:i:s');
        $this->newPage();
    }

    private function newPage(): void
    {
        $this->currentPage++;
        $this->pages[$this->currentPage] = '';
        $this->y = 710;

        // Running top bar & formal header on every page
        $p = &$this->pages[$this->currentPage];
        
        // STI Navy top decorative bar
        $p .= "0.06 0.18 0.37 rg 40 770 532 5 re f\n";
        // Accent gold/orange bar
        $p .= "0.96 0.62 0.04 rg 40 767 115 3 re f\n";

        // Header text: School brand and document designation
        $p .= "BT /F2 11 Tf 0.06 0.18 0.37 rg 40 748 Td (STI COLLEGE) Tj ET\n";
        $p .= "BT /F1 8.5 Tf 0.40 0.45 0.53 rg 115 748 Td (|  Activity Proposal Document  -  STI-ARMES) Tj ET\n";
        $p .= "BT /F2 8.5 Tf 0.06 0.18 0.37 rg 430 748 Td (PROPOSAL #" . $this->proposalId . ") Tj ET\n";

        // Version badge pill
        $badgeText = strtoupper($this->versionLabel);
        $p .= "0.92 0.94 0.97 rg 505 744 67 14 re f\n";
        $p .= "0.82 0.85 0.90 RG 0.5 w 505 744 67 14 re s\n";
        $p .= "BT /F2 7.5 Tf 0.06 0.18 0.37 rg 510 748 Td (" . $this->escapeText(substr($badgeText, 0, 14)) . ") Tj ET\n";

        // Separator rule below header
        $p .= "0.85 0.88 0.92 RG 0.5 w 40 736 m 572 736 l S\n";
    }

    private function checkPageSpace(float $neededHeight): void
    {
        if (($this->y - $neededHeight) < 70) {
            $this->newPage();
        }
    }

    private function escapeText(string $str): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $str);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }

    public function addTitleBanner(string $title, string $theme, string $source, string $status): void
    {
        $this->checkPageSpace(75);
        $p = &$this->pages[$this->currentPage];

        $boxHeight = 65;
        $this->y -= $boxHeight;

        // Navy banner with left orange accent strip
        $p .= "0.06 0.18 0.37 rg 40 " . $this->y . " 532 " . $boxHeight . " re f\n";
        $p .= "0.96 0.62 0.04 rg 40 " . $this->y . " 5 " . $boxHeight . " re f\n";

        // Proposal Title
        $dispTitle = substr($title, 0, 75);
        $p .= "BT /F2 13 Tf 1 1 1 rg 55 " . ($this->y + 44) . " Td (" . $this->escapeText($dispTitle) . ") Tj ET\n";

        // Theme / Focus
        $dispTheme = "Theme / Focus: " . ($theme ?: 'General Academic & Campus Activity');
        $p .= "BT /F3 9 Tf 0.85 0.90 0.96 rg 55 " . ($this->y + 26) . " Td (" . $this->escapeText(substr($dispTheme, 0, 85)) . ") Tj ET\n";

        // Source & Review Status
        $sourceLabel = ($source === 'student_org') ? 'Student Organization Activity' : 'Faculty Initiated Activity';
        $metaLine = "Origin: " . $sourceLabel . "   |   Official Status: " . strtoupper($status);
        $p .= "BT /F1 8 Tf 0.80 0.84 0.92 rg 55 " . ($this->y + 11) . " Td (" . $this->escapeText($metaLine) . ") Tj ET\n";

        $this->y -= 14;
    }

    public function addSectionHeader(string $title): void
    {
        $this->checkPageSpace(35);
        $p = &$this->pages[$this->currentPage];

        $this->y -= 22;
        // Light navy tinted bar with dark navy edge
        $p .= "0.94 0.96 0.98 rg 40 " . ($this->y - 3) . " 532 18 re f\n";
        $p .= "0.06 0.18 0.37 rg 40 " . ($this->y - 3) . " 3 18 re f\n";
        $p .= "BT /F2 9.5 Tf 0.06 0.18 0.37 rg 48 " . ($this->y + 2) . " Td (" . $this->escapeText(strtoupper($title)) . ") Tj ET\n";

        $this->y -= 8;
    }

    public function addGridRow(array $pairs): void
    {
        $this->checkPageSpace(22);
        $p = &$this->pages[$this->currentPage];

        $this->y -= 16;
        $colWidth = $this->contentWidth / count($pairs);
        $x = $this->leftMargin;

        foreach ($pairs as $label => $val) {
            $p .= "BT /F2 8 Tf 0.40 0.45 0.53 rg " . ($x + 4) . " " . ($this->y + 4) . " Td (" . $this->escapeText(strtoupper($label) . ":") . ") Tj ET\n";
            $p .= "BT /F1 8.5 Tf 0.15 0.18 0.24 rg " . ($x + 95) . " " . ($this->y + 4) . " Td (" . $this->escapeText(substr((string)$val, 0, 42)) . ") Tj ET\n";
            $x += $colWidth;
        }

        // Underline separator
        $p .= "0.90 0.92 0.95 RG 0.5 w 40 " . ($this->y) . " m 572 " . ($this->y) . " l S\n";
        $this->y -= 3;
    }

    public function addParagraphBlock(string $label, string $text): void
    {
        $lines = explode("\n", wordwrap(trim($text) ?: 'None specified.', 95));
        $blockHeight = 16 + (count($lines) * 12) + 6;
        $this->checkPageSpace($blockHeight);
        $p = &$this->pages[$this->currentPage];

        $this->y -= 14;
        $p .= "BT /F2 8.5 Tf 0.06 0.18 0.37 rg 42 " . ($this->y) . " Td (" . $this->escapeText($label) . ") Tj ET\n";

        foreach ($lines as $line) {
            $this->y -= 12;
            $p .= "BT /F1 8.5 Tf 0.15 0.18 0.24 rg 50 " . ($this->y) . " Td (" . $this->escapeText(trim($line)) . ") Tj ET\n";
        }
        $this->y -= 6;
    }

    public function addTable(array $headers, array $widths, array $rows, ?array $totalRow = null): void
    {
        $this->checkPageSpace(30 + (count($rows) * 18));
        $p = &$this->pages[$this->currentPage];

        // Navy Header Row
        $this->y -= 18;
        $p .= "0.06 0.18 0.37 rg 40 " . $this->y . " 532 18 re f\n";

        $x = $this->leftMargin;
        foreach ($headers as $i => $h) {
            $w = $widths[$i] ?? 100;
            $p .= "BT /F2 8 Tf 1 1 1 rg " . ($x + 6) . " " . ($this->y + 5) . " Td (" . $this->escapeText(strtoupper($h)) . ") Tj ET\n";
            $x += $w;
        }

        // Data Rows with alternating backgrounds
        $rowIndex = 0;
        foreach ($rows as $r) {
            $this->checkPageSpace(18);
            $p = &$this->pages[$this->currentPage];
            $this->y -= 17;

            if ($rowIndex % 2 === 1) {
                $p .= "0.97 0.98 0.99 rg 40 " . $this->y . " 532 17 re f\n";
            }
            $p .= "0.88 0.90 0.94 RG 0.5 w 40 " . $this->y . " m 572 " . $this->y . " l S\n";

            $x = $this->leftMargin;
            foreach ($r as $i => $val) {
                $w = $widths[$i] ?? 100;
                $isBold = ($i === 0 && count($r) === 2);
                $font = $isBold ? "/F2" : "/F1";
                $p .= "BT {$font} 8 Tf 0.15 0.18 0.24 rg " . ($x + 6) . " " . ($this->y + 5) . " Td (" . $this->escapeText((string)$val) . ") Tj ET\n";
                $x += $w;
            }
            $rowIndex++;
        }

        // Summary / Total row
        if (!empty($totalRow)) {
            $this->checkPageSpace(20);
            $p = &$this->pages[$this->currentPage];
            $this->y -= 18;
            $p .= "0.93 0.95 0.98 rg 40 " . $this->y . " 532 18 re f\n";
            $p .= "0.06 0.18 0.37 RG 1 w 40 " . $this->y . " m 572 " . $this->y . " l S\n";

            $x = $this->leftMargin;
            foreach ($totalRow as $i => $val) {
                $w = $widths[$i] ?? 100;
                $p .= "BT /F2 8.5 Tf 0.06 0.18 0.37 rg " . ($x + 6) . " " . ($this->y + 5) . " Td (" . $this->escapeText((string)$val) . ") Tj ET\n";
                $x += $w;
            }
        }

        $this->y -= 10;
    }

    public function addSignatories(string $facultyName, string $facultyRole, string $status): void
    {
        $this->checkPageSpace(85);
        $p = &$this->pages[$this->currentPage];

        $this->y -= 20;
        $boxW = 165;
        $boxH = 60;
        $yBox = $this->y - $boxH;

        // Box 1: Proponent
        $p .= "0.96 0.97 0.99 rg 40 " . $yBox . " " . $boxW . " " . $boxH . " re f\n";
        $p .= "0.85 0.88 0.92 RG 0.5 w 40 " . $yBox . " " . $boxW . " " . $boxH . " re s\n";
        $p .= "BT /F2 7.5 Tf 0.40 0.45 0.53 rg 46 " . ($yBox + 46) . " Td (PREPARED & SUBMITTED BY:) Tj ET\n";
        $p .= "BT /F2 8.5 Tf 0.06 0.18 0.37 rg 46 " . ($yBox + 22) . " Td (" . $this->escapeText($facultyName ?: 'Faculty Proponent') . ") Tj ET\n";
        $p .= "BT /F1 7.5 Tf 0.40 0.45 0.53 rg 46 " . ($yBox + 10) . " Td (" . $this->escapeText($facultyRole ?: 'Faculty Member / Adviser') . ") Tj ET\n";

        // Box 2: Reviewer Endorsement
        $x2 = 40 + $boxW + 18;
        $p .= "0.96 0.97 0.99 rg " . $x2 . " " . $yBox . " " . $boxW . " " . $boxH . " re f\n";
        $p .= "0.85 0.88 0.92 RG 0.5 w " . $x2 . " " . $yBox . " " . $boxW . " " . $boxH . " re s\n";
        $p .= "BT /F2 7.5 Tf 0.40 0.45 0.53 rg " . ($x2 + 6) . " " . ($yBox + 46) . " Td (REVIEWED & ENDORSED BY:) Tj ET\n";
        $revStatus = in_array($status, ['endorsed', 'pending_final_approval', 'approved']) ? 'ENDORSED FOR APPROVAL' : 'PENDING REVIEW';
        $p .= "BT /F2 8.5 Tf 0.06 0.18 0.37 rg " . ($x2 + 6) . " " . ($yBox + 22) . " Td (" . $this->escapeText($revStatus) . ") Tj ET\n";
        $p .= "BT /F1 7.5 Tf 0.40 0.45 0.53 rg " . ($x2 + 6) . " " . ($yBox + 10) . " Td (Academic Admin / Student Affairs) Tj ET\n";

        // Box 3: Dean Final Action
        $x3 = $x2 + $boxW + 18;
        $p .= "0.96 0.97 0.99 rg " . $x3 . " " . $yBox . " " . $boxW . " " . $boxH . " re f\n";
        $p .= "0.85 0.88 0.92 RG 0.5 w " . $x3 . " " . $yBox . " " . $boxW . " " . $boxH . " re s\n";
        $p .= "BT /F2 7.5 Tf 0.40 0.45 0.53 rg " . ($x3 + 6) . " " . ($yBox + 46) . " Td (FINAL APPROVAL ACTION:) Tj ET\n";
        $isApp = ($status === 'approved');
        $deanStatus = $isApp ? 'OFFICIALLY APPROVED' : 'UNDER EVALUATION';
        $deanColor = $isApp ? '0.06 0.58 0.34' : '0.40 0.45 0.53';
        $p .= "BT /F2 8.5 Tf {$deanColor} rg " . ($x3 + 6) . " " . ($yBox + 22) . " Td (" . $this->escapeText($deanStatus) . ") Tj ET\n";
        $p .= "BT /F1 7.5 Tf 0.40 0.45 0.53 rg " . ($x3 + 6) . " " . ($yBox + 10) . " Td (Office of the Dean) Tj ET\n";

        $this->y = $yBox - 15;
    }

    public function renderPdf(): string
    {
        $totalPages = count($this->pages);

        // Append footers with exact pagination
        for ($i = 0; $i < $totalPages; $i++) {
            $pageNum = $i + 1;
            $footerTextLeft = "STI-ARMES Proposal Snapshot  |  ID: #" . $this->proposalId . " (" . $this->versionLabel . ")";
            $footerTextMid = "Generated: " . $this->generatedAt;
            $footerTextRight = "Page {$pageNum} of {$totalPages}";

            $f = "\n% --- Footer ---\n";
            $f .= "0.85 0.88 0.92 RG 0.5 w 40 45 m 572 45 l S\n";
            $f .= "BT /F1 7.5 Tf 0.40 0.45 0.53 rg 40 33 Td (" . $this->escapeText($footerTextLeft) . ") Tj ET\n";
            $f .= "BT /F1 7.5 Tf 0.40 0.45 0.53 rg 230 33 Td (" . $this->escapeText($footerTextMid) . ") Tj ET\n";
            $f .= "BT /F2 7.5 Tf 0.06 0.18 0.37 rg 525 33 Td (" . $this->escapeText($footerTextRight) . ") Tj ET\n";

            $this->pages[$i] .= $f;
        }

        // Assemble standard PDF-1.4 objects
        $objects = [];
        $header = "%PDF-1.4\n";

        $pageObjIds = [];
        for ($i = 0; $i < $totalPages; $i++) {
            $pageObjIds[] = (6 + ($i * 2));
        }

        $kidsStr = implode(' 0 R ', $pageObjIds) . ' 0 R';
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [{$kidsStr}] /Count {$totalPages} >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";
        $objects[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>\nendobj\n";

        for ($i = 0; $i < $totalPages; $i++) {
            $pageObjId = 6 + ($i * 2);
            $contentObjId = $pageObjId + 1;
            $stream = $this->pages[$i];
            $streamLen = strlen($stream);

            $objects[$pageObjId] = "{$pageObjId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentObjId} 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> >>\nendobj\n";
            $objects[$contentObjId] = "{$contentObjId} 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj\n";
        }

        // Compute offsets and write xref
        $totalObjCount = count($objects);
        $body = $header;
        $offsets = [];

        for ($id = 1; $id <= $totalObjCount; $id++) {
            $offsets[$id] = strlen($body);
            $body .= $objects[$id];
        }

        $startXref = strlen($body);
        $xref = "xref\n0 " . ($totalObjCount + 1) . "\n";
        $xref .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $totalObjCount; $id++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $trailer = "trailer\n<< /Size " . ($totalObjCount + 1) . " /Root 1 0 R >>\nstartxref\n{$startXref}\n%%EOF\n";

        return $body . $xref . $trailer;
    }
}
