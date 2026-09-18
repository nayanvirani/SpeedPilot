/**
 * Client-side helper for fetching a fresh App Bridge session token after
 * hydration (used by action buttons - rollback, approve fix, subscribe -
 * where the SSR-time `id_token` URL param may already be stale).
 */
declare global {
  interface Window {
    shopify?: {
      idToken: () => Promise<string>;
    };
  }
}

export async function getSessionToken(): Promise<string> {
  if (!window.shopify) {
    throw new Error('App Bridge is not loaded - is this page running inside Shopify admin?');
  }

  return window.shopify.idToken();
}
