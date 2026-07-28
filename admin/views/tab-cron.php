<?php
/**
 * "Cron tasks" tab: cron environment diagnostics, task editor, and task list.
 *
 * @package WarmPilot
 */

defined('ABSPATH') || exit;
?>
            <div id="warmpilot-cron-tab" class="warmpilot-tab-panel">
                <?php $this->render_cron_environment(); ?>
                <div class="warmpilot-cron-layout">
                    <section class="warmpilot-card warmpilot-cron-editor">
                        <div class="warmpilot-cron-editor-head">
                            <div>
                                <h2><?php esc_html_e('Cron task settings', 'warmpilot'); ?></h2>
                                <p class="warmpilot-cron-editor-mode" aria-live="polite">
                                    <span class="warmpilot-cron-editor-mode-label"><?php esc_html_e('Creating new task', 'warmpilot'); ?></span>
                                </p>
                            </div>
                            <button type="button" class="button warmpilot-new-cron-task" hidden><?php esc_html_e('Create new task', 'warmpilot'); ?></button>
                        </div>
                        <form id="warmpilot-cron-settings">
                            <input type="hidden" name="profile_id" value="0">
                            <div class="warmpilot-row warmpilot-2">
                                <label><?php esc_html_e('Task name', 'warmpilot'); ?><input type="text" name="profile_name" required placeholder="Products every 15 minutes"></label>
                                <label><?php esc_html_e('Schedule', 'warmpilot'); ?><select name="interval_key"><option value="warmpilot_minute">Every minute</option><option value="five_minutes">Every 5 minutes</option><option value="fifteen_minutes">Every 15 minutes</option><option value="hourly" selected>Hourly</option><option value="twicedaily">Twice daily</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="custom_cron" <?php disabled(!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)); ?>>Custom cron expression<?php echo (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? '' : ' — requires system cron mode'; ?></option></select></label>
                            </div>
                            <div class="warmpilot-custom-cron" hidden>
                                <div class="warmpilot-cron-expression-grid">
                                    <label>Minute<input type="text" name="cron_minute" value="*" placeholder="*"></label>
                                    <label>Hour<input type="text" name="cron_hour" value="*" placeholder="*"></label>
                                    <label>Day<input type="text" name="cron_day" value="*" placeholder="*"></label>
                                    <label>Month<input type="text" name="cron_month" value="*" placeholder="*"></label>
                                    <label>Weekday<input type="text" name="cron_weekday" value="*" placeholder="*"></label>
                                </div>
                                <p class="hint">Standard 5-field cron syntax. Examples: <code>*/15 * * * *</code>, <code>0 * * * *</code>, <code>30 2 * * 1-5</code>. WordPress timezone is used.</p>
                            </div>
                            <div class="warmpilot-row warmpilot-3">
                                <label><?php esc_html_e('Workers', 'warmpilot'); ?><input type="number" name="workers" min="1" max="30" value="<?php echo esc_attr($settings['workers']); ?>"></label>
                                <label><?php esc_html_e('Timeout (seconds)', 'warmpilot'); ?><input type="number" name="timeout" min="1" max="300" value="<?php echo esc_attr($settings['timeout']); ?>"></label>
                                <label><?php esc_html_e('Delay between batches (seconds)', 'warmpilot'); ?><input type="number" name="delay_seconds" min="0" step="0.1" value="<?php echo esc_attr($settings['delay_seconds']); ?>"></label>
                            </div>
                            <div class="warmpilot-row warmpilot-2">
                                <label><?php esc_html_e('Retries after a failed request', 'warmpilot'); ?><input type="number" name="retry_count" min="0" max="10" value="<?php echo esc_attr($settings['retry_count']); ?>"></label>
                                <label><?php esc_html_e('Retry delay (seconds)', 'warmpilot'); ?><input type="number" name="retry_delay_seconds" min="0" max="86400" step="0.1" value="<?php echo esc_attr($settings['retry_delay_seconds']); ?>"></label>
                            </div>
                            <div class="warmpilot-row warmpilot-2">
                                <label><?php esc_html_e('Maximum URLs', 'warmpilot'); ?><input type="number" name="max_urls" min="0" value="<?php echo esc_attr($settings['max_urls']); ?>"><span class="hint"><?php esc_html_e('0 = unlimited.', 'warmpilot'); ?></span></label>
                                <label><?php esc_html_e('Maximum crawl depth', 'warmpilot'); ?><input type="number" name="max_depth" min="-1" value="<?php echo esc_attr($settings['max_depth']); ?>"><span class="hint"><?php esc_html_e('-1 = off; 0 = unlimited; positive = depth limit.', 'warmpilot'); ?></span></label>
                            </div>
                            <label><?php esc_html_e('Entry URLs (one per line)', 'warmpilot'); ?><textarea name="start_urls" rows="4"><?php echo esc_textarea($settings['start_urls']); ?></textarea></label>
                            <label><?php esc_html_e('Sitemap URLs (one per line)', 'warmpilot'); ?><textarea name="sitemap_urls" rows="3"><?php echo esc_textarea($settings['sitemap_urls']); ?></textarea></label>
                            <label><?php esc_html_e('Allowed URL patterns', 'warmpilot'); ?><textarea name="include_patterns" rows="5"><?php echo esc_textarea($settings['include_patterns']); ?></textarea></label>
                            <label><?php esc_html_e('Excluded URL patterns', 'warmpilot'); ?><textarea name="exclude_patterns" rows="7"><?php echo esc_textarea($settings['exclude_patterns']); ?></textarea></label>
                            <label><?php esc_html_e('Request headers', 'warmpilot'); ?><textarea name="headers" rows="5"><?php echo esc_textarea($settings['headers']); ?></textarea></label>
                            <fieldset><legend><?php esc_html_e('Crawler', 'warmpilot'); ?></legend>
                                <?php $this->checkbox('same_host_only', __('Ignore external domains; allow the site domain and all of its subdomains', 'warmpilot'), $settings, 'cron_'); ?>
                                <?php $this->checkbox('verify_after_warm', __('Send a second request to measure the warmed response', 'warmpilot'), $settings, 'cron_'); ?>
                                <?php $this->checkbox('ssl_verify', __('Verify SSL certificates', 'warmpilot'), $settings, 'cron_'); ?>
                            </fieldset>
                            <fieldset><legend><?php esc_html_e('Assets preloading', 'warmpilot'); ?></legend>
                                <?php $this->checkbox('visit_scripts', __('Scripts', 'warmpilot'), $settings, 'cron_'); ?>
                                <?php $this->checkbox('visit_styles', __('Styles', 'warmpilot'), $settings, 'cron_'); ?>
                                <?php $this->checkbox('visit_fonts', __('Fonts', 'warmpilot'), $settings, 'cron_'); ?>
                                <?php $this->checkbox('visit_images', __('Images', 'warmpilot'), $settings, 'cron_'); ?>
                            </fieldset>
                            <div class="warmpilot-actions"><button type="submit" class="button button-primary warmpilot-save-cron"><?php esc_html_e('Save cron task', 'warmpilot'); ?></button></div>
                        </form>
                    </section>
                    <section class="warmpilot-card warmpilot-cron-list">
                        <h2><?php esc_html_e('Configured cron tasks', 'warmpilot'); ?></h2>
                        <div class="warmpilot-table-wrap warmpilot-cron-table-wrap"><table class="widefat striped warmpilot-cron-table"><thead><tr>
                            <th><?php esc_html_e('Name', 'warmpilot'); ?></th>
                            <th><?php esc_html_e('Status', 'warmpilot'); ?></th>
                            <th><?php esc_html_e('Schedule', 'warmpilot'); ?></th>
                            <th><?php esc_html_e('Next run', 'warmpilot'); ?></th>
                            <th><?php esc_html_e('Last run', 'warmpilot'); ?></th>
                            <th><?php esc_html_e('Last job', 'warmpilot'); ?></th>
                            <th class="warmpilot-actions-col"><?php esc_html_e('Actions', 'warmpilot'); ?></th>
                        </tr></thead><tbody>
                        <?php
                        $warmpilot_task_status_labels = [
                            'disabled' => __('Disabled', 'warmpilot'),
                            'stopping' => __('Stopping', 'warmpilot'),
                            'running' => __('Running', 'warmpilot'),
                            'idle' => __('Idle', 'warmpilot'),
                        ];
                        foreach ($this->get_cron_profiles() as $warmpilot_profile) :
                            $warmpilot_is_active = !empty($warmpilot_profile->active_job_id);
                            $warmpilot_is_stopping = $warmpilot_is_active && !empty($warmpilot_profile->active_stop_requested);
                            $warmpilot_task_status_key = !(int)$warmpilot_profile->enabled ? 'disabled' : ($warmpilot_is_stopping ? 'stopping' : ($warmpilot_is_active ? 'running' : 'idle'));
                            $warmpilot_task_status_label = $warmpilot_task_status_labels[$warmpilot_task_status_key];
                        ?>
                            <tr data-profile-id="<?php echo esc_attr($warmpilot_profile->id); ?>" data-task-status="<?php echo esc_attr($warmpilot_task_status_key); ?>">
                                <td><strong><?php echo esc_html($warmpilot_profile->name); ?></strong></td>
                                <td><span class="warmpilot-task-status warmpilot-task-status-<?php echo esc_attr($warmpilot_task_status_key); ?>" data-active-job-id="<?php echo esc_attr($warmpilot_is_active ? (int) $warmpilot_profile->active_job_id : ''); ?>"><?php echo esc_html($warmpilot_task_status_label); ?><?php if ($warmpilot_is_active) echo ' · ' . esc_html(sprintf(/* translators: %d: job ID. */ __('Job #%d', 'warmpilot'), (int) $warmpilot_profile->active_job_id)); ?></span></td>
                                <td><?php echo esc_html($this->schedule_label($warmpilot_profile)); ?></td>
                                <td><?php echo esc_html($this->display_utc_mysql($warmpilot_profile->next_run)); ?></td>
                                <td><?php echo esc_html($this->display_utc_mysql($warmpilot_profile->last_run)); ?></td>
                                <td><?php echo esc_html($warmpilot_profile->last_job_id ?: '—'); ?></td>
                                <td class="warmpilot-actions-col">
                                    <div class="warmpilot-cron-actions">
                                        <button type="button" class="button warmpilot-edit-cron"><?php esc_html_e('Edit', 'warmpilot'); ?></button>
                                        <?php if ($warmpilot_is_active) : ?>
                                            <button type="button" class="button button-primary warmpilot-stop-cron" <?php disabled($warmpilot_is_stopping); ?>><?php echo $warmpilot_is_stopping ? esc_html__('Stopping…', 'warmpilot') : esc_html__('Stop', 'warmpilot'); ?></button>
                                        <?php else : ?>
                                            <button type="button" class="button button-primary warmpilot-run-cron"><?php esc_html_e('Run now', 'warmpilot'); ?></button>
                                        <?php endif; ?>
                                        <div class="warmpilot-cron-secondary-actions">
                                            <button type="button" class="button-link warmpilot-toggle-cron" data-enabled="<?php echo (int) $warmpilot_profile->enabled; ?>"><?php echo (int) $warmpilot_profile->enabled ? esc_html__('Disable', 'warmpilot') : esc_html__('Enable', 'warmpilot'); ?></button>
                                            <span class="warmpilot-action-separator" aria-hidden="true">·</span>
                                            <button type="button" class="button-link warmpilot-delete-profile-logs"><?php esc_html_e('Delete logs', 'warmpilot'); ?></button>
                                            <span class="warmpilot-action-separator" aria-hidden="true">·</span>
                                            <button type="button" class="button-link-delete warmpilot-delete-cron" <?php disabled($warmpilot_is_active); ?>><?php esc_html_e('Delete task', 'warmpilot'); ?></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                        <p class="hint"><?php esc_html_e('The task schedule controls when warming is due. The cron environment above controls how promptly WordPress notices and starts due tasks.', 'warmpilot'); ?></p>
                    </section>
                </div>
            </div><!-- /cron tab -->
