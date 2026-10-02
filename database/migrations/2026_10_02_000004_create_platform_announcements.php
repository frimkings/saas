<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform → Announcements: a message from the platform to chosen clinics, emailed to each
 * clinic's owner and Super Admins and, optionally, shown as a dismissible banner to the
 * clinic's Super Admins in the app. See App\Services\Platform\Announcements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 150);
            $table->string('heading', 150);
            $table->text('body');
            $table->string('button_label', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            // Who it is for: {statuses: [...], plan_ids: [...], modes: [...], clinic_ids: [...]}
            $table->json('audience')->nullable();
            $table->boolean('send_email')->default(true);
            $table->boolean('show_banner')->default(true);
            $table->date('banner_until')->nullable();
            $table->string('status', 20)->default('draft'); // draft | sending | sent
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'show_banner']);
        });

        Schema::create('platform_announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_announcement_id');
            $table->foreign('platform_announcement_id', 'announcement_recipient_announcement_fk')->references('id')->on('platform_announcements')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 20); // owner | super_admin | none (clinic with no email: banner only)
            $table->string('email', 191)->nullable();
            $table->string('status', 20)->default('queued'); // queued | sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['platform_announcement_id', 'clinic_id', 'email'], 'announcement_recipient_unique');
            $table->index(['platform_announcement_id', 'status'], 'announcement_recipient_status');
            $table->index(['clinic_id', 'platform_announcement_id'], 'announcement_recipient_clinic');
        });

        Schema::create('platform_announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_announcement_id');
            $table->foreign('platform_announcement_id', 'announcement_dismissal_announcement_fk')->references('id')->on('platform_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->unique(['platform_announcement_id', 'user_id'], 'announcement_dismissal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcement_dismissals');
        Schema::dropIfExists('platform_announcement_recipients');
        Schema::dropIfExists('platform_announcements');
    }
};
