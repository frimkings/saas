<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_snippets', function (Blueprint $table) {
            $table->id();
            $table->string('letter_type', 40);
            $table->string('field', 60);
            $table->string('title');
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('referral_snippets')->insert([
            [
                'letter_type' => 'referral',
                'field' => 'reasonForReferral',
                'title' => 'Specialist Review',
                'content' => 'Kindly review for further ophthalmic evaluation and management.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'letter_type' => 'referral',
                'field' => 'management',
                'title' => 'Initial Treatment Given',
                'content' => 'Initial treatment and counselling have been provided. Patient has been advised to report for specialist care.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'letter_type' => 'medical_report',
                'field' => 'recommendation',
                'title' => 'Follow-up Recommended',
                'content' => 'The patient is advised to continue treatment and attend scheduled follow-up appointments.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'letter_type' => 'medical_report',
                'field' => 'clinicalFindings',
                'title' => 'Clinical Summary',
                'content' => 'Clinical examination was performed and findings are consistent with the stated diagnosis.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'letter_type' => 'excuse_duty',
                'field' => 'diagnosis',
                'title' => 'Medical Rest',
                'content' => 'Patient requires temporary rest from work or school duties for medical reasons.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_snippets');
    }
};
