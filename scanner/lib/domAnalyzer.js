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

  issues.push(...lcpIssues(audits));
  issues.push(...imageIssues(audits));
  issues.push(...scriptIssues(audits));
  issues.push(...cssIssues(audits));
  issues.push(...domIssues(audits));
  issues.push(...clsIssues(audits));

  return issues;
}

/**
 * The single highest-value diagnosis to get right: what actually painted as
 * the Largest Contentful Paint, with real evidence (measured byte size,
 * displayed dimensions, format) rather than a generic "image is too large."
 * Cross-references three separate Lighthouse audits because none of them
 * alone carries every field: the element/rendered-size comes from
 * largest-contentful-paint-element, the real transfer size and MIME type
 * come from network-requests (matched by URL), and modern-image-formats
 * tells us whether it's already using a next-gen format.
 */
function lcpIssues(audits) {
  const lcpElementAudit = audits['largest-contentful-paint-element'];
  // details is a "list" of tables: [elementTable, phaseTable] - see
  // Lighthouse's LargestContentfulPaintElement.makeElementTable().
  const elementTable = lcpElementAudit?.details?.items?.[0];
  const node = elementTable?.items?.[0]?.node;

  if (!node?.snippet || !/^<img\b/i.test(node.snippet.trim())) {
    return []; // LCP element isn't an <img> (text, background-image, video) - no image evidence to show
  }

  const srcMatch = node.snippet.match(/\bsrc=["']([^"']+)["']/i);
  const url = srcMatch?.[1];

  if (!url) {
    return [];
  }

  const request = findNetworkRequest(audits, url);
  const sizeBytes = request?.transferSize ?? null;
  const format = formatFromMimeType(request?.mimeType) ?? formatFromUrl(url);
  const isModernFormat = ['WebP', 'AVIF'].includes(format);
  const rect = node.boundingRect;

  const severity = sizeBytes === null
    ? 'medium'
    : severityForBytes(sizeBytes, { critical: 400_000, high: 150_000 });

  const recommendations = [];
  if (!isModernFormat) {
    recommendations.push('convert it to WebP via Shopify CDN image transforms');
  }
  if (sizeBytes !== null && sizeBytes > 200_000) {
    recommendations.push('serve a size closer to how large it actually displays');
  }

  return [{
    category: 'image',
    severity,
    title: `Hero image is affecting LCP: ${shortUrl(url)}`,
    description: 'This is the largest element that paints on your page, so its load time '
      + 'directly determines your Largest Contentful Paint score - the metric that most '
      + 'affects how fast your page *feels* to a shopper.',
    why: 'A slow-loading hero image delays the moment shoppers see your page as "loaded," '
      + 'even if the rest of the page rendered instantly.',
    riskTier: 'medium',
    fixAvailable: false,
    meta: {
      evidence: {
        size_bytes: sizeBytes,
        displayed_width: rect?.width ? Math.round(rect.width) : null,
        displayed_height: rect?.height ? Math.round(rect.height) : null,
        format,
        detected_as: 'LCP candidate',
        estimated_impact: severity === 'critical' || severity === 'high' ? 'HIGH' : 'MEDIUM',
      },
      recommendation: recommendations.length > 0
        ? recommendations.join(', then ') + '.'
        : 'Already served efficiently - no action needed.',
    },
  }];
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
      why: "Without a reserved size, the browser doesn't know how much space to leave for "
        + 'this image, so surrounding content visibly jumps once it finishes loading.',
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
      why: 'Loading every image immediately competes for bandwidth with the images the '
        + "shopper can actually see first, slowing down what matters most.",
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
      why: 'Older formats like JPEG and PNG need more bytes than WebP/AVIF for the same '
        + 'visual quality, so every visitor downloads more data than necessary.',
      riskTier: 'medium',
      fixAvailable: false,
      meta: {},
    });
  }

  const responsive = audits['uses-responsive-images'];
  for (const item of responsive?.details?.items ?? []) {
    const rect = item.node?.boundingRect;
    issues.push({
      category: 'image',
      severity: (item.wastedBytes ?? 0) > 100_000 ? 'high' : 'medium',
      title: `Oversized image: ${shortUrl(item.url)}`,
      description: `Served larger than its displayed size, wasting `
        + `${Math.round((item.wastedBytes ?? 0) / 1024)}KB. Serve a size closer to `
        + "how it's actually displayed via Shopify CDN image transforms.",
      why: 'Downloading a bigger image than what actually gets displayed wastes bandwidth '
        + 'and slows the page down for no visible benefit to the shopper.',
      riskTier: 'medium',
      fixAvailable: false,
      meta: {
        evidence: {
          size_bytes: item.totalBytes ?? null,
          wasted_bytes: item.wastedBytes ?? null,
          displayed_width: rect?.width ? Math.round(rect.width) : null,
          displayed_height: rect?.height ? Math.round(rect.height) : null,
          estimated_impact: (item.wastedBytes ?? 0) > 100_000 ? 'HIGH' : 'MEDIUM',
        },
      },
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
      why: 'Shoppers download and parse code that never actually runs on this page, which '
        + 'delays everything else competing for the same bandwidth and CPU time.',
      riskTier: 'medium',
      fixAvailable: false,
      meta: { evidence: { wasted_bytes: item.wastedBytes ?? null } },
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
      why: "The browser won't draw anything on screen until this script finishes "
        + 'downloading and running, even if the rest of the page is ready.',
      riskTier: 'safe',
      fixAvailable: true,
      meta: {
        asset_key: null,
        fix_type: 'defer_script',
        script_src: item.url,
        evidence: { wasted_ms: item.wastedMs ?? null, estimated_impact: 'HIGH' },
      },
    });
  }

  const duplicates = audits['duplicated-javascript'];
  for (const item of duplicates?.details?.items ?? []) {
    issues.push({
      category: 'js',
      severity: 'medium',
      title: `Duplicate library: ${item.source ?? 'unknown'}`,
      description: 'Same library bundled more than once.',
      why: 'The same code is downloaded and parsed twice, wasting bandwidth and CPU time '
        + 'with no benefit - it was likely bundled by two different apps or theme sections.',
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
      why: 'Shoppers download styling rules that never apply to anything on this specific '
        + "page, adding weight without any visual benefit.",
      riskTier: 'medium',
      fixAvailable: false,
      meta: { evidence: { wasted_bytes: item.wastedBytes ?? null } },
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
      why: 'The browser holds off showing any styled content at all until this stylesheet '
        + 'finishes downloading, even content that never depends on it.',
      riskTier: 'medium',
      fixAvailable: false,
      meta: { evidence: { wasted_ms: item.wastedMs ?? null, estimated_impact: 'HIGH' } },
    });
  }

  const unminified = audits['unminified-css'];
  for (const item of unminified?.details?.items ?? []) {
    issues.push({
      category: 'css',
      severity: (item.wastedBytes ?? 0) > 30_000 ? 'medium' : 'low',
      title: `Unminified CSS: ${shortUrl(item.url)}`,
      description: `${Math.round((item.wastedBytes ?? 0) / 1024)}KB could be saved by minifying this file. `
        + 'Preview the exact byte savings before this is applied - whitespace inside CSS string '
        + 'values (rare) is the only thing minification could change unexpectedly.',
      why: 'Extra whitespace and comments in this file are downloaded by every single '
        + 'visitor even though they serve no visual purpose.',
      riskTier: 'medium',
      fixAvailable: true,
      meta: { fix_type: 'minify_css', css_url: item.url, evidence: { wasted_bytes: item.wastedBytes ?? null } },
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
    const score = item.score ?? 0;

    return {
      // Google's own CLS thresholds: >0.25 is "poor", 0.1-0.25 "needs improvement".
      category: 'cls',
      severity: score > 0.25 ? 'critical' : score > 0.1 ? 'high' : 'medium',
      title: `Layout shift: ${shortText(element)}`,
      description: causes.length > 0
        ? `This element shifted during load. Likely cause: ${causes.join(', ')}.`
        : 'This element shifted position during load, contributing to layout instability.',
      why: 'A shopper who starts reading or clicking gets interrupted when nearby content '
        + 'suddenly jumps - the most common cause of an accidental wrong click.',
      // Root causes vary too much (fonts, iframes, dynamic content, sliders)
      // to safely auto-fix any of them the same way - always a recommendation.
      riskTier: 'high',
      fixAvailable: false,
      meta: {
        evidence: {
          element: shortText(element),
          cause: causes.length > 0 ? causes.join(', ') : 'unknown',
          shift_score: Math.round(score * 1000) / 1000,
          estimated_impact: score > 0.25 ? 'HIGH' : score > 0.1 ? 'MEDIUM' : 'LOW',
        },
      },
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
      why: 'A very large page structure takes the browser longer to build, style and '
        + 'update, slowing down scrolling and every interaction, not just the initial load.',
      riskTier: 'high',
      fixAvailable: false,
      meta: {},
    });
  }

  return issues;
}

/**
 * @returns {object|undefined} the matching network-requests item, or undefined
 */
function findNetworkRequest(audits, url) {
  const items = audits['network-requests']?.details?.items ?? [];

  return items.find((i) => i.url === url) ?? items.find((i) => stripQuery(i.url) === stripQuery(url));
}

function stripQuery(url) {
  return typeof url === 'string' ? url.split('?')[0] : url;
}

function formatFromMimeType(mimeType) {
  if (!mimeType) return null;
  const match = mimeType.match(/^image\/(\w+)$/i);
  if (!match) return null;

  return { jpeg: 'JPEG', jpg: 'JPEG', png: 'PNG', webp: 'WebP', avif: 'AVIF', gif: 'GIF', svg: 'SVG' }[match[1].toLowerCase()]
    ?? match[1].toUpperCase();
}

function formatFromUrl(url) {
  const ext = (url.split('?')[0].split('.').pop() ?? '').toLowerCase();

  return { jpg: 'JPEG', jpeg: 'JPEG', png: 'PNG', webp: 'WebP', avif: 'AVIF', gif: 'GIF', svg: 'SVG' }[ext] ?? null;
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
