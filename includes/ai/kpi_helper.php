<?php
/**
 * kpi_helper.php
 *
 * Centralized calculation helper for numerical KPIs and performance status classifications.
 */

// Configurable KPI performance thresholds established in the system
if (!defined('KPI_THRESHOLD_EXCEEDED')) define('KPI_THRESHOLD_EXCEEDED', 110.0);
if (!defined('KPI_THRESHOLD_MET')) define('KPI_THRESHOLD_MET', 90.0);
if (!defined('KPI_THRESHOLD_NEAR')) define('KPI_THRESHOLD_NEAR', 70.0);

if (!function_exists('getKpiStatus')) {
    /**
     * Determines performance status based on achievement percentage using centralized thresholds.
     */
    function getKpiStatus(?float $achievementPct): string
    {
        if ($achievementPct === null) {
            return 'Insufficient Data';
        }
        
        // Exact centralized thresholds standardized to school template labels
        if ($achievementPct >= KPI_THRESHOLD_EXCEEDED) {
            return 'Exceeded the Target';
        } elseif ($achievementPct >= KPI_THRESHOLD_MET) {
            return 'Met the Target';
        } elseif ($achievementPct >= KPI_THRESHOLD_NEAR) {
            return 'Closed to the target';
        } else {
            return 'Did not meet the target';
        }
    }
}

