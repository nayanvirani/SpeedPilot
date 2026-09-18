'use strict';

/**
 * Turns Lighthouse's third-party-summary audit into the app/script impact
 * rows the spec's differentiator feature needs (requests/size/impact by
 * installed app). Lighthouse groups by "entity" (root domain heuristics);
 * we pass its label through as-is as the initial "app name" - a future pass
 * can cross-reference this against the shop's actual installed-apps list
 * (via Admin GraphQL) to get real app names instead of domain guesses.
 */
function thirdPartyImpacts(lhr) {
  const summary = lhr.audits?.['third-party-summary'];
  const items = summary?.details?.items ?? [];

  return items.map((item) => {
    const bytes = item.transferSize ?? 0;
    const blockingMs = item.blockingTime ?? item.mainThreadTime ?? 0;

    return {
      name: item.entity?.text ?? item.entity ?? 'Unknown script',
      url: item.subItems?.items?.[0]?.url ?? null,
      requests: item.subItems?.items?.length ?? 1,
      bytes,
      blockingMs,
      impactLevel: impactLevelFor(bytes, blockingMs),
    };
  });
}

function impactLevelFor(bytes, blockingMs) {
  if (bytes > 300_000 || blockingMs > 250) return 'high';
  if (bytes > 100_000 || blockingMs > 100) return 'medium';
  return 'low';
}

module.exports = { thirdPartyImpacts };
