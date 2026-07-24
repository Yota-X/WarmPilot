<?php
namespace YotaX\WarmPilot;

defined('ABSPATH') || exit;

// WarmPilot uses plugin-owned queue tables whose live state must not be cached.
// Table identifiers come exclusively from $wpdb->prefix in Database::__construct().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
class Job_Repository extends Crawler {
    protected function queue_url(
        int $job_id,
        string $url,
        int $depth,
        string $type,
        ?int $parent_id,
        array $settings,
        array $allowed_hosts,
        bool $bypass_include = false
    ): string {
        global $wpdb;

        $url = $this->normalize_url($url);
        if (!$url) return 'invalid';

        $max_depth = (int) $settings['max_depth'];
        if ($max_depth > 0 && $depth > $max_depth) {
            return $this->record_skip($job_id, $url, $depth, $type, $parent_id, 'Maximum crawl depth exceeded.');
        }

        if (!in_array(wp_parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return 'invalid';
        }

        $host = strtolower(rtrim((string) wp_parse_url($url, PHP_URL_HOST), '.'));
        if (!empty($settings['same_host_only']) && !$this->host_matches_allowed_roots($host, $allowed_hosts)) {
            // External domains are ignored completely: they are not queued and do not appear as skipped rows.
            return 'external';
        }

        foreach ($this->lines($settings['exclude_patterns']) as $pattern) {
            if ($this->wildcard_match($pattern, $url)) {
                return $this->record_skip($job_id, $url, $depth, $type, $parent_id, 'The URL matches excluded pattern "' . $pattern . '".');
            }
        }

        if (!$bypass_include && $type === 'page') {
            $includes = $this->lines($settings['include_patterns']);
            if ($includes) {
                $matched = false;
                foreach ($includes as $pattern) {
                    if ($this->allowed_pattern_match($pattern, $url, $allowed_hosts)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return $this->record_skip($job_id, $url, $depth, $type, $parent_id, 'The URL does not match any allowed pattern.');
                }
            }
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->items_table} WHERE job_id=%d",
            $job_id
        ));
        $max_urls = (int) $settings['max_urls'];
        if ($max_urls > 0 && $count >= $max_urls) {
            return 'limit';
        }

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->items_table}
             (job_id,parent_id,depth,item_type,url,url_hash,status,discovered_at)
             VALUES (%d,%s,%d,%s,%s,%s,'queued',%s)",
            $job_id,
            $parent_id,
            $depth,
            $type,
            $url,
            hash('sha256', $this->canonical_url_key($url)),
            current_time('mysql', true)
        ));

        return $inserted ? 'queued' : 'duplicate';
    }
    protected function record_skip(int $job_id, string $url, int $depth, string $type, ?int $parent_id, string $reason): string {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->items_table}
             (job_id,parent_id,depth,item_type,url,url_hash,status,discovered_at,processed_at,error_text)
             VALUES (%d,%s,%d,%s,%s,%s,'skipped',%s,%s,%s)",
            $job_id,
            $parent_id,
            $depth,
            $type,
            $url,
            hash('sha256', $this->canonical_url_key($url)),
            current_time('mysql', true),
            current_time('mysql', true),
            $reason
        ));
        return 'skipped';
    }
    protected function get_job(int $job_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE id=%d", $job_id)) ?: null;
    }
    protected function refresh_job_totals(int $job_id): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) total,
                SUM(status='successful') successful,
                SUM(status='failed') failed,
                SUM(status='skipped') skipped,
                COALESCE(SUM(CASE WHEN status='successful' THEN verify_time ELSE 0 END),0) total_verify_time
             FROM {$this->items_table} WHERE job_id=%d",
            $job_id
        ));
        $wpdb->update($this->jobs_table, [
            'total' => (int) $row->total,
            'successful' => (int) $row->successful,
            'failed' => (int) $row->failed,
            'skipped' => (int) $row->skipped,
            'total_verify_time' => (float) $row->total_verify_time,
        ], ['id' => $job_id]);
    }
    protected function delete_job_and_items(int $job_id): void {
        global $wpdb;
        $wpdb->delete($this->items_table, ['job_id'=>$job_id]);
        $wpdb->delete($this->jobs_table, ['id'=>$job_id]);
    }
}
