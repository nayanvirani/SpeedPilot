<?php

namespace Database\Seeders;

use App\Models\FaqItem;
use Illuminate\Database\Seeder;

/**
 * Idempotent (firstOrCreate, keyed on the question) so it never overwrites
 * content the admin has already edited through /admin/faq.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $sortOrder => $item) {
            FaqItem::firstOrCreate(
                ['question' => $item['question']],
                ['answer' => $item['answer'], 'sort_order' => $sortOrder, 'published' => true]
            );
        }
    }

    private function items(): array
    {
        return [
            [
                'question' => 'What does SpeedPilot actually do?',
                'answer' => 'SpeedPilot scans your storefront the way a real visitor\'s browser would, finds specific '
                    .'performance issues (slow images, blocking scripts, layout shifts, and more) with real evidence '
                    .'for each one, and helps you fix them - either automatically or by showing you the exact code to '
                    .'apply yourself. It then re-scans to confirm the result and keeps monitoring for regressions.',
            ],
            [
                'question' => 'Will this break my theme?',
                'answer' => 'Every automatic change is backed up before it\'s made, and you can roll any single change '
                    .'back with one click from the Optimizations page. Fixes are grouped by confidence: Safe fixes are '
                    .'reversible, low-risk changes; Review-tier fixes need your explicit approval before anything '
                    .'happens; Manual-tier issues are recommendation-only and never touched automatically.',
            ],
            [
                'question' => 'What\'s the difference between auto-fix and manual fix?',
                'answer' => 'Auto-fix has SpeedPilot edit your theme directly through Shopify\'s API, with a backup taken '
                    .'first. Manual fix computes the exact same change but shows it to you as code to copy and paste '
                    .'into your own theme editor - useful if you\'d rather review every change yourself, or for fixes '
                    .'that aren\'t eligible for automation.',
            ],
            [
                'question' => 'Why does a fix sometimes only offer "Manual fix"?',
                'answer' => 'Automatic theme edits require a one-time platform-level approval from Shopify for the app, '
                    .'separate from the permissions you grant on install. Manual fix works immediately either way, '
                    .'since it never writes to your theme - it just shows you the code.',
            ],
            [
                'question' => 'Does SpeedPilot slow down my store?',
                'answer' => 'The core app only runs when you trigger a scan or during scheduled monitoring - it doesn\'t '
                    .'add anything to your storefront by default. The optional real-visitor monitoring extension adds a '
                    .'small script that only measures performance timing; it does not load any third-party trackers.',
            ],
            [
                'question' => 'What data do you collect about my customers?',
                'answer' => 'SpeedPilot does not collect names, emails, IP addresses, or any other information that '
                    .'identifies a visitor. The optional real-visitor monitoring feature records only a page URL and '
                    .'performance timing numbers. See our Privacy Policy for full details.',
            ],
            [
                'question' => 'Can I undo a change SpeedPilot made?',
                'answer' => 'Yes. Every applied fix keeps a backup of the original file. Open the Optimizations page, '
                    .'find the change, and roll it back in one click - no time limit.',
            ],
            [
                'question' => 'Does this work with any theme?',
                'answer' => 'SpeedPilot works with any Shopify theme. Some fixes locate a specific line in your theme\'s '
                    .'code to edit; if a script is injected by an app rather than hardcoded in the theme, SpeedPilot '
                    .'reports that honestly instead of pretending to fix something it can\'t reach.',
            ],
            [
                'question' => 'What if a scan fails partway through?',
                'answer' => 'Each page and device is scanned independently, so one failure (a slow page timing out, for '
                    .'example) doesn\'t stop the rest of the scan. Any page that couldn\'t be scanned is reported by '
                    .'name with the reason, and you can re-run the scan at any time.',
            ],
            [
                'question' => 'Is there a free trial?',
                'answer' => 'Check the Billing page inside the app for current plan and trial details - pricing and '
                    .'trial terms are managed through Shopify\'s billing system and may change.',
            ],
        ];
    }
}
