<?php

namespace Tests\Unit;

use App\Models\Refractions;
use PHPUnit\Framework\TestCase;

class RefractionsFormattingTest extends TestCase
{
    public function test_it_formats_structured_prescriptions_consistently(): void
    {
        $this->assertSame('-2.00 / -0.75 DC × 090°', Refractions::formatPrescription('-2.00', '-0.75', 90));
        $this->assertSame('+0.00 DS', Refractions::formatPrescription('0.00'));
    }

    public function test_structured_subjective_values_take_priority_over_legacy_text(): void
    {
        $refraction = new Refractions([
            'refractionOD' => 'legacy value',
            'subjective_od_sphere' => '-1.50',
            'subjective_od_cylinder' => '-0.50',
            'subjective_od_axis' => 80,
        ]);

        $this->assertSame('-1.50 / -0.50 DC × 080°', $refraction->subjectiveRx('od'));
    }

    public function test_legacy_text_remains_the_fallback_for_previous_records(): void
    {
        $refraction = new Refractions(['refractionOS' => '-1.00 DS']);

        $this->assertSame('-1.00 DS', $refraction->subjectiveRx('os'));
        $this->assertFalse($refraction->hasStructuredSubjective());
    }
}
