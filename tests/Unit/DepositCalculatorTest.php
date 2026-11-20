<?php

namespace Tests\Unit;

use App\Pricing\DepositCalculator;
use Tests\TestCase;

/** The deposit rules on their own (defaults: margin 150, round to 50, own-risk surcharge 250, leasing from 30 days). */
class DepositCalculatorTest extends TestCase
{
    private const DAY = 24;

    private function cents(int $base, int $priceCents, bool $keepsOwnRisk = false, int $hours = 24): ?int
    {
        return DepositCalculator::calculate($base, $priceCents, $keepsOwnRisk, $hours)['cents'];
    }

    public function test_an_amount_that_is_already_a_multiple_is_not_pushed_up(): void
    {
        $this->assertSame(50000, $this->cents(0, 35000)); // 350 + 150 = 500, exactly on the step
    }

    public function test_one_cent_over_a_multiple_goes_up_a_whole_step(): void
    {
        $this->assertSame(55000, $this->cents(0, 35001)); // 500,01 -> 550
    }

    public function test_the_deposit_is_always_above_the_price(): void
    {
        foreach ([1, 4999, 5000, 12345, 99999, 250000] as $price) {
            $this->assertGreaterThan($price, $this->cents(0, $price), "price $price");
        }
    }

    public function test_the_surcharge_comes_after_the_rounding(): void
    {
        $this->assertSame(50000 + 25000, $this->cents(0, 35000, keepsOwnRisk: true));
        $this->assertSame(55000 + 25000, $this->cents(0, 35001, keepsOwnRisk: true));
    }

    public function test_the_base_deposit_wins_when_it_is_higher_and_tells_so(): void
    {
        $result = DepositCalculator::calculate(100000, 20000, false, 24);

        $this->assertSame(100000, $result['cents']);
        $this->assertSame('base', $result['basis']);
    }

    public function test_the_price_wins_when_it_is_higher_and_on_a_tie(): void
    {
        $this->assertSame('price', DepositCalculator::calculate(10000, 90000, false, 24)['basis']);
        $this->assertSame('price', DepositCalculator::calculate(50000, 35000, false, 24)['basis']); // both 500,00
    }

    public function test_a_negative_base_counts_as_none(): void
    {
        $this->assertSame($this->cents(0, 20000), $this->cents(-5000, 20000));
    }

    public function test_a_rental_of_30_days_is_leasing_and_one_hour_less_is_not(): void
    {
        $short = DepositCalculator::calculate(50000, 100000, true, 30 * self::DAY - 1);
        $long = DepositCalculator::calculate(50000, 100000, true, 30 * self::DAY);

        $this->assertTrue($short['applicable']);
        $this->assertNotNull($short['cents']);

        $this->assertFalse($long['applicable']);
        $this->assertNull($long['cents']);
        $this->assertSame('leasing', $long['reason']);
        $this->assertSame(0, $long['surcharge_cents']);
    }

    public function test_the_settings_are_used(): void
    {
        config(['pricing.deposit' => ['margin' => 100, 'round_to' => 100, 'own_risk_surcharge' => 0, 'leasing_from_days' => 10]]);

        $this->assertSame(50000, $this->cents(0, 35001)); // 350,01 + 100 = 450,01 -> 500
        $this->assertSame(50000, $this->cents(0, 35001, keepsOwnRisk: true)); // no surcharge configured
        $this->assertNull($this->cents(0, 35001, hours: 10 * self::DAY));
    }

    public function test_a_round_to_of_zero_does_not_break(): void
    {
        config(['pricing.deposit.round_to' => 0]);

        $this->assertSame(35001 + 15000, $this->cents(0, 35001)); // rounded to a single cent
    }
}
