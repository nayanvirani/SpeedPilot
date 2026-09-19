<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpeedPilot - Shopify Speed Optimization</title>
    <meta name="description" content="SpeedPilot audits your Shopify store's real performance, applies safe fixes automatically, and shows you exactly which apps are slowing you down.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-slate-900 antialiased">

    <header class="sticky top-0 z-20 bg-white/80 backdrop-blur border-b border-slate-100">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2 font-semibold text-lg">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 text-white text-sm">SP</span>
                SpeedPilot
            </a>
            <nav class="hidden sm:flex items-center gap-8 text-sm text-slate-600">
                <a href="#features" class="hover:text-slate-900">Features</a>
                <a href="#pricing" class="hover:text-slate-900">Pricing</a>
            </nav>
            <a href="https://apps.shopify.com" class="text-sm font-medium bg-slate-900 text-white rounded-full px-5 py-2 hover:bg-slate-800">
                Install app
            </a>
        </div>
    </header>

    <section class="relative overflow-hidden">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-emerald-50 via-white to-white"></div>
        <div class="max-w-4xl mx-auto px-6 pt-24 pb-20 text-center">
            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 text-emerald-700 text-xs font-medium px-3 py-1 mb-6 ring-1 ring-emerald-100">
                Free audit, no card required
            </span>
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight mb-6 text-balance">
                Find what's slow. Fix what's safe.<br class="hidden sm:block"> Show what's not.
            </h1>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto mb-10">
                SpeedPilot audits your Shopify storefront's real performance, applies only
                optimizations proven safe, and shows you exactly which apps and scripts are
                costing you speed &mdash; with backup and rollback on every change.
            </p>
            <div class="flex items-center justify-center gap-4">
                <a href="https://apps.shopify.com" class="inline-flex items-center gap-2 bg-slate-900 text-white rounded-full px-7 py-3.5 font-medium hover:bg-slate-800 transition">
                    Scan My Store
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.293 4.293a1 1 0 011.414 0l5 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.414-1.414L15.586 11H3a1 1 0 110-2h12.586l-3.293-3.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </a>
                <a href="#pricing" class="text-sm font-medium text-slate-600 hover:text-slate-900">View pricing &rarr;</a>
            </div>
        </div>
    </section>

    <section id="features" class="max-w-6xl mx-auto px-6 py-20 border-t border-slate-100">
        <div class="grid md:grid-cols-3 gap-10">
            <div>
                <div class="h-10 w-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1H3a1 1 0 01-1-1v-6zM8 7a1 1 0 011-1h2a1 1 0 011 1v10a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 3a1 1 0 011-1h2a1 1 0 011 1v14a1 1 0 01-1 1h-2a1 1 0 01-1-1V3z"/></svg>
                </div>
                <h3 class="font-semibold text-lg mb-2">Measure real performance</h3>
                <p class="text-sm text-slate-600 leading-relaxed">Lab scores (Lighthouse) plus real-user Core Web Vitals from your actual visitors &mdash; not just a synthetic number.</p>
            </div>
            <div>
                <div class="h-10 w-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a6 6 0 00-3.815 10.631C7.237 12.5 8 13.443 8 14.456v.644a.75.75 0 00.572.729 6.016 6.016 0 002.856 0A.75.75 0 0012 15.1v-.644c0-1.013.762-1.957 1.815-2.825A6 6 0 0010 1zM8.863 17.414a.75.75 0 00-.226 1.483 9.066 9.066 0 002.726 0 .75.75 0 00-.226-1.483 7.553 7.553 0 01-2.274 0z" clip-rule="evenodd"/></svg>
                </div>
                <h3 class="font-semibold text-lg mb-2">Fix conservatively</h3>
                <p class="text-sm text-slate-600 leading-relaxed">Every optimization is tiered by risk. Only "safe" fixes apply automatically &mdash; always with a versioned backup and one-click rollback.</p>
            </div>
            <div>
                <div class="h-10 w-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3z"/></svg>
                </div>
                <h3 class="font-semibold text-lg mb-2">Attribute the slowdown</h3>
                <p class="text-sm text-slate-600 leading-relaxed">See exactly which installed apps and third-party scripts are actually costing your store speed &mdash; something generic tools can't show you.</p>
            </div>
        </div>
    </section>

    <section id="pricing" class="bg-slate-50 border-t border-slate-100">
        <div class="max-w-5xl mx-auto px-6 py-20">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold mb-3">Simple, transparent pricing</h2>
                <p class="text-slate-600">Start with a free scan. Upgrade when you're ready for automatic fixes.</p>
            </div>
            @php
                $plans = \App\Models\Plan::where('active', true)->orderBy('sort_order')->get();
            @endphp
            <div class="grid md:grid-cols-{{ max($plans->count(), 1) }} gap-6 items-start">
                @foreach ($plans as $plan)
                    @php $isHighlighted = $loop->last && $plans->count() > 1; @endphp
                    <div class="bg-white rounded-2xl p-8 {{ $isHighlighted ? 'ring-2 ring-slate-900 shadow-xl' : 'ring-1 ring-slate-200 shadow-sm' }} relative">
                        @if ($isHighlighted)
                            <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-xs font-medium px-3 py-1 rounded-full">Most popular</span>
                        @endif
                        <h3 class="font-semibold text-lg">{{ $plan->name }}</h3>
                        <p class="mt-3 mb-6">
                            <span class="text-4xl font-bold">${{ rtrim(rtrim(number_format($plan->price, 2), '0'), '.') }}</span>
                            <span class="text-slate-500 text-sm">/mo</span>
                        </p>
                        <ul class="text-sm text-slate-600 space-y-2.5 mb-8">
                            @if ($plan->auto_fixes)
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> Automatic safe fixes{{ $plan->auto_fix_limit ? " (up to {$plan->auto_fix_limit})" : ' (unlimited)' }}</li>
                            @else
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> Speed audit &amp; app impact report</li>
                            @endif
                            @if ($plan->script_rule_limit !== 0)
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> {{ $plan->script_rule_limit ? "Up to {$plan->script_rule_limit} script rules" : 'Unlimited script rules' }}</li>
                            @endif
                            @if ($plan->medium_risk_fixes)
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> Medium-risk fixes via preview theme</li>
                            @endif
                            @if ($plan->high_risk_recommendations)
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> High-risk recommendations</li>
                            @endif
                            @if ($plan->ai_recommendations)
                                <li class="flex gap-2"><span class="text-emerald-600">&check;</span> AI-generated recommendations</li>
                            @endif
                            <li class="flex gap-2"><span class="text-emerald-600">&check;</span> {{ $plan->history_days > 0 ? "{$plan->history_days}-day history" : 'One-time scan' }}</li>
                        </ul>
                        <a href="https://apps.shopify.com" class="block text-center rounded-full px-5 py-2.5 text-sm font-medium {{ $isHighlighted ? 'bg-slate-900 text-white hover:bg-slate-800' : 'bg-slate-100 text-slate-900 hover:bg-slate-200' }}">
                            {{ $plan->trial_days > 0 ? "Start {$plan->trial_days}-day free trial" : 'Subscribe now' }}
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <footer class="border-t border-slate-100 py-10">
        <div class="max-w-6xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-slate-500">
            <span>&copy; {{ date('Y') }} SpeedPilot</span>
            <span>Built for Shopify merchants</span>
        </div>
    </footer>
</body>
</html>
