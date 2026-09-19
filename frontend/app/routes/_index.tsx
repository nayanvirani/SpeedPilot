import type { LoaderFunctionArgs } from '@remix-run/node';
import { redirect } from '@remix-run/node';

/**
 * Shopify loads the app's root URL (not /app) as the embedded iframe src,
 * with shop/host/embedded/id_token as query params it needs preserved
 * across the redirect into the actual dashboard.
 */
export async function loader({ request }: LoaderFunctionArgs) {
  const url = new URL(request.url);

  return redirect(`/app${url.search}`);
}
