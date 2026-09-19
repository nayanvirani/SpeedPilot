<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpeedPilot - Shopify Speed Optimization</title>
    <meta name="description" content="SpeedPilot audits your Shopify store's real performance, applies safe fixes automatically, and shows you exactly which apps are slowing you down.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-slate-900">
    <header class="border-b">
        <div class="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
            <span class="font-semibold text-lg">SpeedPilot</span>
            <a href="#pricing" class="text-sm text-slate-600 hover:text-slate-900">Pricing</a>
        </div>
    </header>

    <section class="max-w-5xl mx-auto px-6 py-20 text-center">
        <h1 class="text-4xl font-bold tracking-tight mb-4">
            Find what's slow. Fix what's safe. Show what's not.
        </h1>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-8">
            SpeedPilot audits your Shopify storefront's real performance, applies only
            optimizations proven safe, and shows you exactly which apps and scripts are
            costing you speed &mdash; with backup and rollback on every change.
        </p>
        <a href="https://apps.shopify.com" class="inline-block bg-slate-900 text-white rounded-md px-6 py-3 font-medium hover:bg-slate-800">
            Scan My Store
        </a>
    </section>

    <section class="max-w-5xl mx-auto px-6 py-12 grid md:grid-cols-3 gap-8 border-t">
        <div>
            <h3 class="font-semibold mb-2">Measure real performance</h3>
            <p class="text-sm text-slate-600">Lab scores (Lighthouse) plus real-user Core Web Vitals &mdash; not just a synthetic number.</p>
        </div>
        <div>
            <h3 class="font-semibold mb-2">Fix conservatively</h3>
            <p class="text-sm text-slate-600">Every optimization is tiered by risk. Only "safe" fixes apply automatically, with backup and rollback.</p>
        </div>
        <div>
            <h3 class="font-semibold mb-2">Attribute the slowdown</h3>
            <p class="text-sm text-slate-600">See which installed apps and third-party scripts are actually costing your store speed.</p>
        </div>
    </section>

    <section id="pricing" class="max-w-5xl mx-auto px-6 py-16 border-t">
        <h2 class="text-2xl font-semibold text-center mb-10">Pricing</h2>
        <div class="grid md:grid-cols-3 gap-6">
            @foreach (collect(config('speedpilot.plans'))->except('free') as $key => $plan)
                <div class="border rounded-lg p-6 {{ $key === 'growth' ? 'border-slate-900 shadow-md' : '' }}">
                    <h3 class="font-semibold text-lg">{{ $plan['name'] }}</h3>
                    <p class="text-3xl font-bold my-3">${{ $plan['price'] }}<span class="text-base font-normal text-slate-500">/mo</span></p>
                    <ul class="text-sm text-slate-600 space-y-1">
                        @if ($plan['auto_fixes'])
                            <li>&check; Automatic safe fixes{{ $plan['auto_fix_limit'] ? " (up to {$plan['auto_fix_limit']})" : ' (unlimited)' }}</li>
                        @endif
                        @if ($plan['medium_risk_fixes'])
                            <li>&check; Medium-risk fixes via preview theme</li>
                        @endif
                        @if ($plan['high_risk_recommendations'])
                            <li>&check; High-risk recommendations</li>
                        @endif
                        <li>&check; {{ $plan['history_days'] }}-day history</li>
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    <footer class="border-t py-8 text-center text-sm text-slate-400">
        &copy; {{ date('Y') }} SpeedPilot
    </footer>
</body>
</html>
