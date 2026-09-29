'use strict';

const express = require('express');
const { runLighthouse } = require('./lib/lighthouseRunner');
const { issuesFromLighthouse } = require('./lib/domAnalyzer');
const { thirdPartyImpacts } = require('./lib/thirdPartyAnalyzer');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 4000;

/**
 * Confirmed live (not assumed): this process genuinely runs out of memory
 * and crashes - "FATAL ERROR: ... JavaScript heap out of memory" in the
 * logs - after enough sequential scans, taking down whatever request was
 * in-flight at that moment ("Connection closed" / Railway's "Application
 * failed to respond" for the merchant). A full audit is up to ~24 page
 * scans (12 pages x 2 devices); Lighthouse + a fresh Chromium launch per
 * scan is inherently memory-heavy, and this single long-running process
 * never gets a chance to fully release what accumulates.
 *
 * Rather than chase down every possible leak inside Lighthouse/Playwright's
 * own internals, this recycles the whole process proactively, between
 * scans - never mid-request - once it's done enough work that an OOM crash
 * would otherwise become likely. Railway's restartPolicyType=ON_FAILURE
 * (confirmed via `railway deployment list`) restarts a fresh process
 * automatically on a non-zero exit, so this trades an unpredictable,
 * request-destroying crash for a short, predictable gap between scans
 * instead.
 */
const MAX_SCANS_BEFORE_RECYCLE = 15;
// Conservative on purpose - the actual container memory limit isn't known
// precisely, and the confirmed failure was Node's own V8 heap running out,
// not a generic container OOM-kill, so this needs real margin under
// whatever that ceiling turns out to be rather than cutting it close.
const MEMORY_RSS_LIMIT_MB = 500;

let scanCount = 0;
let recycling = false;

app.get('/health', (_req, res) => {
  if (recycling) {
    return res.status(503).json({ status: 'recycling' });
  }

  res.json({ status: 'ok' });
});

app.post('/scan', async (req, res) => {
  if (recycling) {
    // Distinct from a real scan failure - ScannerClient/RunAuditJob should
    // treat this as worth a fresh attempt, not report it to the merchant as
    // a broken page.
    return res.status(503).json({ error: 'Scanner is restarting, please retry.', code: 'RECYCLING' });
  }

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
  } finally {
    maybeRecycle();
  }
});

function maybeRecycle() {
  scanCount++;
  const rssMb = process.memoryUsage().rss / 1024 / 1024;

  if (scanCount < MAX_SCANS_BEFORE_RECYCLE && rssMb < MEMORY_RSS_LIMIT_MB) {
    return;
  }

  recycling = true;
  console.log(`[scanner] recycling after ${scanCount} scan(s), ${rssMb.toFixed(0)}MB RSS`);

  // The response for this request has already been sent above - this
  // delay is just headroom for Node to actually flush it to the socket
  // before the process exits, not a wait for anything else to finish.
  setTimeout(() => process.exit(1), 250);
}

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
