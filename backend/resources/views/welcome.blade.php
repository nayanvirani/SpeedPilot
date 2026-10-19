<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpeedPilot - Shopify Speed Optimization</title>
    <meta name="description" content="SpeedPilot audits your Shopify store's real performance, applies safe fixes automatically, and shows you exactly which apps are slowing you down.">
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
      /* "Soft Plum" (design review option L3) - blush/lavender ground,
         deep plum accent, Manrope. Dark variant below is a matching plum
         dark theme, not a generic inversion, so prefers-color-scheme/
         data-theme switching stays on-brand either way. */
      :root{
        --ink:#f6f1f7; --surface:#ffffff; --surface-2:#eee3ef; --border:#e6d9e6;
        --text:#2b1f2a; --text-muted:#736471; --text-faint:#a596a3;
        --accent:#7a3b66; --accent-ink:#ffffff; --accent-2:#a14e78;
        --good:#3f8a6b; --warn:#b2791a; --critical:#b23a3a;
        --good-bg:#e4f2eb; --warn-bg:#f8ebd4; --critical-bg:#f7e4e2;
        --shadow: 0 1px 2px rgba(43,31,42,.04), 0 8px 24px -12px rgba(43,31,42,.12);
      }
      @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
          --ink:#17111a; --surface:#201922; --surface-2:#2a212d; --border:#362b39;
          --text:#f3eaf1; --text-muted:#b49dae; --text-faint:#7a6577;
          --accent:#e0a8c4; --accent-ink:#2b1120; --accent-2:#c98fb0;
          --good:#6fd9a8; --warn:#f2c572; --critical:#f28b8b;
          --good-bg:#17291f; --warn-bg:#2b2210; --critical-bg:#2e1819;
          --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px -12px rgba(0,0,0,.55);
        }
      }
      :root[data-theme="dark"]{
        --ink:#17111a; --surface:#201922; --surface-2:#2a212d; --border:#362b39;
        --text:#f3eaf1; --text-muted:#b49dae; --text-faint:#7a6577;
        --accent:#e0a8c4; --accent-ink:#2b1120; --accent-2:#c98fb0;
        --good:#6fd9a8; --warn:#f2c572; --critical:#f28b8b;
        --good-bg:#17291f; --warn-bg:#2b2210; --critical-bg:#2e1819;
        --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px -12px rgba(0,0,0,.55);
      }

      *{box-sizing:border-box;}
      body{
        background:var(--ink); color:var(--text); margin:0;
        font-family:'Manrope',system-ui,sans-serif;
        padding-inline:20px;
      }
      .wrap{max-width:1040px; margin:0 auto;}
      h1,h2,h3{font-family:'Manrope',system-ui,sans-serif; font-weight:800; letter-spacing:-.01em; text-wrap:balance; margin:0;}
      .mono{font-family:'IBM Plex Mono',ui-monospace,monospace;}
      p{color:var(--text-muted); line-height:1.6; margin:0;}
      a{color:inherit;}

      header{display:flex; align-items:center; justify-content:space-between; padding-block:28px; gap:16px; flex-wrap:wrap;}
      .brand{display:flex; align-items:center; gap:10px; font-family:'Manrope',sans-serif; font-weight:700; font-size:18px; text-decoration:none;}
      .brand-mark{width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg,var(--accent),var(--accent-2)); display:flex; align-items:center; justify-content:center; flex:none;}
      .brand-mark svg{width:16px; height:16px;}
      nav{display:flex; align-items:center; gap:28px;}
      nav a{font-size:14px; color:var(--text-muted); text-decoration:none;}
      nav a:hover{color:var(--text);}

      .hero{display:grid; grid-template-columns:1.1fr .9fr; gap:48px; align-items:center; padding-block:32px 56px;}
      @media (max-width:820px){ .hero{grid-template-columns:1fr;} }
      .eyebrow{
        display:inline-flex; align-items:center; gap:8px; font-family:'IBM Plex Mono',monospace;
        font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--accent);
        background:color-mix(in srgb, var(--accent) 12%, transparent); border:1px solid color-mix(in srgb, var(--accent) 30%, transparent);
        padding:6px 12px; border-radius:999px; margin-bottom:18px;
      }
      .eyebrow-dot{width:6px; height:6px; border-radius:50%; background:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent);}
      h1{font-size:44px; line-height:1.08; font-weight:800;}
      @media (max-width:560px){ h1{font-size:32px;} }
      h1 em{font-style:normal; color:var(--accent);}
      .hero p{font-size:17px; margin-top:18px; max-width:46ch;}
      .cta-row{display:flex; gap:12px; margin-top:28px; flex-wrap:wrap;}
      .btn{
        font-family:'Manrope',sans-serif; font-weight:600; font-size:14.5px; padding:13px 22px; border-radius:10px;
        border:1px solid transparent; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px;
      }
      .btn-primary{background:var(--accent); color:var(--accent-ink);}
      .btn-ghost{background:transparent; border-color:var(--border); color:var(--text);}

      .score-card{
        background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:22px;
        box-shadow:var(--shadow); position:relative; overflow:hidden;
      }
      .score-card::before{
        content:""; position:absolute; inset:0; background:radial-gradient(120px 80px at 85% -10%, color-mix(in srgb, var(--accent) 18%, transparent), transparent);
      }
      .score-top{display:flex; justify-content:space-between; align-items:flex-start; position:relative;}
      .score-url{font-family:'IBM Plex Mono',monospace; font-size:12px; color:var(--text-faint);}
      .badge{font-size:11px; font-weight:600; padding:4px 9px; border-radius:999px;}
      .badge-good{background:var(--good-bg); color:var(--good);}
      .score-num-row{display:flex; align-items:baseline; gap:14px; margin-top:14px; position:relative;}
      .score-num{font-family:'IBM Plex Mono',monospace; font-size:56px; font-weight:600; color:var(--good); line-height:1;}
      .score-delta{font-family:'IBM Plex Mono',monospace; font-size:14px; color:var(--good); background:var(--good-bg); padding:3px 9px; border-radius:6px;}
      .cwv-row{display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-top:20px; position:relative;}
      .cwv{background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:9px 6px; text-align:center;}
      .cwv-label{font-size:10px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.05em;}
      .cwv-val{font-family:'IBM Plex Mono',monospace; font-size:14px; font-weight:600; margin-top:3px;}
      .thumb-row{display:flex; gap:8px; margin-top:16px; position:relative;}
      .thumb{flex:1; height:46px; border-radius:8px; border:1px solid var(--border); background:
        linear-gradient(180deg, color-mix(in srgb, var(--accent) 14%, var(--surface-2)) 0%, var(--surface-2) 100%);}

      section{padding-block:52px; border-top:1px solid var(--border);}
      .section-head{max-width:56ch; margin-bottom:34px;}
      .section-head .eyebrow{margin-bottom:12px;}
      h2{font-size:28px; font-weight:700;}
      .section-head p{margin-top:10px; font-size:15.5px;}

      .grid{display:grid; grid-template-columns:repeat(3,1fr); gap:16px;}
      @media (max-width:820px){ .grid{grid-template-columns:repeat(2,1fr);} }
      @media (max-width:560px){ .grid{grid-template-columns:1fr;} }
      .card{background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:20px; display:flex; flex-direction:column; gap:10px;}
      .card-icon{width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; background:var(--surface-2); border:1px solid var(--border);}
      .card-icon svg{width:17px; height:17px; stroke:var(--accent);}
      .card h3{font-size:15.5px; font-weight:600;}
      .card p{font-size:13.8px; line-height:1.55;}
      .tag{align-self:flex-start; font-family:'IBM Plex Mono',monospace; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--accent-2); background:color-mix(in srgb, var(--accent-2) 12%, transparent); padding:3px 8px; border-radius:6px;}

      .diff-demo{display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:8px;}
      @media (max-width:640px){ .diff-demo{grid-template-columns:1fr;} }
      .diff-col{border-radius:12px; border:1px solid var(--border); overflow:hidden;}
      .diff-head{font-family:'IBM Plex Mono',monospace; font-size:11px; padding:8px 12px; border-bottom:1px solid var(--border);}
      .diff-head.before{background:var(--critical-bg); color:var(--critical);}
      .diff-head.after{background:var(--good-bg); color:var(--good);}
      .diff-body{background:var(--surface); font-family:'IBM Plex Mono',monospace; font-size:12px; padding:14px; line-height:1.7; color:var(--text-muted); white-space:pre;}
      .diff-body .hl{color:var(--text); background:color-mix(in srgb, var(--accent) 16%, transparent); border-radius:3px;}

      .chart-card{background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:22px;}
      .chart-legend{display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;}
      .chart-legend .n{font-family:'IBM Plex Mono',monospace; font-size:22px; font-weight:600;}

      .pricing-card{
        background:var(--surface); border:1px solid var(--border); border-radius:18px; padding:36px;
        display:grid; grid-template-columns:1fr auto; gap:32px; align-items:center; box-shadow:var(--shadow);
      }
      @media (max-width:700px){ .pricing-card{grid-template-columns:1fr;} }
      .price-tag{font-family:'IBM Plex Mono',monospace; font-size:46px; font-weight:600;}
      .price-tag span{font-size:16px; color:var(--text-muted); font-weight:400;}
      .plan-name{font-family:'IBM Plex Mono',monospace; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--accent); margin-bottom:6px;}
      .check-list{display:grid; grid-template-columns:1fr 1fr; gap:10px 20px; margin-top:20px;}
      @media (max-width:560px){ .check-list{grid-template-columns:1fr;} }
      .check-item{display:flex; align-items:flex-start; gap:9px; font-size:14px; color:var(--text);}
      .check-item svg{width:16px; height:16px; stroke:var(--good); flex:none; margin-top:2px;}
      .price-cta{margin-top:22px; text-align:right;}
      @media (max-width:700px){ .price-cta{text-align:left;} }
      .pricing-row{display:flex; flex-direction:column; gap:18px;}
      .pricing-card--free{box-shadow:none; background:var(--surface-2);}
      .check-item--muted{color:var(--text-faint);}
      .check-item--muted svg{stroke:var(--text-faint);}

      footer{padding-block:36px 48px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px;}
      footer p{font-size:12.5px;}
    </style>
