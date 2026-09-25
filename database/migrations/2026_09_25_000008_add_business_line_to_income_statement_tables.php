<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['income_statement_entries', 'income_statement_templates', 'income_statement_period_locks'];

    public function up(): void
    {
        // Existing statement lines, templates and locks all belong to the clinic books.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('business_line', 10)->default('clinic')->index();
            });
        }

        // Each line locks its own periods. The new index is added before the old one is
        // dropped so the clinic foreign key always has an index to use.
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->json('snapshot')->nullable();
            $table->unique(['clinic_id', 'branch_id', 'business_line', 'from_date', 'to_date'], 'income_locks_line_period_unique');
        });
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->dropUnique('income_locks_tenant_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->unique(['clinic_id', 'branch_id', 'from_date', 'to_date'], 'income_locks_tenant_period_unique');
        });
        Schema::table('income_statement_period_locks', function (Blueprint $table) {
            $table->dropUnique('income_locks_line_period_unique');
            $table->dropColumn('snapshot');
        });

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['business_line']);
                $table->dropColumn('business_line');
            });
        }
    }
};
