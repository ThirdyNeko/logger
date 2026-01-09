(() => {
    if (window.__QA_HOOK_INSTALLED__) return;
    window.__QA_HOOK_INSTALLED__ = true;

    const FRONTEND_RECEIVER = 'http://localhost/logger/hooks/receiver_frontend.php';
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

        if (typeof body === 'string') {
            return body.trim().length > 0;
        }

        if (body instanceof FormData) {
            return [...body.entries()].length > 0;
        }

        if (typeof body === 'object') {
            return Object.keys(body).length > 0;
        }

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
        if (!QA_MUTATION_METHODS.has(method)) return false;
        if (!hasValidRequestBody(body)) return false;
        return true;
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
        }).catch(() => {});
    }

    /* ==========================
       FETCH
    ========================== */

    window.fetch = async (...args) => {
        const url = args[0] instanceof Request ? args[0].url : args[0];
        const options = args[1];
        const method = (options?.method || 'GET').toUpperCase();
        const body = options?.body || null;

        if (isQaInternalRequest(url, options)) return originalFetch(...args);

        const response = await originalFetch(...args);

        if (!isMajorUpdate(method, body)) return response;

        const normalizedBody = normalizeRequest(body);
        const dedupeKey = method + '|' + url + '|' + JSON.stringify(normalizedBody);
        if (shouldDedupe(dedupeKey)) return response;

        qaSendFrontendLog({
            type: 'frontend-io',
            url,
            method,
            request: normalizedBody,
            status: response.status,
            timestamp: new Date().toISOString()
        });

        return response;
    };

    /* ==========================
       JQUERY AJAX
    ========================== */

    if (window.jQuery) {
        const originalAjax = $.ajax;

        $.ajax = function(options) {
            const method = (options.type || 'GET').toUpperCase();
            const url = options.url;
            const request = options.data || null;

            if (!isMajorUpdate(method, request)) return originalAjax.apply(this, arguments);

            return originalAjax.call(this, {
                ...options,
                success: function(data, textStatus, jqXHR) {
                    const normalizedBody = normalizeRequest(request);
                    const dedupeKey = method + '|' + url + '|' + JSON.stringify(normalizedBody);
                    if (!shouldDedupe(dedupeKey)) {
                        qaSendFrontendLog({
                            type: 'frontend-io',
                            url,
                            method,
                            request: normalizedBody,
                            status: jqXHR.status,
                            timestamp: new Date().toISOString()
                        });
                    }
                    options.success?.apply(this, arguments);
                },
                error: function(jqXHR) {
                    const normalizedBody = normalizeRequest(request);
                    const dedupeKey = method + '|' + url + '|' + JSON.stringify(normalizedBody);
                    if (!shouldDedupe(dedupeKey)) {
                        qaSendFrontendLog({
                            type: 'frontend-io',
                            url,
                            method,
                            request: normalizedBody,
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

    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this._qa_skip = isQaInternalRequest(url);
        this._qa_method = method.toUpperCase();
        this._qa_url = url;
        return origOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function(body) {
        if (!this._qa_skip && isMajorUpdate(this._qa_method, body)) {
            const normalizedBody = normalizeRequest(body);
            this.addEventListener('load', () => {
                const dedupeKey = this._qa_method + '|' + this._qa_url + '|' + JSON.stringify(normalizedBody);
                if (shouldDedupe(dedupeKey)) return;

                qaSendFrontendLog({
                    type: 'frontend-io',
                    url: this._qa_url,
                    method: this._qa_method,
                    request: normalizedBody,
                    status: this.status,
                    timestamp: new Date().toISOString()
                });
            });
        }
        return origSend.call(this, body);
    };

    console.log('[QA] Frontend QA hook active (major updates only, no null requests)');
})();
    