<?php

namespace Tests\Unit;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    public function test_monthly_renewal_from_the_31st_lands_on_the_last_day_of_february(): void
    {
        $next = BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-01-31'));

        $this->assertSame('2027-02-28', $next->toDateString());
    }

    public function test_each_cycle_moves_the_right_number_of_months(): void
    {
        $start = CarbonImmutable::parse('2026-09-26');

        $this->assertSame('2026-12-26', BillingCycle::Quarterly->advance($start)->toDateString());
        $this->assertSame('2027-03-26', BillingCycle::SemiAnnually->advance($start)->toDateString());
        $this->assertSame('2027-09-26', BillingCycle::Annually->advance($start)->toDateString());
        $this->assertSame('2028-09-26', BillingCycle::Biennially->advance($start)->toDateString());
        $this->assertSame('2029-09-26', BillingCycle::Triennially->advance($start)->toDateString());
    }

    public function test_a_clamped_date_goes_back_to_the_day_the_service_started(): void
    {
        $this->assertSame('2027-03-31', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-02-28'), 31)->toDateString());
        $this->assertSame('2027-04-30', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-03-31'), 31)->toDateString());
        $this->assertSame('2027-05-31', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-04-30'), 31)->toDateString());
        $this->assertSame('2027-03-30', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-02-28'), 30)->toDateString());
        $this->assertSame('2032-02-29', BillingCycle::Annually->advance(CarbonImmutable::parse('2031-02-28'), 29)->toDateString());
        $this->assertSame('2027-05-31', BillingCycle::Quarterly->advance(CarbonImmutable::parse('2027-02-28'), 31)->toDateString());
    }

    public function test_a_date_moved_by_hand_is_not_snapped_to_the_start_day(): void
    {
        $this->assertSame('2027-04-15', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-03-15'), 31)->toDateString());
        $this->assertSame('2027-03-10', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-02-10'), 31)->toDateString());
        $this->assertSame('2027-02-28', BillingCycle::Monthly->advance(CarbonImmutable::parse('2027-01-31'), 31)->toDateString());
    }

    public function test_one_time_and_free_do_not_renew(): void
    {
        $start = CarbonImmutable::parse('2026-09-26');

        $this->assertFalse(BillingCycle::OneTime->isRecurring());
        $this->assertFalse(BillingCycle::Free->isRecurring());
        $this->assertTrue($start->equalTo(BillingCycle::OneTime->advance($start)));
    }
}
