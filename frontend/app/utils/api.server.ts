/**
 * Thin fetch wrapper to the Laravel backend. Shopify appends `id_token` (the
 * App Bridge session token) as a URL param on embedded-app SSR navigations;
 * client-side mutations after hydration instead call `getSessionToken()`
 * (app/utils/shopify.client.ts) and pass it straight through as `token`.
 * Either way, Laravel's VerifyShopifySessionToken middleware is what
 * actually authenticates the request - this wrapper just forwards it.
 */
const BACKEND_URL = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000';

export function sessionTokenFromRequest(request: Request): string | null {
  return new URL(request.url).searchParams.get('id_token');
}

export async function backendFetch<T = unknown>(
  path: string,
  token: string | null,
  init: RequestInit = {},
): Promise<T> {
  const response = await fetch(`${BACKEND_URL}/api${path}`, {
    ...init,
    headers: {
      ...(init.headers ?? {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Response(body, { status: response.status });
  }

  return response.json() as Promise<T>;
}
