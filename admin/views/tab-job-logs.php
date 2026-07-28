<?php
/**
 * "Job Logs" tab: full job log list plus the read-only run log viewer.
 *
 * @package WarmPilot
 */

defined('ABSPATH') || exit;
?>
            <div id="warmpilot-log-tab" class="warmpilot-tab-panel">
                <section class="warmpilot-card warmpilot-jobs-log-list">
                    <div class="warmpilot-report-head warmpilot-jobs-log-head">
                        <div>
                            <h2><?php esc_html_e('Job Logs', 'warmpilot'); ?></h2>
                            <p class="description"><?php esc_html_e('All manual and cron warming runs are listed here.', 'warmpilot'); ?></p>
                        </div>
                    </div>
                    <div class="warmpilot-table-wrap warmpilot-logs-table-wrap">
                        <table class="widefat striped warmpilot-logs-table">
                            <thead><tr>
                                <th><?php esc_html_e('Type', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Task', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Job', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Started', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Finished', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Status', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Total', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Successful', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Failed', 'warmpilot'); ?></th>
                                <th class="warmpilot-actions-col"><?php esc_html_e('Actions', 'warmpilot'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php
                            $warmpilot_job_status_labels = [
                                'running' => __('Running', 'warmpilot'),
                                'finished' => __('Finished', 'warmpilot'),
                                'stopped' => __('Stopped', 'warmpilot'),
                            ];
                            foreach ($this->get_all_job_logs() as $warmpilot_log) :
                                $warmpilot_is_cron = in_array($warmpilot_log->trigger_source, ['cron','cron_manual'], true);
                                $warmpilot_type_key = $warmpilot_is_cron ? 'cron' : 'manual';
                                $warmpilot_type_label = $warmpilot_is_cron ? __('Cron', 'warmpilot') : __('Manual', 'warmpilot');
                                /* translators: %d: cron profile ID. */
                                $warmpilot_task_label = $warmpilot_is_cron ? ($warmpilot_log->profile_name ?: sprintf(__('Deleted task #%d', 'warmpilot'), (int) $warmpilot_log->profile_id)) : '—';
                            ?>
                                <tr data-job-id="<?php echo esc_attr($warmpilot_log->id); ?>">
                                    <td><span class="warmpilot-job-type warmpilot-job-type-<?php echo esc_attr($warmpilot_type_key); ?>"><?php echo esc_html($warmpilot_type_label); ?></span></td>
                                    <td><?php echo esc_html($warmpilot_task_label); ?></td>
                                    <td>#<?php echo esc_html($warmpilot_log->id); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->started_at ?: '—'); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->finished_at ?: '—'); ?></td>
                                    <td><?php echo esc_html($warmpilot_job_status_labels[$warmpilot_log->status] ?? $warmpilot_log->status); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->total); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->successful); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->failed); ?></td>
                                    <td class="warmpilot-actions-col"><div class="warmpilot-row-actions warmpilot-job-log-actions">
                                        <button type="button" class="button warmpilot-view-job-log"><?php esc_html_e('View log', 'warmpilot'); ?></button>
                                        <button type="button" class="button warmpilot-view-job-success"><?php esc_html_e('Success', 'warmpilot'); ?></button>
                                        <button type="button" class="button warmpilot-view-job-errors"><?php esc_html_e('Errors', 'warmpilot'); ?></button>
                                        <button type="button" class="button warmpilot-export-job-log"><?php esc_html_e('CSV', 'warmpilot'); ?></button>
                                        <button type="button" class="button button-link-delete warmpilot-delete-job-log" <?php disabled($warmpilot_log->status === 'running'); ?>><?php esc_html_e('Delete', 'warmpilot'); ?></button>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="warmpilot-card warmpilot-log-viewer" hidden>
                    <div class="warmpilot-report-head">
                        <div>
                            <h2><?php esc_html_e('Run log', 'warmpilot'); ?> <span class="warmpilot-log-job-title"></span></h2>
                            <p class="description warmpilot-log-description"><?php esc_html_e('Read-only report. Opening this log does not change or control the current manual warming job.', 'warmpilot'); ?></p>
                        </div>
                        <div class="warmpilot-row-actions warmpilot-log-viewer-actions">
                            <button type="button" class="button warmpilot-log-export"><?php esc_html_e('Export CSV', 'warmpilot'); ?></button>
                            <button type="button" class="warmpilot-log-close" aria-label="<?php esc_attr_e('Close log', 'warmpilot'); ?>" title="<?php esc_attr_e('Close log', 'warmpilot'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                        </div>
                    </div>
                    <div class="warmpilot-progress warmpilot-log-progress"><span></span></div>
                    <div class="warmpilot-progress-meta warmpilot-log-progress-meta">—</div>
                    <div class="warmpilot-stats warmpilot-log-stats">
                        <?php foreach ([
                            'total' => __('Total Visits', 'warmpilot'),
                            'successful' => __('Successful', 'warmpilot'),
                            'failed' => __('Failed', 'warmpilot'),
                            'skipped' => __('Skipped', 'warmpilot'),
                            'avg' => __('Avg. page load after warming (sec.)', 'warmpilot'),
                            'duration' => __('Duration', 'warmpilot'),
                            'speed' => __('Speed (pages / minute)', 'warmpilot'),
                        ] as $warmpilot_key => $warmpilot_label) : ?>
                            <div><strong data-log-stat="<?php echo esc_attr($warmpilot_key); ?>">0</strong><span><?php echo esc_html($warmpilot_label); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="warmpilot-report-pagination warmpilot-log-pagination">
                        <button type="button" class="button warmpilot-log-prev" disabled>&larr; <?php esc_html_e('Previous', 'warmpilot'); ?></button>
                        <span class="warmpilot-log-page"><?php echo esc_html(sprintf(/* translators: %1$s: current page number, %2$s: total number of pages. */ __('Page %1$s of %2$s', 'warmpilot'), '1', '1')); ?></span>
                        <button type="button" class="button warmpilot-log-next" disabled><?php esc_html_e('Next', 'warmpilot'); ?> &rarr;</button>
                        <label><?php esc_html_e('Rows', 'warmpilot'); ?>
                            <select class="warmpilot-log-per-page">
                                <option value="50">50</option>
                                <option value="100" selected>100</option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                            </select>
                        </label>
                    </div>
                    <div class="warmpilot-table-wrap warmpilot-log-table-wrap">
                        <table class="widefat striped" id="warmpilot-log-results">
                            <thead><tr>
                                <th><?php esc_html_e('Time', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Depth', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Type', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('URL', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Afterwards (sec.)', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Code / Error', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Content-Type', 'warmpilot'); ?></th>
                                <th><?php esc_html_e('Cache headers', 'warmpilot'); ?></th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>
            </div><!-- /log tab -->
