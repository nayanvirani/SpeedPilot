import type { ActionFunctionArgs, LoaderFunctionArgs } from '@remix-run/node';

/**
 * Resource route (no default export = Remix never wraps it in a layout).
 * Client-side actions (rollback, approve fix, subscribe) fetch a fresh App
 * Bridge session token via getSessionToken() and call `/api-proxy/<path>`
 * with it as a Bearer header; this just forwards that straight through to
 * the Laravel backend, keeping BACKEND_URL server-side only.
 */
const BACKEND_URL = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000';

async function forward(request: Request, params: { '*': string }) {
  const url = `${BACKEND_URL}/api/${params['*']}${new URL(request.url).search}`;

  const response = await fetch(url, {
    method: request.method,
    headers: {
      Authorization: request.headers.get('Authorization') ?? '',
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: ['GET', 'HEAD'].includes(request.method) ? undefined : await request.text(),
  });

  return new Response(await response.text(), {
    status: response.status,
    headers: { 'Content-Type': 'application/json' },
  });
}

export async function loader({ request, params }: LoaderFunctionArgs) {
  return forward(request, params as { '*': string });
}

export async function action({ request, params }: ActionFunctionArgs) {
  return forward(request, params as { '*': string });
}
