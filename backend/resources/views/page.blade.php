<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->title }} - SpeedPilot</title>
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
      }
      *{box-sizing:border-box;}
      body{background:var(--ink); color:var(--text); margin:0; font-family:'Hanken Grotesk',system-ui,sans-serif; padding-inline:20px;}
      .wrap{max-width:760px; margin:0 auto;}
      h1,h2,h3{font-family:'Sora',system-ui,sans-serif; letter-spacing:-.01em; margin:1.6em 0 .5em;}
      h1{margin-top:0;}
      h2:first-of-type{margin-top:0.5em;}
      p, li{color:var(--text-muted); line-height:1.7;}
      a{color:var(--accent);}
      header{display:flex; align-items:center; justify-content:space-between; padding-block:28px; gap:16px; flex-wrap:wrap; border-bottom:1px solid var(--border);}
      .brand{display:flex; align-items:center; gap:10px; font-family:'Sora',sans-serif; font-weight:700; font-size:18px; text-decoration:none; color:var(--text);}
      .brand-mark{width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg,var(--accent),var(--accent-2)); display:flex; align-items:center; justify-content:center; flex:none;}
      .brand-mark svg{width:16px; height:16px;}
      nav{display:flex; align-items:center; gap:24px;}
      nav a{font-size:14px; color:var(--text-muted); text-decoration:none;}
      nav a:hover{color:var(--text);}
      main{padding-block:48px 64px;}
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
    <h1>{{ $page->title }}</h1>
    {!! $content !!}
  </main>
  <footer>&copy; {{ date('Y') }} SpeedPilot</footer>
</div>
</body>
</html>
