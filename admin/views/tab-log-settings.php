<?php
defined('ABSPATH') || exit;
?>
            <div id="warmpilot-log-settings-tab" class="warmpilot-tab-panel">
                <section class="warmpilot-card warmpilot-log-settings-card">
                    <h2><?php esc_html_e('Log retention settings', 'warmpilot'); ?></h2>
                    <p class="description"><?php esc_html_e('These settings apply globally to both manual and cron job logs. Running jobs are never deleted by rotation.', 'warmpilot'); ?></p>
                    <form id="warmpilot-log-settings" class="warmpilot-log-settings-form">
                        <div class="warmpilot-row warmpilot-2">
                            <label><?php esc_html_e('Keep latest runs', 'warmpilot'); ?>
                                <input type="number" name="log_retention_count" min="0" value="<?php echo esc_attr($log_settings['log_retention_count']); ?>">
                                <span class="hint"><?php esc_html_e('0 = no limit by count.', 'warmpilot'); ?></span>
                            </label>
                            <label><?php esc_html_e('Keep logs for days', 'warmpilot'); ?>
                                <input type="number" name="log_retention_days" min="0" value="<?php echo esc_attr($log_settings['log_retention_days']); ?>">
                                <span class="hint"><?php esc_html_e('0 = no age limit.', 'warmpilot'); ?></span>
                            </label>
                        </div>
                        <div class="warmpilot-actions"><button type="submit" class="button button-primary"><?php esc_html_e('Save log settings', 'warmpilot'); ?></button></div>
                    </form>
                </section>
            </div><!-- /log settings tab -->
