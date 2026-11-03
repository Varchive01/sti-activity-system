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
     * Computes targets, actuals, achievement percentages, and status classifications.
     * Generates a data hash signature based on the computed inputs for caching.
     */
    function calculateActivityKpis(int $activityId, PDO $db): array
    {
        // 1. Fetch Activity details
        $actStmt = $db->prepare("SELECT title, target_participants FROM activities WHERE id = ?");
        $actStmt->execute([$activityId]);
        $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            return [];
        }

        // 2. Fetch Post-Event details
        $peStmt = $db->prepare("SELECT target_attendance, actual_attendance, satisfaction_score, recommendations FROM post_event WHERE activity_id = ?");
        $peStmt->execute([$activityId]);
        $postEvent = $peStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 3. Fetch KPI evaluations rating breakdown
        $kpiStmt = $db->prepare("
            SELECT criteria, AVG(rating) as avg_rating, COUNT(id) as response_count 
            FROM kpi_evaluations 
            WHERE activity_id = ? AND rating IS NOT NULL AND criteria IS NOT NULL AND TRIM(criteria) != ''
            GROUP BY criteria
        ");
        $kpiStmt->execute([$activityId]);
        $criteriaRatings = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

        $kpis = [];
        $achievementScores = [];

        // --- A. Attendance KPI ---
        // School Template: Acceptable attendance benchmark is 95% of expected attendees.
        // Capped at 100.0% when meeting/exceeding acceptable target.
        // Example: target = 40, actual = 38 => acceptable target = 38 => achievement = 100.0%.
        $targetAttendance = !empty($postEvent['target_attendance']) ? (int)$postEvent['target_attendance'] : (!empty($activity['target_participants']) ? (int)$activity['target_participants'] : 0);
        $actualAttendance = isset($postEvent['actual_attendance']) ? (int)$postEvent['actual_attendance'] : null;
        
        $attPct = null;
        if ($targetAttendance > 0 && $actualAttendance !== null) {
            $attPct = function_exists('calculateAttendanceRate')
                ? calculateAttendanceRate($actualAttendance, $targetAttendance, 1)
                : min(100.0, round(($actualAttendance / $targetAttendance) * 100, 1));
            $achievementScores[] = $attPct;
        }
        $attStatus = getKpiStatus($attPct);

        $kpis['attendance'] = [
            'indicator' => 'Participant Attendance',
            'target' => $targetAttendance > 0 ? (string)$targetAttendance : '—',
            'acceptable_target' => $targetAttendance > 0 ? (string)round($targetAttendance * 0.95) : '—',
            'actual' => $actualAttendance !== null ? (string)$actualAttendance : '—',
            'achievement' => $attPct !== null ? $attPct . '%' : '—',
            'achievement_val' => $attPct,
            'status' => $attStatus
        ];

        // --- B. Satisfaction KPI ---
        $targetSat = 4.0; // Benchmark target out of 5.0
        $actualSat = isset($postEvent['satisfaction_score']) && $postEvent['satisfaction_score'] > 0 ? (float)$postEvent['satisfaction_score'] : null;

        $satPct = null;
        if ($actualSat !== null) {
            $satPct = round(($actualSat / $targetSat) * 100, 1);
            $achievementScores[] = $satPct;
        }
        $satStatus = getKpiStatus($satPct);

        $kpis['satisfaction'] = [
            'indicator' => 'Overall Participant Satisfaction',
            'target' => number_format($targetSat, 1) . ' / 5.0',
            'actual' => $actualSat !== null ? number_format($actualSat, 1) . ' / 5.0' : '—',
            'achievement' => $satPct !== null ? $satPct . '%' : '—',
            'achievement_val' => $satPct,
            'status' => $satStatus
        ];

        // --- C. Criteria ratings from evaluations ---
        // School Template Note: The school template specifies a weighted average for Survey/Feedback.
        // Legitimate weights are not yet defined by the school, so criteria ratings are currently
        // calculated as an unweighted average (each weight defaults to 1.0). Weighted formula architecture
        // is preserved so legitimate weights can be supplied without changing the calculation engine.
        $kpis['criteria'] = [];
        $totalWeight = 0;
        $weightedCriteriaSum = 0;
        foreach ($criteriaRatings as $cr) {
            $critName = trim($cr['criteria']);
            $targetRating = 3.0; // Benchmark target out of 4.0 stars
            $actualRating = (float)$cr['avg_rating'];
            // Smallest safe change: allow weight if provided in data/config, default to 1.0 (unweighted)
            $weight = isset($cr['weight']) && is_numeric($cr['weight']) && (float)$cr['weight'] > 0 ? (float)$cr['weight'] : 1.0;

            $critPct = round(($actualRating / $targetRating) * 100, 1);
            $totalWeight += $weight;
            $weightedCriteriaSum += ($critPct * $weight);
            $achievementScores[] = $critPct;
            $critStatus = getKpiStatus($critPct);

            $kpis['criteria'][] = [
                'indicator' => 'Survey Metric: ' . $critName,
                'criteria_name' => $critName,
                'target' => number_format($targetRating, 1) . ' / 4.0',
                'actual' => number_format($actualRating, 1) . ' / 4.0',
                'weight' => $weight,
                'achievement' => $critPct . '%',
                'achievement_val' => $critPct,
                'status' => $critStatus,
                'response_count' => (int)$cr['response_count']
            ];
        }
        $surveyScore = $totalWeight > 0 ? round($weightedCriteriaSum / $totalWeight, 1) : null;
        $kpis['survey_summary'] = [
            'calculation_type' => 'unweighted',
            'note' => 'Calculated as unweighted average; official survey weights pending confirmation',
            'score' => $surveyScore,
            'status' => getKpiStatus($surveyScore)
        ];

        // Calculate Overall Activity Performance
        $overallPct = null;
        $overallStatus = 'Insufficient Data';
        if (!empty($achievementScores)) {
            $overallPct = round(array_sum($achievementScores) / count($achievementScores), 1);
            $overallStatus = getKpiStatus($overallPct);
        }

        // Generate data signature hash for cache detection
        // Serializes all numeric calculation inputs to form a deterministic SHA-256 signature
        $hashInput = json_encode([
            'activity_id' => $activityId,
            'target_attendance' => $targetAttendance,
            'actual_attendance' => $actualAttendance,
            'satisfaction_score' => $actualSat,
            'criteria_data' => $criteriaRatings
        ]);
        $dataHash = hash('sha256', $hashInput);

        return [
            'activity_id' => $activityId,
            'title' => $activity['title'],
            'kpis' => $kpis,
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
    function buildPeriodWhereClause(int $year, string $period, string $dateColumn = 'a.event_date'): array
    {
        $where = "YEAR({$dateColumn}) = ?";
        $params = [$year];

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
    function getInstitutionalKpiSummary(int $year, string $period, PDO $db): array
    {
        $periodBounds = buildPeriodWhereClause($year, $period, 'a.event_date');
        $where = $periodBounds['where'];
        $params = $periodBounds['params'];

        // 1. Activity Statistics (All activities in the period across all statuses)
        $statsStmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(status = 'pending_final_approval') as pending,
                SUM(status = 'approved') as approved,
                SUM(status = 'completed') as completed,
                SUM(status = 'returned_for_revision') as returned,
                SUM(status = 'rejected') as rejected
            FROM activities a
            WHERE {$where}
        ");
        $statsStmt->execute($params);
        $rawStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalActivities = (int)($rawStats['total'] ?? 0);
        $pendingProposals = (int)($rawStats['pending'] ?? 0);
        $approvedActivities = (int)($rawStats['approved'] ?? 0);
        $completedActivities = (int)($rawStats['completed'] ?? 0);
        $returnedActivities = (int)($rawStats['returned'] ?? 0);
        $rejectedActivities = (int)($rawStats['rejected'] ?? 0);
        $returnedRejectedTotal = $returnedActivities + $rejectedActivities;

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
     * Retrieves monthly approval vs returned/rejected proposal trends for the selected period.
     */
    function getApprovalTrends(int $year, string $period, PDO $db, ?string $source = null): array
    {
        $periodBounds = buildPeriodWhereClause($year, $period, 'a.event_date');
        $where = $periodBounds['where'];
        $params = $periodBounds['params'];

        if ($source !== null) {
            $where .= " AND a.source = ?";
            $params[] = $source;
        }

        // Determine active month range for period
        $activeMonths = range(1, 12);
        if ($period === '1st_sem') {
            $activeMonths = range(6, 10);
        } elseif ($period === '2nd_sem') {
            $activeMonths = [11, 12, 1, 2, 3];
        }

        // Query approval and return/rejection counts grouped by month
        // Strictly counts only activities with 'approved' status (completed activities remain classified as completed)
        $trendStmt = $db->prepare("
            SELECT
                MONTH(a.event_date) as m,
                SUM(a.status = 'approved') as approved_count,
                SUM(a.status IN ('returned_for_revision', 'rejected')) as returned_count,
                COUNT(*) as total_count
            FROM activities a
            WHERE {$where}
            GROUP BY MONTH(a.event_date)
            ORDER BY m ASC
        ");
        $trendStmt->execute($params);
        $rows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

        $monthMap = [];
        foreach ($rows as $r) {
            $monthMap[(int)$r['m']] = [
                'approved' => (int)$r['approved_count'],
                'returned' => (int)$r['returned_count'],
                'total' => (int)$r['total_count']
            ];
        }

        $trends = [];
        $maxMonthCount = 1;
        $totalApproved = 0;
        $totalReturned = 0;

        foreach ($activeMonths as $m) {
            $approved = $monthMap[$m]['approved'] ?? 0;
            $returned = $monthMap[$m]['returned'] ?? 0;
            $total = $monthMap[$m]['total'] ?? 0;

            if ($total > $maxMonthCount) {
                $maxMonthCount = $total;
            }
            $totalApproved += $approved;
            $totalReturned += $returned;

            $trends[] = [
                'month_num' => $m,
                'month_name' => date('M', mktime(0, 0, 0, $m, 1)),
                'approved' => $approved,
                'returned' => $returned,
                'total' => $total
            ];
        }

        return [
            'months' => $trends,
            'max_count' => $maxMonthCount,
            'total_approved' => $totalApproved,
            'total_returned' => $totalReturned
        ];
    }
}

