<?php
/**
 * Cron profile scheduling: interval math, a minimal 5-field cron expression
 * evaluator, and the periodic cron_tick() that starts/advances due jobs.
 *
 * @package WarmPilot
 */

namespace YotaX\WarmPilot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

defined('ABSPATH') || exit;

// WarmPilot uses plugin-owned queue tables whose live state must not be cached.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
/**
 * Computes cron profile schedules and drives the periodic tick that starts
 * and advances due jobs.
 */
class Cron_Manager extends Job_Runner {
    /**
     * Recomputes next_run for every cron profile (e.g. after a version upgrade
     * changes how schedules are calculated); leaves invalid legacy custom
     * expressions untouched until the user edits them.
     */
    protected function realign_existing_schedules(): void {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, interval_key, cron_expression FROM {$this->schedules_table}") ?: [];
        foreach ($rows as $row) {
            try {
                $next_run = $this->aligned_next_run_mysql((string)$row->interval_key, null, $row->cron_expression ?: null);
                $wpdb->update($this->schedules_table, ['next_run'=>$next_run], ['id'=>(int)$row->id]);
            } catch (Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Invalid legacy custom expressions remain unchanged until edited.
            }
        }
    }
    /**
     * Filters "cron_schedules" to register WarmPilot's custom recurrence intervals.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Existing registered schedules.
     * @return array<string, array{interval: int, display: string}> Schedules with WarmPilot's intervals added.
     */
    public function cron_schedules(array $schedules): array {
        $schedules['warmpilot_minute'] = ['interval'=>60, 'display'=>'Every minute'];
        $schedules['five_minutes'] = ['interval'=>300, 'display'=>'Every 5 minutes'];
        $schedules['fifteen_minutes'] = ['interval'=>900, 'display'=>'Every 15 minutes'];
        $schedules['weekly'] = ['interval'=>604800, 'display'=>'Weekly'];
        return $schedules;
    }
    /**
     * Fetches every cron profile along with its currently-running job (if any).
     *
     * @return object[] Profile rows augmented with active_job_id/active_stop_requested.
     */
    protected function get_cron_profiles(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT s.*,
                j.id AS active_job_id,
                j.stop_requested AS active_stop_requested
             FROM {$this->schedules_table} s
             LEFT JOIN {$this->jobs_table} j
               ON j.id = (
                    SELECT j2.id
                    FROM {$this->jobs_table} j2
                    WHERE j2.profile_id = s.id AND j2.status = 'running'
                    ORDER BY j2.id DESC
                    LIMIT 1
               )
             ORDER BY s.id DESC"
        ) ?: [];
    }
    /**
     * Resolves an interval_key to its length in seconds.
     *
     * @param string $key One of the recurrence interval keys used by cron profiles.
     */
    protected function interval_seconds(string $key): int {
        return match ($key) {
            'warmpilot_minute' => 60,
            'five_minutes' => 300,
            'fifteen_minutes' => 900,
            'twicedaily' => 43200,
            'daily' => 86400,
            'weekly' => 604800,
            'custom_cron' => 60,
            default => 3600,
        };
    }
    /**
     * Converts a UTC MySQL datetime string to the site's local timezone for display.
     *
     * @param string|null $value UTC MySQL datetime, or null/empty for "not set".
     */
    protected function display_utc_mysql(?string $value): string {
        if (!$value) return '—';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return $value;
        }
    }
    /**
     * Builds the human-readable schedule label shown in the cron tasks table.
     *
     * @param object $profile Cron profile row (interval_key, cron_expression).
     */
    protected function schedule_label(object $profile): string {
        $labels = [
            'warmpilot_minute' => 'Every minute',
            'five_minutes' => 'Every 5 minutes',
            'fifteen_minutes' => 'Every 15 minutes',
            'hourly' => 'Hourly · at :00',
            'twicedaily' => 'Twice daily · 00:00 / 12:00',
            'daily' => 'Daily · 00:00',
            'weekly' => 'Weekly · Monday 00:00',
        ];
        if (($profile->interval_key ?? '') === 'custom_cron') {
            return 'Cron: ' . ($profile->cron_expression ?: '* * * * *');
        }
        return $labels[$profile->interval_key] ?? (string)$profile->interval_key;
    }
    /**
     * Computes the next aligned run time (UTC MySQL datetime) for a cron profile's interval.
     *
     * @param string      $key             Recurrence interval key, or "custom_cron".
     * @param int|null    $from_timestamp  Unix timestamp to compute from; defaults to now.
     * @param string|null $cron_expression 5-field cron expression, required when $key is "custom_cron".
     * @throws InvalidArgumentException If $key is "custom_cron" and $cron_expression cannot be evaluated.
     */
    protected function aligned_next_run_mysql(string $key, ?int $from_timestamp = null, ?string $cron_expression = null): string {
        $tz = wp_timezone();
        $base = (new DateTimeImmutable('@' . ($from_timestamp ?: time())))->setTimezone($tz);
        if ($key === 'custom_cron') {
            $next = $this->cron_next_occurrence($cron_expression ?: '* * * * *', $base);
            if (!$next) throw new InvalidArgumentException('Could not calculate the next run for this cron expression.');
            return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $now = $base;
        $next = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
        switch ($key) {
            case 'warmpilot_minute':
                $next = $next->modify('+1 minute');
                break;
            case 'five_minutes':
                $minute = (int)$now->format('i');
                $add = 5 - ($minute % 5);
                $next = $next->modify('+' . $add . ' minutes');
                break;
            case 'fifteen_minutes':
                $minute = (int)$now->format('i');
                $add = 15 - ($minute % 15);
                $next = $next->modify('+' . $add . ' minutes');
                break;
            case 'hourly':
                $next_hour = $now->modify('+1 hour');
                $next = $next_hour->setTime((int)$next_hour->format('H'), 0, 0);
                break;
            case 'twicedaily':
                $today_noon = $now->setTime(12, 0, 0);
                $next = $now < $today_noon ? $today_noon : $now->modify('+1 day')->setTime(0, 0, 0);
                break;
            case 'daily':
                $next = $now->modify('+1 day')->setTime(0, 0, 0);
                break;
            case 'weekly':
                $next = $now->modify('next monday')->setTime(0, 0, 0);
                break;
            default:
                $next_hour = $now->modify('+1 hour');
                $next = $next_hour->setTime((int)$next_hour->format('H'), 0, 0);
        }
        return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    /**
     * Finds the next minute (within 2 years) matching a 5-field cron expression.
     *
     * @param string            $expression 5-field cron expression (minute hour day month weekday).
     * @param DateTimeImmutable $from       Point in time to search forward from (exclusive).
     */
    protected function cron_next_occurrence(string $expression, DateTimeImmutable $from): ?DateTimeImmutable {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) return null;
        [$minute_expr, $hour_expr, $day_expr, $month_expr, $weekday_expr] = $parts;
        if (!$this->validate_cron_expression($expression)) return null;

        $candidate = $from->setTime((int)$from->format('H'), (int)$from->format('i'), 0)->modify('+1 minute');
        $limit = $candidate->modify('+2 years');
        while ($candidate <= $limit) {
            $minute = (int)$candidate->format('i');
            $hour = (int)$candidate->format('G');
            $day = (int)$candidate->format('j');
            $month = (int)$candidate->format('n');
            $weekday = (int)$candidate->format('w');
            $dom_match = $this->cron_field_matches($day_expr, $day, 1, 31);
            $dow_match = $this->cron_field_matches($weekday_expr, $weekday, 0, 7, true);
            $day_match = ($day_expr !== '*' && $weekday_expr !== '*') ? ($dom_match || $dow_match) : ($dom_match && $dow_match);
            if ($this->cron_field_matches($minute_expr, $minute, 0, 59)
                && $this->cron_field_matches($hour_expr, $hour, 0, 23)
                && $day_match
                && $this->cron_field_matches($month_expr, $month, 1, 12)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 minute');
        }
        return null;
    }
    /**
     * Validates that a string is a well-formed 5-field cron expression.
     *
     * @param string $expression Candidate cron expression.
     */
    protected function validate_cron_expression(string $expression): bool {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) return false;
        $ranges = [[0,59],[0,23],[1,31],[1,12],[0,7]];
        foreach ($parts as $i => $field) {
            if (!$this->cron_field_valid($field, $ranges[$i][0], $ranges[$i][1])) return false;
        }
        return true;
    }
    /**
     * Validates a single cron field (supports lists, ranges, and step values).
     *
     * @param string $field One comma-separated cron field, e.g. a step value or a range like "1-5,10".
     * @param int    $min   Minimum value allowed for this field.
     * @param int    $max   Maximum value allowed for this field.
     */
    protected function cron_field_valid(string $field, int $min, int $max): bool {
        if ($field === '') return false;
        foreach (explode(',', $field) as $part) {
            if (!preg_match('/^(\*|\d+|\d+-\d+)(?:\/(\d+))?$/', $part, $m)) return false;
            $step = isset($m[2]) ? (int)$m[2] : 1;
            if ($step < 1) return false;
            if ($m[1] === '*') continue;
            if (str_contains($m[1], '-')) {
                [$a,$b] = array_map('intval', explode('-', $m[1], 2));
                if ($a < $min || $a > $max || $b < $min || $b > $max || $a > $b) return false;
            } else {
                $v = (int)$m[1];
                if ($v < $min || $v > $max) return false;
            }
        }
        return true;
    }
    /**
     * Both 0 and 7 mean Sunday in a weekday cron field; $value is remapped to
     * 7 when comparing against a "7" boundary so both spellings match.
     *
     * @param string $field   One comma-separated cron field.
     * @param int    $value   Candidate value to test (e.g. current minute, hour, weekday).
     * @param int    $min     Minimum value allowed for this field.
     * @param int    $max     Maximum value allowed for this field.
     * @param bool   $weekday Whether this field is the weekday field (enables the 0/7 = Sunday remap).
     */
    protected function cron_field_matches(string $field, int $value, int $min, int $max, bool $weekday = false): bool {
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) [$part, $step_text] = explode('/', $part, 2) + [1 => '1'];
            else $step_text = '1';
            $step = max(1, (int)$step_text);
            if ($part === '*') {
                if ((($value - $min) % $step) === 0) return true;
                continue;
            }
            if (str_contains($part, '-')) {
                [$start,$end] = array_map('intval', explode('-', $part, 2));
            } else {
                $start = $end = (int)$part;
            }
            $test_value = ($weekday && $value === 0 && $start === 7) ? 7 : $value;
            if ($test_value >= $start && $test_value <= $end && (($test_value - $start) % $step) === 0) return true;
        }
        return false;
    }
    /**
     * Finds the shortest recurrence interval among all currently-enabled cron profiles.
     *
     * @return int Shortest interval in seconds, or 0 if no profile is enabled.
     */
    protected function shortest_enabled_interval(): int {
        global $wpdb;
        $keys = $wpdb->get_col("SELECT interval_key FROM {$this->schedules_table} WHERE enabled=1") ?: [];
        if (!$keys) return 0;
        return min(array_map(fn($key) => $this->interval_seconds((string)$key), $keys));
    }
    /**
     * Registered on the warmpilot_cron_tick hook: starts any due cron profiles as new jobs,
     * then advances already-running cron jobs until the time budget for this tick is spent.
     */
    public function cron_tick(): void {
        global $wpdb;
        if (!$this->acquire_cron_lock()) return;
        try {
            $now = current_time('mysql', true);
            $profiles = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->schedules_table} WHERE enabled=1 AND (next_run IS NULL OR next_run<=%s) ORDER BY id ASC LIMIT 10", $now));
            foreach ($profiles as $profile) {
                $active = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->jobs_table} WHERE profile_id=%d AND status='running' ORDER BY id DESC LIMIT 1", $profile->id));
                if (!$active) {
                    $settings = $this->normalize_settings(json_decode($profile->settings, true) ?: []);
                    $job_id = $this->create_job($settings, (int)$profile->id, 'cron');
                    $wpdb->update($this->schedules_table, [
                        'last_run'=>$now,
                        'last_job_id'=>$job_id ?: null,
                        'next_run'=>$this->aligned_next_run_mysql($profile->interval_key, null, $profile->cron_expression),
                        'updated_at'=>$now,
                    ], ['id'=>(int)$profile->id]);
                }
            }

            $deadline = microtime(true) + 45;
            $jobs = $wpdb->get_col("SELECT id FROM {$this->jobs_table} WHERE status='running' AND trigger_source IN ('cron','cron_manual') ORDER BY id ASC LIMIT 10");
            $this->process_cron_jobs($jobs, $deadline);
        } finally {
            $this->release_cron_lock();
        }
    }
    /**
     * Acquires the global cron-tick lock, stealing a stale lock older than 60 seconds.
     */
    protected function acquire_cron_lock(): bool {
        $created = add_option('warmpilot_cron_lock', time(), '', false);
        if ($created) return true;

        $started_at = (int) get_option('warmpilot_cron_lock', 0);
        if ($started_at > 0 && $started_at < time() - 60) {
            delete_option('warmpilot_cron_lock');
            return add_option('warmpilot_cron_lock', time(), '', false);
        }
        return false;
    }
    /**
     * Releases the global cron-tick lock.
     */
    protected function release_cron_lock(): void {
        delete_option('warmpilot_cron_lock');
    }
    /**
     * Advances each running cron job batch by batch until it stops or the tick's time budget runs out.
     *
     * @param int[] $job_ids  IDs of running cron-triggered jobs.
     * @param float $deadline microtime(true) value after which processing must stop.
     */
    protected function process_cron_jobs(array $job_ids, float $deadline): void {
        foreach ($job_ids as $job_id) {
            while (microtime(true) < $deadline) {
                $payload = $this->process_job_batch((int) $job_id);
                if (is_wp_error($payload) || ($payload['status'] ?? '') !== 'running') break;
                if (($payload['remaining'] ?? 0) <= 0) break;
            }
            if (microtime(true) >= $deadline) break;
        }
    }
}
