'use strict';

const { chromium } = require('playwright');

/**
 * Extracts the CSS actually needed to render the initial viewport
 * correctly - the real fix for the failure class that broke jewel-nests
 * twice in one night (SpeedPilot's own defer_css blanket-deferred whole
 * stylesheets, including ones with above-the-fold rules, causing visible
 * unstyled flashes). Deferring a whole file is a guess; this measures.
 *
 * Deliberately uses the real browser's own CSSOM (document.styleSheets,
 * element.matches()) to decide which rules apply to on-screen elements,
 * rather than a hand-rolled CSS parser - a real browser's selector engine
 * handles every edge case (pseudo-classes, specificity, nesting, @media)
 * correctly by construction; a parser built for this one feature would not,
 * and getting that wrong here reproduces the exact class of bug this exists
 * to prevent.
 *
 * On-demand only, via its own short-lived browser - NOT run as part of
 * every routine /scan. This service has documented real OOM crashes under
 * sustained load (see server.js's recycling comment); adding a second full
 * Chromium launch to the already-memory-heavy main audit path isn't worth
 * the risk for a preview-only feature a merchant triggers occasionally.
 *
 * @returns {Promise<{ stylesheets: Array<{href: string, criticalCss: string, totalRules: number, criticalRules: number}> }>}
 */
async function extractCriticalCss(url, { storefrontPassword } = {}) {
  const browser = await chromium.launch({
    args: ['--headless=new', '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });

  try {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    if (storefrontPassword) {
      await page.goto(`${new URL(url).origin}/password`, { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="password"]', storefrontPassword).catch(() => {});
      await page.click('button[type="submit"]').catch(() => {});
      await page.waitForTimeout(4000);
    }

    await page.goto(url, { waitUntil: 'load', timeout: 30000 });
    // Let whatever shows up by "load" finish painting before taking the
    // snapshot - the first visible frame, same moment LCP/initial-render
    // decisions actually matter, not the fully-settled page.
    await page.waitForTimeout(500);

    const result = await page.evaluate(() => {
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const byHref = new Map();

      const inViewport = (el) => {
        const r = el.getBoundingClientRect();

        return r.bottom > 0 && r.top < vh && r.right > 0 && r.left < vw && (r.width > 0 || r.height > 0);
      };

      const collect = (rules, bucket, totalCounter) => {
        for (const rule of Array.from(rules)) {
          totalCounter.count++;

          if (rule.type === CSSRule.STYLE_RULE) {
            let matched = false;

            try {
              // A rule can have multiple comma-separated selectors
              // (".a, .b { ... }") - matches() only tests the whole
              // selectorText as one unit, so split and test each,
              // short-circuiting on the first viewport hit.
              const selectors = rule.selectorText.split(',').map((s) => s.trim());

              for (const sel of selectors) {
                if (!sel) continue;
                const elements = document.querySelectorAll(sel);

                for (const el of elements) {
                  if (inViewport(el)) {
                    matched = true;
                    break;
                  }
                }

                if (matched) break;
              }
            } catch (e) {
              // Invalid/unsupported selector (browser-prefixed pseudo-
              // classes, etc.) - skip rather than guess.
              matched = false;
            }

            if (matched) bucket.push(rule.cssText);
          } else if (rule.type === CSSRule.FONT_FACE_RULE || rule.type === CSSRule.KEYFRAMES_RULE) {
            // Always kept regardless of viewport - a @font-face or
            // @keyframes declaration has no "position" of its own to
            // check, and omitting one a critical rule depends on would
            // silently break that rule's rendering.
            bucket.push(rule.cssText);
          } else if (rule.type === CSSRule.MEDIA_RULE) {
            // Only relevant if the current viewport actually matches
            // this media query - recurses into the same collection,
            // keeping nested rules under their own @media wrapper so
            // inlining the result doesn't apply them unconditionally.
            if (window.matchMedia(rule.conditionText).matches) {
              const nested = [];
              collect(rule.cssRules, nested, totalCounter);

              if (nested.length > 0) {
                bucket.push(`@media ${rule.conditionText} {\n${nested.join('\n')}\n}`);
              }
            }
          }
        }
      };

      for (const sheet of Array.from(document.styleSheets)) {
        let rules;

        try {
          rules = sheet.cssRules;
        } catch (e) {
          // Cross-origin stylesheet (a CDN/app-hosted file without CORS
          // headers) - the browser itself refuses to expose .cssRules for
          // these, nothing this can do about that.
          continue;
        }

        if (!rules) continue;

        const href = sheet.href || '(inline)';
        const bucket = [];
        const totalCounter = { count: 0 };
        collect(rules, bucket, totalCounter);

        if (bucket.length > 0) {
          const existing = byHref.get(href);

          if (existing) {
            existing.criticalRules.push(...bucket);
            existing.totalRules += totalCounter.count;
          } else {
            byHref.set(href, { criticalRules: bucket, totalRules: totalCounter.count });
          }
        }
      }

      return Array.from(byHref.entries()).map(([href, data]) => ({
        href,
        criticalCss: data.criticalRules.join('\n'),
        totalRules: data.totalRules,
        criticalRules: data.criticalRules.length,
      }));
    });

    return { stylesheets: result };
  } finally {
    await browser.close();
  }
}

module.exports = { extractCriticalCss };
