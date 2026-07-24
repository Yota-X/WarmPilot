<?php
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
class Cron_Manager extends Job_Runner {
    protected function realign_existing_schedules(): void {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, interval_key, cron_expression FROM {$this->schedules_table}") ?: [];
        foreach ($rows as $row) {
            try {
                $nextRun = $this->aligned_next_run_mysql((string)$row->interval_key, null, $row->cron_expression ?: null);
                $wpdb->update($this->schedules_table, ['next_run'=>$nextRun], ['id'=>(int)$row->id]);
            } catch (Throwable $e) {
                // Invalid legacy custom expressions remain unchanged until edited.
            }
        }
    }
    public function cron_schedules(array $schedules): array {
        $schedules['warmpilot_minute'] = ['interval'=>60, 'display'=>'Every minute'];
        $schedules['five_minutes'] = ['interval'=>300, 'display'=>'Every 5 minutes'];
        $schedules['fifteen_minutes'] = ['interval'=>900, 'display'=>'Every 15 minutes'];
        $schedules['weekly'] = ['interval'=>604800, 'display'=>'Weekly'];
        return $schedules;
    }
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
    protected function display_utc_mysql(?string $value): string {
        if (!$value) return '—';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return $value;
        }
    }
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
                $nextHour = $now->modify('+1 hour');
                $next = $nextHour->setTime((int)$nextHour->format('H'), 0, 0);
                break;
            case 'twicedaily':
                $todayNoon = $now->setTime(12, 0, 0);
                $next = $now < $todayNoon ? $todayNoon : $now->modify('+1 day')->setTime(0, 0, 0);
                break;
            case 'daily':
                $next = $now->modify('+1 day')->setTime(0, 0, 0);
                break;
            case 'weekly':
                $next = $now->modify('next monday')->setTime(0, 0, 0);
                break;
            default:
                $nextHour = $now->modify('+1 hour');
                $next = $nextHour->setTime((int)$nextHour->format('H'), 0, 0);
        }
        return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    protected function cron_next_occurrence(string $expression, DateTimeImmutable $from): ?DateTimeImmutable {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) return null;
        [$minuteExpr, $hourExpr, $dayExpr, $monthExpr, $weekdayExpr] = $parts;
        if (!$this->validate_cron_expression($expression)) return null;

        $candidate = $from->setTime((int)$from->format('H'), (int)$from->format('i'), 0)->modify('+1 minute');
        $limit = $candidate->modify('+2 years');
        while ($candidate <= $limit) {
            $minute = (int)$candidate->format('i');
            $hour = (int)$candidate->format('G');
            $day = (int)$candidate->format('j');
            $month = (int)$candidate->format('n');
            $weekday = (int)$candidate->format('w');
            $domMatch = $this->cron_field_matches($dayExpr, $day, 1, 31);
            $dowMatch = $this->cron_field_matches($weekdayExpr, $weekday, 0, 7, true);
            $dayMatch = ($dayExpr !== '*' && $weekdayExpr !== '*') ? ($domMatch || $dowMatch) : ($domMatch && $dowMatch);
            if ($this->cron_field_matches($minuteExpr, $minute, 0, 59)
                && $this->cron_field_matches($hourExpr, $hour, 0, 23)
                && $dayMatch
                && $this->cron_field_matches($monthExpr, $month, 1, 12)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 minute');
        }
        return null;
    }
    protected function validate_cron_expression(string $expression): bool {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) return false;
        $ranges = [[0,59],[0,23],[1,31],[1,12],[0,7]];
        foreach ($parts as $i => $field) {
            if (!$this->cron_field_valid($field, $ranges[$i][0], $ranges[$i][1])) return false;
        }
        return true;
    }
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
    protected function cron_field_matches(string $field, int $value, int $min, int $max, bool $weekday = false): bool {
        if ($weekday && $value === 0 && str_contains($field, '7')) {
            // Both 0 and 7 mean Sunday.
        }
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) [$part, $stepText] = explode('/', $part, 2) + [1 => '1'];
            else $stepText = '1';
            $step = max(1, (int)$stepText);
            if ($part === '*') {
                if ((($value - $min) % $step) === 0) return true;
                continue;
            }
            if (str_contains($part, '-')) {
                [$start,$end] = array_map('intval', explode('-', $part, 2));
            } else {
                $start = $end = (int)$part;
            }
            $testValue = ($weekday && $value === 0 && $start === 7) ? 7 : $value;
            if ($testValue >= $start && $testValue <= $end && (($testValue - $start) % $step) === 0) return true;
        }
        return false;
    }
    protected function shortest_enabled_interval(): int {
        global $wpdb;
        $keys = $wpdb->get_col("SELECT interval_key FROM {$this->schedules_table} WHERE enabled=1") ?: [];
        if (!$keys) return 0;
        return min(array_map(fn($key) => $this->interval_seconds((string)$key), $keys));
    }
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
    protected function release_cron_lock(): void {
        delete_option('warmpilot_cron_lock');
    }
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