</head>
<body>

<div class="wrap">

  <header>
    <a href="/" class="brand">
      <span class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="2.4" stroke-linecap="round"><path d="M4 12h6l2-7 3 14 2-7h3"/></svg>
      </span>
      SpeedPilot
    </a>
    <nav>
      <a href="#features">Features</a>
      <a href="#how">How it works</a>
      <a href="#pricing">Pricing</a>
      <a class="btn btn-primary" href="https://apps.shopify.com/speedpilot" style="padding:10px 18px;">Install app</a>
    </nav>
  </header>

  <section class="hero" style="border-top:none; padding-top:8px;">
    <div>
      <div class="eyebrow"><span class="eyebrow-dot"></span> Free audit, no card required</div>
      <h1>Find what's slow. <em>Fix what's safe.</em> Keep it that way.</h1>
      <p>SpeedPilot audits your Shopify storefront's real performance, applies only optimizations proven safe, and shows you exactly which apps and scripts are costing you speed — with backup and rollback on every change. Then it keeps watching: continuous monitoring catches regressions the moment a new app or theme change slows you back down, with an alert and a diagnosis, not just a dashboard you have to remember to check.</p>
      <div class="cta-row">
        <a class="btn btn-primary" href="https://apps.shopify.com/speedpilot">Scan My Store →</a>
        <a class="btn btn-ghost" href="#pricing">View pricing</a>
      </div>
    </div>

    <div class="score-card">
      <div class="score-top">
        <div class="score-url">yourstore.myshopify.com</div>
        <span class="badge" style="background:var(--surface-2); color:var(--text-muted);">Mobile · Home</span>
      </div>
      <div style="display:flex; align-items:center; justify-content:center; gap:22px; padding:18px 0; position:relative;">
        <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
          <div style="width:82px; height:82px; border-radius:50%; background:conic-gradient(var(--critical) 27%, var(--surface-2) 0); display:flex; align-items:center; justify-content:center;">
            <div style="width:64px; height:64px; border-radius:50%; background:var(--surface); display:flex; align-items:center; justify-content:center;">
              <span class="mono" style="font-size:21px; font-weight:600;">27</span>
            </div>
          </div>
          <span style="font-size:10.5px; color:var(--text-faint); font-weight:600; letter-spacing:.05em;">BEFORE</span>
        </div>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--text-faint)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        <div style="display:flex; flex-direction:column; align-items:center; gap:8px;">
          <div style="width:82px; height:82px; border-radius:50%; background:conic-gradient(var(--good) 91%, var(--surface-2) 0); display:flex; align-items:center; justify-content:center;">
            <div style="width:64px; height:64px; border-radius:50%; background:var(--surface); display:flex; align-items:center; justify-content:center;">
              <span class="mono" style="font-size:21px; font-weight:600; color:var(--good);">91</span>
            </div>
          </div>
          <span style="font-size:10.5px; color:var(--text-faint); font-weight:600; letter-spacing:.05em;">AFTER</span>
        </div>
      </div>
      <div style="display:flex; flex-direction:column; gap:9px; padding-top:15px; border-top:1px solid var(--border); position:relative;">
        <div style="display:flex; align-items:center; gap:9px; padding-top:13px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
          <span style="font-size:12.8px; color:var(--text-muted);">3 render-blocking stylesheets deferred</span>
        </div>
        <div style="display:flex; align-items:center; gap:9px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
          <span style="font-size:12.8px; color:var(--text-muted);">1 duplicate app detected and delayed</span>
        </div>
        <div style="display:flex; align-items:center; gap:9px;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
          <span style="font-size:12.8px; color:var(--text-muted);">24 images switched to lazy-load</span>
        </div>
      </div>
    </div>
  </section>

  <section style="padding-block:28px; border-top:1px solid var(--border);">
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:20px;">
      <div style="display:flex; align-items:center; gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" style="flex:none;"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        <span style="font-size:13.5px; font-weight:500; color:var(--text-muted);">Real Lighthouse audits, not guesses</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none;"><path d="M12 2l8 3v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V5l8-3z"/></svg>
        <span style="font-size:13.5px; font-weight:500; color:var(--text-muted);">Every fix has one-click rollback</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" style="flex:none;"><rect x="3" y="4" width="18" height="4" rx="1.5"/><rect x="3" y="10" width="18" height="4" rx="1.5"/><rect x="3" y="16" width="18" height="4" rx="1.5"/></svg>
        <span style="font-size:13.5px; font-weight:500; color:var(--text-muted);">See which apps slow you down</span>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none;"><path d="M4 10a8 8 0 0115-3M4 7v3h3"/><path d="M20 14a8 8 0 01-15 3M20 17v-3h-3"/></svg>
        <span style="font-size:13.5px; font-weight:500; color:var(--text-muted);">Weekly automatic monitoring</span>
      </div>
    </div>
  </section>

  <section id="how">
    <div class="section-head" style="text-align:center; margin-inline:auto;">
      <div class="eyebrow" style="justify-content:center;"><span class="eyebrow-dot"></span> How it works</div>
      <h2>Three steps, nothing hidden</h2>
      <p>No black box. Every change SpeedPilot makes is visible, reversible, and explained in plain language.</p>
    </div>
    <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:1px; background:var(--border); border:1px solid var(--border); border-radius:16px; overflow:hidden;">
      <div style="background:var(--surface); padding:28px; display:flex; flex-direction:column; gap:12px;">
        <span class="mono" style="font-size:13px; color:var(--accent); font-weight:700;">01</span>
        <h3 style="font-size:18px;">Scan</h3>
        <p style="font-size:14px;">Run a real Lighthouse and Core Web Vitals audit against your live storefront, page by page — home, product, collection, cart.</p>
      </div>
      <div style="background:var(--surface); padding:28px; display:flex; flex-direction:column; gap:12px;">
        <span class="mono" style="font-size:13px; color:var(--accent); font-weight:700;">02</span>
        <h3 style="font-size:18px;">Fix</h3>
        <p style="font-size:14px;">Safe issues apply automatically with a full backup. Riskier ones wait for your one-click review and approval.</p>
      </div>
      <div style="background:var(--surface); padding:28px; display:flex; flex-direction:column; gap:12px;">
        <span class="mono" style="font-size:13px; color:var(--accent); font-weight:700;">03</span>
        <h3 style="font-size:18px;">Monitor</h3>
        <p style="font-size:14px;">Weekly automatic re-scans catch regressions and newly installed apps before they cost you conversions.</p>
      </div>
    </div>
  </section>

  <section id="features">
    <div class="section-head">
      <div class="eyebrow"><span class="eyebrow-dot"></span> What it actually does</div>
      <h2>Every screen shows the real thing — not just a number</h2>
      <p>No black box. Every issue names the exact page it's on, explains why it matters in plain language, and if SpeedPilot fixed it automatically, you can see the exact before/after change.</p>
    </div>
    <div class="grid">
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></span>
        <span class="tag">Audit</span>
        <h3>Full-store scans, on mobile and desktop</h3>
        <p>Checks your homepage, product, collection, cart, search and blog pages — each on both mobile and desktop — because real slowdowns often hide on the templates a homepage-only score never looks at.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l-5-5 5-5M15 6l5 5-5 5"/></svg></span>
        <span class="tag">Fix your way</span>
        <h3>Auto-fix it, or copy the code yourself</h3>
        <p>Safe fixes like deferring render-blocking scripts and lazy-loading images can apply automatically with full backup and one-click rollback — or view the exact code change and paste it into your theme yourself, no approval wait required either way.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="4" height="9"/><rect x="10" y="6" width="4" height="14"/><rect x="17" y="3" width="4" height="17"/></svg></span>
        <span class="tag">Monitor</span>
        <h3>Score trends, not one-off snapshots</h3>
        <p>Scheduled re-scans plot your score over time on an actual chart, so you can see whether that new app you installed helped or hurt.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.2 5.5L20 11l-5.8 2.5L12 19l-2.2-5.5L4 11l5.8-2.5z"/></svg></span>
        <span class="tag">AI</span>
        <h3>AI recommendations on demand</h3>
        <p>For anything that can't be auto-applied, ask for a specific, written recommendation instead of a generic "optimize your images" tip.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg></span>
        <span class="tag">Impact</span>
        <h3>See which apps are slowing you down</h3>
        <p>Every third-party script gets attributed to the app that loaded it, with its actual weight and blocking time — so you know what to disable, delay, or keep.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v6h6M20 20v-6h-6"/><path d="M20 9a8 8 0 00-14.9-3M4 15a8 8 0 0014.9 3"/></svg></span>
        <span class="tag">Rollback</span>
        <h3>Nothing is ever a one-way door</h3>
        <p>Every automatic fix keeps a full backup of the original file. Undo any single change from the Optimizations screen, any time.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
        <span class="tag">Score breakdown</span>
        <h3>One score, six honest sub-scores</h3>
        <p>Your overall score breaks down into Core Web Vitals, Images, JavaScript, CSS, Third-party apps and Theme — so you know exactly what's dragging you down, not just that something is.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path d="M12 2a15 15 0 010 20M12 2a15 15 0 000 20M2 12h20"/></svg></span>
        <span class="tag">Real visitors</span>
        <h3>Lab data and real-visitor data, together</h3>
        <p>Lighthouse scans show what's possible; a lightweight on-storefront collector shows what your actual shoppers experience — both Core Web Vitals views, side by side.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c.14.36.4.66.75.85"/></svg></span>
        <span class="tag">Your choice</span>
        <h3>Test on a copy first, or go live directly</h3>
        <p>Pick your live theme for immediate effect, or point SpeedPilot at a theme you've duplicated yourself in Shopify — fixes land there first so you can review before publishing.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 5v2M12 17v2M5 12h2M17 12h2M7.5 7.5l1.4 1.4M15.1 15.1l1.4 1.4M16.5 7.5l-1.4 1.4M8.9 15.1l-1.4 1.4"/></svg></span>
        <span class="tag">Per-page control</span>
        <h3>Decide when each app loads, per page</h3>
        <p>Smart Script Manager lets you turn a chat widget off on product pages, delay it until the shopper scrolls on collection pages, and load it immediately on the homepage — one rule per app, per page type, no theme code required.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></span>
        <span class="tag">Image Health</span>
        <h3>Every broken image pattern, in one place</h3>
        <p>Missing alt text, oversized files, images without reserved dimensions, next-gen format candidates, large animated GIFs, and the same asset accidentally loaded twice — counted and grouped, not buried in a flat issue list.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l-5-5 5-5M15 6l5 5-5 5"/><path d="M3 13h4M17 13h4"/></svg></span>
        <span class="tag">What if</span>
        <h3>See the score before you disable anything</h3>
        <p>"What would my score be without this app?" runs a real second scan with that app's scripts blocked — no theme write, nothing live changes — so you know the real impact before you decide, not a guess.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/><path d="M3 3l2 2M21 3l-2 2"/></svg></span>
        <span class="tag">Timeline</span>
        <h3>Why your score changed, not just that it did</h3>
        <p>A running log of every applied fix, every reverted change, and every monitoring run worth knowing about — "new script detected," "regression detected," "+6 points this week" — in order, with a reason attached to each.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 18h6"/></svg></span>
        <span class="tag">Real visitors</span>
        <h3>Real-visitor data, split by device and browser</h3>
        <p>The on-storefront collector now breaks real Core Web Vitals down by mobile vs. desktop and by browser — so "my score looks fine" and "my mobile Safari shoppers are waiting three extra seconds" can both be true, and you'll know which.</p>
      </div>
    </div>
  </section>

  <section style="background:var(--surface); border-top:1px solid var(--border); border-bottom:1px solid var(--border);">
    <div class="wrap" style="padding-block:60px; display:flex; gap:48px; align-items:center; flex-wrap:wrap;">
      <div style="flex:1 1 380px; min-width:0; display:flex; flex-direction:column; gap:14px;">
        <div class="eyebrow"><span class="eyebrow-dot"></span> Transparent scoring</div>
        <h2 style="font-size:30px; line-height:1.15;">See exactly where your score comes from</h2>
        <p style="font-size:15px;">
          No single opaque number. SpeedPilot breaks every audit down by category, so you know whether
          it's your images, your apps, or your theme that's costing you — and what to do about each one.
        </p>
      </div>
      <div style="flex:1 1 400px; min-width:280px;">
        <div class="score-card" style="padding:26px; display:flex; flex-direction:column; gap:16px;">
          <span style="font-size:11px; font-weight:700; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.06em; position:relative;">Example score breakdown</span>
          <div style="display:flex; flex-direction:column; gap:3px; position:relative;">
            <div style="display:flex; justify-content:space-between; font-size:13px;"><span style="color:var(--text);">Core Web Vitals</span><span class="mono" style="font-weight:600;">48</span></div>
            <div style="height:7px; border-radius:4px; background:var(--surface-2);"><div style="height:100%; width:48%; border-radius:4px; background:var(--warn);"></div></div>
          </div>
          <div style="display:flex; flex-direction:column; gap:3px; position:relative;">
            <div style="display:flex; justify-content:space-between; font-size:13px;"><span style="color:var(--text);">Images</span><span class="mono" style="font-weight:600;">72</span></div>
            <div style="height:7px; border-radius:4px; background:var(--surface-2);"><div style="height:100%; width:72%; border-radius:4px; background:var(--good);"></div></div>
          </div>
          <div style="display:flex; flex-direction:column; gap:3px; position:relative;">
            <div style="display:flex; justify-content:space-between; font-size:13px;"><span style="color:var(--text);">JavaScript</span><span class="mono" style="font-weight:600;">55</span></div>
            <div style="height:7px; border-radius:4px; background:var(--surface-2);"><div style="height:100%; width:55%; border-radius:4px; background:var(--warn);"></div></div>
          </div>
          <div style="display:flex; flex-direction:column; gap:3px; position:relative;">
            <div style="display:flex; justify-content:space-between; font-size:13px;"><span style="color:var(--text);">Third-party apps</span><span class="mono" style="font-weight:600;">40</span></div>
            <div style="height:7px; border-radius:4px; background:var(--surface-2);"><div style="height:100%; width:40%; border-radius:4px; background:var(--critical);"></div></div>
          </div>
          <div style="display:flex; flex-direction:column; gap:3px; position:relative;">
            <div style="display:flex; justify-content:space-between; font-size:13px;"><span style="color:var(--text);">SEO</span><span class="mono" style="font-weight:600;">65</span></div>
            <div style="height:7px; border-radius:4px; background:var(--surface-2);"><div style="height:100%; width:65%; border-radius:4px; background:var(--warn);"></div></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section>
    <div class="section-head">
      <div class="eyebrow"><span class="eyebrow-dot"></span> Full transparency</div>
      <h2>See exactly what changed, not just "fixed"</h2>
      <p>Every applied fix keeps the original file next to the updated one — a real diff, not a status badge you have to trust blindly.</p>
    </div>
    <div class="diff-demo">
      <div class="diff-col">
        <div class="diff-head before">sections/announcement-bar.liquid — before</div>
        <div class="diff-body">&lt;script src="widget.js"&gt;
