(() => {
    if (window.__QA_HOOK_INSTALLED__) return;
    window.__QA_HOOK_INSTALLED__ = true;

    const FRONTEND_RECEIVER = 'http://localhost/logger/hooks/receiver_frontend.php';
    const originalFetch = window.fetch.bind(window);

    /* ------------------------------
       NORMALIZE REQUEST BODY
    ------------------------------ */
    async function extractRequestBody(options, request) {
        try {
            if (request) {
                const clone = request.clone();
                const contentType = clone.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    return await clone.json();
                }
                if (contentType.includes('application/x-www-form-urlencoded')) {
                    return Object.fromEntries(new URLSearchParams(await clone.text()));
                }
                return await clone.text();
            }

            if (!options || !options.body) return null;

            if (options.body instanceof FormData) {
                return Object.fromEntries(options.body.entries());
            }
            if (options.body instanceof URLSearchParams) {
                return Object.fromEntries(options.body.entries());
            }
            if (typeof options.body === 'string') {
                try {
                    return JSON.parse(options.body);
                } catch {
                    return options.body;
                }
            }
        } catch {
            return '[unreadable request body]';
        }
    }

    /* ------------------------------
       FETCH OVERRIDE
    ------------------------------ */
    window.fetch = async (...args) => {
        let url, method = 'GET', requestBody = null;

        if (args[0] instanceof Request) {
            const req = args[0];
            url = req.url;
            method = req.method;
            requestBody = await extractRequestBody(null, req);
        } else {
            url = args[0];
            const options = args[1] || {};
            method = options.method || 'GET';
            requestBody = await extractRequestBody(options, null);

            options.headers = options.headers || {};
            options.headers['X-QA-ENABLE'] = '1';
            args[1] = options;
        }

        const response = await originalFetch(...args);

        try {
            await originalFetch(FRONTEND_RECEIVER, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: 'frontend-io',
                    url,
                    method,
                    request: requestBody,
                    status: response.status,
                    timestamp: new Date().toISOString()
                })
            });
        } catch (e) {
            console.warn('[QA] Frontend log failed', e);
        }

        return response;
    };

    /* ------------------------------
       JQUERY AJAX OVERRIDE
    ------------------------------ */
    if (window.jQuery) {
        const originalAjax = $.ajax;
        $.ajax = function(options) {
            const origSuccess = options.success;
            const origError = options.error;

            options.success = function(data, textStatus, jqXHR) {
                try {
                    $.post(FRONTEND_RECEIVER, JSON.stringify({
                        type: 'frontend-io-jquery',
                        url: options.url,
                        method: options.type || 'GET',
                        request: options.data || null,
                        status: jqXHR.status,
                        timestamp: new Date().toISOString()
                    }));
                } catch (e) { console.warn('[QA] jQuery AJAX log failed', e); }

                if (origSuccess) origSuccess.apply(this, arguments);
            };

            options.error = function(jqXHR, textStatus, errorThrown) {
                try {
                    $.post(FRONTEND_RECEIVER, JSON.stringify({
                        type: 'frontend-io-jquery',
                        url: options.url,
                        method: options.type || 'GET',
                        request: options.data || null,
                        status: jqXHR.status,
                        timestamp: new Date().toISOString()
                    }));
                } catch (e) { console.warn('[QA] jQuery AJAX error log failed', e); }

                if (origError) origError.apply(this, arguments);
            };

            return originalAjax.call(this, options);
        };
        console.log('[QA] jQuery AJAX hook active');
    }

    /* ------------------------------
       XHR OVERRIDE
    ------------------------------ */
    const origXHRopen = XMLHttpRequest.prototype.open;
    const origXHRsend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this._qa_method = method;
        this._qa_url = url;
        return origXHRopen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function(body) {
        this.addEventListener('load', () => {
            try {
                fetch(FRONTEND_RECEIVER, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        type: 'frontend-io-xhr',
                        url: this._qa_url,
                        method: this._qa_method,
                        request: body || null,
                        status: this.status,
                        timestamp: new Date().toISOString()
                    })
                });
            } catch (e) { console.warn('[QA] XHR log failed', e); }
        });
        return origXHRsend.call(this, body);
    };

    console.log('[QA] Frontend QA hook fully active (fetch + jQuery + XHR)');
})();
