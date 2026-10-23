<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drugs prescribed in the clinic's previous software, one row each, so the Prescription tab can list them
 * beside the patient's own past prescriptions. They are history: not cart items, never dispensed or billed.
 * Imports that ran before this table existed only wrote them into the consultation notes
 * ("Medications:" then "- ITEM × QTY — NOTE" lines); those are read back out here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_legacy_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('external_import_batch_id')->nullable()->constrained('external_import_batches')->nullOnDelete();
            $table->string('source', 30);
            $table->date('prescribed_on');
            $table->string('item', 250);
            $table->string('quantity', 30)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id', 'prescribed_on'], 'legacy_meds_patient_date_index');
        });

        $this->backfillFromNotes();
    }

    /** Reads the medications block of every imported consultation's notes into rows (separate so tests can run it). */
    public function backfillFromNotes(): void
    {
        if (! Schema::hasTable('external_import_rows')) return;

        DB::table('external_import_rows as r')
            ->join('external_import_batches as b', 'b.id', '=', 'r.external_import_batch_id')
            ->join('consultations as c', 'c.id', '=', 'r.record_id')
            ->where('r.table_name', 'consultations')
            ->where('c.notes', 'like', "%Medications:\n%")
            ->whereNotExists(fn ($q) => $q->from('patient_legacy_medications as m')->whereColumn('m.consultation_id', 'c.id'))
            ->select('c.id', 'c.clinic_id', 'c.patient_id', 'c.notes', 'c.created_at', 'b.id as batch_id', 'b.source')
            // By id, not by page: rows written below drop their consultation out of this query.
            ->chunkById(500, function ($consultations) {
                DB::transaction(function () use ($consultations) {
                    foreach ($consultations as $c) {
                        foreach (self::parse($c->notes) as $med) {
                            $id = DB::table('patient_legacy_medications')->insertGetId($med + [
                                'clinic_id' => $c->clinic_id, 'patient_id' => $c->patient_id, 'consultation_id' => $c->id,
                                'external_import_batch_id' => $c->batch_id, 'source' => $c->source,
                                'prescribed_on' => substr((string) $c->created_at, 0, 10), 'created_at' => $c->created_at, 'updated_at' => now(),
                            ]);
                            DB::table('external_import_rows')->insert(['external_import_batch_id' => $c->batch_id, 'table_name' => 'patient_legacy_medications', 'record_id' => $id]);
                        }
                    }
                });
            }, 'c.id', 'id');
    }

    /** "- ITEM × QTY — NOTE" lines under "Medications:", up to the next blank line. */
    public static function parse(?string $notes): array
    {
        if (! preg_match('/(?:^|\n)Medications:\n((?:- .*(?:\n|$))+)/u', (string) $notes, $m)) return [];

        $meds = [];
        foreach (preg_split('/\n/', trim($m[1])) as $line) {
            $line = trim(substr($line, 2));
            if ($line === '') continue;
            [$main, $note] = array_pad(explode(' — ', $line, 2), 2, null);
            $quantity = null;
            if (($at = mb_strrpos($main, ' × ')) !== false && mb_strlen($q = trim(mb_substr($main, $at + 3))) <= 30) {
                $quantity = $q;
                $main = mb_substr($main, 0, $at);
            }
            $meds[] = ['item' => mb_substr(trim($main), 0, 250), 'quantity' => $quantity === '' ? null : $quantity, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null];
        }

        return $meds;
    }

    public function down(): void
    {
        if (Schema::hasTable('external_import_rows')) DB::table('external_import_rows')->where('table_name', 'patient_legacy_medications')->delete();
        Schema::dropIfExists('patient_legacy_medications');
    }
};
