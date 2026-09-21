<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FAQ - SpeedPilot</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
      :root{
        --ink:#f6f8fa; --surface:#ffffff; --border:#dde3ea;
        --text:#131a24; --text-muted:#5b6675;
        --accent:#0d9488; --accent-2:#7c3aed;
        --shadow: 0 1px 2px rgba(20,25,35,.04), 0 8px 24px -12px rgba(20,25,35,.12);
      }
      *{box-sizing:border-box;}
      body{background:var(--ink); color:var(--text); margin:0; font-family:'Hanken Grotesk',system-ui,sans-serif; padding-inline:20px;}
      .wrap{max-width:760px; margin:0 auto;}
      h1{font-family:'Sora',system-ui,sans-serif; letter-spacing:-.01em; margin:0;}
      p{color:var(--text-muted); line-height:1.7;}
      a{color:var(--accent);}
      header{display:flex; align-items:center; justify-content:space-between; padding-block:28px; gap:16px; flex-wrap:wrap; border-bottom:1px solid var(--border);}
      .brand{display:flex; align-items:center; gap:10px; font-family:'Sora',sans-serif; font-weight:700; font-size:18px; text-decoration:none; color:var(--text);}
      .brand-mark{width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg,var(--accent),var(--accent-2)); display:flex; align-items:center; justify-content:center; flex:none;}
      .brand-mark svg{width:16px; height:16px;}
      nav{display:flex; align-items:center; gap:24px;}
      nav a{font-size:14px; color:var(--text-muted); text-decoration:none;}
      nav a:hover{color:var(--text);}
      main{padding-block:48px 64px;}
      .item{background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:22px 24px; margin-bottom:14px; box-shadow:var(--shadow);}
      .item h2{font-family:'Sora',system-ui,sans-serif; font-size:16.5px; margin:0 0 8px; color:var(--text);}
      .item p{margin:0; font-size:14.5px;}
      footer{padding-block:32px 44px; border-top:1px solid var(--border); font-size:12.5px; color:var(--text-muted);}
    </style>
</head>
<body>
<div class="wrap">
  <header>
    <a href="/" class="brand">
      <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="#06231f" stroke-width="2.4" stroke-linecap="round"><path d="M4 12h6l2-7 3 14 2-7h3"/></svg></span>
      SpeedPilot
    </a>
    <nav>
      <a href="/">Home</a>
      <a href="/faq">FAQ</a>
      <a href="/privacy">Privacy</a>
    </nav>
  </header>
  <main>
    <h1>Frequently asked questions</h1>
    <p style="margin-top:10px;">Common questions about how SpeedPilot works.</p>
    <div style="margin-top:28px;">
      @forelse ($items as $item)
        <div class="item">
          <h2>{{ $item->question }}</h2>
          <p>{{ $item->answer }}</p>
        </div>
      @empty
        <p>No questions published yet.</p>
      @endforelse
    </div>
  </main>
  <footer>&copy; {{ date('Y') }} SpeedPilot</footer>
</div>
</body>
</html>
