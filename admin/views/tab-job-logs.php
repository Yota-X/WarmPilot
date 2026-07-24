<?php
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
                            <thead><tr><th>Type</th><th>Task</th><th>Job</th><th>Started</th><th>Finished</th><th>Status</th><th>Total</th><th>Successful</th><th>Failed</th><th class="warmpilot-actions-col">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($this->get_all_job_logs() as $warmpilot_log) :
                                $warmpilot_is_cron = in_array($warmpilot_log->trigger_source, ['cron','cron_manual'], true);
                                $warmpilot_type_label = $warmpilot_is_cron ? 'Cron' : 'Manual';
                                $warmpilot_task_label = $warmpilot_is_cron ? ($warmpilot_log->profile_name ?: ('Deleted task #' . (int)$warmpilot_log->profile_id)) : '—';
                            ?>
                                <tr data-job-id="<?php echo esc_attr($warmpilot_log->id); ?>">
                                    <td><span class="warmpilot-job-type warmpilot-job-type-<?php echo esc_attr(strtolower($warmpilot_type_label)); ?>"><?php echo esc_html($warmpilot_type_label); ?></span></td>
                                    <td><?php echo esc_html($warmpilot_task_label); ?></td>
                                    <td>#<?php echo esc_html($warmpilot_log->id); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->started_at ?: '—'); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->finished_at ?: '—'); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->status); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->total); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->successful); ?></td>
                                    <td><?php echo esc_html($warmpilot_log->failed); ?></td>
                                    <td class="warmpilot-actions-col"><div class="warmpilot-row-actions warmpilot-job-log-actions">
                                        <button type="button" class="button warmpilot-view-job-log">View log</button>
                                        <button type="button" class="button warmpilot-view-job-success">Success</button>
                                        <button type="button" class="button warmpilot-view-job-errors">Errors</button>
                                        <button type="button" class="button warmpilot-export-job-log">CSV</button>
                                        <button type="button" class="button button-link-delete warmpilot-delete-job-log" <?php disabled($warmpilot_log->status === 'running'); ?>>Delete</button>
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
                            'total' => 'Total Visits',
                            'successful' => 'Successful',
                            'failed' => 'Failed',
                            'skipped' => 'Skipped',
                            'avg' => 'Avg. page load after warming (sec.)',
                            'duration' => 'Duration',
                            'speed' => 'Speed (pages / minute)',
                        ] as $warmpilot_key => $warmpilot_label) : ?>
                            <div><strong data-log-stat="<?php echo esc_attr($warmpilot_key); ?>">0</strong><span><?php echo esc_html($warmpilot_label); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="warmpilot-report-pagination warmpilot-log-pagination">
                        <button type="button" class="button warmpilot-log-prev" disabled>&larr; Previous</button>
                        <span class="warmpilot-log-page">Page 1 of 1</span>
                        <button type="button" class="button warmpilot-log-next" disabled>Next &rarr;</button>
                        <label>Rows
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
                                <th>Time</th><th>Depth</th><th>Type</th><th>URL</th>
                                <th>Afterwards (sec.)</th><th>Code / Error</th><th>Content-Type</th><th>Cache headers</th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>
            </div><!-- /log tab -->
