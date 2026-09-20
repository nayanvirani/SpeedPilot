'use strict';

const { chromium } = require('playwright');

/**
 * Shopify's storefront password gate sets an auth cookie once submitted.
 * Lighthouse opens its own tab via a fresh CDP target, which - unlike
 * plain tabs in one real browser window - does NOT share cookies with a
 * Playwright page from a separate browser.newPage() call (each call gets
 * its own isolated browser context). Rather than fight that, this reads
 * the cookie back off the page that unlocked it and hands it to Lighthouse
 * directly via its own extraHeaders setting, which every request Lighthouse
 * makes carries regardless of which context it ends up in.
 *
 * @returns {Promise<string|null>} a `Cookie:` header value, or null if the
 *                                  unlock didn't produce one
 */
async function unlockStorefrontPassword(browser, origin, password) {
  const page = await browser.newPage();

  try {
    await page.goto(`${origin}/password`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');

    // The form submits via fetch (data-remote="true"), not a plain POST, so
    // racing a click against waitForNavigation is unreliable - the redirect
    // this triggers lands a beat later. A flat wait is what actually works.
    await page.waitForTimeout(4000);

    const cookies = await page.context().cookies(origin);

    return cookies.length > 0
      ? cookies.map((c) => `${c.name}=${c.value}`).join('; ')
      : null;
  } finally {
    await page.close();
  }
}

/**
 * Launches Chromium via Playwright with remote debugging enabled, then hands
 * that debugging port to the `lighthouse` npm package - this is what lets a
 * pure-Node service run real Lighthouse audits without chrome-launcher
 * managing its own Chrome install.
 */
async function runLighthouse(url, options = {}) {
  const { storefrontPassword } = options;
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
    let cookieHeader = null;

    if (storefrontPassword) {
      cookieHeader = await unlockStorefrontPassword(browser, new URL(url).origin, storefrontPassword);
    } else {
      // Matches the previously-working behavior of always having at least
      // one page open before Lighthouse attaches over CDP.
      const warmupPage = await browser.newPage();
      await warmupPage.goto('about:blank');
    }

    const runnerResult = await lighthouse(url, {
      port: 9222,
      output: 'json',
      onlyCategories: ['performance'],
      formFactor: 'mobile',
      screenEmulation: { mobile: true, width: 375, height: 667, deviceScaleFactor: 2 },
      extraHeaders: cookieHeader ? { Cookie: cookieHeader } : undefined,
    });

    const lhr = runnerResult.lhr;

    // A password-protected store (any dev store, or a live store with
    // "restrict access" on) 302s every URL to /password - Lighthouse
    // follows that redirect silently and would otherwise audit the
    // password page itself, reporting a misleadingly perfect score for
    // content nobody can actually see. Fail loudly instead.
    const finalUrl = lhr.finalDisplayedUrl || lhr.finalUrl || '';
    if (/\/password(\?|$)/.test(new URL(finalUrl).pathname)) {
      const message = storefrontPassword
        ? 'This store is password-protected and the saved password did not unlock it - '
          + 'double-check it under Storefront password on the Dashboard.'
        : "This store is password-protected, so the scan was redirected to the password "
          + 'page instead of reaching the real content. Save your storefront password under '
          + 'Storefront password on the Dashboard so SpeedPilot can unlock it automatically.';
      const err = new Error(message);
      err.code = 'PASSWORD_PROTECTED';
      throw err;
    }

    return lhr;
  } finally {
    await browser.close();
  }
}

module.exports = { runLighthouse };
