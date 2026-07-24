<?php
defined('ABSPATH') || exit;
?>
            <div id="warmpilot-manual-tab" class="warmpilot-tab-panel is-active">
            <div class="warmpilot-grid">
                <section class="warmpilot-card">
                    <h2><?php esc_html_e('Warm-up settings', 'warmpilot'); ?></h2>
                    <form id="warmpilot-settings">
                        <div class="warmpilot-row warmpilot-3">
                            <label><?php esc_html_e('Workers', 'warmpilot'); ?>
                                <input type="number" name="workers" min="1" max="30" value="<?php echo esc_attr($settings['workers']); ?>">
                            </label>
                            <label><?php esc_html_e('Timeout (seconds)', 'warmpilot'); ?>
                                <input type="number" name="timeout" min="1" max="300" value="<?php echo esc_attr($settings['timeout']); ?>">
                            </label>
                            <label><?php esc_html_e('Delay between batches (seconds)', 'warmpilot'); ?>
                                <input type="number" name="delay_seconds" min="0" step="0.1" value="<?php echo esc_attr($settings['delay_seconds']); ?>">
                            </label>
                        </div>
                        <div class="warmpilot-row warmpilot-2">
                            <label><?php esc_html_e('Retries after a failed request', 'warmpilot'); ?>
                                <input type="number" name="retry_count" min="0" max="10" value="<?php echo esc_attr($settings['retry_count']); ?>">
                                <span class="hint"><?php esc_html_e('Retries network errors, timeouts, HTTP 408, 429 and 5xx responses.', 'warmpilot'); ?></span>
                            </label>
                            <label><?php esc_html_e('Retry delay (seconds)', 'warmpilot'); ?>
                                <input type="number" name="retry_delay_seconds" min="0" max="86400" step="0.1" value="<?php echo esc_attr($settings['retry_delay_seconds']); ?>">
                            </label>
                        </div>

                        <div class="warmpilot-row warmpilot-2">
                            <label><?php esc_html_e('Maximum URLs', 'warmpilot'); ?>
                                <input type="number" name="max_urls" min="0" step="1" value="<?php echo esc_attr($settings['max_urls']); ?>">
                                <span class="hint"><?php esc_html_e('0 = unlimited.', 'warmpilot'); ?></span>
                            </label>
                            <label><?php esc_html_e('Maximum crawl depth', 'warmpilot'); ?>
                                <input type="number" name="max_depth" min="-1" step="1" value="<?php echo esc_attr($settings['max_depth']); ?>">
                                <span class="hint"><?php esc_html_e('-1 = do not discover links from HTML; 0 = unlimited; any positive number limits the crawl depth.', 'warmpilot'); ?></span>
                            </label>
                        </div>


                        <label><?php esc_html_e('Entry URLs (one per line)', 'warmpilot'); ?>
                            <textarea name="start_urls" rows="4"><?php echo esc_textarea($settings['start_urls']); ?></textarea>
                            <span class="hint"><?php esc_html_e('Pages from which crawling begins.', 'warmpilot'); ?></span>
                        </label>

                        <label><?php esc_html_e('Sitemap URLs (one per line)', 'warmpilot'); ?>
                            <textarea name="sitemap_urls" rows="3"><?php echo esc_textarea($settings['sitemap_urls']); ?></textarea>
                        </label>

                        <label><?php esc_html_e('Allowed URL patterns (one wildcard pattern per line)', 'warmpilot'); ?>
                            <textarea name="include_patterns" rows="5"><?php echo esc_textarea($settings['include_patterns']); ?></textarea>
                            <span class="hint">Example: <?php echo esc_html(untrailingslashit(home_url()) . '/product-brand/*/?e-page-*=*'); ?></span>
                        </label>

                        <label><?php esc_html_e('Excluded URL patterns (one wildcard pattern per line)', 'warmpilot'); ?>
                            <textarea name="exclude_patterns" rows="7"><?php echo esc_textarea($settings['exclude_patterns']); ?></textarea>
                        </label>

                        <label><?php esc_html_e('Request headers (Header: value)', 'warmpilot'); ?>
                            <textarea name="headers" rows="5"><?php echo esc_textarea($settings['headers']); ?></textarea>
                        </label>

                        <fieldset>
                            <legend><?php esc_html_e('Crawler', 'warmpilot'); ?></legend>
                            <?php $this->checkbox('same_host_only', __('Ignore external domains; allow the site domain and all of its subdomains', 'warmpilot'), $settings); ?>
                            <?php $this->checkbox('verify_after_warm', __('Send a second request to measure the warmed response', 'warmpilot'), $settings); ?>
                            <?php $this->checkbox('ssl_verify', __('Verify SSL certificates', 'warmpilot'), $settings); ?>
                        </fieldset>

                        <fieldset>
                            <legend><?php esc_html_e('Assets preloading', 'warmpilot'); ?></legend>
                            <?php $this->checkbox('visit_scripts', __('Scripts', 'warmpilot'), $settings); ?>
                            <?php $this->checkbox('visit_styles', __('Styles', 'warmpilot'), $settings); ?>
                            <?php $this->checkbox('visit_fonts', __('Fonts', 'warmpilot'), $settings); ?>
                            <?php $this->checkbox('visit_images', __('Images', 'warmpilot'), $settings); ?>
                        </fieldset>

                        <div class="warmpilot-actions">
                            <button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'warmpilot'); ?></button>
                            <button class="button button-primary warmpilot-start" type="button"><?php esc_html_e('Start warming', 'warmpilot'); ?></button>
                            <button class="button warmpilot-stop" type="button" hidden><?php esc_html_e('Stop', 'warmpilot'); ?></button>
                            <button class="button warmpilot-reset" type="button"><?php esc_html_e('Reset report', 'warmpilot'); ?></button>
                            <button class="button warmpilot-export" type="button"><?php esc_html_e('Export CSV', 'warmpilot'); ?></button>
                        </div>
                    </form>
                </section>

                <section class="warmpilot-card warmpilot-report-card">
                    <div class="warmpilot-report-head">
                        <h2><?php esc_html_e('Live report', 'warmpilot'); ?></h2>
                        <span id="warmpilot-state" class="warmpilot-badge">Idle</span>
                    </div>
                    <div class="warmpilot-progress"><span></span></div>
                    <div id="warmpilot-progress-meta" class="warmpilot-progress-meta">Known queue: 0 processed / 0 discovered · 0 remaining. More URLs may still be discovered while crawling.</div>
                    <div class="warmpilot-stats">
                        <?php
                        $warmpilot_stats = [
                            'total' => 'Total Visits',
                            'successful' => 'Successful',
                            'failed' => 'Failed',
                            'skipped' => 'Skipped',
                            'avg' => 'Avg. page load after warming (sec.)',
                            'duration' => 'Duration',
                            'speed' => 'Speed (pages / minute)',
                        ];
                        foreach ($warmpilot_stats as $warmpilot_key => $warmpilot_label) : ?>
                            <div><strong data-stat="<?php echo esc_attr($warmpilot_key); ?>">0</strong><span><?php echo esc_html($warmpilot_label); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="warmpilot-report-pagination" id="warmpilot-report-pagination">
                        <button type="button" class="button warmpilot-report-prev" disabled>&larr; Previous</button>
                        <span class="warmpilot-report-page">Page 1 of 1</span>
                        <button type="button" class="button warmpilot-report-next" disabled>Next &rarr;</button>
                        <label>Rows
                            <select class="warmpilot-report-per-page">
                                <option value="50">50</option>
                                <option value="100" selected>100</option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                            </select>
                        </label>
                    </div>
                    <div class="warmpilot-table-wrap">
                        <table class="widefat striped" id="warmpilot-results">
                            <thead><tr>
                                <th>Time</th><th>Depth</th><th>Type</th><th>URL</th>
                                <th>Afterwards (sec.)</th><th>Code / Error</th><th>Content-Type</th><th>Cache headers</th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>
            </div>

            </div><!-- /manual tab -->
