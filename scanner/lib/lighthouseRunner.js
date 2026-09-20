'use strict';

const { chromium } = require('playwright');

/**
 * Launches Chromium via Playwright with remote debugging enabled, then hands
 * that debugging port to the `lighthouse` npm package - this is what lets a
 * pure-Node service run real Lighthouse audits without chrome-launcher
 * managing its own Chrome install.
 */
async function runLighthouse(url) {
  const lighthouse = (await import('lighthouse')).default;

  const browser = await chromium.launch({
    // Railway's container doesn't grant the privileges Chromium's sandbox
    // needs, which makes browserType.launch fail outright without these -
    // this is the standard fix for running Chromium in most containers.
    args: [
      '--remote-debugging-port=9222',
      '--headless=new',
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
    ],
  });

  try {
    const page = await browser.newPage();
    await page.goto('about:blank');

    const runnerResult = await lighthouse(url, {
      port: 9222,
      output: 'json',
      onlyCategories: ['performance'],
      formFactor: 'mobile',
      screenEmulation: { mobile: true, width: 375, height: 667, deviceScaleFactor: 2 },
    });

    const lhr = runnerResult.lhr;

    // A password-protected store (any dev store, or a live store with
    // "restrict access" on) 302s every URL to /password - Lighthouse
    // follows that redirect silently and would otherwise audit the
    // password page itself, reporting a misleadingly perfect score for
    // content nobody can actually see. Fail loudly instead.
    const finalUrl = lhr.finalDisplayedUrl || lhr.finalUrl || '';
    if (/\/password(\?|$)/.test(new URL(finalUrl).pathname)) {
      const err = new Error(
        'This store is password-protected, so the scan was redirected to the '
        + 'password page instead of reaching the real content. Remove the '
        + 'storefront password (Online Store > Preferences) or scan a different, '
        + 'publicly accessible URL.'
      );
      err.code = 'PASSWORD_PROTECTED';
      throw err;
    }

    return lhr;
  } finally {
    await browser.close();
  }
}

module.exports = { runLighthouse };
