<?php

namespace Tests\Unit;

use App\Livewire\Secretary\PatientsComponent;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PatientsComponentDateParsingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('displayDates')]
    public function test_it_resolves_two_digit_birth_years_without_returning_a_future_date(
        string $displayDate,
        ?string $expectedDate,
    ): void {
        Carbon::setTestNow('2026-08-05 12:00:00');

        $method = new ReflectionMethod(PatientsComponent::class, 'parseDisplayDate');
        $actualDate = $method->invoke(new PatientsComponent(), $displayDate);

        $this->assertSame($expectedDate, $actualDate);
    }

    public static function displayDates(): array
    {
        return [
            'year 60 is the previous century' => ['15/08/60', '1960-08-15'],
            'reported year 66 case is the previous century' => ['08/05/66', '1966-05-08'],
            'past year in the current century remains unchanged' => ['01/01/20', '2020-01-01'],
            'invalid calendar date is rejected' => ['31/02/60', null],
        ];
    }

    public function test_registry_filter_dates_do_not_get_moved_back_one_century(): void
    {
        Carbon::setTestNow('2026-08-05 12:00:00');

        $method = new ReflectionMethod(PatientsComponent::class, 'parseDisplayDate');
        $actualDate = $method->invoke(new PatientsComponent(), '01/09/26', false);

        $this->assertSame('2026-09-01', $actualDate);
    }
}
