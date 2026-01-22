(() => {
    // ---------------------------
    // Persistent device ID
    // ---------------------------
    const COOKIE_NAME = 'qa_device_id';

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    function setCookie(name, value, days = 365) {
        const expires = new Date();
        expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
        document.cookie = `${name}=${value};expires=${expires.toUTCString()};path=/`;
    }

    let QA_DEVICE_NAME = getCookie(COOKIE_NAME);
    if (!QA_DEVICE_NAME) {
        QA_DEVICE_NAME = 'device_' + crypto.randomUUID();
        setCookie(COOKIE_NAME, QA_DEVICE_NAME, 365);
    }

    // ---------------------------
    // App program name
    // ---------------------------
    const QA_APP_PROGRAM = (() => {
        try {
            // Get full path of current URL
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            // Take the first folder after the domain as the root folder name
            return pathParts.length > 0 ? pathParts[0] : 'UNKNOWN_APP';
        } catch {
            return 'UNKNOWN_APP';
        }
    })();



    if (window.__QA_HOOK_INSTALLED__) return;
    window.__QA_HOOK_INSTALLED__ = true;

    const FRONTEND_RECEIVER =
    `${window.location.origin}/logger/hooks/receiver_frontend.php`;

    const originalFetch = window.fetch.bind(window);

    /* ==========================
       CONFIG
    ========================== */
    const QA_MUTATION_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
    const QA_DEDUPE_WINDOW = 500; // ms
    const qaRecent = new Map();

    /* ==========================
       HELPERS
    ========================== */
    function isQaInternalRequest(url, options) {
        if (typeof url === 'string' && url.includes('receiver_frontend.php')) return true;
        if (options?.headers?.['X-QA-INTERNAL'] === '1') return true;
        return false;
    }

    function hasValidRequestBody(body) {
        if (body === null || body === undefined) return false;
        if (typeof body === 'string') return body.trim().length > 0;
        if (body instanceof FormData) return [...body.entries()].length > 0;
        if (typeof body === 'object') return Object.keys(body).length > 0;
        return false;
    }

    function normalizeRequest(body) {
        if (body instanceof FormData) {
            const obj = {};
            body.forEach((v, k) => obj[k] = v);
            return obj;
        }
        return body;
    }

    function isMajorUpdate(method, body) {
        return QA_MUTATION_METHODS.has(method) && hasValidRequestBody(body);
    }

    function shouldDedupe(key) {
        const now = Date.now();
        const last = qaRecent.get(key);
        qaRecent.set(key, now);
        return last && (now - last < QA_DEDUPE_WINDOW);
    }

    function qaSendFrontendLog(payload) {
        return originalFetch(FRONTEND_RECEIVER, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-QA-INTERNAL': '1'
            },
            body: JSON.stringify(payload)
        }).catch(err => {
            console.error('[QA LOGGER FAILED]', err);
        });
    }

    /* ==========================
       LAST REQUEST TRACKING
    ========================== */
    let __qa_last_request = null;

    function updateLastRequest(url, method, requestBody, status = 200) {
        __qa_last_request = { url, method, request: requestBody, status };
    }

    function logUIAsFrontendIO(message) {
        const ctx = __qa_last_request || {
            url: location.href,
            method: 'UI',
            request: null,
            status: 200
        };

        qaSendFrontendLog({
            type: 'frontend-io',
            program_name: QA_APP_PROGRAM,   // ✅ add this
            device_name: QA_DEVICE_NAME,
            url: ctx.url,
            method: ctx.method,
            request: ctx.request,
            response: message,
            status: ctx.status,
            timestamp: new Date().toISOString()
        });
    }

    /* ==========================
       FETCH
    ========================== */
    window.fetch = async (...args) => {
        const req = args[0] instanceof Request ? args[0] : null;
        const url = req ? req.url : args[0];
        const options = req ? req : args[1] || {};
        const method = (options.method || 'GET').toUpperCase();
        const body = options.body || null;

        if (isQaInternalRequest(url, options)) return originalFetch(...args);

        const response = await originalFetch(...args);

        if (!isMajorUpdate(method, body)) return response;

        const normalizedBody = normalizeRequest(body);
        const dedupeKey = `${method}|${url}|${JSON.stringify(normalizedBody)}`;
        if (shouldDedupe(dedupeKey)) return response;

        let output = null;
        const contentType = response.headers.get('content-type') || '';

        try {
            const cloned = response.clone();
            if (contentType.includes('application/json')) output = await cloned.json();
            else if (contentType.includes('text')) output = await cloned.text();
        } catch {
            output = '[unreadable response]';
        }

        updateLastRequest(url, method, normalizedBody, response.status);

        qaSendFrontendLog({
            type: 'frontend-io',
            program_name: QA_APP_PROGRAM,   // ✅ add this
            device_name: QA_DEVICE_NAME,
            url,
            method,
            request: normalizedBody,
            response: output,
            status: response.status,
            timestamp: new Date().toISOString()
        });

        return response;
    };

    /* ==========================
       jQuery AJAX
    ========================== */
    if (window.jQuery) {
        const originalAjax = $.ajax;

        $.ajax = function (options) {
            const method = (options.type || 'GET').toUpperCase();
            const url = options.url;
            const request = options.data || null;

            return originalAjax.call(this, {
                ...options,
                success: function (data, textStatus, jqXHR) {
                    updateLastRequest(url, method, request, jqXHR.status);

                    const normalizedBody = normalizeRequest(request);
                    const dedupeKey = `${method}|${url}|${JSON.stringify(normalizedBody)}`;
                    if (!shouldDedupe(dedupeKey)) {
                        qaSendFrontendLog({
                            type: 'frontend-io',
                            program_name: QA_APP_PROGRAM,   // ✅ add this
                            device_name: QA_DEVICE_NAME,
                            url,
                            method,
                            request: normalizedBody,
                            response: data,
                            status: jqXHR.status,
                            timestamp: new Date().toISOString()
                        });
                    }
                    options.success?.apply(this, arguments);
                },
                error: function (jqXHR) {
                    updateLastRequest(url, method, request, jqXHR.status);

                    const normalizedBody = normalizeRequest(request);
                    const dedupeKey = `${method}|${url}|${JSON.stringify(normalizedBody)}`;
                    if (!shouldDedupe(dedupeKey)) {
                        qaSendFrontendLog({
                            type: 'frontend-io',
                            program_name: QA_APP_PROGRAM,   // ✅ add this
                            device_name: QA_DEVICE_NAME,
                            url,
                            method,
                            request: normalizedBody,
                            response: jqXHR.responseText || null,
                            status: jqXHR.status,
                            timestamp: new Date().toISOString()
                        });
                    }
                    options.error?.apply(this, arguments);
                }
            });
        };
    }

    /* ==========================
       XHR
    ========================== */
    const origOpen = XMLHttpRequest.prototype.open;
    const origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this._qa_skip = isQaInternalRequest(url);
        this._qa_method = method.toUpperCase();
        this._qa_url = url;
        return origOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function (body) {
        if (!this._qa_skip && isMajorUpdate(this._qa_method, body)) {
            const normalizedBody = normalizeRequest(body);
            this.addEventListener('load', () => {
                updateLastRequest(this._qa_url, this._qa_method, normalizedBody, this.status);

                const dedupeKey = `${this._qa_method}|${this._qa_url}|${JSON.stringify(normalizedBody)}`;
                if (shouldDedupe(dedupeKey)) return;

                let output;
                try {
                    output = (this.responseType === '' || this.responseType === 'text')
                        ? this.responseText
                        : this.response;
                } catch {
                    output = '[unreadable response]';
                }

                qaSendFrontendLog({
                    type: 'frontend-io',
                    program_name: QA_APP_PROGRAM,   // ✅ add this
                    device_name: QA_DEVICE_NAME,
                    url: this._qa_url,
                    method: this._qa_method,
                    request: normalizedBody,
                    response: output,
                    status: this.status,
                    timestamp: new Date().toISOString()
                });
            });
        }
        return origSend.call(this, body);
    };

    /* ==========================
       UI POPUPS (alert / confirm / prompt)
    ========================== */
    const _alert = window.alert;
    const _confirm = window.confirm;
    const _prompt = window.prompt;

    window.alert = function (msg) { logUIAsFrontendIO(msg); return _alert.apply(this, arguments); }
    window.confirm = function (msg) { logUIAsFrontendIO(msg); return _confirm.apply(this, arguments); }
    window.prompt = function (msg) { logUIAsFrontendIO(msg); return _prompt.apply(this, arguments); }

    /* ==========================
       Validation Helper
    ========================== */
    window.qaValidationError = function(msg, meta = {}) {
        logUIAsFrontendIO(msg);
    };

    /* ==========================
       SweetAlert / Swal2 / Toastr Hooks
    ========================== */
    function hookSwal() {
        if (!window.Swal?.fire || window.__QA_SWAL_HOOKED__) return;
        window.__QA_SWAL_HOOKED__ = true;

        const originalSwalFire = window.Swal.fire;

        window.Swal.fire = function(options, ...rest) {
            let message = '';
            if (typeof options === 'string') message = options;
            else if (options?.title || options?.text)
                message = [options.title, options.text].filter(Boolean).join(' - ');

            logUIAsFrontendIO(message);

            return originalSwalFire.apply(this, [options, ...rest]);
        };
    }

    window.addEventListener('load', hookSwal);
    setTimeout(hookSwal, 1000);

    if (window.toastr) {
        ['success', 'info', 'warning', 'error'].forEach(level => {
            const origFn = window.toastr[level];
            if (!origFn) return;
            window.toastr[level] = function(message, title, optionsOverride) {
                logUIAsFrontendIO(message);
                return origFn.apply(this, [message, title, optionsOverride]);
            };
        });
    }

    /* ==========================
       FORM SUBMIT HOOK
    ========================== */
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        const method = (form.method || 'GET').toUpperCase();
        const url = form.action || window.location.href;

        if (isQaInternalRequest(url)) return;

        const formData = new FormData(form);
        if (!hasValidRequestBody(formData)) return;

        const normalizedBody = normalizeRequest(formData);
        const dedupeKey = `${method}|${url}|${JSON.stringify(normalizedBody)}`;
        if (shouldDedupe(dedupeKey)) return;

        updateLastRequest(url, method, normalizedBody, 0);

        qaSendFrontendLog({
            type: 'frontend-io',
            program_name: QA_APP_PROGRAM,   // ✅ add this
            device_name: QA_DEVICE_NAME,
            url,
            method,
            request: normalizedBody,
            response: '[form submit]',
            status: 0,
            timestamp: new Date().toISOString()
        });
    }, true); // capture phase

    console.log('[QA] Frontend QA hook active (network + UI + validation + toast, logs normalized)');
})();


