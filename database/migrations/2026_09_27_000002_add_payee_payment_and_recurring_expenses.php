<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses record who was paid and how (cash from the till, MoMo, card, bank), and
 * regular bills such as rent and salaries can repeat: each one comes due on its
 * schedule and is recorded, or skipped, by a person.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('business_line', 10)->default('clinic')->index();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('description', 255);
            $table->string('payee', 150)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->string('frequency', 10);
            $table->date('next_due_date');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'is_active', 'next_due_date'], 'recurring_expenses_due_lookup');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payee', 150)->nullable()->after('description');
            $table->string('payment_method', 20)->nullable()->after('amount');
            $table->foreignId('recurring_expense_id')->nullable()->after('business_line')->constrained('recurring_expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn(['payee', 'payment_method']);
        });
        Schema::dropIfExists('recurring_expenses');
    }
};
