/**
 * Fetch wrapper for the embedded app's own API. Every request is signed
 * with the current App Bridge session token (never a cookie/session),
 * matching the shopify.session middleware on the Laravel side. Same-origin
 * now that the SPA is served from this Laravel app directly - no proxy hop.
 */
async function getSessionToken() {
    if (window.shopify?.idToken) {
        return window.shopify.idToken();
    }

    throw new Error('Shopify App Bridge is not available on this page.');
}

async function request(path, options = {}) {
    const token = await getSessionToken();

    const response = await fetch(`/api${path}`, {
        ...options,
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...options.headers,
        },
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const error = new Error(body.error || body.message || `Request to ${path} failed (${response.status})`);
        error.status = response.status;
        error.body = body;
        throw error;
    }

    if (response.status === 204) {
        return null;
    }

    return response.json();
}

export const api = {
    get: (path) => request(path),
    post: (path, data) => request(path, { method: 'POST', body: data ? JSON.stringify(data) : undefined }),
    put: (path, data) => request(path, { method: 'PUT', body: data ? JSON.stringify(data) : undefined }),
    patch: (path, data) => request(path, { method: 'PATCH', body: data ? JSON.stringify(data) : undefined }),
    delete: (path) => request(path, { method: 'DELETE' }),
};

/**
 * Shopify's hosted plan page has to open outside the embedded iframe -
 * window.open(url, '_top') is the safe way to do that; directly setting
 * window.top.location.href can throw a SecurityError under stricter
 * iframe sandboxing.
 */
export function openShopifyPricingPage(url) {
    window.open(url, '_top');
}
