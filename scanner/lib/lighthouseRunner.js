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
    args: ['--remote-debugging-port=9222', '--headless=new'],
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

    return runnerResult.lhr;
  } finally {
    await browser.close();
  }
}

module.exports = { runLighthouse };
