<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** The refraction form picks one lens; changing it swaps the prescription line instead of adding another. */
class RefractionLensChoiceTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private Product $single;
    private Product $progressive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $lenses = Category::factory()->create(['user_id' => $doctor->id, 'name' => 'Lenses']);
        $this->single = Product::factory()->create(['user_id' => $doctor->id, 'category_id' => $lenses->id,
            'name' => 'SV BLUE BLOCK', 'selling_price' => 650, 'quantity' => 20]);
        $this->progressive = Product::factory()->create(['user_id' => $doctor->id, 'category_id' => $lenses->id,
            'name' => 'PROGRESSIVE BLUE AR', 'selling_price' => 1300, 'quantity' => 20]);
    }

    private function lines(PatientRecordsComponent $component): array
    {
        return collect($component->productsList)->mapWithKeys(fn ($item) => [$item['name'] => $item['quantity']])->all();
    }

    public function test_changing_the_lens_replaces_the_earlier_choice(): void
    {
        $component = new PatientRecordsComponent;

        $component->selectLensProduct($this->progressive->id);
        $component->selectLensProduct($this->single->id);

        $this->assertSame(['SV BLUE BLOCK' => 1], $this->lines($component));
        $this->assertSame($this->single->id, $component->refractionLensProductId);

        $component->selectLensProduct($this->single->id);
        $this->assertSame(['SV BLUE BLOCK' => 1], $this->lines($component), 'Picking the same lens again adds nothing.');

        $component->selectLensProduct('');
        $this->assertSame([], $this->lines($component), 'Choosing no lens removes it.');
    }

    public function test_a_lens_also_added_by_hand_keeps_the_hand_added_unit(): void
    {
        $component = new PatientRecordsComponent;
        $component->selectProduct($this->progressive->id);

        $component->selectLensProduct($this->progressive->id);
        $this->assertSame(['PROGRESSIVE BLUE AR' => 2], $this->lines($component));

        $component->selectLensProduct($this->single->id);
        $this->assertSame(['PROGRESSIVE BLUE AR' => 1, 'SV BLUE BLOCK' => 1], $this->lines($component));
    }
}
