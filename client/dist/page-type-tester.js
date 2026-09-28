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
        liveDomain: config.liveDomain || '',
        totals: { passed: 0, failed: 0, manual: 0, login: 0 }
    };

    var rows = config.rows.slice();
    var blocks = (config.blocks || []).slice();
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
     *
     * wantErrorBody keeps the body of an error response, for endpoints that say what
     * went wrong in it.
     */
    async function checkLink(url, wantHtml, wantErrorBody) {
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

            var result = {
                status: status,
                loginRequired: false,
                html: '',
                body: '',
                contentType: (response.headers.get('Content-Type') || '').toLowerCase()
            };
            if (wantHtml && response.status === 200) {
                result.html = await response.text();
            }
            if (wantErrorBody && response.status >= 400) {
                result.body = await response.text();
            }

            return result;
        } catch (e) {
            return { status: 0, loginRequired: false, html: '', body: '', contentType: '' };
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

    async function checkCmsLink(url, wantErrorBody, wantHtml) {
        if (state.notLoggedIn) {
            return { status: 302, loginRequired: true, html: '', body: '', contentType: '' };
        }

        var result = await checkLink(url, wantHtml, wantErrorBody);

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
        } else if (result.problem && expected.indexOf(result.status) !== -1) {
            // The right status, but the content shows it did not really work.
            label = String(result.status);
            className = 'ptl-status-fail';
            glyph = icon('warning');
        } else if (expected.indexOf(result.status) !== -1) {
            label = String(result.status);
            className = 'ptl-status-pass';
            glyph = icon('check');
        } else if (result.status >= 300 && result.status < 400) {
            label = String(result.status);
            className = 'ptl-status-redirect';
            glyph = icon('cross');
        } else {
            label = statusLabel(result.status);
            className = 'ptl-status-fail';
            glyph = icon('cross');
        }

        // A note says more than the status alone, such as which request failed or the
        // error it gave.
        var explanation = result.loginRequired ? '' : result.note || problemNote(result);
        var description = result.loginRequired
            ? 'Redirected to the CMS login page. Log in, then re-check.'
            : explanation || 'Responded with ' + label + '.';

        // An explanation is why a badge is not what its status suggests, such as a red
        // 200, so it gets a tip that shows at once and on focus. A title would be slow
        // to appear and never shows for the keyboard.
        var tip = explanation ? tipHtml(description + ' Click to re-check.') : '';

        return '<button type="button" class="ptl-status-badge ' + className + '"'
            + ' data-ptl-action="recheck" data-ptl-target="' + escapeHtml(target) + '"'
            + ' aria-label="' + escapeHtml(description + ' Activate to re-check.') + '"'
            + (tip ? '' : ' title="' + escapeHtml(description + ' Click to re-check.') + '"') + '>'
            + escapeHtml(label) + glyph + tip + '</button>';
    }

    // Hidden from assistive technology, which gets the same text from the label.
    function tipHtml(text) {
        return '<span class="ptl-tip ptl-tip-above ptl-tip-text" aria-hidden="true">' + escapeHtml(text) + '</span>';
    }

    function problemNote(result) {
        if (!result.problem) {
            return '';
        }

        return 'Responded with ' + statusLabel(result.status) + ', but '
            + result.problem.charAt(0).toLowerCase() + result.problem.slice(1) + '.';
    }

    function statusLabel(status) {
        return status === 0 ? 'ERR' : String(status);
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
        } else if (expected.indexOf(result.status) !== -1 && !result.problem) {
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

    /* content */

    /**
     * Mirrors ContentProblemFinder in PHP: signs in a 200 response that it did not
     * really work. Returns a description of the first problem found, or ''.
     *
     * isDocument is true for a whole page, which should also have a title and end
     * properly. Actions and blocks are only searched for error output and unrendered
     * code.
     */
    function findContentProblem(result, isDocument) {
        if (result.status !== 200 || !result.html || result.contentType.indexOf('html') === -1) {
            return '';
        }

        return errorOutput(result.html)
            || unrenderedCode(result.html)
            || (isDocument ? documentProblem(result.html) : '');
    }

    function errorOutput(html) {
        // Silverstripe's own error view, e.g. <h1>[Warning] Undefined variable $x</h1>.
        var match = html.match(/<div class="header info [a-z]+"><h1>\[([^\]]+)\]\s*([\s\S]*?)<\/h1>/);
        if (match) {
            return 'Shows a PHP ' + match[1] + ': ' + excerpt(match[2]);
        }

        // Debug::message() and Debug::show() left in the code.
        match = html.match(/<b>Debug \(line (\d+) of ([^)]+)\):<\/b>/);
        if (match) {
            return 'Shows debug output from ' + match[2].split(/[\\/]/).pop() + ' line ' + match[1];
        }

        // PHP's own error display, with and without html_errors.
        match = html.match(/(?:<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated)(?:<\/b>)?:\s+(.+?) in (?:<b>)?\S+?(?:<\/b>)? on line (?:<b>)?\d+/);
        if (match) {
            return 'Shows a PHP ' + match[1] + ': ' + excerpt(match[2]);
        }

        return '';
    }

    function unrenderedCode(html) {
        // Scripts, styles, comments and code samples are expected to hold template
        // syntax, and would only be a false alarm.
        var markup = html
            .replace(/<!--[\s\S]*?-->/g, '')
            .replace(/<(script|style|template|textarea|pre|code)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '');

        // Template control blocks, raw or escaped: <% if $Foo %>, &lt;% loop %&gt;.
        var match = markup.match(/(?:<|&lt;)%-?\s*(?:if|else_if|else|end_[a-z]+|loop|with|include|require|base_tag|cached|uncached|_t)\b[\s\S]*?%(?:>|&gt;)/);
        if (match) {
            return 'Shows unrendered template code: ' + excerpt(match[0]);
        }

        // A bare $Title is left out: it also matches prices and copy like "$Millions".
        match = markup.match(/\{\$[A-Z]\w*(?:\.\w+)*\}/);
        if (match) {
            return 'Shows unrendered template code: ' + match[0];
        }

        // Shortcodes that were never parsed, usually a link or image in the content.
        match = markup.match(/\[(?:sitetree_link|file_link|image|embed)[\s,]+\w+\s*=[^\]]*\]/i);
        if (match) {
            return 'Shows an unparsed shortcode: ' + excerpt(match[0]);
        }

        return '';
    }

    function documentProblem(html) {
        // Something that is not a whole HTML document has no title or end to check.
        if (!/<html[\s>]/i.test(html)) {
            return '';
        }

        if (!/<\/html\s*>/i.test(html)) {
            return 'Stops before </html>, so the page may have been cut off';
        }

        var match = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
        if (!match) {
            return 'Has no <title>';
        }

        if (!textOf(match[1]).trim()) {
            return 'Has an empty <title>';
        }

        return '';
    }

    // The text of some markup, decoded. DOMParser documents are inert, so nothing in
    // the markup runs or loads.
    function textOf(markup) {
        return new DOMParser().parseFromString(markup, 'text/html').documentElement.textContent || '';
    }

    function excerpt(markup) {
        var text = textOf(markup).replace(/\s+/g, ' ').trim();
        return text.length > 160 ? text.slice(0, 157) + '...' : text;
    }

    /* forms */

    /**
     * Returns each form in the page's main content, with its id (to link straight to
     * it) and a label to find it by: the id, else its action, else its name.
     */
    function detectForms(html) {
        var cleaned = html
            .replace(/<header[^>]*>[\s\S]*?<\/header>/gi, '')
            .replace(/<footer[^>]*>[\s\S]*?<\/footer>/gi, '');

        var matches = cleaned.match(/<form[^>]*>/gi) || [];

        return matches.filter(function (tag) {
            // The CMS preview toolbar injects its own form, which is not page content.
            return tag.indexOf('BetterNavigator') === -1;
        }).map(function (tag) {
            var id = formAttribute(tag, 'id');

            // "#" and an empty action both submit to the page itself, so say nothing.
            var action = formAttribute(tag, 'action').replace(config.baseUrl, '').split(/[?#]/)[0];

            return {
                id: id,
                label: id ? '#' + id : (action || formAttribute(tag, 'name') || '(no id)'),
                // The last segment of the URL it submits to, which for a form on a page's
                // own controller is the action that builds it.
                target: action.replace(/\/+$/, '').split('/').pop().toLowerCase()
            };
        });
    }

    function formAttribute(tag, name) {
        var match = tag.match(new RegExp('\\s' + name + '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))', 'i'));
        return match ? (match[1] || match[2] || match[3] || '').trim() : '';
    }

    /**
     * Silverstripe gives a form the id Form_{name} and submits it to {page}/{name}, and
     * that name is also an allowed action. Either match means the action is the form.
     */
    function isFormAction(form, action) {
        var name = action.toLowerCase();
        var id = form.id.toLowerCase();

        return id === 'form_' + name || id === name || form.target === name;
    }

    /**
     * Actions that are not one of the page's forms. The rest are shown as their form, as
     * there is no link to follow and the form is the thing to check.
     */
    function linkActions(row) {
        return row.actions.filter(function (action) {
            return !(row.forms || []).some(function (form) {
                return isFormAction(form, action);
            });
        });
    }

    function renderForms(row, forms) {
        row.forms = forms;

        var container = el('forms-container-' + row.index);
        if (!container) {
            return;
        }

        // Without an id there is nothing to jump to, so that link just opens the page.
        container.innerHTML = forms.map(function (form) {
            var url = form.id ? row.frontendLink + '#' + encodeURIComponent(form.id) : row.frontendLink;
            var opens = form.id ? 'Opens the page at the form.' : 'Opens the page.';
            var actions = row.actions.filter(function (action) {
                return isFormAction(form, action);
            }).map(function (action) {
                return '/' + action;
            });

            // A form that is one of the page's actions is listed as that action, among
            // the others, rather than as a form found on the page.
            var badge = actions.length ? 'form action' : 'form';
            var label = actions.length ? actions.join(', ') : form.label;
            var tip = actions.length
                ? 'This action is a form on the page' + (form.id ? ' (#' + form.id + ')' : '')
                    + ', so has no link to check. Check the form manually.'
                : 'A form was detected on this page. Check it manually.';

            return '<span class="ptl-status"><a href="' + escapeHtml(url) + '" target="_blank" rel="noopener"'
                + ' class="ptl-form-badge">' + icon('document') + ' ' + badge
                + '<span class="ptl-sr-only">: ' + escapeHtml(label) + '. ' + opens + '</span>'
                + '<span class="ptl-tip ptl-tip-above" aria-hidden="true">' + escapeHtml(tip)
                + ' ' + opens + '</span></a></span>'
                + '<span class="ptl-form-name" aria-hidden="true">' + escapeHtml(label) + '</span>';
        }).join('');
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

        linkActions(row).forEach(function (action) {
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
        if (!container) {
            return;
        }

        var actions = linkActions(row);
        if (!actions.length) {
            container.innerHTML = '';
            return;
        }

        var label = actions.length === 1
            ? '<span class="action-name">' + escapeHtml(actions[0]) + '</span>'
            : actions.length + ' actions';

        container.innerHTML = '<span class="ptl-actions-warning">'
            + '<button type="button" class="ptl-btn-actions" data-ptl-action="check-row-actions"'
            + ' data-ptl-row="' + row.index + '">' + label
            + '<span class="ptl-tip ptl-tip-above"><em>Activate to detect and test:</em><br>'
            + escapeHtml(actions.join(', ')) + '</span></button></span>';
    }

    async function checkAction(row, action, url) {
        var span = el('action-status-' + row.index + '-' + action);
        if (!span) {
            return false;
        }

        var result = await checkLink(url, true);
        result.problem = findContentProblem(result, false);
        span.innerHTML = statusBadge(result, [200], 'action:' + row.index + ':' + action);

        return result.status === 200 && !result.problem;
    }

    /* row checks */

    async function checkCmsCell(row) {
        var span = el('cms-status-' + row.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkCmsLink(row.cmsLink);

        // The edit form loads its blocks afterwards, so it answers 200 even when the
        // blocks editor inside it cannot load. The first block list to fail stands in
        // for the whole cell.
        var blockLists = row.blockListUrls || [];
        for (var i = 0; i < blockLists.length && result.status === 200 && !state.stopRequested; i++) {
            var listResult = await checkCmsLink(blockLists[i]);
            if (listResult.loginRequired || listResult.status !== 200) {
                if (!listResult.loginRequired) {
                    listResult.note = 'The edit form responded with 200, but its block list responded with '
                        + statusLabel(listResult.status) + ', so the blocks editor will not load.';
                }
                result = listResult;
            }
        }

        var screenFailures = result.status === 200 && !result.loginRequired ? await checkCmsScreens(row) : [];

        if (state.stopRequested) {
            span.innerHTML = '';
            renderScreenFailures(span, []);
            return;
        }

        span.innerHTML = statusBadge(result, [200], 'cms:' + row.index);
        renderScreenFailures(span, screenFailures);
        recordResult(result, [200]);
        state.totals.failed += screenFailures.length;
    }

    // On a line of their own under the cell, so they do not push its link aside.
    function renderScreenFailures(span, failures) {
        var cell = span.closest('td');
        var holder = cell.querySelector('.ptl-screen-badges');

        if (!failures.length) {
            if (holder) {
                holder.remove();
            }
            return;
        }

        if (!holder) {
            holder = document.createElement('div');
            holder.className = 'ptl-screen-badges';
            cell.appendChild(holder);
        }

        holder.innerHTML = failures.map(screenBadge).join('');
    }

    /**
     * The page's Settings and History screens. They work far more often than not, so
     * only a failure is shown, as a small badge under the edit form's own.
     */
    async function checkCmsScreens(row) {
        var checks = row.cmsScreenChecks || [];
        var failures = [];
        var failed = {};

        for (var i = 0; i < checks.length && !state.stopRequested; i++) {
            var check = checks[i];

            // History is two requests under one label, and one failure says enough.
            if (failed[check.label]) {
                continue;
            }

            var result = await checkCmsLink(check.url, true);
            if (result.loginRequired || result.status === 200) {
                continue;
            }

            failed[check.label] = true;
            failures.push({ check: check, result: result });
        }

        return failures;
    }

    // A link rather than a re-check button, as the screen is what needs looking at. The
    // edit form's badge above it re-checks the whole cell.
    function screenBadge(failure) {
        var label = failure.check.label + ' ' + statusLabel(failure.result.status);
        var error = blockErrorNote(failure.result);
        var description = 'The ' + failure.check.label + ' screen '
            + (error ? error.charAt(0).toLowerCase() + error.slice(1) : 'responded with '
                + statusLabel(failure.result.status)) + '.';

        return '<a href="' + escapeHtml(failure.check.link) + '" target="_blank" rel="noopener"'
            + ' class="ptl-status-badge ptl-status-fail ptl-screen-badge">'
            + icon('cross') + escapeHtml(label)
            + '<span class="ptl-sr-only">. ' + escapeHtml(description) + '</span>'
            + tipHtml(description + ' Click to open.') + '</a>';
    }

    async function checkFrontendCell(row, includeActions, onActionChecked) {
        var span = el('frontend-status-' + row.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');
        var result = await checkLink(row.frontendLink, true);
        result.problem = findContentProblem(result, true);

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, row.expected, 'frontend:' + row.index);
        recordResult(result, row.expected);

        renderForms(row, result.html ? detectForms(result.html) : []);

        if (!row.actions.length) {
            return;
        }

        if (!includeActions || !result.html) {
            renderActionPrompt(row);
            return;
        }

        // Form actions are left out, so they count as manual checks in the loop below.
        var foundLinks = findActionLinks(result.html, linkActions(row), row.frontendLink);
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

    /**
     * One of a block type's three checks: 'editor', 'form' or 'frontend'.
     */
    async function checkBlockCell(block, kind) {
        var span = el('block-' + kind + '-status-' + block.index);
        if (!span) {
            return;
        }

        span.innerHTML = placeholder('...');

        var result;
        if (kind === 'editor') {
            result = await checkCmsLink(block.editorCheckUrl, true);
            result.note = blockErrorNote(result);
        } else if (kind === 'form') {
            result = await checkCmsLink(block.editFormUrl);
        } else {
            // A draft block can only be rendered for somebody logged in to the CMS.
            result = block.frontendNeedsLogin
                ? await checkCmsLink(block.frontendUrl, false, true)
                : await checkLink(block.frontendUrl, true);
            result.problem = findContentProblem(result, false);
        }

        if (state.stopRequested) {
            span.innerHTML = '';
            return;
        }

        span.innerHTML = statusBadge(result, [200], 'block-' + kind + ':' + block.index);
        recordResult(result, [200]);
    }

    // The editor check says what went wrong in a JSON body.
    function blockErrorNote(result) {
        try {
            var error = JSON.parse(result.body).error;
            return error ? 'Responded with ' + statusLabel(result.status) + ': ' + error : '';
        } catch (e) {
            return '';
        }
    }

    function findBlock(index) {
        return blocks.filter(function (block) {
            return block.index === index;
        })[0];
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

        var total = pageRows.length * 2 + blocks.length * 3 + adminSections.length + adminEditLinks.length
            + expectedActionCount;
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

        blocks.forEach(function (block) {
            ['editor', 'form', 'frontend'].forEach(function (kind) {
                tasks.push(function () {
                    return checkBlockCell(block, kind);
                });
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
                actions: result.allowedActions || [],
                blockListUrls: result.blockListUrls || [],
                cmsScreenChecks: result.cmsScreenChecks || []
            };
            rows.push(newRow);
            rememberCreatedPage(result.id);

            row.removeAttribute('id');
            replaceRowCells(row, buildRowHtml(newRow, result));
            renderTested();

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
        var actionsHtml = '<div class="ptl-actions-container">'
            + '<span id="actions-container-' + newRow.index + '" class="ptl-actions-part"></span>'
            + '<span id="forms-container-' + newRow.index + '" class="ptl-actions-part"></span>'
            + emailPartSlot() + '</div>';

        return testedCellHtml(newRow)
            + '<td class="ptl-preview-col"><div class="ptl-preview">'
            + '<iframe title="Preview of ' + escapeHtml(result.title) + '" data-src="'
            + escapeHtml(result.frontendLink) + '"></iframe></div></td>'
            + '<td><span class="ptl-type">' + escapeHtml(newRow.shortClass) + '</span></td>'
            + '<td><span class="ptl-count">0 <span class="ptl-count-draft">+ 1</span></span></td>'
            + '<td>' + linkCell(
                '<span id="cms-status-' + newRow.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(result.editLink, 'Edit in CMS', newRow.shortClass, 'ptl-cms', 'desktop',
                    ['This CMS', 'edit ' + newRow.shortClass + ' on this site'])
            ) + '</td>'
            + '<td>' + linkCell(
                '<span id="frontend-status-' + newRow.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(result.frontendLink, 'View Page', newRow.shortClass, 'ptl-frontend', 'desktop',
                    ['This page', 'view ' + newRow.shortClass + ' on this site'])
            ) + actionsHtml + '</td>'
            + '<td class="ptl-example-cell"><span class="ptl-title">' + escapeHtml(result.title)
            + deleteButtonHtml(newRow, result.title) + '</span>'
            + '<span class="ptl-url">' + escapeHtml(result.pageUrl) + '</span></td>';
    }

    /**
     * Rebuilds a row's cells, but moves its email part across as it was. The server
     * found those usages in the type's code, and the script has no copy of them, so each
     * row builder leaves an empty slot for the part to go back into.
     */
    function replaceRowCells(rowEl, html) {
        var emailPart = rowEl.querySelector('.ptl-email-part');

        rowEl.innerHTML = html;

        var slot = rowEl.querySelector('.ptl-email-part');
        if (emailPart && slot) {
            slot.replaceWith(emailPart);
        }
    }

    // Mirrors HtmlReport::emailPart, empty, for replaceRowCells to fill.
    function emailPartSlot() {
        return '<span class="ptl-actions-part ptl-email-part"></span>';
    }

    // Mirrors HtmlReport::emailList, for block types, which have no actions list.
    function emailListSlot() {
        return '<div class="ptl-actions-container">' + emailPartSlot() + '</div>';
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
    function cellLink(url, label, description, className, iconName, comparing) {
        return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="' + className + '">'
            + icon(iconName)
            + '<span class="ptl-link-label">' + swapText(label, comparing && comparing[0]) + '</span>'
            + '<span class="ptl-sr-only"> \u2013 ' + swapText(description, comparing && comparing[1])
            + '</span></a>';
    }

    // Mirrors HtmlReport::swapText.
    function swapText(plain, comparing) {
        if (!comparing) {
            return escapeHtml(plain);
        }

        return '<span class="ptl-when-plain">' + escapeHtml(plain) + '</span>'
            + '<span class="ptl-when-comparing">' + escapeHtml(comparing) + '</span>';
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

    /* blocks: create and delete */

    /**
     * A block has to sit on a page, so the server makes a draft test page to hold it
     * rather than adding it to real content. Deleting the block deletes that page.
     */
    async function createBlock(button) {
        var row = button.closest('tr');
        var original = button.innerHTML;

        button.disabled = true;
        button.innerHTML = icon('spinner', 'ptl-spin') + ' Creating...';

        try {
            var body = new FormData();
            body.append(config.createBlockParam, button.getAttribute('data-ptl-class'));
            body.append(config.securityToken.name, config.securityToken.value);

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

            var block = {
                index: blocks.length ? Math.max.apply(null, blocks.map(function (b) { return b.index; })) + 1 : 0,
                class: button.getAttribute('data-ptl-class'),
                shortClass: result.shortClass,
                singularName: result.singularName,
                pageId: result.pageId,
                editorCheckUrl: result.editorCheckUrl,
                editFormUrl: result.editFormUrl,
                frontendUrl: result.frontendUrl,
                frontendNeedsLogin: result.frontendNeedsLogin
            };
            blocks.push(block);
            rememberCreatedPage(result.pageId);

            replaceRowCells(row, buildBlockRowHtml(block, result));
            renderTested();

            if (state.checksHaveRun) {
                await checkBlockCell(block, 'editor');
                await checkBlockCell(block, 'form');
                await checkBlockCell(block, 'frontend');
                updateSummary(false);
            }
        } catch (e) {
            showButtonError(button, original, e.message);
        }
    }

    /**
     * Mirrors HtmlReport::blockRow. A block created here is not on the live site, so
     * like a created page row it has no live link and nothing to compare.
     */
    function buildBlockRowHtml(block, result) {
        var draftOnly = Math.max(0, result.totalCount - result.liveCount);
        var formCell = block.editFormUrl
            ? linkCell(
                '<span id="block-form-status-' + block.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(block.editFormUrl, 'Edit Block', block.shortClass + ' edit form', 'ptl-cms', 'desktop',
                    ['This CMS', 'edit ' + block.shortClass + ' on this site'])
            )
            : '<span class="ptl-url">—</span>';

        return testedCellHtml(block)
            + '<td>' + blockTypeNameHtml(block) + '</td>'
            + '<td><span class="ptl-count">' + result.liveCount
            + (draftOnly > 0 ? ' <span class="ptl-count-draft">+ ' + draftOnly + '</span>' : '')
            + '<span class="ptl-tip ptl-tip-above">' + result.liveCount + ' live, ' + draftOnly
            + ' draft only</span></span></td>'
            + '<td>' + linkCell(
                '<span id="block-editor-status-' + block.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(result.pageCmsLink, 'Edit Page', block.shortClass + ' summary in its page\'s block list',
                    'ptl-cms', 'desktop')
            ) + '</td>'
            + '<td>' + formCell + '</td>'
            + '<td>' + linkCell(
                '<span id="block-frontend-status-' + block.index + '" class="ptl-status">' + placeholder('?') + '</span>',
                cellLink(block.frontendUrl, 'View Block', block.shortClass + ' rendered on its own',
                    'ptl-frontend', 'desktop')
            ) + emailListSlot() + '</td>'
            + '<td class="ptl-example-cell"><span class="ptl-title">'
            + (result.pageLink
                ? '<a href="' + escapeHtml(result.pageLink) + '" target="_blank" rel="noopener">'
                    + escapeHtml(result.title) + '</a>'
                : escapeHtml(result.title))
            + blockDeleteButtonHtml(block, result.title) + '</span>'
            + '<span class="ptl-subtext">on ' + escapeHtml(result.pageTitle) + '</span></td>';
    }

    // Mirrors HtmlReport::blockTypeName.
    function blockTypeNameHtml(block) {
        var name = block.singularName && block.singularName !== block.shortClass
            ? '<span class="ptl-subtext">' + escapeHtml(block.singularName) + '</span>'
            : '';

        return '<span class="ptl-type">' + escapeHtml(block.shortClass) + '</span>' + name;
    }

    // Mirrors HtmlReport::blockDeleteButton.
    function blockDeleteButtonHtml(block, title) {
        return '<button type="button" class="ptl-delete-btn" data-ptl-action="delete-block"'
            + ' data-ptl-page="' + block.pageId + '" data-ptl-row="' + block.index + '"'
            + ' aria-label="' + escapeHtml('Delete ' + title + ' and its test page, created by this report') + '">'
            + icon('trash') + ' Delete'
            + '<span class="ptl-tip ptl-tip-above">Deletes its test page too.'
            + ' Recoverable from the CMS archive.</span></button>';
    }

    // Mirrors HtmlReport::emptyBlockRow, for a type whose only block has just gone.
    function buildEmptyBlockRowHtml(block) {
        return testedCellHtml(block)
            + '<td>' + blockTypeNameHtml(block) + '</td>'
            + '<td><span class="ptl-count">0</span></td>'
            + '<td colspan="4"><div class="ptl-empty-content">'
            + '<button type="button" class="ptl-create-btn" data-ptl-action="create-block" data-ptl-class="'
            + escapeHtml(block.class) + '" data-ptl-short="' + escapeHtml(block.shortClass) + '" data-ptl-name="'
            + escapeHtml(block.singularName) + '">'
            + icon('plus') + ' Create ' + escapeHtml(block.shortClass) + '</button>'
            + emailListSlot() + '</div></td>';
    }

    async function deleteBlock(button) {
        var pageId = parseInt(button.getAttribute('data-ptl-page'), 10);
        var blockIndex = parseInt(button.getAttribute('data-ptl-row'), 10);
        var rowEl = button.closest('tr');
        var block = findBlock(blockIndex);
        var original = button.innerHTML;

        if (!window.confirm('Delete this block?\n\nIt was created by this report, on a test page of its own, '
            + 'which is deleted too. Both come off draft and live, and stay recoverable from the CMS archive.')) {
            return;
        }

        button.disabled = true;
        button.innerHTML = icon('spinner', 'ptl-spin') + ' Deleting...';

        try {
            var result = await postDelete(pageId, block ? block.class : '');

            if (!result.success) {
                throw new Error(result.error || 'Failed');
            }

            forgetCreatedPage(pageId);

            // Other blocks of this type exist, so reload to show one as the example.
            if (result.remainingBlocks > 0 || !block) {
                window.location.reload();
                return;
            }

            releaseRowTotals(rowEl);
            blocks = blocks.filter(function (b) {
                return b.index !== blockIndex;
            });
            replaceRowCells(rowEl, buildEmptyBlockRowHtml(block));

            renderTested();
            updateSummary(false);
        } catch (e) {
            showButtonError(button, original, e.message);
        }
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
                replaceRowCells(rowEl, buildEmptyRowHtml(rowData));
            } else {
                rowEl.remove();
            }

            renderTested();
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

    // blockClass asks the server how many blocks of that type are left afterwards.
    async function postDelete(pageId, blockClass) {
        var body = new FormData();
        body.append(config.deleteParam, pageId);
        body.append(config.securityToken.name, config.securityToken.value);
        if (blockClass) {
            body.append('blockClass', blockClass);
        }

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
        var actions = (row.actions || []).map(function (action) {
            return '<span class="ptl-status"><span class="ptl-action-badge">' + icon('warning')
                + ' action<span class="ptl-sr-only">: create a page of this type to check it</span>'
                + '<span class="ptl-tip ptl-tip-above" aria-hidden="true">Create a page of this type to check'
                + ' this action</span></span></span>'
                + '<span class="ptl-action-missing">/' + escapeHtml(action) + '</span>';
        }).join('');

        return testedCellHtml(row)
            + '<td class="ptl-preview-col"><div class="ptl-preview-empty">No preview</div></td>'
            + '<td><span class="ptl-type">' + escapeHtml(row.shortClass) + '</span></td>'
            + '<td><span class="ptl-count">0</span></td>'
            + '<td colspan="3"><div class="ptl-empty-content">'
            + '<button type="button" class="ptl-create-btn" data-ptl-action="create-page" data-ptl-class="'
            + escapeHtml(row.class) + '" data-ptl-short="' + escapeHtml(row.shortClass) + '">'
            + icon('plus') + ' Create ' + escapeHtml(row.shortClass) + '</button>'
            + '<div class="ptl-actions-container"><span class="ptl-actions-part">' + actions + '</span>'
            + emailPartSlot() + '</div></div></td>';
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
            state.liveDomain + button.getAttribute('data-ptl-live-path'),
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

    /* tested */

    /**
     * Which page types have been tested by hand, keyed by class name.
     *
     * Kept in localStorage rather than the database or session: nothing to install,
     * it survives logging out, and it survives pulling a fresh copy of the live
     * database, which is common midway through testing an upgrade. The cost is that
     * the ticks belong to this browser only.
     */
    var TESTED_KEY = 'ptl-tested:' + config.baseUrl;
    var HIDE_TESTED_KEY = 'ptl-hide-tested:' + config.baseUrl;

    function readStore(key, fallback) {
        try {
            var value = JSON.parse(window.localStorage.getItem(key));
            return value == null ? fallback : value;
        } catch (e) {
            return fallback;
        }
    }

    // Storage can be unavailable or full. The ticks still work for this visit.
    function writeStore(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Nothing useful to do.
        }
    }

    var tested = readStore(TESTED_KEY, {});
    var hideTested = readHideTested();

    // Which tables are hiding their tested rows, keyed by table ID.
    function readHideTested() {
        var value = readStore(HIDE_TESTED_KEY, {});
        return value && typeof value === 'object' ? value : {};
    }

    function setTested(className, isTested) {
        if (isTested) {
            tested[className] = Date.now();
        } else {
            delete tested[className];
        }

        writeStore(TESTED_KEY, tested);
        renderTested();
    }

    function clearTested(button) {
        var table = el(button.getAttribute('data-ptl-table'));
        var boxes = table ? table.querySelectorAll('[data-ptl-tested]:checked') : [];
        if (!boxes.length || !window.confirm('Clear the tested mark from ' + boxes.length + ' '
            + button.getAttribute('data-ptl-label') + '?')) {
            return;
        }

        boxes.forEach(function (box) {
            delete tested[box.getAttribute('data-ptl-tested')];
        });
        writeStore(TESTED_KEY, tested);
        renderTested();
    }

    // A short date to sit under the tick. The full date and time go in its title.
    function shortTestedDate(timestamp) {
        var date = new Date(timestamp);
        var options = { day: 'numeric', month: 'short' };
        if (date.getFullYear() !== new Date().getFullYear()) {
            options.year = 'numeric';
        }

        return date.toLocaleDateString(undefined, options);
    }

    function fullTestedDate(timestamp) {
        return new Date(timestamp).toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' });
    }

    /**
     * Brings every checkbox, the progress count and the filter in line with the stored
     * ticks. Called after anything that changes either, including rows being created or
     * deleted, so it reads the rows from the DOM rather than keeping its own list.
     */
    function renderTested() {
        document.querySelectorAll('[data-ptl-tested]').forEach(function (box) {
            var stamp = tested[box.getAttribute('data-ptl-tested')];
            var date = box.parentNode.querySelector('.ptl-tested-date');

            box.checked = !!stamp;
            box.closest('tr').classList.toggle('ptl-is-tested', !!stamp);

            if (date) {
                date.textContent = stamp ? shortTestedDate(stamp) : '';
                date.title = stamp ? 'Ticked ' + fullTestedDate(stamp) : '';
            }
        });

        // Each table counts its own rows.
        document.querySelectorAll('[data-ptl-progress-for]').forEach(function (progress) {
            var table = el(progress.getAttribute('data-ptl-progress-for'));
            if (!table) {
                return;
            }

            var total = table.querySelectorAll('[data-ptl-tested]').length;
            var done = table.querySelectorAll('[data-ptl-tested]:checked').length;

            var complete = total > 0 && done === total;

            progress.innerHTML = (complete ? icon('check') + ' ' : '') + done + ' of ' + total + ' tested';
            progress.classList.toggle('ptl-tested-complete', complete);
        });

        document.querySelectorAll('[data-ptl-action="toggle-hide-tested"]').forEach(function (toggle) {
            var tableId = toggle.getAttribute('data-ptl-table');
            var hiding = hideTested[tableId] === true;
            var table = el(tableId);

            if (table) {
                table.classList.toggle('ptl-hide-tested', hiding);
            }

            toggle.setAttribute('aria-pressed', hiding ? 'true' : 'false');
            toggle.innerHTML = (hiding ? icon('eye') + ' Show Tested' : icon('eye-slash') + ' Hide Tested')
                + '<span class="ptl-sr-only"> ' + escapeHtml(toggle.getAttribute('data-ptl-label')) + '</span>';
        });
    }

    function toggleHideTested(button) {
        var tableId = button.getAttribute('data-ptl-table');
        hideTested[tableId] = hideTested[tableId] !== true;
        writeStore(HIDE_TESTED_KEY, hideTested);
        renderTested();
    }

    // Mirrors HtmlReport::testedCell.
    function testedCellHtml(row) {
        return '<td class="ptl-tested-col ptl-tested-ui"><label class="ptl-tested">'
            + '<input type="checkbox" data-ptl-tested="' + escapeHtml(row.class) + '">'
            + '<span class="ptl-sr-only">' + escapeHtml(row.shortClass) + ' tested</span>'
            + '<span class="ptl-tested-date"></span></label></td>';
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
        } else if (kind === 'block-editor' || kind === 'block-form' || kind === 'block-frontend') {
            var block = findBlock(index);
            if (block) {
                await checkBlockCell(block, kind.slice('block-'.length));
            }
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

    function urlWithParam(name, value) {
        var url = new URL(window.location.href);
        if (value) {
            url.searchParams.set(name, value);
        } else {
            url.searchParams.delete(name);
        }
        return url.toString();
    }

    function setParam(name, value) {
        window.location.href = urlWithParam(name, value);
    }

    /* live domain */

    /**
     * Mirrors PageTypeTesterTask::sanitiseLiveDomain: an absolute http(s) URL, rebuilt
     * from its parts, with no trailing slash. Anything else gives an empty string.
     */
    function sanitiseLiveDomain(value) {
        var url;
        try {
            url = new URL(value.trim());
        } catch (e) {
            return '';
        }

        if ((url.protocol !== 'http:' && url.protocol !== 'https:') || !/^[a-z0-9.\-]+$/i.test(url.hostname)) {
            return '';
        }

        return (url.protocol + '//' + url.host + url.pathname).replace(/\/+$/, '');
    }

    /**
     * Sets or clears the live domain in place. The live links are already in the
     * markup, so this only fills in their hrefs and flips the class that shows them,
     * which keeps every check result on the page instead of reloading and running the
     * lot again. The address bar is updated too, so a reload or a shared link keeps it.
     */
    function applyLiveDomain(domain) {
        state.liveDomain = domain;

        document.querySelectorAll('.ptl-wrap').forEach(function (wrap) {
            wrap.classList.toggle('ptl-comparing', domain !== '');
        });

        document.querySelectorAll('a[data-ptl-live-path]').forEach(function (link) {
            if (domain) {
                link.href = domain + link.getAttribute('data-ptl-live-path');
            } else {
                link.removeAttribute('href');
            }
        });

        el('ptl-live-domain-input').value = domain;
        el('ptl-live-domain-status').textContent = domain
            ? 'Live links now point to ' + domain + '.'
            : 'Live links removed.';

        history.replaceState(history.state, '', urlWithParam('live-domain', domain));
    }

    function submitLiveDomain(form) {
        var input = form.querySelector('#ptl-live-domain-input');
        var raw = input.value.trim();
        var domain = sanitiseLiveDomain(raw);

        // The browser has already checked it is a URL. This catches the rest, such as
        // an ftp: address, which the server would otherwise drop without a word.
        if (raw && !domain) {
            input.setCustomValidity('Enter an http or https address, such as https://example.com');
            input.reportValidity();
            return;
        }

        applyLiveDomain(domain);
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
            case 'create-block':
                createBlock(trigger);
                break;
            case 'delete-block':
                deleteBlock(trigger);
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
            case 'toggle-hide-tested':
                toggleHideTested(trigger);
                break;
            case 'clear-tested':
                clearTested(trigger);
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
            case 'clear-live-domain':
                applyLiveDomain('');
                // Clear hides itself, so focus would otherwise drop to the body.
                el('ptl-live-domain-input').focus();
                break;
            case 'login':
                goToLogin();
                break;
            case 'reload':
                window.location.reload();
                break;
        }
    });

    // Enter in the field submits too, as it is a real form. It only submits for real
    // without the script.
    document.addEventListener('submit', function (event) {
        if (event.target.matches('.ptl-live-domain-form')) {
            event.preventDefault();
            submitLiveDomain(event.target);
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target.id === 'ptl-live-domain-input') {
            event.target.setCustomValidity('');
        }
    });

    document.addEventListener('change', function (event) {
        var box = event.target.closest('[data-ptl-tested]');
        if (box) {
            setTested(box.getAttribute('data-ptl-tested'), box.checked);
        }
    });

    // The whole cell is the target, not just the box. Clicks on the label already
    // reach the checkbox, so only those outside it are passed on.
    document.addEventListener('click', function (event) {
        var cell = event.target.closest('td.ptl-tested-col');
        if (!cell || event.target.closest('label')) {
            return;
        }

        var box = cell.querySelector('[data-ptl-tested]');
        if (box) {
            box.click();
        }
    });

    // Keeps a second report tab in step, so it cannot write back a stale set of ticks.
    window.addEventListener('storage', function (event) {
        if (event.key === TESTED_KEY || event.key === HIDE_TESTED_KEY) {
            tested = readStore(TESTED_KEY, {});
            hideTested = readHideTested();
            renderTested();
        }
    });

    /**
     * Opens every link of one kind from one table. Each table's buttons name their
     * kind in data-ptl-links.
     */
    function openAll(which) {
        var pageRows = rows.filter(function (r) { return r.index >= 0; });
        var sources = {
            'cms': function () { return pageRows.map(function (r) { return r.cmsLink; }); },
            'frontend': function () { return pageRows.map(function (r) { return r.frontendLink; }); },
            'block-cms': function () { return blocks.map(function (b) { return b.editFormUrl; }); },
            'block-frontend': function () { return blocks.map(function (b) { return b.frontendUrl; }); },
            'admin': function () { return adminSections.map(function (s) { return s.url; }); },
            'admin-edit': function () {
                return adminEditLinks.filter(function (l) { return !l.isNew; }).map(function (l) { return l.url; });
            }
        };

        // A block with no edit form has an empty URL, and the same URL twice would
        // only open a duplicate tab.
        var links = (sources[which] ? sources[which]() : []).filter(function (url, i, all) {
            return url && all.indexOf(url) === i;
        });

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

        renderForms(row, detectForms(result.html));

        // Form actions are left out, so they count as manual checks in the loop below.
        var foundLinks = findActionLinks(result.html, linkActions(row), row.frontendLink);
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

    // The tested ticks only work with the script, so they stay hidden without it.
    document.querySelectorAll('.ptl-wrap').forEach(function (wrap) {
        wrap.classList.add('ptl-js');
    });
    renderTested();

    // Start the full check automatically, as the point of opening the page is to see
    // the results. The primary button doubles as a stop control while it runs.
    checkAll(true);
})();
