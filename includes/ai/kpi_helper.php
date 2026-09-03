<?php
/**
 * kpi_helper.php
 *
 * Centralized calculation helper for numerical KPIs and performance status classifications.
 */

if (!function_exists('getKpiStatus')) {
    /**
     * Determines performance status based on achievement percentage.
     */
    function getKpiStatus(?float $achievementPct): string
    {
        if ($achievementPct === null) {
            return 'Insufficient Data';
        }
        
        // Exact centralized thresholds
        if ($achievementPct >= 110.0) {
            return 'Exceeded Target';
        } elseif ($achievementPct >= 90.0) {
            return 'Met Target';
        } elseif ($achievementPct >= 70.0) {
            return 'Near Target';
        } else {
            return 'Below Target';
        }
    }
}

if (!function_exists('getKpiStatusClass')) {
    /**
     * Returns the appropriate badge class for a given KPI status.
     */
    function getKpiStatusClass(string $status): string
    {
        switch ($status) {
            case 'Exceeded Target':
                return 'badge-success';
            case 'Met Target':
                return 'badge-info';
            case 'Near Target':
                return 'badge-warning';
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
        $targetAttendance = !empty($postEvent['target_attendance']) ? (int)$postEvent['target_attendance'] : (!empty($activity['target_participants']) ? (int)$activity['target_participants'] : 0);
        $actualAttendance = isset($postEvent['actual_attendance']) ? (int)$postEvent['actual_attendance'] : null;
        
        $attPct = null;
        if ($targetAttendance > 0 && $actualAttendance !== null) {
            $attPct = round(($actualAttendance / $targetAttendance) * 100, 1);
            $achievementScores[] = $attPct;
        }
        $attStatus = getKpiStatus($attPct);

        $kpis['attendance'] = [
            'indicator' => 'Participant Attendance',
            'target' => $targetAttendance > 0 ? (string)$targetAttendance : '—',
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
        $kpis['criteria'] = [];
        foreach ($criteriaRatings as $cr) {
            $critName = trim($cr['criteria']);
            $targetRating = 3.0; // Benchmark target out of 4.0 stars
            $actualRating = (float)$cr['avg_rating'];

            $critPct = round(($actualRating / $targetRating) * 100, 1);
            $achievementScores[] = $critPct;
            $critStatus = getKpiStatus($critPct);

            $kpis['criteria'][] = [
                'indicator' => 'Survey Metric: ' . $critName,
                'criteria_name' => $critName,
                'target' => number_format($targetRating, 1) . ' / 4.0',
                'actual' => number_format($actualRating, 1) . ' / 4.0',
                'achievement' => $critPct . '%',
                'achievement_val' => $critPct,
                'status' => $critStatus,
                'response_count' => (int)$cr['response_count']
            ];
        }

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
