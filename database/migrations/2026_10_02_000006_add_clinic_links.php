<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinic Links (Settings → Clinic Profile, or Optical Settings): the links SMS templates can
 * insert by name: [MAP_LINK] (also the old [LINK]), [WHATSAPP_LINK], [REVIEW_LINK], [WEBSITE],
 * [BOOKING_LINK], [FACEBOOK], [INSTAGRAM] and [TIKTOK]. The map link (settings.clinic_link)
 * and the review link (settings.review_link) already exist; a branch can have its own map and
 * WhatsApp links, falling back to the clinic's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('whatsapp_link', 500)->nullable();
            $table->string('website_link', 500)->nullable();
            $table->string('booking_link', 500)->nullable();
            $table->string('facebook_link', 500)->nullable();
            $table->string('instagram_link', 500)->nullable();
            $table->string('tiktok_link', 500)->nullable();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('map_link', 500)->nullable();
            $table->string('whatsapp_link', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['map_link', 'whatsapp_link']));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn([
            'whatsapp_link', 'website_link', 'booking_link', 'facebook_link', 'instagram_link', 'tiktok_link',
        ]));
    }
};
