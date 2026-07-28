jQuery(function ($) {
    let jobId = Number(localStorage.getItem('warmpilotJobId') || 0);
    let processing = false;
    let timer = null;
    let reportPage = 1;
    let perPage = Number($('.warmpilot-report-per-page').val() || 100);
    let logJobId = Number(sessionStorage.getItem('warmpilotLogJobId') || 0);
    let logMode = sessionStorage.getItem('warmpilotLogMode') === 'errors' ? 'errors' : 'all';
    let logPage = 1;
    let logPerPage = Number($('.warmpilot-log-per-page').val() || 100);
    let logRefreshTimer = null;
    let logLoading = false;
    let jobLogsLoading = false;

    const $state = $('#warmpilot-state');
    const $tbody = $('#warmpilot-results tbody');

    function payload(extra = {}) { return Object.assign({ nonce: WarmPilotAdmin.nonce }, extra); }

    function serializeForm($form, action) {
        const data = {};
        $form.serializeArray().forEach((row) => { data[row.name] = row.value; });
        $form.find('input[type="checkbox"]').each(function () {
            if (!this.checked) data[this.name] = 0;
        });
        data.action = action;
        data.nonce = WarmPilotAdmin.nonce;
        return data;
    }

    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function formatPageOfRows(page, pages, total) {
        return WarmPilotAdmin.strings.pageOfPages.replace('%1$s', page).replace('%2$s', pages) +
            ' · ' + WarmPilotAdmin.strings.rowsCount.replace('%s', Number(total || 0));
    }
    function setState(status) { $state.text(status || WarmPilotAdmin.strings.idle).attr('data-state', status || 'idle'); }

    function updateManualControls(status) {
        const normalized = String(status || 'idle').toLowerCase();
        const running = normalized === 'running' || normalized === 'starting' || normalized === 'stopping';
        const stopping = normalized === 'stopping';
        $('.warmpilot-start').prop('hidden', running).prop('disabled', running);
        $('.warmpilot-stop')
            .prop('hidden', !running)
            .prop('disabled', stopping)
            .text(stopping ? WarmPilotAdmin.strings.stopping : WarmPilotAdmin.strings.stop);
    }

    function stopLogAutoRefresh() {
        if (logRefreshTimer) {
            clearInterval(logRefreshTimer);
            logRefreshTimer = null;
        }
    }

    function closeOpenJobLog() {
        stopLogAutoRefresh();
        sessionStorage.removeItem('warmpilotLogJobId');
        sessionStorage.removeItem('warmpilotLogMode');
        logJobId = 0;
        logMode = 'all';
        logPage = 1;
        logLoading = false;
        $('.warmpilot-log-viewer').prop('hidden', true);
        $('#warmpilot-log-results tbody').empty();
    }

    function startLogAutoRefresh() {
        stopLogAutoRefresh();
        if (!logJobId || $('.warmpilot-log-viewer').prop('hidden')) return;
        logRefreshTimer = setInterval(function () {
            if (document.hidden || !$('#warmpilot-log-tab').hasClass('is-active')) return;
            loadLog(true);
        }, 5000);
    }

    function activateTab(name) {
        // Leaving Job Logs closes the currently opened viewer. Returning to the
        // tab shows only the jobs list, so the user explicitly reopens a log.
        if (name !== 'log' && $('#warmpilot-log-tab').hasClass('is-active')) {
            closeOpenJobLog();
        } else if (name !== 'log') {
            stopLogAutoRefresh();
        }
        $('.warmpilot-tabs .nav-tab').removeClass('nav-tab-active');
        $('.warmpilot-tabs .nav-tab[data-tab="' + name + '"]').addClass('nav-tab-active');
        $('.warmpilot-tab-panel').removeClass('is-active');
        $('#warmpilot-' + name + '-tab').addClass('is-active');
        const nextHash = '#warmpilot-' + name + '-tab';
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search + nextHash);
        }
        if (name === 'log') {
            loadJobLogs();
            if (logJobId && !$('.warmpilot-log-viewer').prop('hidden')) {
                loadLog(true);
                startLogAutoRefresh();
            }
        }
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    }

    $('.warmpilot-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        activateTab($(this).data('tab'));
    });
    if (window.location.hash === '#warmpilot-cron-tab') activateTab('cron');
    if (window.location.hash === '#warmpilot-log-tab') {
        // Do not restore a previously opened log after navigation/reload.
        closeOpenJobLog();
        activateTab('log');
    }
    if (window.location.hash === '#warmpilot-log-settings-tab') activateTab('log-settings');
    if (window.location.hash === '#warmpilot-uninstall-tab') activateTab('uninstall');

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && logJobId && $('#warmpilot-log-tab').hasClass('is-active') && !$('.warmpilot-log-viewer').prop('hidden')) {
            loadLog(true);
            startLogAutoRefresh();
        }
    });

    function render(data) {
        setState(data.status);
        updateManualControls(data.status);
        ['total', 'successful', 'failed', 'skipped', 'avg', 'duration', 'speed'].forEach((key) => {
            $('[data-stat="' + key + '"]').text(data[key] ?? 0);
        });
        $('.warmpilot-progress span').css('width', Math.min(100, Number(data.progress || 0)) + '%');
        $('#warmpilot-progress-meta').text(
            'Known queue: ' + Number(data.processed || 0) + ' processed / ' + Number(data.known_total || data.total || 0) +
            ' discovered · ' + Number(data.remaining || 0) + ' remaining. More URLs may still be discovered while crawling.'
        );
        reportPage = Number(data.report_page || reportPage || 1);
        const reportPages = Number(data.report_pages || 1);
        $('.warmpilot-report-page').text(formatPageOfRows(reportPage, reportPages, data.report_total));
        $('.warmpilot-report-prev').prop('disabled', reportPage <= 1);
        $('.warmpilot-report-next').prop('disabled', reportPage >= reportPages);

        $tbody.html((data.items || []).map((row) => {
            const codeOrError = row.status === 'skipped' || row.status === 'failed' ? (row.error_text || row.response_code || '') : (row.response_code || '');
            return '<tr class="warmpilot-' + esc(row.status) + '">' +
                '<td>' + esc(row.processed_at || '') + '</td><td>' + esc(row.depth) + '</td><td>' + esc(row.item_type) + '</td>' +
                '<td class="warmpilot-url"><a href="' + esc(row.url) + '" target="_blank" rel="noopener">' + esc(row.url) + '</a></td>' +
                '<td>' + esc(row.verify_time || '') + '</td><td>' + esc(codeOrError) + '</td><td>' + esc(row.content_type || '') + '</td>' +
                '<td>' + esc(row.cache_headers || row.cf_cache_status || '').replace(/\n/g, '<br>') + '</td></tr>';
        }).join(''));

        if (['finished', 'stopped'].includes(data.status)) { processing = false; clearTimeout(timer); }
    }

    function status() {
        if (!jobId) return;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_status', job_id: jobId, report_page: reportPage, per_page: perPage }))
            .done((res) => { if (res.success) render(res.data); });
    }

    function processNext() {
        if (!jobId || processing) return;
        processing = true;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_process', job_id: jobId }))
            .done((res) => {
                if (!res.success) { setState(res.data?.message || WarmPilotAdmin.strings.error); processing = false; return; }
                reportPage === 1 ? render(res.data) : status();
                processing = false;
                if (res.data.status === 'running') timer = setTimeout(processNext, 150);
            })
            .fail(() => { setState(WarmPilotAdmin.strings.error); processing = false; });
    }

    $('#warmpilot-settings').on('submit', function (e) {
        e.preventDefault();
        $.post(WarmPilotAdmin.ajaxUrl, serializeForm($(this), 'warmpilot_save_settings'))
            .done((res) => setState(res.success ? 'Settings saved' : 'Save failed'));
    });
    $('#warmpilot-log-settings').on('submit', function (e) {
        e.preventDefault();
        const $button = $(this).find('button[type="submit"]').prop('disabled', true).text('Saving…');
        $.post(WarmPilotAdmin.ajaxUrl, serializeForm($(this), 'warmpilot_save_log_settings')).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not save retention settings.'); return; }
            $button.text('Saved');
            setTimeout(() => $button.text('Save log settings'), 1200);
        }).always(() => $button.prop('disabled', false));
    });
    $('#warmpilot-uninstall-settings').on('submit', function (e) {
        e.preventDefault();
        const $button = $(this).find('button[type="submit"]').prop('disabled', true).text('Saving…');
        $.post(WarmPilotAdmin.ajaxUrl, serializeForm($(this), 'warmpilot_save_uninstall_settings')).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not save uninstall settings.'); return; }
            $button.text('Saved');
            setTimeout(() => $button.text('Save uninstall settings'), 1200);
        }).always(() => $button.prop('disabled', false));
    });
    $('.warmpilot-start').on('click', function () {
        if (processing) return;
        setState(WarmPilotAdmin.strings.starting);
        updateManualControls('starting');
        $.post(WarmPilotAdmin.ajaxUrl, serializeForm($('#warmpilot-settings'), 'warmpilot_start')).done((res) => {
            if (!res.success) { setState(res.data?.message || 'Could not start'); updateManualControls('idle'); return; }
            jobId = Number(res.data.job_id); localStorage.setItem('warmpilotJobId', jobId); processing = false; processNext();
        });
    });
    $('.warmpilot-stop').on('click', function () {
        if (!jobId) return;
        updateManualControls('stopping');
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_stop', job_id: jobId }))
            .done(() => setState(WarmPilotAdmin.strings.stoppingState))
            .fail(() => updateManualControls('running'));
    });
    $('.warmpilot-reset').on('click', function () {
        if (!jobId || !confirm('Delete this report?')) return;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_reset', job_id: jobId })).done(() => {
            jobId = 0; processing = false; localStorage.removeItem('warmpilotJobId'); $tbody.empty(); $('.warmpilot-progress span').css('width', '0'); $('[data-stat]').text('0'); setState(WarmPilotAdmin.strings.idle); updateManualControls('idle');
        });
    });
    $('.warmpilot-report-prev').on('click', function () { if (reportPage > 1) { reportPage--; status(); } });
    $('.warmpilot-report-next').on('click', function () { reportPage++; status(); });
    $('.warmpilot-report-per-page').on('change', function () { perPage = Number($(this).val() || 100); reportPage = 1; status(); });
    $('.warmpilot-export').on('click', function () { if (jobId) window.location = WarmPilotAdmin.ajaxUrl + '?action=warmpilot_export_csv&job_id=' + encodeURIComponent(jobId) + '&nonce=' + encodeURIComponent(WarmPilotAdmin.nonce); });

    const $cronForm = $('#warmpilot-cron-settings');
    function toggleCustomCronFields() {
        const custom = $cronForm.find('[name="interval_key"]').val() === 'custom_cron';
        $cronForm.find('.warmpilot-custom-cron').prop('hidden', !custom);
    }
    $cronForm.on('change', '[name="interval_key"]', toggleCustomCronFields);
    toggleCustomCronFields();
    function setCronEditorMode(mode, taskName = '') {
        const editing = mode === 'edit';
        $('.warmpilot-cron-editor').toggleClass('is-editing', editing);
        $('.warmpilot-cron-editor-mode-label').text(editing ? 'Editing task: ' + taskName : 'Creating new task');
        $('.warmpilot-save-cron').text(editing ? 'Update cron task' : 'Save cron task');
        $('.warmpilot-new-cron-task').prop('hidden', !editing);
    }
    function resetCronEditor() {
        $cronForm[0].reset();
        $cronForm.find('[name="profile_id"]').val('0');
        $cronForm.find('[name^="cron_"]').val('*');
        toggleCustomCronFields();
        setCronEditorMode('new');
    }
    function fillCronEditor(data) {
        resetCronEditor();
        $cronForm.find('[name="profile_id"]').val(data.id);
        $cronForm.find('[name="profile_name"]').val(data.name);
        $cronForm.find('[name="interval_key"]').val(data.interval_key);
        if (data.cron_expression) {
            const parts = String(data.cron_expression).trim().split(/\s+/);
            const names = ['cron_minute','cron_hour','cron_day','cron_month','cron_weekday'];
            names.forEach((name, i) => $cronForm.find('[name="' + name + '"]').val(parts[i] || '*'));
        }
        toggleCustomCronFields();
        Object.entries(data.settings || {}).forEach(([key, value]) => {
            const $field = $cronForm.find('[name="' + key + '"]');
            if (!$field.length) return;
            if ($field.attr('type') === 'checkbox') $field.prop('checked', Number(value) === 1);
            else $field.val(value);
        });
        setCronEditorMode('edit', data.name || ('#' + data.id));
        activateTab('cron');
        window.scrollTo({ top: 0, behavior: 'auto' });
    }

    $cronForm.on('submit', function (e) {
        e.preventDefault();
        const $button = $('.warmpilot-save-cron').prop('disabled', true).text('Saving…');
        $.post(WarmPilotAdmin.ajaxUrl, serializeForm($cronForm, 'warmpilot_save_cron_profile')).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not save cron task.'); return; }
            window.location.hash = 'warmpilot-cron-tab'; window.location.reload();
        }).always(() => $button.prop('disabled', false));
    });
    $('.warmpilot-new-cron-task').on('click', function () { resetCronEditor(); $cronForm.find('[name="profile_name"]').trigger('focus'); });
    $('.warmpilot-edit-cron').on('click', function () {
        const id = $(this).closest('tr').data('profile-id');
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_get_cron_profile', profile_id: id })).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not load task.'); return; }
            fillCronEditor(res.data);
        });
    });
    $('.warmpilot-toggle-cron').on('click', function () {
        const $button = $(this), id = $button.closest('tr').data('profile-id');
        const enabling = Number($button.data('enabled')) !== 1;
        $button.prop('disabled', true).text(enabling ? 'Enabling…' : 'Disabling…');
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_toggle_cron_profile', profile_id: id })).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not change task state.'); return; }
            window.location.hash = 'warmpilot-cron-tab';
            window.location.reload();
        }).always(() => $button.prop('disabled', false));
    });

    $('.warmpilot-delete-cron').on('click', function () {
        if (!confirm('Delete this cron task?')) return;
        const id = $(this).closest('tr').data('profile-id');
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_delete_cron_profile', profile_id: id })).done((res) => { if (res.success) window.location.reload(); });
    });

    function renderJobLogs(logs) {
        $('.warmpilot-logs-table tbody').html((logs || []).map((row) => {
            const id = Number(row.id || 0);
            const typeKey = String(row.type_key || 'manual');
            const typeLabel = String(row.type || WarmPilotAdmin.strings.manual);
            const running = String(row.status || '') === 'running';
            return '<tr data-job-id="' + id + '">' +
                '<td><span class="warmpilot-job-type warmpilot-job-type-' + esc(typeKey) + '">' + esc(typeLabel) + '</span></td>' +
                '<td>' + esc(row.task || '—') + '</td><td>#' + id + '</td>' +
                '<td>' + esc(row.started_at || '—') + '</td><td>' + esc(row.finished_at || '—') + '</td>' +
                '<td>' + esc(row.status_label || row.status || '') + '</td><td>' + Number(row.total || 0) + '</td>' +
                '<td>' + Number(row.successful || 0) + '</td><td>' + Number(row.failed || 0) + '</td>' +
                '<td class="warmpilot-actions-col"><div class="warmpilot-row-actions warmpilot-job-log-actions">' +
                '<button type="button" class="button warmpilot-view-job-log">' + esc(WarmPilotAdmin.strings.viewLog) + '</button>' +
                '<button type="button" class="button warmpilot-view-job-success">' + esc(WarmPilotAdmin.strings.success) + '</button>' +
                '<button type="button" class="button warmpilot-view-job-errors">' + esc(WarmPilotAdmin.strings.errorsLabel) + '</button>' +
                '<button type="button" class="button warmpilot-export-job-log">' + esc(WarmPilotAdmin.strings.csv) + '</button>' +
                '<button type="button" class="button button-link-delete warmpilot-delete-job-log"' + (running ? ' disabled' : '') + '>' + esc(WarmPilotAdmin.strings.delete) + '</button>' +
                '</div></td></tr>';
        }).join(''));
    }

    function loadJobLogs() {
        if (jobLogsLoading) return;
        jobLogsLoading = true;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_job_logs' }))
            .done((res) => {
                if (res.success) renderJobLogs(res.data?.logs || []);
            })
            .always(() => { jobLogsLoading = false; });
    }

    function renderLog(data) {
        $('.warmpilot-log-job-title').text('· Job #' + Number(data.job_id || logJobId));
        $('.warmpilot-log-viewer h2').contents().first()[0].textContent = 'Run log ';
        $('.warmpilot-log-description').text(
            logMode === 'errors'
                ? 'Filtered view: only failed and skipped URL records are shown. The table may be empty when this job has no errors.'
                : (logMode === 'success'
                    ? 'Filtered view: only successful URL records are shown. The table may be empty when this job has no successful records.'
                    : 'Read-only report. Opening this log does not change or control the current manual warming job.')
        );
        $('[data-log-stat]').each(function () {
            const key = $(this).data('log-stat');
            $(this).text(data[key] ?? 0);
        });
        $('.warmpilot-log-progress span').css('width', Math.min(100, Number(data.progress || 0)) + '%');
        $('.warmpilot-log-progress-meta').text(
            'Status: ' + String(data.status || 'unknown') + ' · Known queue: ' + Number(data.processed || 0) +
            ' processed / ' + Number(data.known_total || data.total || 0) + ' discovered · ' +
            Number(data.remaining || 0) + ' remaining.'
        );
        logPage = Number(data.report_page || logPage || 1);
        const pages = Number(data.report_pages || 1);
        $('.warmpilot-log-page').text(formatPageOfRows(logPage, pages, data.report_total));
        $('.warmpilot-log-prev').prop('disabled', logPage <= 1);
        $('.warmpilot-log-next').prop('disabled', logPage >= pages);
        $('#warmpilot-log-results tbody').html((data.items || []).map((row) => {
            const codeOrError = row.status === 'skipped' || row.status === 'failed' ? (row.error_text || row.response_code || '') : (row.response_code || '');
            return '<tr class="warmpilot-' + esc(row.status) + '">' +
                '<td>' + esc(row.processed_at || '') + '</td><td>' + esc(row.depth) + '</td><td>' + esc(row.item_type) + '</td>' +
                '<td class="warmpilot-url"><a href="' + esc(row.url) + '" target="_blank" rel="noopener">' + esc(row.url) + '</a></td>' +
                '<td>' + esc(row.verify_time || '') + '</td><td>' + esc(codeOrError) + '</td><td>' + esc(row.content_type || '') + '</td>' +
                '<td>' + esc(row.cache_headers || row.cf_cache_status || '').replace(/\n/g, '<br>') + '</td></tr>';
        }).join(''));
    }

    function loadLog(silent = false) {
        if (!logJobId || logLoading) return;
        logLoading = true;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_status', job_id: logJobId, report_page: logPage, per_page: logPerPage, errors_only: logMode === 'errors' ? 1 : 0, success_only: logMode === 'success' ? 1 : 0 }))
            .done((res) => {
                if (!res.success) {
                    if (!silent) alert(res.data?.message || 'Could not load log.');
                    return;
                }
                renderLog(res.data);
            })
            .always(() => { logLoading = false; });
    }

    function openJobLog(id, mode) {
        if (!id) return;
        logJobId = Number(id);
        logMode = mode === 'errors' ? 'errors' : (mode === 'success' ? 'success' : 'all');
        logPage = 1;
        sessionStorage.setItem('warmpilotLogJobId', String(logJobId));
        sessionStorage.setItem('warmpilotLogMode', logMode);
        $('.warmpilot-log-viewer').prop('hidden', false);
        activateTab('log');
        loadLog();
        startLogAutoRefresh();
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    }

    $(document).on('click', '.warmpilot-view-job-log', function () {
        openJobLog(Number($(this).closest('tr').data('job-id')), 'all');
    });
    $(document).on('click', '.warmpilot-view-job-success', function () {
        // Use the normal Run log viewer with a successful-only filter. Even when
        // there are no successful rows, the viewer still opens and shows 0 rows.
        openJobLog(Number($(this).closest('tr').data('job-id')), 'success');
    });
    $(document).on('click', '.warmpilot-view-job-errors', function () {
        // Use the normal Run log viewer with an errors-only filter. Even when
        // there are no failed/skipped rows, the viewer still opens and shows 0 rows.
        openJobLog(Number($(this).closest('tr').data('job-id')), 'errors');
    });
    $('.warmpilot-log-close').on('click', function () {
        closeOpenJobLog();
        activateTab('log');
    });
    $('.warmpilot-log-export').on('click', function () {
        if (logJobId) window.location = WarmPilotAdmin.ajaxUrl + '?action=warmpilot_export_csv&job_id=' + encodeURIComponent(logJobId) + '&errors_only=' + (logMode === 'errors' ? '1' : '0') + '&success_only=' + (logMode === 'success' ? '1' : '0') + '&nonce=' + encodeURIComponent(WarmPilotAdmin.nonce);
    });
    $('.warmpilot-log-prev').on('click', function () { if (logPage > 1) { logPage--; loadLog(); } });
    $('.warmpilot-log-next').on('click', function () { logPage++; loadLog(); });
    $('.warmpilot-log-per-page').on('change', function () { logPerPage = Number($(this).val() || 100); logPage = 1; loadLog(); });
    $('.warmpilot-export-job-log').on('click', function () {
        const id = Number($(this).closest('tr').data('job-id'));
        if (id) window.location = WarmPilotAdmin.ajaxUrl + '?action=warmpilot_export_csv&job_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(WarmPilotAdmin.nonce);
    });
    $('.warmpilot-delete-job-log').on('click', function () {
        if (!confirm('Delete this run log and all of its URL rows?')) return;
        const id = Number($(this).closest('tr').data('job-id'));
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_delete_job_log', job_id: id })).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not delete log.'); return; }
            window.location.reload();
        });
    });
    let cronTransitionTimer = null;
    let cronTransitionRequest = false;

    function hasCronTransitionState() {
        return $('.warmpilot-cron-table tbody tr').filter(function () {
            const state = String($(this).attr('data-task-status') || '').toLowerCase();
            const buttonText = String($(this).find('.warmpilot-run-cron, .warmpilot-stop-cron').first().text() || '').toLowerCase();
            return state === 'starting' || state === 'stopping' || buttonText.includes('starting') || buttonText.includes('stopping');
        }).length > 0;
    }

    function stopCronTransitionPolling() {
        if (cronTransitionTimer) clearTimeout(cronTransitionTimer);
        cronTransitionTimer = null;
    }

    function scheduleCronTransitionPolling(delay = 5000) {
        stopCronTransitionPolling();
        if (!hasCronTransitionState()) return;
        cronTransitionTimer = setTimeout(pollCronTransitionStates, delay);
    }

    function pollCronTransitionStates() {
        if (cronTransitionRequest || !hasCronTransitionState()) return;
        if (document.hidden) {
            scheduleCronTransitionPolling(5000);
            return;
        }
        cronTransitionRequest = true;
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_cron_profiles_status' }))
            .done((res) => {
                if (!res.success) return;
                let reloadNeeded = false;
                (res.data?.profiles || []).forEach((profile) => {
                    const $row = $('.warmpilot-cron-table tbody tr[data-profile-id="' + Number(profile.profile_id) + '"]');
                    if (!$row.length) return;
                    const oldState = String($row.attr('data-task-status') || '').toLowerCase();
                    const newState = String(profile.status || '').toLowerCase();
                    if (oldState !== newState) reloadNeeded = true;
                });
                if (reloadNeeded) {
                    window.location.hash = 'warmpilot-cron-tab';
                    window.location.reload();
                    return;
                }
            })
            .always(() => {
                cronTransitionRequest = false;
                scheduleCronTransitionPolling(5000);
            });
    }

    $('.warmpilot-delete-profile-logs').on('click', function () {
        if (!confirm('Delete all completed logs for this cron task?')) return;
        const id = Number($(this).closest('tr').data('profile-id'));
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_delete_profile_logs', profile_id: id })).done((res) => {
            if (!res.success) { alert(res.data?.message || 'Could not delete logs.'); return; }
            window.location.reload();
        });
    });

    $('.warmpilot-run-cron').on('click', function () {
        const $button = $(this), $row = $button.closest('tr'), id = $row.data('profile-id');
        $row.attr('data-task-status', 'starting');
        $row.find('.warmpilot-task-status').attr('class', 'warmpilot-task-status warmpilot-task-status-starting').text(WarmPilotAdmin.strings.starting);
        $button.prop('disabled', true).text(WarmPilotAdmin.strings.starting);
        scheduleCronTransitionPolling(5000);
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_run_cron_profile', profile_id: id })).done((res) => {
            if (!res.success) {
                $row.attr('data-task-status', 'idle');
                alert(res.data?.message || 'Could not start task.');
                window.location.hash = 'warmpilot-cron-tab';
                window.location.reload();
            }
        });
    });

    $('.warmpilot-stop-cron').on('click', function () {
        if (!confirm('Stop the currently running job for this cron task?')) return;
        const $button = $(this), $row = $button.closest('tr'), id = $row.data('profile-id');
        $row.attr('data-task-status', 'stopping');
        const activeJobId = $row.find('.warmpilot-task-status').data('active-job-id');
        const jobSuffix = activeJobId ? ' · ' + WarmPilotAdmin.strings.jobHash.replace('%d', activeJobId) : '…';
        $row.find('.warmpilot-task-status').attr('class', 'warmpilot-task-status warmpilot-task-status-stopping').text(WarmPilotAdmin.strings.stoppingState + jobSuffix);
        $button.prop('disabled', true).text(WarmPilotAdmin.strings.stopping);
        scheduleCronTransitionPolling(5000);
        $.post(WarmPilotAdmin.ajaxUrl, payload({ action: 'warmpilot_stop_cron_profile', profile_id: id })).done((res) => {
            if (!res.success) {
                alert(res.data?.message || 'Could not stop task.');
                window.location.hash = 'warmpilot-cron-tab';
                window.location.reload();
            }
        });
    });

    if (jobId) { updateManualControls('running'); status(); setTimeout(processNext, 300); } else { updateManualControls('idle'); }
    scheduleCronTransitionPolling(5000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && hasCronTransitionState()) scheduleCronTransitionPolling(0);
    });
    // Job log viewers are intentionally not restored automatically. The user
    // must click View log or Errors after returning to the Job Logs tab.
});
