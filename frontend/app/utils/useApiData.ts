import { useCallback, useEffect, useState } from 'react';
import { getSessionToken } from '~/utils/shopify.client';

/**
 * Client-side data fetching for embedded-admin pages. A server-side Remix
 * loader can't authenticate on first render - Shopify doesn't put a session
 * token in the URL, it only becomes available via window.shopify.idToken()
 * once App Bridge's script has run in the browser. So every page's real
 * data fetch happens here, after mount, through /api-proxy/* with a fresh
 * token - not in a loader.
 */
export function useApiData<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const token = await getSessionToken();
      const response = await fetch(`/api-proxy${path}`, {
        headers: { Authorization: `Bearer ${token}` },
      });

      if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
      }

      setData(await response.json());
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Something went wrong');
    } finally {
      setLoading(false);
    }
  }, [path]);

  useEffect(() => {
    refetch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path]);

  return { data, loading, error, refetch };
}

export async function apiPost<T = unknown>(path: string, body?: unknown): Promise<T> {
  const token = await getSessionToken();
  const response = await fetch(`/api-proxy${path}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });

  if (!response.ok) {
    throw new Error(`Request failed (${response.status})`);
  }

  return response.json();
}

export async function apiPatch<T = unknown>(path: string, body?: unknown): Promise<T> {
  const token = await getSessionToken();
  const response = await fetch(`/api-proxy${path}`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });

  if (!response.ok) {
    throw new Error(`Request failed (${response.status})`);
  }

  return response.json();
}