&lt;/script&gt;</div>
      </div>
      <div class="diff-col">
        <div class="diff-head after">sections/announcement-bar.liquid — after</div>
        <div class="diff-body">&lt;script src="widget.js" <span class="hl">defer</span>&gt;
&lt;/script&gt;</div>
      </div>
    </div>
  </section>

  <section>
    <div class="section-head">
      <div class="eyebrow"><span class="eyebrow-dot"></span> Recurring value</div>
      <h2>Watch the trend, not just today's score</h2>
      <p>A single number tells you where you stand. A trend line tells you whether what you're paying for is working.</p>
    </div>
    <div class="chart-card">
      <div class="chart-legend">
        <span style="color:var(--text-muted); font-size:13px;">Score — last 30 days</span>
        <span class="n" style="color:var(--good);">↑ 94</span>
      </div>
      <svg viewBox="0 0 600 140" width="100%" height="140" preserveAspectRatio="none">
        <line x1="0" y1="35" x2="600" y2="35" stroke="var(--border)" stroke-width="1"/>
        <line x1="0" y1="70" x2="600" y2="70" stroke="var(--border)" stroke-width="1"/>
        <line x1="0" y1="105" x2="600" y2="105" stroke="var(--border)" stroke-width="1"/>
        <path d="M0,110 L75,102 L150,96 L225,80 L300,74 L375,58 L450,48 L525,30 L600,20" fill="none" stroke="var(--good)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="600" cy="20" r="5" fill="var(--good)" stroke="var(--surface)" stroke-width="2"/>
      </svg>
    </div>
  </section>

  @php
      $plans = \App\Models\Plan::where('active', true)->orderBy('sort_order')->get();
  @endphp
  @if ($plans->isNotEmpty())
  <section id="pricing">
    <div class="section-head">
      <div class="eyebrow"><span class="eyebrow-dot"></span> Free forever, upgrade when you're ready</div>
      <h2>See your score for free. Fix it when you want to.</h2>
      <p>Every store gets a real score, Core Web Vitals, and a full list of what's slowing it down — no card required. Upgrade to have SpeedPilot actually apply the safe fixes, with backup and rollback on every change.</p>
    </div>
    @php
        $checkIcon = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
        $dashIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/></svg>';
    @endphp
    <div class="pricing-row">
      @foreach ($plans as $plan)
      @php $isFree = (float) $plan->price === 0.0; @endphp
      <div class="pricing-card{{ $isFree ? ' pricing-card--free' : '' }}">
        <div>
          <div class="plan-name">{{ $plan->name }}</div>
          {{-- Same top_features list entered in Shopify's Partner Dashboard
               pricing config (Plan::top_features, edited via /admin/plans) -
               review checks these match, so this is never reworded here,
               only ever the literal stored text, same as PlanPicker.jsx. --}}
          <div class="check-list">
            @forelse (($plan->top_features ?? []) as $feature)
            <div class="check-item">{!! $checkIcon !!} {{ $feature }}</div>
            @empty
            <div class="check-item check-item--muted">{!! $dashIcon !!} No features listed for this plan yet.</div>
            @endforelse
          </div>
          <div class="price-cta">
            <a class="btn {{ $isFree ? 'btn-ghost' : 'btn-primary' }}" href="https://apps.shopify.com/speedpilot">
              {{ $isFree ? 'Scan free, no card required' : ($plan->trial_days > 0 ? "Start {$plan->trial_days}-day free trial" : 'Install & subscribe') }}
            </a>
          </div>
        </div>
        <div style="text-align:right;">
          <div class="price-tag">${{ rtrim(rtrim(number_format($plan->price, 2), '0'), '.') }}<span>/mo</span></div>
        </div>
      </div>
      @endforeach
    </div>
  </section>
  @endif

  <footer>
    <p>&copy; {{ date('Y') }} SpeedPilot — Shopify storefront performance, audited and fixed.</p>
    <p><a href="/faq">FAQ</a> &nbsp;&middot;&nbsp; <a href="/privacy">Privacy</a></p>
  </footer>

</div>
</body>
</html>
