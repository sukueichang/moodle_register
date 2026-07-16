<?php
/**
 * Validates submitted interactive calendar plans for dedicated class reservations.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class reservation_plan_validator {

    /**
     * @return int[]
     */
    public static function get_reservation_course_ids(\stdClass $reservation): array {
        $ids = [];
        if (!empty($reservation->courseids_json)) {
            $decoded = json_decode((string) $reservation->courseids_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid) {
                    $cid = (int) $cid;
                    if ($cid > 0) {
                        $ids[] = $cid;
                    }
                }
            }
        }
        if (empty($ids) && (int) $reservation->courseid > 0) {
            $ids[] = (int) $reservation->courseid;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @return array{ok:bool,error:string,blocks?:array<int,array<string,mixed>>}
     */
    public static function validate_submitted_plan(\stdClass $reservation, array $blocks): array {
        global $DB;

        if ((int) $reservation->status !== 0) {
            return ['ok' => false, 'error' => 'reservation_calendar_error_not_pending'];
        }

        $courseids = self::get_reservation_course_ids($reservation);
        if (empty($courseids)) {
            return ['ok' => false, 'error' => 'reservation_calendar_error_no_courses'];
        }

        $delivery = (string) $reservation->delivery_mode;
        $onlineendhhmm = session_manager::get_online_day_end_hhmm();
        $classroommap = enabled_course_manager::get_classroom_map();
        $preferredclassroomid = (int)($reservation->preferred_classroomid ?? 0);
        $fallbackroomid = 0;
        if ($delivery === session_manager::DELIVERY_ONSITE) {
            $roomrows = $DB->get_records('local_tm_classroom', [], 'id ASC', 'id');
            if (!empty($roomrows)) {
                reset($roomrows);
                $first = current($roomrows);
                if (!empty($first) && !empty($first->id)) {
                    $fallbackroomid = (int)$first->id;
                }
            }
        }

        $blockcourseids = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                return ['ok' => false, 'error' => 'reservation_calendar_error_invalid_block'];
            }
            $blockcourseids[] = (int) ($b['courseId'] ?? $b['courseid'] ?? 0);
        }
        $sortedexpected = $courseids;
        sort($sortedexpected);
        $uniqueblockcourses = array_values(array_unique(array_filter(array_map('intval', $blockcourseids), function($v) {
            return $v > 0;
        })));
        sort($uniqueblockcourses);
        if ($uniqueblockcourses !== $sortedexpected) {
            return ['ok' => false, 'error' => 'reservation_calendar_error_course_mismatch'];
        }

        $classroomoccupancy = [];
        if ($delivery === session_manager::DELIVERY_ONSITE) {
            $existing = $DB->get_records_sql(
                "SELECT id, classroomid, starttime, endtime
                   FROM {local_tm_course_sessions}
                  WHERE classroomid IS NOT NULL AND classroomid > 0"
            );
            foreach ($existing as $row) {
                $rid = (int) $row->classroomid;
                if (empty($classroomoccupancy[$rid])) {
                    $classroomoccupancy[$rid] = [];
                }
                $classroomoccupancy[$rid][] = [
                    'start' => (int) $row->starttime,
                    'end' => (int) $row->endtime,
                ];
            }
        }

        // A course may arrive as several day-segments (onsite multi-day). Anchor on the
        // EARLIEST segment start so the authoritative rebuild begins on the intended day 1.
        $blocksbycourse = [];
        foreach ($blocks as $b) {
            $bcid = (int) ($b['courseId'] ?? $b['courseid'] ?? 0);
            if ($bcid <= 0) {
                continue;
            }
            if (!isset($blocksbycourse[$bcid])
                || (int) ($b['start'] ?? 0) < (int) ($blocksbycourse[$bcid]['start'] ?? PHP_INT_MAX)) {
                $blocksbycourse[$bcid] = $b;
            }
        }
        $blocksordered = [];
        foreach ($courseids as $ocid) {
            if (!empty($blocksbycourse[$ocid])) {
                $blocksordered[] = $blocksbycourse[$ocid];
                unset($blocksbycourse[$ocid]);
            }
        }
        foreach ($blocksbycourse as $leftover) {
            $blocksordered[] = $leftover;
        }

        $normalized = [];
        $reservationintervals = [];
        foreach ($blocksordered as $b) {
            $cid = (int) ($b['courseId'] ?? $b['courseid'] ?? 0);
            $room = (int) ($b['classroomId'] ?? $b['classroomid'] ?? 0);
            $start = (int) ($b['start'] ?? 0);
            $end = (int) ($b['end'] ?? 0);
            if ($cid <= 0 || $start <= 0 || $end <= $start) {
                return ['ok' => false, 'error' => 'reservation_calendar_error_invalid_block'];
            }
            if (session_manager::is_weekend_timestamp($start)
                || session_manager::interval_spans_weekend($start, $end)) {
                return ['ok' => false, 'error' => 'reservation_calendar_error_weekend'];
            }

            if ($delivery === session_manager::DELIVERY_ONLINE) {
                $dayendlimit = strtotime(date('Y-m-d', $start) . ' ' . $onlineendhhmm . ':00');
                if ($end > $dayendlimit) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_online_day_end_limit'];
                }
            }

            if ($delivery === session_manager::DELIVERY_ONLINE) {
                $expected = enabled_course_manager::get_online_classroom_id($cid);
                if ($expected <= 0) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_online_classroom_unconfigured'];
                }
                if ($room <= 0) {
                    $room = $expected;
                } else if ($room !== $expected) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_online_classroom'];
                }
            } else {
                $allowed = $classroommap[$cid] ?? [];
                if ($room <= 0) {
                    $room = enabled_course_manager::resolve_plan_classroom(
                        $cid,
                        $delivery,
                        $preferredclassroomid,
                        $fallbackroomid
                    );
                }
                if ($room <= 0) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_classroom'];
                }
                if (!empty($allowed) && !in_array($room, $allowed, true)) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_classroom'];
                }
                if (empty($allowed) && !$DB->record_exists('local_tm_classroom', ['id' => $room])) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_classroom'];
                }
            }

            if ($delivery === session_manager::DELIVERY_ONSITE && $cid > 0) {
                $roomocc = ($room > 0) ? ($classroomoccupancy[$room] ?? []) : [];
                $cursor = $start;
                $guard = 0;
                while ($guard < 500) {
                    $built = session_manager::build_reservation_onsite_block($cid, $cursor, $roomocc);
                    $start = (int) $built['starttime'];
                    $end = (int) $built['endtime'];
                    $roomconflict = ($room > 0)
                        && self::intervals_overlap($classroomoccupancy[$room] ?? [], $start, $end);
                    $resvconflict = self::intervals_overlap($reservationintervals, $start, $end);
                    if (!$roomconflict && !$resvconflict) {
                        break;
                    }
                    $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $start)));
                    $cursor = session_manager::next_weekday_timestamp(
                        (int) strtotime(date('Y-m-d', $nextday) . ' 09:30:00'),
                        true
                    );
                    $guard++;
                }
                if ($guard >= 500) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_invalid_block'];
                }
                if (session_manager::interval_spans_weekend($start, $end)) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_weekend'];
                }
                if ($room > 0) {
                    if (empty($classroomoccupancy[$room])) {
                        $classroomoccupancy[$room] = [];
                    }
                    $classroomoccupancy[$room][] = ['start' => $start, 'end' => $end];
                }
                $reservationintervals[] = ['start' => $start, 'end' => $end];
            }

            $normalized[] = [
                'courseId' => $cid,
                'classroomId' => $room,
                'start' => $start,
                'end' => $end,
                'title' => (string) ($b['title'] ?? ''),
            ];
        }

        usort($normalized, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $resvintervals = [];
        foreach ($normalized as $n) {
            if (self::intervals_overlap($resvintervals, $n['start'], $n['end'])) {
                return ['ok' => false, 'error' => 'reservation_calendar_error_overlap_internal'];
            }
            $resvintervals[] = ['start' => $n['start'], 'end' => $n['end']];
        }

        list($pinsql, $pinparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $priorityrows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $pinsql", $pinparams);
        $prioritycourseid = 0;
        foreach ($priorityrows as $prow) {
            if (self::is_priority_course_name((string)$prow->fullname)) {
                $prioritycourseid = (int)$prow->id;
                break;
            }
        }
        if ($prioritycourseid > 0) {
            $firstcourseid = (int)($normalized[0]['courseId'] ?? 0);
            if ($firstcourseid !== $prioritycourseid) {
                return ['ok' => false, 'error' => 'reservation_calendar_error_priority_course_first'];
            }
        }

        $byroom = [];
        foreach ($normalized as $n) {
            if ($n['classroomId'] <= 0) {
                continue;
            }
            $rid = $n['classroomId'];
            if (empty($byroom[$rid])) {
                $byroom[$rid] = [];
            }
            $byroom[$rid][] = $n;
        }

        foreach ($byroom as $rid => $roomblocks) {
            usort($roomblocks, function($a, $b) {
                return $a['start'] <=> $b['start'];
            });
            // Only formal sessions count as "existing" room occupancy; same-application
            // blocks are checked separately (overlap_internal).
            $dbocc = [];
            $rows = $DB->get_records_sql(
                "SELECT id, starttime, endtime
                   FROM {local_tm_course_sessions}
                  WHERE classroomid = :rid AND classroomid > 0",
                ['rid' => $rid]
            );
            foreach ($rows as $row) {
                $dbocc[] = ['start' => (int) $row->starttime, 'end' => (int) $row->endtime];
            }
            foreach ($roomblocks as $n) {
                if (self::intervals_overlap($dbocc, $n['start'], $n['end'])) {
                    return ['ok' => false, 'error' => 'reservation_calendar_error_overlap_room'];
                }
            }
        }

        return ['ok' => true, 'error' => '', 'blocks' => $normalized];
    }

    /**
     * @param array<int,array{start:int,end:int}> $intervals
     */
    private static function intervals_overlap(array $intervals, int $start, int $end): bool {
        foreach ($intervals as $itv) {
            $s = (int) ($itv['start'] ?? 0);
            $e = (int) ($itv['end'] ?? 0);
            if ($s > 0 && $e > 0 && $s < $end && $e > $start) {
                return true;
            }
        }
        return false;
    }

    private static function is_priority_course_name(string $name): bool {
        $n = strtolower(trim($name));
        $n = str_replace(["\xE2\x80\x99", "\xE2\x80\x98", "'", ' '], '', $n);
        return strpos($n, 'aicobotbeginner') !== false;
    }
}
