<?php
/**
 * Post-Activity Compliance Calculation Helper
 *
 * Evaluates whether an approved activity was implemented as approved by comparing
 * available stored proposal/activity records against post-event execution records across 8 fields:
 * 1. Activity title
 * 2. Event date
 * 3. Venue
 * 4. Objectives
 * 5. Program/sequence where available
 * 6. Assigned people/manpower where available
 * 7. KPI/evaluation criteria where available
 * 8. Post-event documentation presence
 *
 * Rules:
 * - If a comparison cannot be made because the required record is unavailable, mark that item
 *   as "Not Verifiable" rather than passing or failing it.
 * - Compliance percentage is calculated based ONLY on verifiable checks.
 * - Overall status: Compliant / Partially Compliant / Not Compliant / Not Verifiable.
 * - Pure read-only helper: does NOT mutate database records.
 */

if (!function_exists('calculatePostActivityCompliance')) {

    /**
     * Calculates the post-activity compliance summary for a given activity.
     *
     * @param int        $activityId          The activity ID.
     * @param array|null $postEventOverrides  Optional overrides or simulated post-event data.
     * @param PDO|null   $db                  Optional PDO instance (defaults to getDB()).
     * @return array Structured compliance calculation result.
     */
    function calculatePostActivityCompliance(int $activityId, ?array $postEventOverrides = null, ?PDO $db = null): array
    {
        if ($db === null) {
            if (function_exists('getDB')) {
                $db = getDB();
            } else {
                throw new RuntimeException("Database connection helper getDB() is not available.");
            }
        }

        // 1. Fetch activity proposal record
        $actStmt = $db->prepare("
            SELECT id, faculty_id, title, event_date, venue, general_objectives, specific_objectives,
                   kpi_targets, evaluation_questions, status
            FROM activities
            WHERE id = ?
            LIMIT 1
        ");
        $actStmt->execute([$activityId]);
        $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            return [
                'activity_id'           => $activityId,
                'overall_status'        => 'Not Verifiable',
                'compliance_percentage' => null,
                'total_checks'          => 8,
                'verifiable_count'      => 0,
                'compliant_count'       => 0,
                'non_compliant_count'   => 0,
                'not_verifiable_count'  => 8,
                'checks'                => [],
                'error'                 => "Activity with ID {$activityId} not found."
            ];
        }

        // 2. Fetch stored post_event record
        $peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ? LIMIT 1");
        $peStmt->execute([$activityId]);
        $postEvent = $peStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $hasPostEventSubmission = ($postEvent !== null) || (!empty($postEventOverrides));

        // 3. Fetch related stored records
        // Program sequence
        $progStmt = $db->prepare("SELECT id, time_slot, segment, description, person_ic, sort_order FROM program_sequence WHERE activity_id = ? ORDER BY sort_order ASC, id ASC");
        $progStmt->execute([$activityId]);
        $programSequence = $progStmt->fetchAll(PDO::FETCH_ASSOC);

        // Manpower
        $mpStmt = $db->prepare("SELECT id, role, assigned_person, type FROM manpower WHERE activity_id = ?");
        $mpStmt->execute([$activityId]);
        $manpower = $mpStmt->fetchAll(PDO::FETCH_ASSOC);

        // Faculty tasks
        $ftStmt = $db->prepare("SELECT id, faculty_name, assigned_task, task_title, committee, status, completion_pct FROM faculty_tasks WHERE activity_id = ?");
        $ftStmt->execute([$activityId]);
        $facultyTasks = $ftStmt->fetchAll(PDO::FETCH_ASSOC);

        // KPI evaluations
        $kpiStmt = $db->prepare("SELECT id, criteria, rating, comments, evaluator_name, indicator, target_metric, evaluation_method FROM kpi_evaluations WHERE activity_id = ?");
        $kpiStmt->execute([$activityId]);
        $kpiEvaluations = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

        // Uploaded post-event documents
        $docStmt = $db->prepare("SELECT id, doc_type, file_name, file_path, uploaded_at FROM documents WHERE activity_id = ? AND doc_type = 'post_event'");
        $docStmt->execute([$activityId]);
        $postEventDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        $checks = [];

        // -------------------------------------------------------------
        // Check 1: Activity title
        // -------------------------------------------------------------
        $approvedTitle = !empty($activity['title']) ? trim((string)$activity['title']) : null;
        $actualTitleRaw = $postEventOverrides['actual_title']
            ?? $postEventOverrides['title']
            ?? $postEvent['actual_title']
            ?? $postEvent['title']
            ?? null;
        $actualTitle = ($actualTitleRaw !== null && trim((string)$actualTitleRaw) !== '')
            ? trim((string)$actualTitleRaw)
            : null;

        if ($approvedTitle === null || $actualTitle === null) {
            $checks[] = [
                'field'          => 'Activity title',
                'approved_value' => $approvedTitle,
                'actual_value'   => $actualTitle,
                'result'         => 'Not Verifiable',
                'notes'          => ($actualTitle === null) ? 'Actual title not recorded in post-event report' : 'Approved title missing in proposal'
            ];
        } else {
            $isMatch = (strtolower($approvedTitle) === strtolower($actualTitle));
            $checks[] = [
                'field'          => 'Activity title',
                'approved_value' => $approvedTitle,
                'actual_value'   => $actualTitle,
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Title matches approved proposal' : 'Actual title differs from approved proposal'
            ];
        }

        // -------------------------------------------------------------
        // Check 2: Event date
        // -------------------------------------------------------------
        $approvedDate = !empty($activity['event_date']) ? date('Y-m-d', strtotime($activity['event_date'])) : null;
        $actualDateRaw = $postEventOverrides['actual_event_date']
            ?? $postEventOverrides['event_date']
            ?? $postEvent['actual_event_date']
            ?? $postEvent['event_date']
            ?? null;
        $actualDate = ($actualDateRaw !== null && trim((string)$actualDateRaw) !== '')
            ? date('Y-m-d', strtotime($actualDateRaw))
            : null;

        if ($approvedDate === null || $actualDate === null) {
            $checks[] = [
                'field'          => 'Event date',
                'approved_value' => $approvedDate,
                'actual_value'   => $actualDate,
                'result'         => 'Not Verifiable',
                'notes'          => ($actualDate === null) ? 'Actual event date not recorded in post-event report' : 'Approved event date missing in proposal'
            ];
        } else {
            $isMatch = ($approvedDate === $actualDate);
            $checks[] = [
                'field'          => 'Event date',
                'approved_value' => $approvedDate,
                'actual_value'   => $actualDate,
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Event conducted on approved date' : 'Actual date differs from approved date'
            ];
        }

        // -------------------------------------------------------------
        // Check 3: Venue
        // -------------------------------------------------------------
        $approvedVenue = !empty($activity['venue']) ? trim((string)$activity['venue']) : null;
        $actualVenueRaw = $postEventOverrides['actual_venue']
            ?? $postEventOverrides['venue']
            ?? $postEvent['actual_venue']
            ?? $postEvent['venue']
            ?? null;
        $actualVenue = ($actualVenueRaw !== null && trim((string)$actualVenueRaw) !== '')
            ? trim((string)$actualVenueRaw)
            : null;

        if ($approvedVenue === null || $actualVenue === null) {
            $checks[] = [
                'field'          => 'Venue',
                'approved_value' => $approvedVenue,
                'actual_value'   => $actualVenue,
                'result'         => 'Not Verifiable',
                'notes'          => ($actualVenue === null) ? 'Actual venue not recorded in post-event report' : 'Approved venue missing in proposal'
            ];
        } else {
            $isMatch = (strtolower($approvedVenue) === strtolower($actualVenue));
            $checks[] = [
                'field'          => 'Venue',
                'approved_value' => $approvedVenue,
                'actual_value'   => $actualVenue,
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Venue matches approved location' : 'Actual venue differs from approved location'
            ];
        }

        // -------------------------------------------------------------
        // Check 4: Objectives
        // -------------------------------------------------------------
        $genObj = !empty($activity['general_objectives']) ? trim((string)$activity['general_objectives']) : '';
        $specObj = !empty($activity['specific_objectives']) ? trim((string)$activity['specific_objectives']) : '';
        $approvedObjectives = trim($genObj . ($genObj && $specObj ? "\n" : "") . $specObj);
        $approvedObjectives = ($approvedObjectives !== '') ? $approvedObjectives : null;

        $actualObjRaw = $postEventOverrides['actual_objectives']
            ?? $postEventOverrides['objectives']
            ?? $postEvent['actual_objectives']
            ?? $postEvent['objectives']
            ?? null;

        if ($approvedObjectives === null) {
            $checks[] = [
                'field'          => 'Objectives',
                'approved_value' => null,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'No objectives defined in proposal'
            ];
        } elseif ($actualObjRaw !== null) {
            if (is_bool($actualObjRaw)) {
                $isMatch = ($actualObjRaw === true);
                $checks[] = [
                    'field'          => 'Objectives',
                    'approved_value' => $approvedObjectives,
                    'actual_value'   => $isMatch ? 'Objectives achieved' : 'Objectives not achieved',
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Objectives confirmed achieved' : 'Objectives reported as not achieved'
                ];
            } else {
                $cleanStr = strtolower(trim((string)$actualObjRaw));
                if (in_array($cleanStr, ['met', 'achieved', 'completed', 'compliant', 'yes', 'true', 'objectives achieved'])) {
                    $checks[] = [
                        'field'          => 'Objectives',
                        'approved_value' => $approvedObjectives,
                        'actual_value'   => (string)$actualObjRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Objectives achieved as planned'
                    ];
                } elseif (in_array($cleanStr, ['unmet', 'not met', 'incomplete', 'non-compliant', 'no', 'false', 'failed', 'not achieved'])) {
                    $checks[] = [
                        'field'          => 'Objectives',
                        'approved_value' => $approvedObjectives,
                        'actual_value'   => (string)$actualObjRaw,
                        'result'         => 'Not Compliant',
                        'notes'          => 'Objectives reported as unmet or incomplete'
                    ];
                } elseif ($cleanStr === strtolower($approvedObjectives)) {
                    $checks[] = [
                        'field'          => 'Objectives',
                        'approved_value' => $approvedObjectives,
                        'actual_value'   => (string)$actualObjRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Implemented objectives match approved proposal'
                    ];
                } else {
                    $checks[] = [
                        'field'          => 'Objectives',
                        'approved_value' => $approvedObjectives,
                        'actual_value'   => (string)$actualObjRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Objectives reported in post-event report'
                    ];
                }
            }
        } elseif (!empty($kpiEvaluations)) {
            // Check if evaluations contain objective-specific feedback
            $objKpis = array_filter($kpiEvaluations, function ($k) {
                return stripos($k['criteria'] ?? '', 'objective') !== false;
            });
            if (!empty($objKpis)) {
                $ratings = array_column($objKpis, 'rating');
                $avg = count($ratings) > 0 ? (array_sum($ratings) / count($ratings)) : 0;
                $isMatch = ($avg >= 3.0);
                $checks[] = [
                    'field'          => 'Objectives',
                    'approved_value' => $approvedObjectives,
                    'actual_value'   => 'Participant evaluation rating: ' . number_format($avg, 1) . '/5',
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Objectives achieved according to participant evaluations' : 'Objective rating fell below passing benchmark (3.0/5)'
                ];
            } else {
                $checks[] = [
                    'field'          => 'Objectives',
                    'approved_value' => $approvedObjectives,
                    'actual_value'   => null,
                    'result'         => 'Not Verifiable',
                    'notes'          => 'Actual objective attainment not recorded in post-event data'
                ];
            }
        } else {
            $checks[] = [
                'field'          => 'Objectives',
                'approved_value' => $approvedObjectives,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'Actual objective attainment not recorded in post-event data'
            ];
        }

        // -------------------------------------------------------------
        // Check 5: Program/sequence where available
        // -------------------------------------------------------------
        $approvedProgCount = count($programSequence);
        $approvedProgDisplay = ($approvedProgCount > 0) ? "{$approvedProgCount} scheduled segment(s)" : null;

        $actualProgRaw = $postEventOverrides['actual_program']
            ?? $postEventOverrides['actual_program_sequence']
            ?? $postEventOverrides['program_sequence']
            ?? $postEvent['actual_program']
            ?? null;

        if ($approvedProgDisplay === null) {
            $checks[] = [
                'field'          => 'Program/sequence',
                'approved_value' => 'No program sequence defined in proposal',
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'No program sequence defined in approved proposal'
            ];
        } elseif ($actualProgRaw !== null) {
            if (is_bool($actualProgRaw)) {
                $isMatch = ($actualProgRaw === true);
                $checks[] = [
                    'field'          => 'Program/sequence',
                    'approved_value' => $approvedProgDisplay,
                    'actual_value'   => $isMatch ? 'Executed as scheduled' : 'Not executed as scheduled',
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Program sequence executed as scheduled' : 'Program sequence diverged from approved plan'
                ];
            } elseif (is_array($actualProgRaw)) {
                $actualCnt = count($actualProgRaw);
                $isMatch = ($actualCnt === $approvedProgCount);
                $checks[] = [
                    'field'          => 'Program/sequence',
                    'approved_value' => $approvedProgDisplay,
                    'actual_value'   => "{$actualCnt} segment(s) executed",
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'All scheduled segments executed' : "Executed segment count ({$actualCnt}) differs from approved ({$approvedProgCount})"
                ];
            } else {
                $cleanStr = strtolower(trim((string)$actualProgRaw));
                if (in_array($cleanStr, ['conducted as scheduled', 'followed', 'completed', 'compliant', 'yes', 'as scheduled', 'executed as approved'])) {
                    $checks[] = [
                        'field'          => 'Program/sequence',
                        'approved_value' => $approvedProgDisplay,
                        'actual_value'   => (string)$actualProgRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Program sequence executed according to approved plan'
                    ];
                } elseif (in_array($cleanStr, ['modified', 'changed', 'delayed', 'not followed', 'incomplete', 'non-compliant', 'no', 'cancelled'])) {
                    $checks[] = [
                        'field'          => 'Program/sequence',
                        'approved_value' => $approvedProgDisplay,
                        'actual_value'   => (string)$actualProgRaw,
                        'result'         => 'Not Compliant',
                        'notes'          => 'Program sequence not implemented as approved'
                    ];
                } else {
                    $checks[] = [
                        'field'          => 'Program/sequence',
                        'approved_value' => $approvedProgDisplay,
                        'actual_value'   => (string)$actualProgRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Program sequence conducted'
                    ];
                }
            }
        } else {
            $checks[] = [
                'field'          => 'Program/sequence',
                'approved_value' => $approvedProgDisplay,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'Actual program execution sequence not recorded in post-event data'
            ];
        }

        // -------------------------------------------------------------
        // Check 6: Assigned people/manpower where available
        // -------------------------------------------------------------
        $mpCount = count($manpower);
        $ftCount = count($facultyTasks);
        $approvedManpowerParts = [];
        if ($mpCount > 0) $approvedManpowerParts[] = "{$mpCount} manpower role(s)";
        if ($ftCount > 0) $approvedManpowerParts[] = "{$ftCount} assigned task(s)";
        $approvedManpowerDisplay = !empty($approvedManpowerParts) ? implode(', ', $approvedManpowerParts) : null;

        $actualMpRaw = $postEventOverrides['actual_manpower']
            ?? $postEventOverrides['actual_assigned_people']
            ?? $postEventOverrides['manpower']
            ?? $postEvent['actual_manpower']
            ?? null;

        if ($approvedManpowerDisplay === null) {
            $checks[] = [
                'field'          => 'Assigned people/manpower',
                'approved_value' => 'No assigned people/manpower defined in proposal',
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'No manpower or task assignments defined in approved proposal'
            ];
        } elseif ($actualMpRaw !== null) {
            if (is_bool($actualMpRaw)) {
                $isMatch = ($actualMpRaw === true);
                $checks[] = [
                    'field'          => 'Assigned people/manpower',
                    'approved_value' => $approvedManpowerDisplay,
                    'actual_value'   => $isMatch ? 'All assigned personnel fulfilled roles' : 'Assigned personnel did not fulfill roles',
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Manpower fulfilled as approved' : 'Manpower duties not fulfilled as approved'
                ];
            } elseif (is_array($actualMpRaw)) {
                $actualCnt = count($actualMpRaw);
                $reqCnt = ($mpCount ?: 1);
                $isMatch = ($actualCnt >= $reqCnt);
                $checks[] = [
                    'field'          => 'Assigned people/manpower',
                    'approved_value' => $approvedManpowerDisplay,
                    'actual_value'   => "{$actualCnt} personnel participated",
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Assigned manpower roles staffed and present' : 'Participating manpower count below approved staffing'
                ];
            } else {
                $cleanStr = strtolower(trim((string)$actualMpRaw));
                if (in_array($cleanStr, ['fulfilled', 'present', 'compliant', 'yes', 'all present', 'assigned roles fulfilled', 'completed'])) {
                    $checks[] = [
                        'field'          => 'Assigned people/manpower',
                        'approved_value' => $approvedManpowerDisplay,
                        'actual_value'   => (string)$actualMpRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Assigned personnel fulfilled duties as approved'
                    ];
                } elseif (in_array($cleanStr, ['unfulfilled', 'absent', 'non-compliant', 'no', 'roles missing', 'changed', 'incomplete'])) {
                    $checks[] = [
                        'field'          => 'Assigned people/manpower',
                        'approved_value' => $approvedManpowerDisplay,
                        'actual_value'   => (string)$actualMpRaw,
                        'result'         => 'Not Compliant',
                        'notes'          => 'Assigned personnel did not fulfill duties as approved'
                    ];
                } else {
                    $checks[] = [
                        'field'          => 'Assigned people/manpower',
                        'approved_value' => $approvedManpowerDisplay,
                        'actual_value'   => (string)$actualMpRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'Manpower participation recorded'
                    ];
                }
            }
        } elseif ($hasPostEventSubmission && $ftCount > 0) {
            // Check completed faculty tasks if post-event submission exists
            $completedTasks = array_filter($facultyTasks, function ($t) {
                return (int)($t['completion_pct'] ?? 0) === 100 || strtolower($t['status'] ?? '') === 'completed';
            });
            $cCount = count($completedTasks);
            $completionPct = round(($cCount / $ftCount) * 100);
            $isMatch = ($completionPct >= 80);
            $checks[] = [
                'field'          => 'Assigned people/manpower',
                'approved_value' => $approvedManpowerDisplay,
                'actual_value'   => "{$cCount}/{$ftCount} tasks completed ({$completionPct}%)",
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Assigned committee members completed responsibilities' : "Task completion below satisfactory threshold ({$completionPct}%)"
            ];
        } else {
            $checks[] = [
                'field'          => 'Assigned people/manpower',
                'approved_value' => $approvedManpowerDisplay,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'Actual manpower fulfillment not recorded in post-event data'
            ];
        }

        // -------------------------------------------------------------
        // Check 7: KPI/evaluation criteria where available
        // -------------------------------------------------------------
        $evalQuestions = !empty($activity['evaluation_questions'])
            ? json_decode($activity['evaluation_questions'], true)
            : null;

        $approvedKpiDisplay = null;
        if (!empty($evalQuestions) && is_array($evalQuestions)) {
            $approvedKpiDisplay = count($evalQuestions) . " evaluation question(s)/criteria";
        } elseif (!empty($activity['kpi_targets'])) {
            $approvedKpiDisplay = "KPI targets: " . trim((string)$activity['kpi_targets']);
        }

        $actualKpiRaw = $postEventOverrides['actual_kpis']
            ?? $postEventOverrides['kpi_evaluations']
            ?? $postEventOverrides['kpi_criteria']
            ?? null;

        if ($approvedKpiDisplay === null) {
            $checks[] = [
                'field'          => 'KPI/evaluation criteria',
                'approved_value' => 'No KPI/evaluation criteria defined in proposal',
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'No KPI criteria defined in approved proposal'
            ];
        } elseif ($actualKpiRaw !== null) {
            if (is_bool($actualKpiRaw)) {
                $isMatch = ($actualKpiRaw === true);
                $checks[] = [
                    'field'          => 'KPI/evaluation criteria',
                    'approved_value' => $approvedKpiDisplay,
                    'actual_value'   => $isMatch ? 'KPI criteria evaluated and met' : 'KPI criteria not met',
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Evaluation criteria evaluated with satisfactory outcomes' : 'KPI criteria failed or not met'
                ];
            } elseif (is_numeric($actualKpiRaw)) {
                $score = (float)$actualKpiRaw;
                $isMatch = ($score >= 3.0);
                $checks[] = [
                    'field'          => 'KPI/evaluation criteria',
                    'approved_value' => $approvedKpiDisplay,
                    'actual_value'   => "Average rating: " . number_format($score, 1) . "/5",
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Evaluation rating met satisfactory benchmark (>= 3.0/5)' : 'Evaluation rating fell below benchmark (< 3.0/5)'
                ];
            } elseif (is_array($actualKpiRaw)) {
                $actualCnt = count($actualKpiRaw);
                $isMatch = ($actualCnt > 0);
                $checks[] = [
                    'field'          => 'KPI/evaluation criteria',
                    'approved_value' => $approvedKpiDisplay,
                    'actual_value'   => "{$actualCnt} criteria evaluated",
                    'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                    'notes'          => $isMatch ? 'Evaluation criteria evaluated' : 'No evaluation criteria evaluated'
                ];
            } else {
                $cleanStr = strtolower(trim((string)$actualKpiRaw));
                if (in_array($cleanStr, ['met', 'compliant', 'achieved', 'yes', 'satisfactory', 'passed'])) {
                    $checks[] = [
                        'field'          => 'KPI/evaluation criteria',
                        'approved_value' => $approvedKpiDisplay,
                        'actual_value'   => (string)$actualKpiRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'KPI criteria met'
                    ];
                } elseif (in_array($cleanStr, ['unmet', 'not met', 'non-compliant', 'no', 'unsatisfactory', 'failed'])) {
                    $checks[] = [
                        'field'          => 'KPI/evaluation criteria',
                        'approved_value' => $approvedKpiDisplay,
                        'actual_value'   => (string)$actualKpiRaw,
                        'result'         => 'Not Compliant',
                        'notes'          => 'KPI criteria not met'
                    ];
                } else {
                    $checks[] = [
                        'field'          => 'KPI/evaluation criteria',
                        'approved_value' => $approvedKpiDisplay,
                        'actual_value'   => (string)$actualKpiRaw,
                        'result'         => 'Compliant',
                        'notes'          => 'KPI criteria evaluated'
                    ];
                }
            }
        } elseif (!empty($kpiEvaluations)) {
            $ratings = array_column($kpiEvaluations, 'rating');
            $avg = count($ratings) > 0 ? (array_sum($ratings) / count($ratings)) : 0;
            $distinctCriteria = array_unique(array_filter(array_column($kpiEvaluations, 'criteria')));
            $critCount = count($distinctCriteria);
            $isMatch = ($avg >= 3.0);
            $checks[] = [
                'field'          => 'KPI/evaluation criteria',
                'approved_value' => $approvedKpiDisplay,
                'actual_value'   => "{$critCount} criteria evaluated (Avg rating: " . number_format($avg, 1) . "/5)",
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Participant evaluation ratings met satisfactory threshold (>= 3.0/5)' : 'Participant evaluation ratings fell below threshold (< 3.0/5)'
            ];
        } else {
            $checks[] = [
                'field'          => 'KPI/evaluation criteria',
                'approved_value' => $approvedKpiDisplay,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'No post-event KPI evaluations recorded'
            ];
        }

        // -------------------------------------------------------------
        // Check 8: Post-event documentation presence
        // -------------------------------------------------------------
        $docApprovedDisplay = 'Required (Post-event report and supporting documentation)';

        if (isset($postEventOverrides['documents_present'])) {
            $isMatch = ($postEventOverrides['documents_present'] === true);
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => $isMatch ? 'Present (Documentation uploaded)' : 'Missing (0 documents)',
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Post-event documentation uploaded' : 'Required post-event documentation is missing'
            ];
        } elseif (isset($postEventOverrides['has_documentation'])) {
            $isMatch = ($postEventOverrides['has_documentation'] === true);
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => $isMatch ? 'Present (Documentation uploaded)' : 'Missing (0 documents)',
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Post-event documentation uploaded' : 'Required post-event documentation is missing'
            ];
        } elseif (isset($postEventOverrides['documents']) && is_array($postEventOverrides['documents'])) {
            $cnt = count($postEventOverrides['documents']);
            $isMatch = ($cnt > 0);
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => $isMatch ? "Present ({$cnt} document(s))" : 'Missing (0 documents)',
                'result'         => $isMatch ? 'Compliant' : 'Not Compliant',
                'notes'          => $isMatch ? 'Post-event documentation uploaded' : 'Required post-event documentation is missing'
            ];
        } elseif (count($postEventDocs) > 0) {
            $cnt = count($postEventDocs);
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => "Present ({$cnt} document(s) uploaded)",
                'result'         => 'Compliant',
                'notes'          => 'Post-event supporting documents are uploaded'
            ];
        } elseif ($postEvent !== null) {
            // Post-event record exists, but 0 documents were uploaded
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => 'Missing (0 documents uploaded)',
                'result'         => 'Not Compliant',
                'notes'          => 'Post-event report submitted without supporting documentation'
            ];
        } else {
            // No post-event record or documentation exists at all
            $checks[] = [
                'field'          => 'Post-event documentation presence',
                'approved_value' => $docApprovedDisplay,
                'actual_value'   => null,
                'result'         => 'Not Verifiable',
                'notes'          => 'Post-event report and documentation have not been submitted'
            ];
        }

        // -------------------------------------------------------------
        // Calculate Totals, Compliance Percentage, and Overall Status
        // -------------------------------------------------------------
        $verifiableChecks    = array_values(array_filter($checks, fn($c) => $c['result'] !== 'Not Verifiable'));
        $compliantChecks     = array_values(array_filter($checks, fn($c) => $c['result'] === 'Compliant'));
        $nonCompliantChecks  = array_values(array_filter($checks, fn($c) => $c['result'] === 'Not Compliant'));
        $notVerifiableChecks = array_values(array_filter($checks, fn($c) => $c['result'] === 'Not Verifiable'));

        $verifiableCount    = count($verifiableChecks);
        $compliantCount     = count($compliantChecks);
        $nonCompliantCount  = count($nonCompliantChecks);
        $notVerifiableCount = count($notVerifiableChecks);
        $totalChecks        = count($checks);

        if ($verifiableCount === 0) {
            $compliancePercentage = null;
            $overallStatus = 'Not Verifiable';
        } else {
            $compliancePercentage = round(($compliantCount / $verifiableCount) * 100, 1);
            if ($compliantCount === $verifiableCount) {
                $overallStatus = 'Compliant';
            } elseif ($compliantCount > 0 && $nonCompliantCount > 0) {
                $overallStatus = 'Partially Compliant';
            } elseif ($compliantCount === 0) {
                $overallStatus = 'Not Compliant';
            } else {
                $overallStatus = 'Partially Compliant';
            }
        }

        return [
            'activity_id'           => $activityId,
            'overall_status'        => $overallStatus,
            'compliance_percentage' => $compliancePercentage,
            'total_checks'          => $totalChecks,
            'verifiable_count'      => $verifiableCount,
            'compliant_count'       => $compliantCount,
            'non_compliant_count'   => $nonCompliantCount,
            'not_verifiable_count'  => $notVerifiableCount,
            'checks'                => $checks
        ];
    }
}
