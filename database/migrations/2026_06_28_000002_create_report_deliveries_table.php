<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_key')->unique();
            $table->string('period', 20);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('subject');
            $table->json('report_payload');
            $table->json('recipients');
            $table->json('sent_recipients')->nullable();
            $table->json('failed_recipients')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_attempt_at']);
            $table->index(['period', 'period_start']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('report_deliveries');
    }
};
