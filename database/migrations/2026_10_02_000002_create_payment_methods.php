<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each clinic's own payment methods, one list for the clinical tills and one for optical
 * (App\Support\PaymentMethods). A clinic with no rows yet uses the built-in list, so nothing
 * changes until it edits its methods. Payments keep the method key they were taken with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('business_line', 10); // clinic | optical
            $table->string('key', 50);
            $table->string('label', 60);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['clinic_id', 'business_line', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
