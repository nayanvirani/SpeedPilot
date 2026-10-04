<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify's app review checks that the in-app billing page's feature list
 * matches what's declared in the Partner Dashboard's pricing config - a
 * mismatch is a real rejection reason. Previously PlanPicker.jsx derived
 * its bullet list from the plan's individual boolean columns, which could
 * say something different from whatever text was typed into the Partner
 * Dashboard's "top features" fields by hand. This column is the single
 * source of truth for both - edit it once (here, via /admin/plans), paste
 * the same lines into the Partner Dashboard, and the two can never drift.
 * Seeded with the exact same 8 lines already given for both plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('top_features')->nullable()->after('sort_order');
        });

        DB::table('plans')->where('key', 'free')->update([
            'top_features' => json_encode([
                'Homepage speed score & scan',
                'Core Web Vitals report',
                'Full issue list & causes',
                'App & script impact report',
                'Image Health Center',
                'Score breakdown by category',
                '1 free scan per week',
                'See your potential score',
            ]),
        ]);

        DB::table('plans')->where('key', 'starter')->update([
            'top_features' => json_encode([
                'Full-store scans, mobile+desktop',
                'Automatic safe fixes & rollback',
                'Medium-risk fixes via preview',
                'AI-generated recommendations',
                'Daily monitoring & alerts',
                'Smart Script Manager per page',
                'App & script disable/delay',
                '90-day score history',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('top_features');
        });
    }
};
