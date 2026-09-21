'use strict';

/**
 * Turns Lighthouse's third-party-summary audit into the app/script impact
 * rows the spec's differentiator feature needs (requests/size/impact by
 * installed app). Lighthouse groups by "entity" (root domain heuristics);
 * we pass its label through as-is as the initial "app name" - a future pass
 * can cross-reference this against the shop's actual installed-apps list
 * (via Admin GraphQL) to get real app names instead of domain guesses.
 */

// Shopify's own infrastructure, not a merchant-installed app - these are
// injected by Shopify itself via content_for_header (Shop Pay, checkout,
// core analytics), so the literal script tag never appears anywhere in the
// theme's own Liquid source for ScriptImpactActionService to find, and
// disabling/delaying core platform functionality isn't something an app
// should offer even where it were technically possible.
const PLATFORM_HOSTS = [
  'cdn.shopify.com',
  'shop.app',
  'shopifycloud.com',
  'monorail-edge.shopifysvc.com',
  'checkout.shopify.com',
];

function isPlatformUrl(url) {
  if (!url) return false;

  try {
    const host = new URL(url).hostname;

    return PLATFORM_HOSTS.some((platformHost) => host === platformHost || host.endsWith(`.${platformHost}`));
  } catch {
    return false;
  }
}

function thirdPartyImpacts(lhr) {
  const summary = lhr.audits?.['third-party-summary'];
  const items = summary?.details?.items ?? [];

  return items.map((item) => {
    const bytes = item.transferSize ?? 0;
    const blockingMs = item.blockingTime ?? item.mainThreadTime ?? 0;
    const url = item.subItems?.items?.[0]?.url ?? null;

    return {
      name: item.entity?.text ?? item.entity ?? 'Unknown script',
      url,
      requests: item.subItems?.items?.length ?? 1,
      bytes,
      blockingMs,
      impactLevel: impactLevelFor(bytes, blockingMs),
      isPlatform: isPlatformUrl(url),
    };
  });
}

function impactLevelFor(bytes, blockingMs) {
  if (bytes > 300_000 || blockingMs > 250) return 'high';
  if (bytes > 100_000 || blockingMs > 100) return 'medium';
  return 'low';
}

module.exports = { thirdPartyImpacts };
