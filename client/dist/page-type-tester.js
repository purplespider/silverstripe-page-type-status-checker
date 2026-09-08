/*
 * Page Type Status Checker
 *
 * Configuration comes from the JSON payload rendered by HtmlReport, so no server
 * data is interpolated into this file. Clicks are handled by one delegated listener
 * at the bottom, keyed off data-ptl-action.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('ptl-config');
    if (!configEl) {
        return;
    }

    var config = JSON.parse(configEl.textContent);

    // Checks to run at once. Set via HtmlReport.check_concurrency.
    var CONCURRENCY = config.concurrency > 0 ? config.concurrency : 6;

    var state = {
        checking: false,
        stopRequested: false,
        hoveringStop: false,
        progressText: '',
        notLoggedIn: false,
        checksHaveRun: false,
        checksIncludedActions: false,
        totals: { passed: 0, failed: 0, manual: 0, login: 0 }
    };

    var rows = config.rows.slice();
    var adminSections = config.adminSections.slice();
    var adminEditLinks = config.adminEditLinks.slice();
    var createdPageIds = (config.createdPageIds || []).slice();

    /* helpers */

    function icon(name, className) {
        return '<svg class="ptl-icon ' + (className || '') + '" aria-hidden="true" focusable="false">'
            + '<use href="#ptl-i-' + name + '"></use></svg>';
    }

    function el(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function stripTrailingSlash(value) {
        return value.replace(/\/$/, '');
    }

    /* requesting */

    /**
     * Redirects are not followed, so a page that redirects reports its own status
     * rather than the status of wherever it points. This matches the CLI report.
     */
    async function checkLink(url, wantHtml) {
        try {
            var response = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                redirect: 'manual'
            });

            var status = response.status;
            if (response.type === 'opaqueredirect' || (status === 0 && response.type !== 'error')) {
                status = 302;
            }

            var result = { status: status, loginRequired: false, html: '' };
            if (wantHtml && response.status === 200) {
                result.html = await response.text();
            }

            return result;
        } catch (e) {
            return { status: 0, loginRequired: false, html: '' };
        }
    }

    /**
     * A redirect on a CMS URL is almost always the login screen. Confirm by following
     * it once and seeing where it lands; after the first confirmation the remaining
     * CMS URLs are marked without being requested, since they would all do the same.
     */
    async function isLoginRedirect(url) {
        try {
            var response = await fetch(url, { method: 'GET', credentials: 'include', redirect: 'follow' });
            return !!response.url && response.url.indexOf(config.loginPath) !== -1;
        } catch (e) {
            return false;
        }
    }

    async function checkCmsLink(url) {
        if (state.notLoggedIn) {
            return { status: 302, loginRequired: true, html: '' };
        }

        var result = await checkLink(url, false);

        if (result.status >= 300 && result.status < 400 && await isLoginRedirect(url)) {
            markNotLoggedIn();
            result.loginRequired = true;
        }

        return result;
    }

    function markNotLoggedIn() {
        state.notLoggedIn = true;
        var banner = el('ptl-login-banner');
        if (banner) {
            banner.classList.add('ptl-visible');
        }
    }

    /* badges */

    function statusBadge(result, expected, target) {
        var label;
        var className;
        var glyph;

        if (result.loginRequired) {
            label = 'login';
            className = 'ptl-status-login';
            glyph = icon('lock');
        } else if (expected.indexOf(result.status) !== -1) {
            label = String(result.status);
            className = 'ptl-status-pass';
            glyph = icon('check');
        } else if (result.status >= 300 && result.status < 400) {
            label = String(result.status);
            className = 'ptl-status-redirect';
            glyph = icon('cross');
        } else {
            label = result.status === 0 ? 'ERR' : String(result.status);
            className = 'ptl-status-fail';
            glyph = icon('cross');
        }

        var description = result.loginRequired
            ? 'Redirected to the CMS login page. Log in, then re-check.'
            : 'Responded with ' + label + '.';

        return '<button type="button" class="ptl-status-badge ' + className + '"'
            + ' data-ptl-action="recheck" data-ptl-target="' + escapeHtml(target) + '"'
            + ' aria-label="' + escapeHtml(description + ' Activate to re-check.') + '"'
            + ' title="' + escapeHtml(description + ' Click to re-check.') + '">'
            + escapeHtml(label) + glyph + '</button>';
    }

    function placeholder(text) {
        return '<span class="ptl-status-placeholder">' + escapeHtml(text) + '</span>';
    }

    /* counting */

    function resetTotals() {
        state.totals = { passed: 0, failed: 0, manual: 0, login: 0 };
    }

    function recordResult(result, expected) {
        if (result.loginRequired) {
            state.totals.login++;
        } else if (expected.indexOf(result.status) !== -1) {
            state.totals.passed++;
        } else {
            state.totals.failed++;
        }
    }

    function updateSummary(stopped) {
        var summary = el('ptl-summary');
        if (!summary) {
            return;
        }

        var t = state.totals;
        var parts = [];
        var background;
        var colour;
        var lead;

        if (stopped) {
            lead = icon('warning') + ' Stopped: ' + t.failed + ' failed, ' + t.passed + ' passed';
            background = '#fff3cd';
            colour = '#6b5203';
        } else if (t.failed === 0 && t.login > 0) {
            lead = icon('lock') + ' ' + t.login + ' need login, ' + t.passed + ' passed';
            background = '#fff3cd';
            colour = '#6b5203';
        } else if (t.failed === 0) {
            lead = icon('check') + ' ' + t.passed + ' passed';
            background = '#d1e7dd';
            colour = '#0a3622';
        } else {
            lead = icon('cross') + ' ' + t.failed + ' failed, ' + t.passed + ' passed';
            background = '#f8d7da';
            colour = '#6a1a21';
        }

        if (t.manual > 0) {
            parts.push(t.manual + ' manual');
        }
        if (t.login > 0 && !(t.failed === 0 && t.login > 0) && !stopped) {
            parts.push(t.login + ' need login');
        }

        summary.style.background = background;
        summary.innerHTML = '<span style="color:' + colour + ';">' + lead
            + (parts.length ? ', ' + parts.join(', ') : '') + '</span>';
    }

    /* concurrency */

    /**
     * Runs the given thunks with a bounded number in flight, stopping early if the
     * user asks it to.
     */
    async function runPool(tasks, limit, onEach) {
        var next = 0;

        async function worker() {
            while (next < tasks.length && !state.stopRequested) {
                var index = next++;
                await tasks[index]();
                if (onEach) {
                    onEach();
                }
            }
        }

        var workerCount = Math.min(limit, tasks.length);
        var workers = [];
        for (var i = 0; i < workerCount; i++) {
            workers.push(worker());
        }

        await Promise.all(workers);
    }

    /* forms */

    function detectForms(html) {
        var cleaned = html
            .replace(/<header[^>]*>[\s\S]*?<\/header>/gi, '')
            .replace(/<footer[^>]*>[\s\S]*?<\/footer>/gi, '');

        var matches = cleaned.match(/<form[^>]*>/gi);
        if (!matches) {
            return 0;
        }

        return matches.filter(function (tag) {
            // The CMS preview toolbar injects its own form, which is not page content.
            return tag.indexOf('BetterNavigator') === -1;
        }).length;
    }

    /* actions */

    /**
     * Mirrors ActionLinkFinder in PHP: a controller action lives directly beneath the
     * page that owns it, so only links under this page's own path are considered.
     */
    function findActionLinks(html, actions, pageUrl) {
        var found = {};
        var hrefPattern = /href=["']([^"']+)["']/gi;
        var links = [];
        var match;

        while ((match = hrefPattern.exec(html)) !== null) {
            links.push(match[1]);
        }

        var pagePath = stripTrailingSlash(new URL(pageUrl, config.baseUrl).pathname);

        actions.forEach(function (action) {
            var expectedPath = (pagePath + '/' + action).toLowerCase();

            for (var i = 0; i < links.length; i++) {
                var absolute = toAbsolute(links[i], pageUrl);
                if (!absolute) {
                    continue;
                }

                var path = stripTrailingSlash(absolute.pathname).toLowerCase();
                if (path === expectedPath || path.indexOf(expectedPath + '/') === 0) {
                    found[action] = absolute.href;
                    break;
                }
            }

            if (!found[action] && config.directActions.indexOf(action.toLowerCase()) !== -1) {
                found[action] = stripTrailingSlash(pageUrl) + '/' + action;
            }
        });

        return found;
    }

    function toAbsolute(link, pageUrl) {
        if (link.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(link)) {
            return null;
        }

        try {
            return new URL(link, pageUrl);
        } catch (e) {
            return null;
        }
    }

    function renderActionLinks(row, foundLinks) {
        var container = el('actions-container-' + row.index);
        if (!container) {
            return;
        }

        var html = '';

        row.actions.forEach(function (action) {
            if (foundLinks[action]) {
                html += '<span id="action-status-' + row.index + '-' + escapeHtml(action) + '" class="ptl-status">'
                    + placeholder('...') + '</span>'
                    + '<a href="' + escapeHtml(foundLinks[action]) + '" target="_blank" rel="noopener"'
                    + ' class="ptl-action">/' + escapeHtml(action) + '</a>';
            } else {
                html += '<span class="ptl-status"><span class="ptl-check-badge">' + icon('warning')
                    + ' check<span class="ptl-tip ptl-tip-above">No link to this action was found on the page.'
                    + ' Visit the page and check it manually.</span></span></span>'
                    + '<span class="ptl-action-missing">/' + escapeHtml(action) + '</span>';
            }
        });

        container.innerHTML = html;
    }

    function renderActionPrompt(row) {
        var container = el('actions-container-' + row.index);
        if (!container || !row.actions.length) {
            return;
        }

        var label = row.actions.length === 1
            ? '<span class="action-name">' + escapeHtml(row.actions[0]) + '</span>'
            : row.actions.length + ' actions';

        container.innerHTML = '<span class="ptl-actions-warning">'
            + '<button type="button" class="ptl-btn-actions" data-ptl-action="check-row-actions"'
            + ' data-ptl-row="' + row.index + '">' + label
            + '<span class="ptl-tip ptl-tip-above"><em>Activate to detect and test:</em><br>'
            + escapeHtml(row.actions.join(', ')) + '</span></button></span>';
    }

    async function checkAction(row, action, url) {
        var span = el('action-status-' + row.index + '-' + action);
        if (!span) {
            return false;
        }

        var result = await checkLink(url, false);
        span.innerHTML = statusBadge(result, [200], 'action:' + row.index + ':' + action);

        return result.status === 200;
    }

    /* row checks */

    async function checkCmsCell(row) {
        var span = el('cms-status-' + row.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkCmsLink(row.cmsLink);

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, [200], 'cms:' + row.index);
        recordResult(result, [200]);
    }

    async function checkFrontendCell(row, includeActions, onActionChecked) {
        var span = el('frontend-status-' + row.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkLink(row.frontendLink, true);

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, row.expected, 'frontend:' + row.index);
        recordResult(result, row.expected);

        if (result.html) {
            var formIndicator = el('form-indicator-' + row.index);
            if (formIndicator && detectForms(result.html) > 0) {
                formIndicator.innerHTML = '<span class="ptl-form-badge">' + icon('document')
                    + ' form<span class="ptl-tip ptl-tip-above">A form was detected on this page.'
                    + ' Check it manually.</span></span>';
            }
        }

        if (!row.actions.length) {
            return;
        }

        if (!includeActions || !result.html) {
            renderActionPrompt(row);
            return;
        }

        var foundLinks = findActionLinks(result.html, row.actions, row.frontendLink);
        renderActionLinks(row, foundLinks);

        for (var i = 0; i < row.actions.length; i++) {
            if (state.stopRequested) {
                return;
            }

            var action = row.actions[i];
            if (foundLinks[action]) {
                var passed = await checkAction(row, action, foundLinks[action]);
                if (passed) {
                    state.totals.passed++;
                } else {
                    state.totals.failed++;
                }
                if (onActionChecked) {
                    onActionChecked();
                }
            } else {
                state.totals.manual++;
            }
        }
    }

    async function checkAdminCell(section) {
        var span = el('admin-status-' + section.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkCmsLink(section.url);

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, [200], 'admin:' + section.index);
        recordResult(result, [200]);
    }

    async function checkAdminEditCell(link) {
        var span = el('admin-edit-status-' + link.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkCmsLink(link.url);

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, [200], 'admin-edit:' + link.index);
        recordResult(result, [200]);
    }

    /* the main run */

    async function checkAll(includeActions) {
        if (state.checking) {
            state.stopRequested = true;
            return;
        }

        var primaryBtn = el('ptl-check-actions-btn');
        var secondaryBtn = el('ptl-check-btn');
        var activeBtn = includeActions ? primaryBtn : secondaryBtn;
        var inactiveBtn = includeActions ? secondaryBtn : primaryBtn;

        state.checking = true;
        state.stopRequested = false;
        state.hoveringStop = false;
        resetTotals();

        inactiveBtn.disabled = true;
        activeBtn.classList.add('ptl-checking');

        var summary = el('ptl-summary');
        summary.style.background = '#f8f9fa';
        summary.innerHTML = '<span style="color:#495057;">' + icon('spinner', 'ptl-spin') + ' Checking...</span>';

        var pageRows = rows.filter(function (row) {
            return row.index >= 0;
        });

        if (!includeActions) {
            pageRows.forEach(function (row) {
                var container = el('actions-container-' + row.index);
                if (!container) {
                    return;
                }
                if (row.actions.length) {
                    renderActionPrompt(row);
                } else {
                    container.innerHTML = '';
                }
            });
        }

        var expectedActionCount = 0;
        if (includeActions) {
            pageRows.forEach(function (row) {
                expectedActionCount += row.actions.length;
            });
        }

        var total = pageRows.length * 2 + adminSections.length + adminEditLinks.length + expectedActionCount;
        var done = 0;

        function progress() {
            done++;
            state.progressText = icon('spinner', 'ptl-spin') + ' Checking ' + done + '/' + total + '...';
            if (!state.hoveringStop) {
                activeBtn.innerHTML = state.progressText;
            }
        }

        activeBtn.addEventListener('mouseenter', onStopHover);
        activeBtn.addEventListener('focus', onStopHover);
        activeBtn.addEventListener('mouseleave', offStopHover);
        activeBtn.addEventListener('blur', offStopHover);

        function onStopHover() {
            if (state.checking) {
                state.hoveringStop = true;
                activeBtn.innerHTML = icon('stop') + ' Stop';
            }
        }

        function offStopHover() {
            if (state.checking) {
                state.hoveringStop = false;
                activeBtn.innerHTML = state.progressText;
            }
        }

        var tasks = [];

        pageRows.forEach(function (row) {
            tasks.push(function () {
                return checkFrontendCell(row, includeActions, progress);
            });
            tasks.push(function () {
                return checkCmsCell(row);
            });
        });

        adminSections.forEach(function (section) {
            tasks.push(function () {
                return checkAdminCell(section);
            });
        });

        adminEditLinks.forEach(function (link) {
            tasks.push(function () {
                return checkAdminEditCell(link);
            });
        });

        await runPool(tasks, CONCURRENCY, progress);

        activeBtn.removeEventListener('mouseenter', onStopHover);
        activeBtn.removeEventListener('focus', onStopHover);
        activeBtn.removeEventListener('mouseleave', offStopHover);
        activeBtn.removeEventListener('blur', offStopHover);

        var stopped = state.stopRequested;

        state.checking = false;
        state.hoveringStop = false;
        state.stopRequested = false;
        state.checksHaveRun = true;
        state.checksIncludedActions = includeActions;

        inactiveBtn.disabled = false;
        activeBtn.classList.remove('ptl-checking');
        primaryBtn.innerHTML = icon('check-double') + ' Check Links &amp; Actions';
        secondaryBtn.innerHTML = icon('check') + ' Check Links Only';

        updateSummary(stopped);
    }

    /* create */

    async function createPage(button) {
        var className = button.getAttribute('data-ptl-class');
        var shortName = button.getAttribute('data-ptl-short');
        var row = button.closest('tr');
        var original = button.innerHTML;

        button.disabled = true;
        button.innerHTML = icon('spinner', 'ptl-spin') + ' Creating...';

        try {
            var body = new FormData();
            body.append('createPage', className);
            body.append(config.securityToken.name, config.securityToken.value);

            // POST with a security token: creating a page writes to the database, which
            // should not be possible by following a URL.
            var response = await fetch(config.taskUrl, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Accept': 'application/json' },
                body: body
            });

            var result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Failed');
            }

            var newRow = {
                index: rows.length ? Math.max.apply(null, rows.map(function (r) { return r.index; })) + 1 : 0,
                class: className,
                shortClass: result.shortClass,
                pageId: result.id,
                cmsLink: result.editLink,
                frontendLink: result.frontendLink,
                expected: result.expectedStatus,
                actions: result.allowedActions || []
            };
            rows.push(newRow);
            rememberCreatedPage(result.id);

            row.removeAttribute('id');
            row.innerHTML = buildRowHtml(newRow, result);

            if (state.checksHaveRun) {
                await checkFrontendCell(newRow, state.checksIncludedActions, null);
                await checkCmsCell(newRow);
                updateSummary(false);
            }
        } catch (e) {
            showButtonError(button, original, e.message);
        }
    }

    function buildRowHtml(newRow, result) {
        var actionsHtml = newRow.actions.length
            ? '<span id="actions-container-' + newRow.index + '" class="ptl-actions-container"></span>'
            : '';

        return '<td class="ptl-preview-col"><div class="ptl-preview">'
            + '<iframe title="Preview of ' + escapeHtml(result.title) + '" data-src="'
            + escapeHtml(result.frontendLink) + '"></iframe></div></td>'
            + '<td><span class="ptl-type">' + escapeHtml(newRow.shortClass) + '</span></td>'
            + '<td><span class="ptl-count">0 <span class="ptl-count-draft">+ 1</span></span></td>'
            + '<td>' + linkCell(
                '<span id="cms-status-' + newRow.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(result.editLink, config.comparing ? 'This CMS' : 'Edit in CMS',
                    config.comparing ? 'edit ' + newRow.shortClass + ' on this site' : newRow.shortClass,
                    'ptl-cms', 'desktop')
            ) + '</td>'
            + '<td>' + linkCell(
                '<span id="frontend-status-' + newRow.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(result.frontendLink, config.comparing ? 'This page' : 'View Page',
                    config.comparing ? 'view ' + newRow.shortClass + ' on this site' : newRow.shortClass,
                    'ptl-frontend', 'desktop')
                    + '<span id="form-indicator-' + newRow.index + '"></span>'
            ) + actionsHtml + '</td>'
            + '<td class="ptl-example-cell"><span class="ptl-title">' + escapeHtml(result.title)
            + deleteButtonHtml(newRow, result.title) + '</span>'
            + '<span class="ptl-url">' + escapeHtml(result.pageUrl) + '</span></td>';
    }

    /**
     * Mirrors HtmlReport::linkCell. A page that has just been created does not exist on
     * the live site yet, so a created row has no live link and nothing to compare.
     */
    function linkCell(status, localRow) {
        return '<div class="ptl-link-cell">' + status
            + '<div class="ptl-link-stack"><div class="ptl-link-row">' + localRow + '</div></div></div>';
    }

    // Mirrors HtmlReport::cellLink.
    function cellLink(url, label, description, className, iconName) {
        return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="' + className + '">'
            + icon(iconName)
            + '<span class="ptl-link-label">' + escapeHtml(label) + '</span>'
            + '<span class="ptl-sr-only"> \u2013 ' + escapeHtml(description) + '</span></a>';
    }

    // Mirrors HtmlReport::deleteButton.
    function deleteButtonHtml(row, title) {
        return '<button type="button" class="ptl-delete-btn" data-ptl-action="delete-page"'
            + ' data-ptl-page="' + row.pageId + '" data-ptl-row="' + row.index + '"'
            + ' aria-label="' + escapeHtml('Delete ' + title + ', created by this report') + '">'
            + icon('trash') + ' Delete'
            + '<span class="ptl-tip ptl-tip-above">Created by this report.'
            + ' Recoverable from the CMS archive.</span></button>';
    }

    /* delete */

    /**
     * Pages made by the Create buttons are litter once the check has run. The server
     * only accepts IDs it created this session, so this cannot reach anything else.
     */
    async function deletePage(button) {
        var pageId = parseInt(button.getAttribute('data-ptl-page'), 10);
        var rowIndex = parseInt(button.getAttribute('data-ptl-row'), 10);
        var rowEl = button.closest('tr');
        var rowData = findRow(rowIndex);
        var original = button.innerHTML;

        if (!window.confirm('Delete this page?\n\nIt was created by this report. It comes off draft and '
            + 'live, and stays recoverable from the CMS archive.')) {
            return;
        }

        button.disabled = true;
        button.innerHTML = icon('spinner', 'ptl-spin') + ' Deleting...';

        try {
            var result = await postDelete(pageId);

            if (!result.success) {
                throw new Error(result.error || 'Failed');
            }

            forgetCreatedPage(pageId);

            // Another page of this type is still there, so reload to show it as the
            // example rather than pretending the type has none.
            if (result.remaining > 0) {
                window.location.reload();
                return;
            }

            releaseRowTotals(rowEl);
            dropRow(rowIndex);

            if (rowData) {
                rowEl.innerHTML = buildEmptyRowHtml(rowData);
            } else {
                rowEl.remove();
            }

            updateSummary(false);
        } catch (e) {
            showButtonError(button, original, e.message);
        }
    }

    async function deleteAllCreated(button) {
        if (!createdPageIds.length) {
            return;
        }

        if (!window.confirm('Delete all ' + createdPageIds.length + ' page(s) created by this report?\n\n'
            + 'They come off draft and live, and stay recoverable from the CMS archive.')) {
            return;
        }

        var original = button.innerHTML;
        var ids = createdPageIds.slice();
        var failed = 0;

        button.disabled = true;

        for (var i = 0; i < ids.length; i++) {
            button.innerHTML = icon('spinner', 'ptl-spin') + ' Deleting ' + (i + 1) + '/' + ids.length + '...';

            try {
                var result = await postDelete(ids[i]);
                if (result.success) {
                    forgetCreatedPage(ids[i]);
                } else {
                    failed++;
                }
            } catch (e) {
                failed++;
            }
        }

        if (failed) {
            showButtonError(button, original, failed + ' failed');
            return;
        }

        window.location.reload();
    }

    async function postDelete(pageId) {
        var body = new FormData();
        body.append(config.deleteParam, pageId);
        body.append(config.securityToken.name, config.securityToken.value);

        // POST with a security token, for the same reason as creating: this writes to
        // the database and must not be reachable by following a URL.
        var response = await fetch(config.taskUrl, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Accept': 'application/json' },
            body: body
        });

        return await response.json();
    }

    function forgetCreatedPage(pageId) {
        var at = createdPageIds.indexOf(pageId);
        if (at !== -1) {
            createdPageIds.splice(at, 1);
        }

        updateCreatedCount();
    }

    function rememberCreatedPage(pageId) {
        if (createdPageIds.indexOf(pageId) === -1) {
            createdPageIds.push(pageId);
        }

        updateCreatedCount();
    }

    function updateCreatedCount() {
        var wrap = el('ptl-delete-created-wrap');
        var count = el('ptl-delete-created-count');
        if (!wrap || !count) {
            return;
        }

        count.textContent = createdPageIds.length;
        wrap.hidden = createdPageIds.length === 0;
    }

    /**
     * Takes a row's results back out of the running totals before its cells are
     * replaced, so the summary still matches what is on screen.
     */
    function releaseRowTotals(row) {
        row.querySelectorAll('.ptl-status-badge').forEach(function (badge) {
            if (badge.classList.contains('ptl-status-pass')) {
                state.totals.passed = Math.max(0, state.totals.passed - 1);
            } else if (badge.classList.contains('ptl-status-login')) {
                state.totals.login = Math.max(0, state.totals.login - 1);
            } else {
                state.totals.failed = Math.max(0, state.totals.failed - 1);
            }
        });

        row.querySelectorAll('.ptl-check-badge').forEach(function () {
            state.totals.manual = Math.max(0, state.totals.manual - 1);
        });
    }

    function dropRow(index) {
        rows = rows.filter(function (row) {
            return row.index !== index;
        });
    }

    /**
     * Mirrors HtmlReport::emptyRow, so a row whose only page has just been deleted goes
     * back to offering to create one.
     */
    function buildEmptyRowHtml(row) {
        var note = row.actions && row.actions.length
            ? '<div class="ptl-action-note">Has actions: ' + escapeHtml(row.actions.join(', ')) + '</div>'
            : '';

        return '<td class="ptl-preview-col"><div class="ptl-preview-empty">No preview</div></td>'
            + '<td><span class="ptl-type">' + escapeHtml(row.shortClass) + '</span></td>'
            + '<td><span class="ptl-count">0</span></td>'
            + '<td colspan="3" style="text-align:center;">'
            + '<button type="button" class="ptl-create-btn" data-ptl-action="create-page" data-ptl-class="'
            + escapeHtml(row.class) + '" data-ptl-short="' + escapeHtml(row.shortClass) + '">'
            + icon('plus') + ' Create ' + escapeHtml(row.shortClass) + '</button>' + note + '</td>';
    }

    function showButtonError(button, original, message) {
        button.classList.add('ptl-btn-error');
        button.innerHTML = icon('cross') + ' ' + escapeHtml(message || 'Error');

        setTimeout(function () {
            button.classList.remove('ptl-btn-error');
            button.innerHTML = original;
            button.disabled = false;
        }, 4000);
    }

    /* compare */

    /**
     * Opens the local and live version of the same thing in windows side by side.
     *
     * Windows rather than frames: Silverstripe sends X-Frame-Options SAMEORIGIN on the
     * admin, and plenty of sites send it for every response, so an embedded live pane
     * is usually blank. A window is a top-level browsing context, so framing rules do
     * not apply, the live CMS stays logged in, and the page renders exactly as it does
     * normally - which is the point of comparing.
     */
    function openCompare(button) {
        var original = button.innerHTML;

        // Half the screen each. Browsers that ignore the position open ordinary tabs,
        // which is still both pages, just not arranged.
        var width = Math.floor(screen.availWidth / 2);
        var height = screen.availHeight;
        var left = screen.availLeft || 0;
        var top = screen.availTop || 0;

        // Named, so using Compare again reuses the same two windows rather than
        // leaving a trail of them behind.
        var local = window.open(
            button.getAttribute('data-ptl-local'),
            'ptl-compare-local',
            windowFeatures(width, height, left, top)
        );
        var live = window.open(
            button.getAttribute('data-ptl-live'),
            'ptl-compare-live',
            windowFeatures(width, height, left + width, top)
        );

        if (!local || !live) {
            showButtonError(button, original, 'Pop-ups blocked');
            return;
        }

        live.focus();
    }

    function windowFeatures(width, height, left, top) {
        return 'popup=yes,noopener=no,width=' + width + ',height=' + height
            + ',left=' + left + ',top=' + top;
    }

    /* rechecks */

    async function recheck(target) {
        var parts = target.split(':');
        var kind = parts[0];
        var index = parseInt(parts[1], 10);

        // A manual re-check usually means somebody has just logged in, so probe again
        // rather than assuming the earlier result still holds.
        state.notLoggedIn = false;

        if (kind === 'cms') {
            await checkCmsCell(findRow(index));
        } else if (kind === 'frontend') {
            await checkFrontendCell(findRow(index), false, null);
        } else if (kind === 'admin') {
            await checkAdminCell(adminSections[index]);
        } else if (kind === 'admin-edit') {
            await checkAdminEditCell(adminEditLinks[index]);
        } else if (kind === 'action') {
            var row = findRow(index);
            var action = parts[2];
            var link = document.querySelector('#action-status-' + index + '-' + action + ' + a');
            if (row && link) {
                await checkAction(row, action, link.getAttribute('href'));
            }
        }

        updateSummary(false);
    }

    function findRow(index) {
        return rows.filter(function (row) {
            return row.index === index;
        })[0];
    }

    /* view options */

    var previewsLoaded = false;

    function togglePreviews(button) {
        var tables = document.querySelectorAll('.ptl-table');
        var showing = !document.body.classList.contains('ptl-previews-on');

        document.body.classList.toggle('ptl-previews-on', showing);
        tables.forEach(function (table) {
            table.classList.toggle('ptl-previews-visible', showing);
        });

        button.setAttribute('aria-pressed', showing ? 'true' : 'false');
        button.innerHTML = showing
            ? icon('eye-slash') + ' Hide Previews'
            : icon('eye') + ' Show Previews';

        if (showing && !previewsLoaded) {
            document.querySelectorAll('.ptl-preview iframe[data-src]').forEach(function (iframe) {
                iframe.src = iframe.getAttribute('data-src');
            });
            previewsLoaded = true;
        }
    }

    function setParam(name, value) {
        var url = new URL(window.location.href);
        if (value) {
            url.searchParams.set(name, value);
        } else {
            url.searchParams.delete(name);
        }
        window.location.href = url.toString();
    }

    function goToLogin() {
        var separator = config.loginUrl.indexOf('?') === -1 ? '?' : '&';
        window.location.href = config.loginUrl + separator + 'BackURL=' + encodeURIComponent(window.location.href);
    }

    /* wiring */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-ptl-action]');
        if (!trigger) {
            return;
        }

        var action = trigger.getAttribute('data-ptl-action');

        switch (action) {
            case 'check':
                checkAll(trigger.getAttribute('data-ptl-with-actions') === '1');
                break;
            case 'recheck':
                recheck(trigger.getAttribute('data-ptl-target'));
                break;
            case 'check-row-actions':
                checkRowActions(parseInt(trigger.getAttribute('data-ptl-row'), 10));
                break;
            case 'create-page':
                createPage(trigger);
                break;
            case 'delete-page':
                deletePage(trigger);
                break;
            case 'delete-all-created':
                deleteAllCreated(trigger);
                break;
            case 'compare':
                openCompare(trigger);
                break;
            case 'toggle-previews':
                togglePreviews(trigger);
                break;
            case 'open-all':
                openAll(trigger.getAttribute('data-ptl-links'));
                break;
            case 'randomise':
                setParam('randomise', '1');
                break;
            case 'reset':
                setParam('randomise', '');
                break;
            case 'set-live-domain':
                setParam('live-domain', el('ptl-live-domain-input').value.trim());
                break;
            case 'clear-live-domain':
                setParam('live-domain', '');
                break;
            case 'login':
                goToLogin();
                break;
            case 'reload':
                window.location.reload();
                break;
        }
    });

    function openAll(which) {
        var links = which === 'cms'
            ? rows.filter(function (r) { return r.index >= 0; }).map(function (r) { return r.cmsLink; })
            : rows.filter(function (r) { return r.index >= 0; }).map(function (r) { return r.frontendLink; });

        links.forEach(function (url) {
            window.open(url, '_blank', 'noopener');
        });
    }

    async function checkRowActions(index) {
        var row = findRow(index);
        if (!row || !row.actions.length) {
            return;
        }

        var container = el('actions-container-' + index);
        container.innerHTML = placeholder('...');

        var result = await checkLink(row.frontendLink, true);
        if (!result.html) {
            container.innerHTML = '<span class="ptl-check-badge">' + icon('warning')
                + ' could not load page</span>';
            return;
        }

        var foundLinks = findActionLinks(result.html, row.actions, row.frontendLink);
        renderActionLinks(row, foundLinks);

        for (var i = 0; i < row.actions.length; i++) {
            var action = row.actions[i];
            if (foundLinks[action]) {
                var passed = await checkAction(row, action, foundLinks[action]);
                if (passed) {
                    state.totals.passed++;
                } else {
                    state.totals.failed++;
                }
            } else {
                state.totals.manual++;
            }
        }

        updateSummary(false);
    }

    // Start the full check automatically, as the point of opening the page is to see
    // the results. The primary button doubles as a stop control while it runs.
    checkAll(true);
})();
