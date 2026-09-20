<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SpeedPilot</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Shopify App Bridge must load before anything else on the page. --}}
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="{{ $apiKey }}"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
