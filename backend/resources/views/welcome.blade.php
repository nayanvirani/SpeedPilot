<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpeedPilot - Shopify Speed Optimization</title>
    <meta name="description" content="SpeedPilot audits your Shopify store's real performance, applies safe fixes automatically, and shows you exactly which apps are slowing you down.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Hanken+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
      :root{
        --ink:#f6f8fa; --surface:#ffffff; --surface-2:#eef1f5; --border:#dde3ea;
        --text:#131a24; --text-muted:#5b6675; --text-faint:#8b96a5;
        --accent:#0d9488; --accent-ink:#ffffff; --accent-2:#7c3aed;
        --good:#16a34a; --warn:#b45309; --critical:#dc2626;
        --good-bg:#e9f8ee; --warn-bg:#fdf3e2; --critical-bg:#fdecec;
        --shadow: 0 1px 2px rgba(20,25,35,.04), 0 8px 24px -12px rgba(20,25,35,.12);
      }
      @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
          --ink:#0a0e14; --surface:#12171f; --surface-2:#181f29; --border:#232c39;
          --text:#e8edf2; --text-muted:#93a0b1; --text-faint:#5f6b7a;
          --accent:#5eead4; --accent-ink:#06231f; --accent-2:#b6a3fb;
          --good:#4ade80; --warn:#fbbf24; --critical:#f87171;
          --good-bg:#122019; --warn-bg:#221c0c; --critical-bg:#241315;
          --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px -12px rgba(0,0,0,.55);
        }
      }
      :root[data-theme="dark"]{
        --ink:#0a0e14; --surface:#12171f; --surface-2:#181f29; --border:#232c39;
        --text:#e8edf2; --text-muted:#93a0b1; --text-faint:#5f6b7a;
        --accent:#5eead4; --accent-ink:#06231f; --accent-2:#b6a3fb;
        --good:#4ade80; --warn:#fbbf24; --critical:#f87171;
        --good-bg:#122019; --warn-bg:#221c0c; --critical-bg:#241315;
        --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px -12px rgba(0,0,0,.55);
      }

      *{box-sizing:border-box;}
      body{
        background:var(--ink); color:var(--text); margin:0;
        font-family:'Hanken Grotesk',system-ui,sans-serif;
        padding-inline:20px;
      }
      .wrap{max-width:1040px; margin:0 auto;}
      h1,h2,h3{font-family:'Sora',system-ui,sans-serif; letter-spacing:-.01em; text-wrap:balance; margin:0;}
      .mono{font-family:'IBM Plex Mono',ui-monospace,monospace;}
      p{color:var(--text-muted); line-height:1.6; margin:0;}
      a{color:inherit;}

      header{display:flex; align-items:center; justify-content:space-between; padding-block:28px; gap:16px; flex-wrap:wrap;}
      .brand{display:flex; align-items:center; gap:10px; font-family:'Sora',sans-serif; font-weight:700; font-size:18px; text-decoration:none;}
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
        font-family:'Sora',sans-serif; font-weight:600; font-size:14.5px; padding:13px 22px; border-radius:10px;
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

      footer{padding-block:36px 48px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px;}
      footer p{font-size:12.5px;}
    </style>
</head>
<body>

