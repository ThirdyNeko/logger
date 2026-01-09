(() => {
    if (window.__QA_HOOK_INSTALLED__) return;
    window.__QA_HOOK_INSTALLED__ = true;

    const FRONTEND_RECEIVER = 'http://localhost/logger/hooks/receiver_frontend.php';
    const originalFetch = window.fetch.bind(window);

    function isQaInternalRequest(url, options) {
        if (typeof url === 'string' && url.includes('receiver_frontend.php')) return true;
        if (options?.headers?.['X-QA-INTERNAL'] === '1') return true;
        return false;
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

    /* ---------------- FETCH ---------------- */
    window.fetch = async (...args) => {
        const url = args[0] instanceof Request ? args[0].url : args[0];
        const options = args[1];

        if (isQaInternalRequest(url, options)) {
            return originalFetch(...args);
        }

        const response = await originalFetch(...args);

        qaSendFrontendLog({
            type: 'frontend-io',
            url,
            method: options?.method || 'GET',
            request: options?.body || null,
            status: response.status,
            timestamp: new Date().toISOString()
        });

        return response;
    };

    /* ---------------- JQUERY ---------------- */
    if (window.jQuery) {
        const originalAjax = $.ajax;

        $.ajax = function(options) {
            const method = options.type || 'GET';
            const url = options.url;
            const request = options.data || null;

            return originalAjax.call(this, {
                ...options,
                success: function(data, textStatus, jqXHR) {
                    qaSendFrontendLog({
                        type: 'frontend-io',
                        url,
                        method,
                        request,
                        status: jqXHR.status,
                        timestamp: new Date().toISOString()
                    });
                    options.success?.apply(this, arguments);
                },
                error: function(jqXHR) {
                    qaSendFrontendLog({
                        type: 'frontend-io',
                        url,
                        method,
                        request,
                        status: jqXHR.status,
                        timestamp: new Date().toISOString()
                    });
                    options.error?.apply(this, arguments);
                }
            });
        };
    }

    /* ---------------- XHR ---------------- */
    const origOpen = XMLHttpRequest.prototype.open;
    const origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this._qa_skip = isQaInternalRequest(url);
        this._qa_method = method;
        this._qa_url = url;
        return origOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function(body) {
        if (!this._qa_skip) {
            this.addEventListener('load', () => {
                qaSendFrontendLog({
                    type: 'frontend-io',
                    url: this._qa_url,
                    method: this._qa_method,
                    request: body || null,
                    status: this.status,
                    timestamp: new Date().toISOString()
                });
            });
        }
        return origSend.call(this, body);
    };

    console.log('[QA] Frontend QA hook active');
})();
