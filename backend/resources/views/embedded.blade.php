<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SpeedPilot</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Shopify App Bridge must load before anything else on the page. --}}
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="{{ $apiKey }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
