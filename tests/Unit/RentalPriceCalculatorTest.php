<?php

namespace Tests\Unit;

use App\Models\VehicleClassRate;
use App\Pricing\RentalPriceCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The price rules on their own: no database, only the calculator, one rate set and two moments. */
class RentalPriceCalculatorTest extends TestCase
{
    private const PICKUP = '2030-01-07 10:00'; // a Monday

    private function price(array $prices, int $minutes): ?array
    {
        $pickup = Carbon::parse(self::PICKUP);

        return RentalPriceCalculator::calculate(new VehicleClassRate($prices), $pickup, $pickup->copy()->addMinutes($minutes));
    }

    private function cents(array $prices, int $minutes): int
    {
        return $this->price($prices, $minutes)['cents'];
    }

    private const H = 60;

    private const DAY = 24 * 60;

    // --- the rental length ----------------------------------------------------------------------------

    public function test_every_started_hour_counts(): void
    {
        $pickup = Carbon::parse(self::PICKUP);

        $this->assertSame(1, RentalPriceCalculator::hours($pickup, $pickup->copy()->addMinutes(1)));
        $this->assertSame(1, RentalPriceCalculator::hours($pickup, $pickup->copy()->addMinutes(60)));
        $this->assertSame(2, RentalPriceCalculator::hours($pickup, $pickup->copy()->addMinutes(61)));
        $this->assertSame(24, RentalPriceCalculator::hours($pickup, $pickup->copy()->addDay()));
    }

    public function test_a_rental_is_never_shorter_than_one_hour(): void
    {
        $pickup = Carbon::parse(self::PICKUP);

        $this->assertSame(1, RentalPriceCalculator::hours($pickup, $pickup->copy()));
        $this->assertSame(1, RentalPriceCalculator::hours($pickup, $pickup->copy()->subDay()));
    }

    // --- the boundaries between the tariffs ---------------------------------------------------------------

    public function test_exactly_six_hours_is_a_half_day_but_one_minute_more_is_a_day(): void
    {
        $prices = ['price_half_day' => 30, 'price_day' => 50];

        $this->assertSame(3000, $this->cents($prices, 6 * self::H));
        $this->assertSame(5000, $this->cents($prices, 6 * self::H + 1)); // 7 started hours: a day is cheaper than two half days
    }

    public function test_the_half_day_length_follows_the_setting(): void
    {
        config(['pricing.half_day_hours' => 4]);
        $prices = ['price_half_day' => 30, 'price_day' => 50];

        $this->assertSame(3000, $this->cents($prices, 4 * self::H));
        $this->assertSame(5000, $this->cents($prices, 5 * self::H));
    }

    public function test_a_day_and_a_few_hours_is_a_day_plus_a_half_day(): void
    {
        $prices = ['price_half_day' => 30, 'price_day' => 50];

        $this->assertSame(5000, $this->cents($prices, self::DAY));
        $this->assertSame(8000, $this->cents($prices, self::DAY + 1)); // 25 h: day + half day, cheaper than two days
    }

    public function test_thirty_days_are_a_month_and_one_hour_more_adds_a_unit(): void
    {
        $prices = ['price_half_day' => 30, 'price_day' => 50, 'price_week' => 250, 'price_month' => 900];

        $this->assertSame(90000, $this->cents($prices, 30 * self::DAY));
        $this->assertSame(93000, $this->cents($prices, 30 * self::DAY + 1)); // month + half day
    }

    public function test_a_year_is_365_days_and_one_day_more_is_a_year_plus_a_day(): void
    {
        $prices = ['price_day' => 50, 'price_month' => 900, 'price_year' => 9000];

        $this->assertSame(900000, $this->cents($prices, 365 * self::DAY));
        $this->assertSame(905000, $this->cents($prices, 366 * self::DAY));
    }

    // --- choosing the cheapest ------------------------------------------------------------------------

    public function test_with_only_a_year_tariff_even_one_hour_costs_the_year(): void
    {
        $this->assertSame(900000, $this->cents(['price_year' => 9000], self::H));
    }

    public function test_a_longer_unit_is_used_even_when_the_rental_is_a_bit_shorter(): void
    {
        // 6 days at 50 = 300, a week costs 250: the week covers it and is cheaper
        $this->assertSame(25000, $this->cents(['price_day' => 50, 'price_week' => 250], 6 * self::DAY));
    }

    public function test_on_equal_cost_the_fewest_units_win(): void
    {
        $result = $this->price(['price_day' => 10, 'price_week' => 70], 7 * self::DAY);

        $this->assertSame([['unit' => 'week', 'count' => 1, 'cents' => 7000]], $result['breakdown']);
    }

    public function test_the_breakdown_lists_the_longest_unit_first_and_adds_up_to_the_price(): void
    {
        $result = $this->price(['price_half_day' => 30, 'price_day' => 50, 'price_week' => 250], 8 * self::DAY + 2 * self::H);

        $this->assertSame(['week', 'day', 'half_day'], array_column($result['breakdown'], 'unit'));
        $this->assertSame($result['cents'], array_sum(array_column($result['breakdown'], 'cents')));
        $this->assertSame(8 * 24 + 2, $result['hours']);
    }

    public function test_without_any_tariff_there_is_no_price(): void
    {
        $this->assertNull($this->price([], self::DAY));
        $this->assertNull($this->price(['price_day' => null], self::DAY));
    }

    public function test_prices_with_cents_are_not_rounded_off(): void
    {
        $this->assertSame(4999, $this->cents(['price_day' => 49.99], self::DAY));
        $this->assertSame(9998, $this->cents(['price_day' => 49.99], 2 * self::DAY));
    }
}
