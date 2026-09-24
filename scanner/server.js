'use strict';

const express = require('express');
const { runLighthouse } = require('./lib/lighthouseRunner');
const { issuesFromLighthouse } = require('./lib/domAnalyzer');
const { thirdPartyImpacts } = require('./lib/thirdPartyAnalyzer');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 4000;

app.get('/health', (_req, res) => res.json({ status: 'ok' }));

app.post('/scan', async (req, res) => {
  const { url, storefrontPassword, device } = req.body || {};

  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'Missing "url" in request body' });
  }

  try {
    const [lhr, rawHtml] = await Promise.all([
      runLighthouse(url, { storefrontPassword, device }),
      fetchRawHtml(url, storefrontPassword),
    ]);
    res.json(buildReport(lhr, rawHtml));
  } catch (err) {
    console.error(`Scan failed for ${url}:`, err);
    res.status(502).json({ error: 'Scan failed', message: err.message, code: err.code || null });
  }
});

/**
 * A plain, unauthenticated GET of the same URl Lighthouse audits - not a
 * browser, just the literal bytes Shopify's server sends before any client
 * JS runs. This is what lets thirdPartyAnalyzer confirm a flagged script is
 * genuinely static markup (safe for a Liquid `replace` to target) rather
 * than assuming from the URL alone. Best-effort: a password-protected store
 * (or any other fetch failure) just means no match gets detected this
 * scan - `content_for_header_match` stays null and the "Stop (verified)"
 * action simply isn't offered, the same safe-by-default degradation as any
 * other detection miss.
 */
async function fetchRawHtml(url, storefrontPassword) {
  // Unlocking a password-protected store needs the same cookie dance
  // lighthouseRunner.js's unlockStorefrontPassword() does via a real
  // browser page - not worth replicating over a plain fetch for a
  // best-effort detection pass. Skip outright; content_for_header_match
  // just stays null for these shops, same safe degradation as any other
  // detection miss.
  if (storefrontPassword) return null;

  try {
    const res = await fetch(url, { redirect: 'follow' });

    if (!res.ok) return null;

    const html = await res.text();

    // A locked store 302s every URL to /password - that page's HTML would
    // otherwise be searched (and never match anything real) instead of
    // being recognized as "we didn't actually see the content".
    if (/name=["']password["']/i.test(html) && /action=["'][^"']*\/password/i.test(html)) {
      return null;
    }

    return html;
  } catch {
    return null;
  }
}

/**
 * Maps Lighthouse's raw result into the flat contract RunAuditJob (Laravel)
 * expects: score, core metrics, resource weight, audit_issues rows, and
 * app/script impact rows. Keeping this shape stable is what lets the PHP
 * side stay a thin persistence layer instead of a second analysis engine.
 */
function buildReport(lhr, rawHtml) {
  const audits = lhr.audits || {};

  return {
    score: Math.round((lhr.categories?.performance?.score ?? 0) * 100),
    metrics: {
      lcp: metricValueSeconds(audits['largest-contentful-paint']),
      inp: audits['interaction-to-next-paint']?.numericValue ?? null,
      cls: audits['cumulative-layout-shift']?.numericValue ?? null,
      fcp: metricValueSeconds(audits['first-contentful-paint']),
      ttfb: metricValueSeconds(audits['server-response-time']),
      tbt: audits['total-blocking-time']?.numericValue != null
        ? Math.round(audits['total-blocking-time'].numericValue)
        : null,
      speed_index: metricValueSeconds(audits['speed-index']),
    },
    weight: {
      page_bytes: audits['total-byte-weight']?.numericValue ?? null,
      js_bytes: sumResourceType(audits, 'script'),
      css_bytes: sumResourceType(audits, 'stylesheet'),
      image_bytes: sumResourceType(audits, 'image'),
      request_count: totalRequestCount(audits),
    },
    issues: issuesFromLighthouse(lhr),
    thirdParty: thirdPartyImpacts(lhr, rawHtml),
    // Lighthouse already captures this as part of scoring performance - a
    // base64 JPEG data URI, small enough (mobile viewport, Lighthouse's own
    // compression) to store directly rather than needing separate file
    // storage/CDN infrastructure just for this.
    screenshot: audits['final-screenshot']?.details?.data ?? null,
  };
}

function metricValueSeconds(audit) {
  return audit?.numericValue != null ? audit.numericValue / 1000 : null;
}

function sumResourceType(audits, resourceType) {
  const items = audits['resource-summary']?.details?.items ?? [];
  const match = items.find((i) => i.resourceType === resourceType);
  return match?.transferSize ?? null;
}

function totalRequestCount(audits) {
  // network-requests lists every individual request Lighthouse observed -
  // a direct count, rather than relying on resource-summary's aggregate
  // "total" row existing in every Lighthouse version.
  const items = audits['network-requests']?.details?.items;
  return Array.isArray(items) ? items.length : null;
}

app.listen(PORT, () => {
  console.log(`SpeedPilot scanner listening on :${PORT}`);
});