if (!function_exists('getKpiStatusClass')) {
    /**
     * Returns the appropriate badge class for a given KPI status.
     * Supports both standardized school labels and legacy aliases.
     */
    function getKpiStatusClass(string $status): string
    {
        switch ($status) {
            case 'Exceeded the Target':
            case 'Exceeded Target':
                return 'badge-success';
            case 'Met the Target':
            case 'Met Target':
                return 'badge-info';
            case 'Closed to the target':
            case 'Close to Target':
            case 'Near Target':
                return 'badge-warning';
            case 'Did not meet the target':
            case 'Did Not Meet Target':
            case 'Below Target':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    }
}

if (!function_exists('calculateActivityKpis')) {
    /**
     * Computes KPI results using the criteria defined in the proposal (from Step 9).
     * Attendance is dynamically calculated from paper-based list, survey responses, or both (with deduplication).
     * Generates a deterministic data hash signature for cache detection.
     */
    function calculateActivityKpis(int $activityId, PDO $db): array
    {
        try {
            // 1. Fetch Activity details
            $actStmt = $db->prepare("SELECT title, target_participants, kpi_targets, evaluation_method FROM activities WHERE id = ?");
            $actStmt->execute([$activityId]);
            $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

            if (!$activity) {
                return [];
            }

            // 2. Fetch Post-Event details
            $peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ?");
            $peStmt->execute([$activityId]);
            $postEvent = $peStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // 3. Fetch Proposal-defined KPI criteria (from Step 9 of proposal form)
            $defStmt = $db->prepare("
                SELECT id, indicator, target_metric, evaluation_method 
                FROM kpi_evaluations 
                WHERE activity_id = ? AND indicator IS NOT NULL AND TRIM(indicator) != ''
                ORDER BY id ASC
            ");
            $defStmt->execute([$activityId]);
            $definedKpis = $defStmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Fetch KPI evaluations rating breakdown (from participant evaluation survey questions)
            $kpiStmt = $db->prepare("
                SELECT criteria, AVG(rating) as avg_rating, COUNT(id) as response_count 
                FROM kpi_evaluations 
                WHERE activity_id = ? AND rating IS NOT NULL AND criteria IS NOT NULL AND TRIM(criteria) != ''
                GROUP BY criteria
            ");
            $kpiStmt->execute([$activityId]);
            $criteriaRatings = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Fetch distinct survey respondents & overall survey rating
            $respStmt = $db->prepare("
                SELECT COUNT(DISTINCT CASE WHEN evaluator_name IS NOT NULL AND TRIM(evaluator_name) != '' THEN evaluator_name ELSE id END) as respondent_count,
                       AVG(rating) as overall_survey_avg
                FROM kpi_evaluations 
                WHERE activity_id = ? AND rating IS NOT NULL
            ");
            $respStmt->execute([$activityId]);
            $surveyStats = $respStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $surveyRespondentCount = (int)($surveyStats['respondent_count'] ?? 0);
            $overallSurveyAvg = $surveyStats['overall_survey_avg'] !== null ? round((float)$surveyStats['overall_survey_avg'], 2) : null;

            // If respondent count is 0 but a survey CSV exists, inspect the CSV directly
            if ($surveyRespondentCount === 0 && !empty($postEvent['survey_csv_path'])) {
                $csvFullPath = dirname(__DIR__, 2) . '/' . ltrim($postEvent['survey_csv_path'], '/\\');
                if (function_exists('parseEvaluationCsvFile') && file_exists($csvFullPath)) {
                    $csvData = parseEvaluationCsvFile($csvFullPath);
                    if (!empty($csvData['respondent_count'])) {
                        $surveyRespondentCount = (int)$csvData['respondent_count'];
                    }
                    if ($csvData['average_rating'] !== null && $overallSurveyAvg === null) {
                        $overallSurveyAvg = $csvData['average_rating'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("calculateActivityKpis error: " . $e->getMessage());
            return [];
        }

        // Attendance determination & deduplication logic
        $attMethod = !empty($postEvent['attendance_method']) ? $postEvent['attendance_method'] : 'paper_list';
        $manualAtt = isset($postEvent['manual_attendance']) && $postEvent['manual_attendance'] !== null ? (int)$postEvent['manual_attendance'] : null;
        $targetAttendance = !empty($postEvent['target_attendance']) ? (int)$postEvent['target_attendance'] : (!empty($activity['target_participants']) ? (int)$activity['target_participants'] : 0);

        $actualAttendance = null;
        $attendanceDetails = '';
        $dedupApplied = false;

        if ($attMethod === 'survey_only') {
            $actualAttendance = $surveyRespondentCount > 0 ? $surveyRespondentCount : (isset($postEvent['actual_attendance']) ? (int)$postEvent['actual_attendance'] : null);
            $attendanceDetails = "Actual attendance derived from {$surveyRespondentCount} verified evaluation survey responses.";
        } elseif ($attMethod === 'both') {
            $dedupApplied = true;
            $paperCount = $manualAtt !== null ? $manualAtt : (isset($postEvent['actual_attendance']) ? (int)$postEvent['actual_attendance'] : 0);
            // Avoid counting the same participants twice when both attendance methods are used:
            // Since attendees who signed the paper list and also submitted an online survey are the same event cohort,
            // the combined verified attendance is deduplicated:
            $actualAttendance = max($paperCount, $surveyRespondentCount);
            $attendanceDetails = "Combined unique attendees: {$actualAttendance} participants (Paper list: {$paperCount}, Online survey: {$surveyRespondentCount}; overlapping participants deduplicated to avoid counting twice).";
        } else { // 'paper_list'
            $actualAttendance = $manualAtt !== null ? $manualAtt : (isset($postEvent['actual_attendance']) ? (int)$postEvent['actual_attendance'] : null);
            $attendanceDetails = "Actual attendance recorded from physical paper attendance list: " . ($actualAttendance !== null ? "{$actualAttendance} participants." : "No data yet.");
        }

        // Determine satisfaction score (normalized to 5.0 scale)
        $actualSat = isset($postEvent['satisfaction_score']) && $postEvent['satisfaction_score'] > 0 
            ? (float)$postEvent['satisfaction_score'] 
            : ($overallSurveyAvg !== null ? round(($overallSurveyAvg / 4.0) * 5.0, 1) : null);

        $kpis = [];
        $allKpiRows = [];
        $achievementScores = [];

        // Primary Attendance KPI container (standard contract for downstream services)
        $attPct = null;
        if ($targetAttendance > 0 && $actualAttendance !== null) {
            $attPct = function_exists('calculateAttendanceRate')
                ? calculateAttendanceRate($actualAttendance, $targetAttendance, 1)
                : min(100.0, round(($actualAttendance / $targetAttendance) * 100, 1));
        }
        $attStatus = getKpiStatus($attPct);
        $kpis['attendance'] = [
            'indicator' => 'Participant Attendance',
            'target' => $targetAttendance > 0 ? (string)$targetAttendance : '—',
            'acceptable_target' => $targetAttendance > 0 ? (string)round($targetAttendance * 0.95) : '—',
            'actual' => $actualAttendance !== null ? (string)$actualAttendance : '—',
            'achievement' => $attPct !== null ? $attPct . '%' : '—',
            'achievement_val' => $attPct,
            'status' => $attStatus,
            'evaluation_method' => $attMethod === 'both' ? 'Both Paper & Survey' : ($attMethod === 'survey_only' ? 'Online Survey' : 'Paper Attendance List'),
            'calculation_details' => $attPct !== null ? "Calculation: Actual ({$actualAttendance}) / Target ({$targetAttendance}) = {$attPct}%. {$attendanceDetails}" : "Insufficient data.",
            'response_count' => $surveyRespondentCount
        ];

        // Primary Satisfaction KPI container (standard contract for downstream services)
        $targetSat = 4.0;
        $satPct = null;
        if ($actualSat !== null) {
            $satPct = round(($actualSat / $targetSat) * 100, 1);
        }
        $satStatus = getKpiStatus($satPct);
        $kpis['satisfaction'] = [
            'indicator' => 'Overall Participant Satisfaction',
            'target' => number_format($targetSat, 1) . ' / 5.0',
            'actual' => $actualSat !== null ? number_format($actualSat, 1) . ' / 5.0' : '—',
            'achievement' => $satPct !== null ? $satPct . '%' : '—',
            'achievement_val' => $satPct,
            'status' => $satStatus,
            'evaluation_method' => 'Participant Evaluation Survey',
            'calculation_details' => $actualSat !== null ? "Calculation: Actual Rating ({$actualSat}/5.0) / Target Rating ({$targetSat}/5.0) = {$satPct}% [Responses: {$surveyRespondentCount}]." : "Pending evaluation survey.",
            'response_count' => $surveyRespondentCount
        ];

        // Dynamic Proposal Criteria Evaluation
        if (!empty($definedKpis)) {
            foreach ($definedKpis as $dk) {
                $ind = trim($dk['indicator'] ?? '');
                $targetRaw = trim($dk['target_metric'] ?? '');
                $methodRaw = trim($dk['evaluation_method'] ?? '');
                $indLower = strtolower($ind);
                $methodLower = strtolower($methodRaw);

                $parsed = function_exists('parseKpiTargetMetric') 
                    ? parseKpiTargetMetric($targetRaw, $targetAttendance)
                    : ['type' => 'numeric', 'calc_target' => (float)$targetRaw, 'raw' => $targetRaw];

                $targetVal = $parsed['calc_target'];
                $actualVal = null;
                $achPct = null;
                $calcDetails = '';

                $isAttendance = (
                    str_contains($indLower, 'attend') || str_contains($indLower, 'participant') || 
                    str_contains($indLower, 'headcount') || str_contains($indLower, 'turnout') ||
                    str_contains($methodLower, 'attendance') || str_contains($methodLower, 'paper')
                );

                $isSatisfaction = (
                    str_contains($indLower, 'satisfact') || str_contains($indLower, 'survey') ||
                    str_contains($indLower, 'rating') || str_contains($indLower, 'feedback') ||
                    str_contains($indLower, 'evaluat') || str_contains($methodLower, 'survey') ||
                    str_contains($methodLower, 'student evaluation')
                );

                if ($isAttendance) {
                    $actualVal = $actualAttendance;
                    $effTarget = ($targetVal && $targetVal > 0) ? $targetVal : $targetAttendance;
                    if ($actualVal !== null && $effTarget > 0) {
                        $achPct = round(($actualVal / $effTarget) * 100, 1);
                        $calcDetails = "Calculation: Actual ({$actualVal}) / Target ({$effTarget}) = {$achPct}%. {$attendanceDetails}";
                    } else {
                        $calcDetails = "Insufficient attendance data recorded. Target: {$effTarget}.";
                    }
                } elseif ($isSatisfaction) {
                    $targetRating = ($targetVal && $targetVal > 0) ? $targetVal : 4.0;
                    $actualVal = $actualSat;
                    if ($actualVal !== null && $targetRating > 0) {
                        $achPct = round(($actualVal / $targetRating) * 100, 1);
                        $calcDetails = "Calculation: Actual Rating ({$actualVal}/5.0) / Target Rating ({$targetRating}/5.0) = {$achPct}% [Survey Responses: {$surveyRespondentCount}].";
                    } else {
                        $calcDetails = "Pending participant evaluation survey responses or post-event ratings.";
                    }
                } else {
                    // Check if criterion matches one of the evaluation questions
                    $matchedQuestion = null;
                    foreach ($criteriaRatings as $cr) {
                        if (stripos($cr['criteria'], $ind) !== false || stripos($ind, $cr['criteria']) !== false) {
                            $matchedQuestion = $cr;
                            break;
                        }
                    }
                    if ($matchedQuestion) {
                        $actualVal = (float)$matchedQuestion['avg_rating'];
                        $targetRating = ($targetVal && $targetVal > 0) ? $targetVal : 3.0;
                        $achPct = round(($actualVal / $targetRating) * 100, 1);
                        $calcDetails = "Calculation: Actual Question Score ({$actualVal}/4.0) / Target ({$targetRating}/4.0) = {$achPct}% [Responses: {$matchedQuestion['response_count']}].";
                    } else {
                        $calcDetails = "Pending specific criteria verification from post-event documentation or evaluation responses.";
                    }
                }

                $status = getKpiStatus($achPct);
                if ($achPct !== null) {
                    $achievementScores[] = $achPct;
                }

                $allKpiRows[] = [
                    'indicator' => $ind,
                    'name' => $ind,
                    'target' => $targetRaw ?: ($targetAttendance > 0 ? (string)$targetAttendance : '—'),
                    'evaluation_method' => $methodRaw ?: 'Proposal Defined Metric',
                    'actual' => $actualVal !== null ? (string)$actualVal : '—',
                    'achievement' => $achPct !== null ? $achPct . '%' : '—',
                    'achievement_val' => $achPct,
                    'status' => $status,
                    'calculation_details' => $calcDetails,
                    'response_count' => $surveyRespondentCount
                ];
            }
        } else {
            // Legacy activities fallback: include primary attendance and satisfaction
            if ($attPct !== null) $achievementScores[] = $attPct;
            $allKpiRows[] = $kpis['attendance'];

            if ($satPct !== null) $achievementScores[] = $satPct;
            $allKpiRows[] = $kpis['satisfaction'];
        }

        // Criteria ratings from evaluation survey questions
        $kpis['criteria'] = [];
        $totalWeight = 0;
        $weightedCriteriaSum = 0;
        foreach ($criteriaRatings as $cr) {
            $critName = trim($cr['criteria']);
            $targetRating = 3.0; // Benchmark target out of 4.0 stars
            $actualRating = (float)$cr['avg_rating'];
            $weight = isset($cr['weight']) && is_numeric($cr['weight']) && (float)$cr['weight'] > 0 ? (float)$cr['weight'] : 1.0;

            $critPct = round(($actualRating / $targetRating) * 100, 1);
            $totalWeight += $weight;
            $weightedCriteriaSum += ($critPct * $weight);
            if (empty($definedKpis)) {
                $achievementScores[] = $critPct;
            }
            $critStatus = getKpiStatus($critPct);

            $kpis['criteria'][] = [
                'indicator' => 'Survey Metric: ' . $critName,
                'name' => $critName,
                'criteria_name' => $critName,
                'target' => number_format($targetRating, 1) . ' / 4.0',
                'actual' => number_format($actualRating, 1) . ' / 4.0',
                'weight' => $weight,
                'achievement' => $critPct . '%',
                'achievement_val' => $critPct,
                'status' => $critStatus,
                'response_count' => (int)$cr['response_count'],
                'evaluation_method' => 'Participant Evaluation Survey',
                'calculation_details' => "Calculation: Average Rating ({$actualRating}/4.0) / Target ({$targetRating}/4.0) = {$critPct}% [Responses: {$cr['response_count']}]."
            ];
        }

        $surveyScore = $totalWeight > 0 ? round($weightedCriteriaSum / $totalWeight, 1) : null;
        $kpis['survey_summary'] = [
            'calculation_type' => 'unweighted',
            'note' => 'Calculated as unweighted average of survey evaluation criteria',
            'score' => $surveyScore,
            'status' => getKpiStatus($surveyScore)
        ];

        // Overall Performance Score
        $overallPct = !empty($achievementScores) ? round(array_sum($achievementScores) / count($achievementScores), 1) : null;
        $overallStatus = getKpiStatus($overallPct);

        // Data signature hash for cache detection
        $hashInput = json_encode([
            'activity_id' => $activityId,
            'target_attendance' => $targetAttendance,
            'actual_attendance' => $actualAttendance,
            'satisfaction_score' => $actualSat,
            'all_kpi_rows' => $allKpiRows,
            'criteria_data' => $criteriaRatings
        ]);
        $dataHash = hash('sha256', $hashInput);

        return [
            'activity_id' => $activityId,
            'title' => $activity['title'],
            'kpis' => $kpis,
            'all_kpi_rows' => $allKpiRows,
            'attendance_method' => $attMethod,
            'deduplication_applied' => $dedupApplied,
            'overall_performance' => $overallPct,
            'overall_status' => $overallStatus,
            'data_hash' => $dataHash,
            'has_data' => !empty($achievementScores)
        ];
    }
}

if (!function_exists('buildPeriodWhereClause')) {
    /**
     * Builds SQL where clause and parameter array adhering strictly to existing semester conventions.
     */
    function buildPeriodWhereClause($year, string $period, string $dateColumn = 'a.event_date'): array
    {
        if ($year === 'all' || $year === null || $year === 0 || $year === '') {
            $where = "1=1";
            $params = [];
        } else {
            $where = "YEAR({$dateColumn}) = ?";
            $params = [(int)$year];
        }

        if ($period === '1st_sem') {
            // 1st Sem typically June to October
            $where .= " AND MONTH({$dateColumn}) BETWEEN 6 AND 10";
        } elseif ($period === '2nd_sem') {
            // 2nd Sem typically November to March
            $where .= " AND (MONTH({$dateColumn}) >= 11 OR MONTH({$dateColumn}) <= 3)";
        }
        return ['where' => $where, 'params' => $params];
    }
}

if (!function_exists('getInstitutionalKpiSummary')) {
    /**
     * Deterministically calculates institutional activity statistics and KPI performance metrics.
     * Strictly separates overall activity statistics from KPI performance calculations
     * (only activities with completed post-event/KPI data are evaluated for KPI performance).
     */
    function getInstitutionalKpiSummary($year, string $period, PDO $db, ?int $deanId = null): array
    {
        $periodBounds = buildPeriodWhereClause($year, $period, 'a.event_date');
        $where = $periodBounds['where'];
        $params = $periodBounds['params'];

        $rawStats = [];
        try {
            // 1. Activity Statistics: Comprehensive institutional oversight across all submitted proposals
            // Counts all non-draft activities regardless of which approver acted on them (parity with Dean All Activities)
            $statsStmt = $db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN a.status = 'pending_final_approval' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN a.status = 'returned_for_revision' THEN 1 ELSE 0 END) as returned,
                    SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM activities a
                WHERE {$where} AND a.status != 'draft'
            ");
            $statsStmt->execute($params);
            $rawStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log("getInstitutionalKpiSummary stats error: " . $e->getMessage());
            $rawStats = [];
        }

        $totalActivities = (int)($rawStats['total'] ?? 0);
        $pendingProposals = (int)($rawStats['pending'] ?? 0);
        $approvedActivities = (int)($rawStats['approved'] ?? 0);
        $completedActivities = (int)($rawStats['completed'] ?? 0);
        $returnedActivities = (int)($rawStats['returned'] ?? 0);
        $rejectedActivities = (int)($rawStats['rejected'] ?? 0);
        $returnedRejectedTotal = $returnedActivities + $rejectedActivities;

        $eligibleActivities = [];
        try {
            // 2. Fetch completed/approved activities for KPI performance computation
            $actQuery = "
                SELECT a.id, a.title, a.event_date, a.status,
                       pe.actual_attendance, COALESCE(NULLIF(pe.target_attendance, 0), a.target_participants, 0) AS target_attendance, pe.satisfaction_score,
                       AVG(k.rating) as avg_kpi
                FROM activities a
                LEFT JOIN post_event pe ON a.id = pe.activity_id
                LEFT JOIN kpi_evaluations k ON a.id = k.activity_id
                WHERE {$where} AND a.status IN ('approved', 'completed')
                GROUP BY a.id
                ORDER BY a.event_date DESC
            ";
            $actStmt = $db->prepare($actQuery);
            $actStmt->execute($params);
            $eligibleActivities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("getInstitutionalKpiSummary eligibleActivities error: " . $e->getMessage());
            $eligibleActivities = [];
        }

        $kpiRatingsSum = 0;
        $kpiRatingsCount = 0;
        $satisfactionSum = 0;
        $satisfactionCount = 0;
        $attendanceActualSum = 0;
        $attendanceTargetSum = 0;

        $meetingTargets = 0;
        $nearTargets = 0;
        $belowTargets = 0;
        $overallPerfScores = [];
        $anonymizedActivities = [];
        $completedWithKpiCount = 0;

        $activityIdx = 1;
        foreach ($eligibleActivities as $act) {
            $actId = (int)$act['id'];
            $kpiDetails = calculateActivityKpis($actId, $db);

            // KPI performance calculations must use ONLY activities that have actual KPI/post-event data
            if (!empty($kpiDetails) && !empty($kpiDetails['has_data']) && $kpiDetails['overall_performance'] !== null) {
                $completedWithKpiCount++;
                $score = (float)$kpiDetails['overall_performance'];
                $overallPerfScores[] = $score;

                if ($score >= KPI_THRESHOLD_MET) {
                    $meetingTargets++;
                } elseif ($score >= KPI_THRESHOLD_NEAR) {
                    $nearTargets++;
                } else {
                    $belowTargets++;
                }

                $avgKpi = $act['avg_kpi'] !== null ? (float)$act['avg_kpi'] : null;
                $satisfaction = $act['satisfaction_score'] !== null ? (float)$act['satisfaction_score'] : null;
                $actualAtt = $act['actual_attendance'] !== null ? (int)$act['actual_attendance'] : null;
                $targetAtt = $act['target_attendance'] !== null ? (int)$act['target_attendance'] : null;

                if ($avgKpi !== null) {
                    $kpiRatingsSum += $avgKpi;
                    $kpiRatingsCount++;
                }
                if ($satisfaction !== null) {
                    $satisfactionSum += $satisfaction;
                    $satisfactionCount++;
                }
                if ($actualAtt !== null && $targetAtt !== null && $targetAtt > 0) {
                    $attendanceActualSum += $actualAtt;
                    $attendanceTargetSum += $targetAtt;
                }

                // Anonymized entry for Gemini payload (zero participant details, zero PII, generic index)
                $anonymizedActivities[] = [
                    'activity_ref' => 'Activity ' . $activityIdx++,
                    'event_date' => $act['event_date'],
                    'avg_kpi' => $avgKpi !== null ? number_format($avgKpi, 2) : '—',
                    'satisfaction_score' => $satisfaction !== null ? number_format($satisfaction, 1) : '—',
                    'attendance_rate' => ($targetAtt !== null && $targetAtt > 0 && $actualAtt !== null) ? (min(100.0, round(($actualAtt / $targetAtt) * 100, 1)) . '%') : '—',
                    'overall_performance' => $score . '%'
                ];
            }
        }

        $avgKpiRating = $kpiRatingsCount > 0 ? round($kpiRatingsSum / $kpiRatingsCount, 2) : null;
        $avgSatisfaction = $satisfactionCount > 0 ? round($satisfactionSum / $satisfactionCount, 1) : null;
        $rawAttendanceRate = $attendanceTargetSum > 0 ? min(100.0, round(($attendanceActualSum / $attendanceTargetSum) * 100, 1)) : null;
        $overallAttendanceRate = $attendanceTargetSum > 0 ? min(100.0, round(($attendanceActualSum / $attendanceTargetSum) * 100, 1)) : null;
        $overallKpiPerformance = !empty($overallPerfScores) ? round(array_sum($overallPerfScores) / count($overallPerfScores), 1) : null;

        // Deterministic signature for cache freshness
        $hashInput = json_encode([
            'year' => $year,
            'period' => $period,
            'total_activities' => $totalActivities,
            'completed_activities' => $completedActivities,
            'completed_with_kpi' => $completedWithKpiCount,
            'meeting_targets' => $meetingTargets,
            'near_targets' => $nearTargets,
            'below_targets' => $belowTargets,
            'overall_kpi_performance' => $overallKpiPerformance,
            'avg_kpi_rating' => $avgKpiRating,
            'avg_satisfaction' => $avgSatisfaction,
            'overall_attendance_rate' => $overallAttendanceRate
        ]);
        $dataHash = hash('sha256', $hashInput);

        return [
            'year' => $year,
            'period' => $period,
            'statistics' => [
                'total' => $totalActivities,
                'pending' => $pendingProposals,
                'approved' => $approvedActivities,
                'completed' => $completedActivities,
                'returned' => $returnedActivities,
                'rejected' => $rejectedActivities,
                'returned_rejected' => $returnedRejectedTotal
            ],
            'kpi_performance' => [
                'completed_with_kpi' => $completedWithKpiCount,
                'meeting_targets' => $meetingTargets,
                'near_targets' => $nearTargets,
                'below_targets' => $belowTargets,
                'overall_performance' => $overallKpiPerformance,
                'overall_status' => getKpiStatus($overallKpiPerformance),
                'avg_kpi_rating' => $avgKpiRating !== null ? number_format($avgKpiRating, 2) : '—',
                'avg_satisfaction' => $avgSatisfaction !== null ? number_format($avgSatisfaction, 1) : '—',
                'attendance_rate' => $overallAttendanceRate !== null ? $overallAttendanceRate . '%' : '—',
                'raw_attendance_rate' => $rawAttendanceRate !== null ? $rawAttendanceRate . '%' : '—',
                'attendance_status' => getKpiStatus($overallAttendanceRate),
                'has_data' => $completedWithKpiCount > 0
            ],
            'activities' => $anonymizedActivities,
            'data_hash' => $dataHash
        ];
    }
}

if (!function_exists('getApprovalTrends')) {
    /**
     * Retrieves monthly approval, returned, and rejected proposal trends for the selected period
     * based on actual decision dates (approved_at, approval_logs.acted_at, or updated_at).
     */
    function getApprovalTrends($year, string $period, PDO $db, ?string $source = null, ?int $reviewerId = null): array
    {
        // Determine active month range for period
        $activeMonths = range(1, 12);
        if ($period === '1st_sem') {
            $activeMonths = range(6, 10);
        } elseif ($period === '2nd_sem') {
            $activeMonths = [11, 12, 1, 2, 3];
        }

        $monthData = [];
        foreach ($activeMonths as $m) {
            $monthData[$m] = [
                'approved' => 0,
                'returned' => 0,
                'rejected' => 0,
                'total'    => 0
            ];
        }

        // Shared source filter
        $sourceSql = ($source !== null) ? " AND a.source = ?" : "";

        // 1. Approved proposals (counted by actual approval decision date)
        try {
            $appWhere = "a.status IN ('approved', 'completed'){$sourceSql}";
            $appParams = ($source !== null) ? [$source] : [];

            if ($reviewerId !== null) {
                $appWhere .= " AND (a.status IN ('approved', 'completed') OR EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?))";
                $appParams[] = $reviewerId;
            }

            $appStmt = $db->prepare("
                SELECT 
                    MONTH(COALESCE(
                        a.approved_at,
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'approved' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as m,
                    YEAR(COALESCE(
                        a.approved_at,
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'approved' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as y,
                    COUNT(DISTINCT a.id) as cnt
                FROM activities a
                WHERE {$appWhere}
                GROUP BY y, m
            ");
            $appStmt->execute($appParams);
            $appRows = $appStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($appRows as $r) {
                $m = (int)$r['m'];
                $y = (int)$r['y'];
                if (($year === 'all' || $year === null || $year === '' || $year === 0 || $y === (int)$year) && in_array($m, $activeMonths, true)) {
                    $monthData[$m]['approved'] += (int)$r['cnt'];
                }
            }
        } catch (\Throwable $e) {
            error_log("getApprovalTrends approved query error: " . $e->getMessage());
        }

        // 2. Returned proposals (current status = 'returned_for_revision', counted by return decision date)
        try {
            $retWhere = "a.status = 'returned_for_revision'{$sourceSql}";
            $retParams = ($source !== null) ? [$source] : [];

            if ($reviewerId !== null) {
                $retWhere .= " AND EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)";
                $retParams[] = $reviewerId;
            }

            $retStmt = $db->prepare("
                SELECT 
                    MONTH(COALESCE(
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'returned_for_revision' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as m,
                    YEAR(COALESCE(
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'returned_for_revision' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as y,
                    COUNT(DISTINCT a.id) as cnt
                FROM activities a
                WHERE {$retWhere}
                GROUP BY y, m
            ");
            $retStmt->execute($retParams);
            $retRows = $retStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($retRows as $r) {
                $m = (int)$r['m'];
                $y = (int)$r['y'];
                if (($year === 'all' || $year === null || $year === '' || $year === 0 || $y === (int)$year) && in_array($m, $activeMonths, true)) {
                    $monthData[$m]['returned'] += (int)$r['cnt'];
                }
            }
        } catch (\Throwable $e) {
            error_log("getApprovalTrends returned query error: " . $e->getMessage());
        }

        // 3. Rejected proposals (current status = 'rejected', counted by rejection decision date)
        try {
            $rejWhere = "a.status = 'rejected'{$sourceSql}";
            $rejParams = ($source !== null) ? [$source] : [];

            if ($reviewerId !== null) {
                $rejWhere .= " AND EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)";
                $rejParams[] = $reviewerId;
            }

            $rejStmt = $db->prepare("
                SELECT 
                    MONTH(COALESCE(
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'rejected' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as m,
                    YEAR(COALESCE(
                        (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'rejected' ORDER BY al.acted_at DESC LIMIT 1),
                        a.updated_at
                    )) as y,
                    COUNT(DISTINCT a.id) as cnt
                FROM activities a
                WHERE {$rejWhere}
                GROUP BY y, m
            ");
            $rejStmt->execute($rejParams);
            $rejRows = $rejStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rejRows as $r) {
                $m = (int)$r['m'];
                $y = (int)$r['y'];
                if (($year === 'all' || $year === null || $year === '' || $year === 0 || $y === (int)$year) && in_array($m, $activeMonths, true)) {
                    $monthData[$m]['rejected'] += (int)$r['cnt'];
                }
            }
        } catch (\Throwable $e) {
            error_log("getApprovalTrends rejected query error: " . $e->getMessage());
        }

        // Aggregate monthly trends and grand totals
        $trends = [];
        $maxMonthCount = 1;
        $totalApproved = 0;
        $totalReturned = 0;
        $totalRejected = 0;

        foreach ($activeMonths as $m) {
            $app = $monthData[$m]['approved'];
            $ret = $monthData[$m]['returned'];
            $rej = $monthData[$m]['rejected'];
            $tot = $app + $ret + $rej;

            $maxMonthCount = max($maxMonthCount, $app, $ret, $rej);
            $totalApproved += $app;
            $totalReturned += $ret;
            $totalRejected += $rej;

            $trends[] = [
                'month_num'  => $m,
                'month_name' => date('M', mktime(0, 0, 0, $m, 1)),
                'approved'   => $app,
                'returned'   => $ret,
                'rejected'   => $rej,
                'total'      => $tot
            ];
        }

        return [
            'months'         => $trends,
            'max_count'      => $maxMonthCount,
            'total_approved' => $totalApproved,
            'total_returned' => $totalReturned,
            'total_rejected' => $totalRejected
        ];
    }
}

