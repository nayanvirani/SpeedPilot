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
    const lhr = await runLighthouse(url, { storefrontPassword, device });
    res.json(buildReport(lhr));
  } catch (err) {
    console.error(`Scan failed for ${url}:`, err);
    res.status(502).json({ error: 'Scan failed', message: err.message, code: err.code || null });
  }
});

/**
 * Maps Lighthouse's raw result into the flat contract RunAuditJob (Laravel)
 * expects: score, core metrics, resource weight, audit_issues rows, and
 * app/script impact rows. Keeping this shape stable is what lets the PHP
 * side stay a thin persistence layer instead of a second analysis engine.
 */
function buildReport(lhr) {
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
    thirdParty: thirdPartyImpacts(lhr),
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
