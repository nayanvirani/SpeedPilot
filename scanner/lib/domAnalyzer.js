'use strict';

/**
 * Post-processes Lighthouse's audit/artifact output for the "resource health"
 * checks the spec calls out that Lighthouse doesn't score directly: missing
 * image dimensions, duplicate libraries, excessive DOM size, render-blocking
 * resources. Returns audit_issues-shaped rows (category/severity/title/
 * riskTier/fixAvailable/meta) so RunAuditJob can persist them as-is.
 */

function issuesFromLighthouse(lhr) {
  const issues = [];
  const audits = lhr.audits || {};

  issues.push(...imageIssues(audits));
  issues.push(...scriptIssues(audits));
  issues.push(...cssIssues(audits));
  issues.push(...domIssues(audits));
  issues.push(...clsIssues(audits));

  return issues;
}

function imageIssues(audits) {
  const issues = [];
  const dims = audits['image-size-responsive'] || audits['unsized-images'];

  for (const item of dims?.details?.items ?? []) {
    issues.push({
      category: 'image',
      severity: 'medium',
      title: `Missing width/height: ${shortUrl(item.url)}`,
      description: 'Image has no explicit width/height, causing layout shift (CLS). '
        + 'Set explicit dimensions on this image in the theme editor - the correct '
        + "values depend on the image's real size, so this isn't auto-applied.",
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const offscreen = audits['offscreen-images'];
  for (const item of offscreen?.details?.items ?? []) {
    issues.push({
      category: 'image',
      severity: 'medium',
      title: `Lazy-load candidate: ${shortUrl(item.url)}`,
      description: 'Below-the-fold image is not lazy-loaded. Fixed by adding '
        + 'loading="lazy" to every plain image tag across the theme at once, '
        + 'not just this one image.',
      riskTier: 'safe',
      fixAvailable: true,
      meta: { fix_type: 'lazy_load_sweep' },
    });
  }

  const modernFormat = audits['modern-image-formats'];
  for (const item of modernFormat?.details?.items ?? []) {
    issues.push({
      category: 'image',
      severity: 'low',
      title: `Serve next-gen format: ${shortUrl(item.url)}`,
      description: 'Recommend WebP/AVIF via Shopify CDN image transforms.',
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const responsive = audits['uses-responsive-images'];
  for (const item of responsive?.details?.items ?? []) {
    issues.push({
      category: 'image',
      severity: (item.wastedBytes ?? 0) > 100_000 ? 'high' : 'medium',
      title: `Oversized image: ${shortUrl(item.url)}`,
      description: `Served larger than its displayed size, wasting `
        + `${Math.round((item.wastedBytes ?? 0) / 1024)}KB. Serve a size closer to `
        + "how it's actually displayed via Shopify CDN image transforms.",
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  return issues;
}

function scriptIssues(audits) {
  const issues = [];
  const unused = audits['unused-javascript'];

  for (const item of unused?.details?.items ?? []) {
    issues.push({
      category: 'js',
      severity: severityForBytes(item.wastedBytes, { critical: 150_000, high: 50_000 }),
      title: `Unused JS: ${shortUrl(item.url)}`,
      description: `${Math.round((item.wastedBytes ?? 0) / 1024)}KB unused.`,
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const renderBlocking = audits['render-blocking-resources'];
  for (const item of renderBlocking?.details?.items ?? []) {
    if (!/\.js(\?|$)/.test(item.url ?? '')) continue;
    issues.push({
      category: 'js',
      severity: 'high',
      title: `Render-blocking script: ${shortUrl(item.url)}`,
      description: 'Script blocks rendering and can likely be deferred.',
      riskTier: 'safe',
      fixAvailable: true,
      meta: { asset_key: null, fix_type: 'defer_script', script_src: item.url },
    });
  }

  const duplicates = audits['duplicated-javascript'];
  for (const item of duplicates?.details?.items ?? []) {
    issues.push({
      category: 'js',
      severity: 'medium',
      title: `Duplicate library: ${item.source ?? 'unknown'}`,
      description: 'Same library bundled more than once.',
      riskTier: 'high',
      fixAvailable: false,
      meta: {},
    });
  }

  return issues;
}

function cssIssues(audits) {
  const issues = [];
  const unusedCss = audits['unused-css-rules'];

  for (const item of unusedCss?.details?.items ?? []) {
    issues.push({
      category: 'css',
      severity: (item.wastedBytes ?? 0) > 30_000 ? 'high' : 'low',
      title: `Unused CSS: ${shortUrl(item.url)}`,
      description: `${Math.round((item.wastedBytes ?? 0) / 1024)}KB unused.`,
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const renderBlocking = audits['render-blocking-resources'];
  for (const item of renderBlocking?.details?.items ?? []) {
    if (!/\.css(\?|$)/.test(item.url ?? '')) continue;
    issues.push({
      category: 'css',
      severity: 'high',
      title: `Render-blocking stylesheet: ${shortUrl(item.url)}`,
      description: 'Blocks rendering until it downloads. Making it non-blocking (e.g. '
        + 'loading it async then swapping the media type) is a theme change worth '
        + 'reviewing rather than an automatic one, since it can affect how quickly '
        + "styled content becomes visible.",
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const unminified = audits['unminified-css'];
  for (const item of unminified?.details?.items ?? []) {
    issues.push({
      category: 'css',
      severity: (item.wastedBytes ?? 0) > 30_000 ? 'medium' : 'low',
      title: `Unminified CSS: ${shortUrl(item.url)}`,
      description: `${Math.round((item.wastedBytes ?? 0) / 1024)}KB could be saved by minifying this file.`,
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  return issues;
}

/**
 * "Show affected element and evidence" (root-cause CLS) - layout-shifts
 * lists the actual element that moved and Lighthouse's own diagnosed cause
 * (unsized media, a web font swapping in, an injected iframe, etc.), unlike
 * cumulative-layout-shift which is just the aggregate score already stored
 * as audit.cls.
 */
function clsIssues(audits) {
  const shifts = audits['layout-shifts'];
  const items = shifts?.details?.items ?? [];

  return items.slice(0, 5).map((item) => {
    const element = item.node?.nodeLabel || item.node?.selector || 'an element on the page';
    const causes = (item.subItems?.items ?? [])
      .map((sub) => sub.cause)
      .filter((cause) => typeof cause === 'string' && cause.length > 0);

    return {
      // Google's own CLS thresholds: >0.25 is "poor", 0.1-0.25 "needs improvement".
      category: 'cls',
      severity: (item.score ?? 0) > 0.25 ? 'critical' : (item.score ?? 0) > 0.1 ? 'high' : 'medium',
      title: `Layout shift: ${shortText(element)}`,
      description: causes.length > 0
        ? `This element shifted during load. Likely cause: ${causes.join(', ')}.`
        : 'This element shifted position during load, contributing to layout instability.',
      // Root causes vary too much (fonts, iframes, dynamic content, sliders)
      // to safely auto-fix any of them the same way - always a recommendation.
      riskTier: 'high',
      fixAvailable: false,
      meta: {},
    };
  });
}

function domIssues(audits) {
  const issues = [];
  const domSize = audits['dom-size'];

  if (domSize && domSize.score !== null && domSize.score < 0.9) {
    issues.push({
      category: 'theme',
      severity: domSize.score < 0.3 ? 'critical' : domSize.score < 0.5 ? 'high' : 'medium',
      title: 'Excessive DOM size',
      description: domSize.displayValue ?? 'DOM is larger than recommended.',
      riskTier: 'high',
      fixAvailable: false,
      meta: {},
    });
  }

  return issues;
}

function shortUrl(url) {
  if (!url) return 'unknown';
  try {
    const u = new URL(url);
    return u.pathname.split('/').pop() || u.hostname;
  } catch {
    return url;
  }
}

function shortText(text, maxLength = 60) {
  return text.length > maxLength ? `${text.slice(0, maxLength - 1)}…` : text;
}

function severityForBytes(bytes, { critical, high }) {
  const b = bytes ?? 0;
  if (b > critical) return 'critical';
  if (b > high) return 'high';
  return 'medium';
}

module.exports = { issuesFromLighthouse };
