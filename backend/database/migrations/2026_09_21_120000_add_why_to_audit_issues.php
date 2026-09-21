<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_issues', function (Blueprint $table) {
            // Merchant-facing "why does this matter" sentence, distinct from
            // description's more technical what/how - spec's "why this issue
            // matters" requirement, plain language for a non-developer reader.
            $table->text('why')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('audit_issues', function (Blueprint $table) {
            $table->dropColumn('why');
        });
    }
};
