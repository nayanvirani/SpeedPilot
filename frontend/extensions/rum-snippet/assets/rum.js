(function () {
  'use strict';

  // Vanilla PerformanceObserver-based Core Web Vitals collector - deliberately
  // not the `web-vitals` npm package, since Theme App Extension assets are
  // served as-is with no bundling step, and this keeps the storefront payload
  // to one small dependency-free file.
  var config = window.SpeedPilotConfig || {};
  var metrics = { lcp: null, cls: null, inp: null };
  var clsValue = 0;
  var maxInteractionDelay = 0;

  function observe(type, callback) {
    try {
      new PerformanceObserver(callback).observe({ type: type, buffered: true });
    } catch (e) {
      // Unsupported entry type in this browser - metric stays null, still send the rest.
    }
  }

  observe('largest-contentful-paint', function (list) {
    var entries = list.getEntries();
    var last = entries[entries.length - 1];
    if (last) metrics.lcp = last.renderTime || last.loadTime;
  });

  observe('layout-shift', function (list) {
    list.getEntries().forEach(function (entry) {
      if (!entry.hadRecentInput) clsValue += entry.value;
    });
    metrics.cls = clsValue;
  });

  observe('event', function (list) {
    list.getEntries().forEach(function (entry) {
      var delay = entry.processingEnd - entry.startTime;
      if (delay > maxInteractionDelay) maxInteractionDelay = delay;
    });
    metrics.inp = maxInteractionDelay;
  });

  function send() {
    if (metrics.lcp === null && metrics.cls === null && metrics.inp === null) return;

    var payload = JSON.stringify({
      shop_domain: config.shopDomain,
      page_url: location.href,
      lcp: metrics.lcp ? metrics.lcp / 1000 : null,
      inp: metrics.inp,
      cls: metrics.cls,
    });

    var url = (config.backendUrl || '') + '/api/rum-events';

    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
    } else {
      fetch(url, { method: 'POST', body: payload, headers: { 'Content-Type': 'application/json' }, keepalive: true });
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') send();
  });
  window.addEventListener('pagehide', send);
})();
