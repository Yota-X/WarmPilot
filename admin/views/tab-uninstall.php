<?php
defined('ABSPATH') || exit;
?>
            <div id="warmpilot-uninstall-tab" class="warmpilot-tab-panel">
                <section class="warmpilot-card warmpilot-uninstall-card">
                    <h2><?php esc_html_e('Data & Uninstall', 'warmpilot'); ?></h2>
                    <p class="description"><?php esc_html_e('Choose what happens to WarmPilot data when the plugin is deleted from the WordPress Plugins screen.', 'warmpilot'); ?></p>
                    <form id="warmpilot-uninstall-settings" class="warmpilot-uninstall-settings-form">
                        <fieldset>
                            <legend><?php esc_html_e('Data removal policy', 'warmpilot'); ?></legend>
                            <?php $this->checkbox(
                                'delete_data_on_uninstall',
                                __('Permanently delete all WarmPilot settings, cron tasks, jobs, and logs when the plugin is deleted', 'warmpilot'),
                                $log_settings
                            ); ?>
                            <p class="description"><?php esc_html_e('Leave this disabled to preserve data for a future reinstall. This setting affects plugin deletion, not ordinary deactivation.', 'warmpilot'); ?></p>
                            <p class="description"><strong><?php esc_html_e('Warning:', 'warmpilot'); ?></strong> <?php esc_html_e('Deleted data cannot be restored unless you have a database backup.', 'warmpilot'); ?></p>
                        </fieldset>
                        <div class="warmpilot-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Save uninstall settings', 'warmpilot'); ?></button>
                        </div>
                    </form>
                </section>
            </div><!-- /uninstall tab -->
