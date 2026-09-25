<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PatientNumberAllocationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_generated_patient_numbers_are_unique_and_database_backed(): void
    {
        $user = User::factory()->create();
        $attributes = [
            'user_id' => $user->id,
            'name' => 'Test Patient',
            'contact' => '0200000000',
            'dob' => '1990-01-01',
            'gender' => 'Other',
            'address' => 'Test Address',
        ];

        $first = Patient::createWithGeneratedPxNumber($attributes);
        $second = Patient::createWithGeneratedPxNumber($attributes);

        $this->assertNotSame($first->pxnumber, $second->pxnumber);
        $this->assertMatchesRegularExpression('/^PX-[A-F0-9]{10}-\d{2}$/', $first->pxnumber);
        $this->assertDatabaseHas('patients', ['pxnumber' => $first->pxnumber]);
        $this->assertDatabaseHas('patients', ['pxnumber' => $second->pxnumber]);
    }
}
