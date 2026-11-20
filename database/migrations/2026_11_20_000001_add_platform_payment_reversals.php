<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment recorded by mistake (wrong clinic, wrong amount) is reversed, not refunded: it stays
     * on record marked reversed, and the subscription dates its invoice bought go back to what they
     * were. The invoice remembers those dates when its payment renews the subscription.
     */
    public function up(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->json('renewed_from')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn('renewed_from');
        });
    }
};
