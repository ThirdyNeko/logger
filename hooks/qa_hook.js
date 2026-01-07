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

        /* ------------------------------
           SEND LOG (NO RESPONSE BODY)
        ------------------------------ */
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

        // IMPORTANT: return backend response untouched
        return response;
    };

    console.log('[QA] Frontend QA hook active');
})();
