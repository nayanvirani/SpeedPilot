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
      description: 'Image has no explicit width/height, causing layout shift (CLS).',
      riskTier: 'safe',
      fixAvailable: true,
      meta: { asset_key: null, fix_type: 'image_dimensions', selector: shortUrl(item.url) },
    });
  }

  const offscreen = audits['offscreen-images'];
  for (const item of offscreen?.details?.items ?? []) {
    issues.push({
      category: 'image',
      severity: 'medium',
      title: `Lazy-load candidate: ${shortUrl(item.url)}`,
      description: 'Below-the-fold image is not lazy-loaded.',
      riskTier: 'safe',
      fixAvailable: true,
      meta: { asset_key: null, fix_type: 'lazy_load', selector: shortUrl(item.url) },
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

  return issues;
}

function scriptIssues(audits) {
  const issues = [];
  const unused = audits['unused-javascript'];

  for (const item of unused?.details?.items ?? []) {
    issues.push({
      category: 'js',
      severity: (item.wastedBytes ?? 0) > 50_000 ? 'high' : 'medium',
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

  return issues;
}

function domIssues(audits) {
  const issues = [];
  const domSize = audits['dom-size'];

  if (domSize && domSize.score !== null && domSize.score < 0.9) {
    issues.push({
      category: 'theme',
      severity: domSize.score < 0.5 ? 'high' : 'medium',
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

module.exports = { issuesFromLighthouse };