<div class="wrap">

  <header>
    <a href="/" class="brand">
      <span class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="#06231f" stroke-width="2.4" stroke-linecap="round"><path d="M4 12h6l2-7 3 14 2-7h3"/></svg>
      </span>
      SpeedPilot
    </a>
    <nav>
      <a href="#features">Features</a>
      <a href="#pricing">Pricing</a>
      <a class="btn btn-primary" href="https://apps.shopify.com" style="padding:10px 18px;">Install app</a>
    </nav>
  </header>

  <section class="hero" style="border-top:none; padding-top:8px;">
    <div>
      <div class="eyebrow"><span class="eyebrow-dot"></span> Free audit, no card required</div>
      <h1>Find what's slow. <em>Fix what's safe.</em> Show what's not.</h1>
      <p>SpeedPilot audits your Shopify storefront's real performance, applies only optimizations proven safe, and shows you exactly which apps and scripts are costing you speed — with backup and rollback on every change.</p>
      <div class="cta-row">
        <a class="btn btn-primary" href="https://apps.shopify.com">Scan My Store →</a>
        <a class="btn btn-ghost" href="#pricing">View pricing</a>
      </div>
    </div>

    <div class="score-card">
      <div class="score-top">
        <div class="score-url">yourstore.myshopify.com</div>
        <span class="badge badge-good">complete</span>
      </div>
      <div class="score-num-row">
        <div class="score-num">94</div>
        <span class="score-delta">+22 this month</span>
      </div>
      <div class="cwv-row">
        <div class="cwv"><div class="cwv-label">LCP</div><div class="cwv-val">1.8s</div></div>
        <div class="cwv"><div class="cwv-label">INP</div><div class="cwv-val">142ms</div></div>
        <div class="cwv"><div class="cwv-label">CLS</div><div class="cwv-val">0.02</div></div>
        <div class="cwv"><div class="cwv-label">FCP</div><div class="cwv-val">0.9s</div></div>
        <div class="cwv"><div class="cwv-label">TTFB</div><div class="cwv-val">0.3s</div></div>
      </div>
      <div class="thumb-row">
        <div class="thumb"></div><div class="thumb"></div><div class="thumb"></div>
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
        <h3>Full-store scans, not just the homepage</h3>
        <p>Checks your homepage, a product page, and a collection page in one run — real slowdowns often hide on the pages a homepage-only score never looks at.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l-5-5 5-5M15 6l5 5-5 5"/></svg></span>
        <span class="tag">Auto-fix</span>
        <h3>Safe fixes apply themselves</h3>
        <p>Render-blocking scripts get deferred, images get lazy-loaded — automatically, with every change backed up and reversible in one click.</p>
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
      $plan = \App\Models\Plan::where('active', true)->orderBy('sort_order')->first();
  @endphp
  @if ($plan)
  <section id="pricing">
    <div class="section-head">
      <div class="eyebrow"><span class="eyebrow-dot"></span> No tiers, no upsells</div>
      <h2>One plan. Everything unlocked.</h2>
      <p>No feature-gated "Pro" tier to upgrade into later — every SpeedPilot subscriber gets the full product from day one.</p>
    </div>
    @php
        $checkIcon = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
    @endphp
    <div class="pricing-card">
      <div>
        <div class="plan-name">{{ $plan->name }}</div>
        <div class="check-list">
          <div class="check-item">{!! $checkIcon !!} {{ $plan->pages_per_scan > 1 ? 'Full-store scans (home + product + collection)' : 'Homepage speed scans' }}</div>
          <div class="check-item">{!! $checkIcon !!} {{ $plan->auto_fixes ? 'Automatic safe fixes' . ($plan->auto_fix_limit ? " (up to {$plan->auto_fix_limit})" : ' (unlimited)') : 'Speed audit & app impact report' }}</div>
          @if ($plan->medium_risk_fixes)
          <div class="check-item">{!! $checkIcon !!} Medium-risk fixes via preview theme</div>
          @endif
          @if ($plan->script_rule_limit !== 0)
          <div class="check-item">{!! $checkIcon !!} {{ $plan->script_rule_limit ? "Up to {$plan->script_rule_limit} script rules" : 'Unlimited script rules' }}</div>
          @endif
          @if ($plan->high_risk_recommendations)
          <div class="check-item">{!! $checkIcon !!} High-risk recommendations</div>
          @endif
          @if ($plan->ai_recommendations)
          <div class="check-item">{!! $checkIcon !!} AI-generated recommendations</div>
          @endif
          @if ($plan->monitoring)
          <div class="check-item">{!! $checkIcon !!} {{ ucfirst(str_replace('_', ' + ', $plan->monitoring)) }} monitoring</div>
          @endif
          <div class="check-item">{!! $checkIcon !!} {{ $plan->history_days > 0 ? "{$plan->history_days}-day history" : 'One-time scan' }}</div>
          <div class="check-item">{!! $checkIcon !!} One-click rollback on every fix</div>
        </div>
        <div class="price-cta">
          <a class="btn btn-primary" href="https://apps.shopify.com">
            {{ $plan->trial_days > 0 ? "Start {$plan->trial_days}-day free trial" : 'Install & subscribe' }}
          </a>
        </div>
      </div>
      <div style="text-align:right;">
        <div class="price-tag">${{ rtrim(rtrim(number_format($plan->price, 2), '0'), '.') }}<span>/mo</span></div>
      </div>
    </div>
  </section>
  @endif

  <footer>
    <p>&copy; {{ date('Y') }} SpeedPilot — Shopify storefront performance, audited and fixed.</p>
    <p>Built for Shopify merchants</p>
  </footer>

</div>
</body>
</html>
