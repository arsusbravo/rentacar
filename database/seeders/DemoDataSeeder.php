<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Extra;
use App\Models\VehicleClass;
use App\Pricing\QuoteBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fake clients and bookings (some unconfirmed, some confirmed, a few cancelled) so the back office has
 * something to show. Run once: it does nothing when bookings or demo customers already exist, so it never
 * touches data entered by hand. Prices come from the real quote calculation.
 */
class DemoDataSeeder extends Seeder
{
    private const CUSTOMERS = [
        ['Sanne', 'de Vries', 'Amsterdam', '1012 AB', 'Damrak', '12'], ['Jeroen', 'Bakker', 'Rotterdam', '3011 BG', 'Coolsingel', '45'],
        ['Fatima', 'El Amrani', 'Den Haag', '2511 CV', 'Lange Poten', '8'], ['Lars', 'Jansen', 'Utrecht', '3511 AA', 'Oudegracht', '101'],
        ['Emma', 'Visser', 'Haarlem', '2011 HK', 'Grote Houtstraat', '33'], ['Daan', 'Smit', 'Delft', '2611 AB', 'Voldersgracht', '5'],
        ['Mila', 'Meijer', 'Leiden', '2311 EZ', 'Breestraat', '70'], ['Thijs', 'van den Berg', 'Gouda', '2801 JJ', 'Markt', '21'],
        ['Noor', 'Mulder', 'Zoetermeer', '2711 CB', 'Stationsplein', '14'], ['Ruben', 'de Boer', 'Dordrecht', '3311 LD', 'Voorstraat', '60'],
        ['Lotte', 'Hoekstra', 'Amstelveen', '1181 ZN', 'Van der Hooplaan', '9'], ['Bram', 'Dekker', 'Schiedam', '3111 AE', 'Hoogstraat', '27'],
        ['Yasmina', 'Bouzid', 'Rotterdam', '3012 KM', 'Binnenweg', '112'], ['Pieter', 'van Dijk', 'Capelle aan den IJssel', '2901 EH', 'Ravelstraat', '29'],
        ['Anouk', 'Brouwer', 'Almere', '1315 AB', 'Stadhuisplein', '3'], ['Sem', 'Vos', 'Hilversum', '1211 BC', 'Kerkbrink', '18'],
        ['Iris', 'Peters', 'Amsterdam', '1017 CT', 'Reguliersdwarsstraat', '40'], ['Kees', 'Hendriks', 'Den Haag', '2514 GK', 'Lange Voorhout', '77'],
        ['Zeynep', 'Yilmaz', 'Rotterdam', '3014 CM', 'Westblaak', '200'], ['Joris', 'van Leeuwen', 'Vlaardingen', '3131 BA', 'Westnieuwland', '6'],
        ['Femke', 'Kok', 'Alphen aan den Rijn', '2406 AB', 'Julianastraat', '31'], ['Milan', 'Jacobs', 'Rijswijk', '2282 GA', 'Generaal Spoorlaan', '22'],
        ['Julia', 'Schouten', 'Nieuwegein', '3431 BE', 'Zandveld', '15'], ['Hugo', 'Willems', 'Maassluis', '3141 AA', 'Hoogstraat', '4'],
    ];

    public function run(): void
    {
        if (Booking::exists() || Customer::where('email', 'like', '%@example.test')->exists()) {
            $this->command?->info('Demo clients/bookings already exist: nothing done.');

            return;
        }

        mt_srand(2026); // the same demo data every time

        $customers = collect(self::CUSTOMERS)->map(fn ($c, $i) => Customer::create([
            'first_name' => $c[0], 'last_name' => $c[1], 'city' => $c[2], 'postal_code' => $c[3], 'street' => $c[4], 'house_number' => $c[5],
            'date_of_birth' => Carbon::create(1958 + ($i * 7) % 40, 1 + ($i * 5) % 12, 1 + ($i * 11) % 28)->format('Y-m-d'),
            'email' => strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $c[0].'.'.$c[1]))).'@example.test',
            'phone' => '06 '.str_pad((string) mt_rand(10000000, 99999999), 8, '0'),
        ]));

        $classes = VehicleClass::active()->with(['translations', 'rates', 'fuelOptions', 'transmissionOptions'])->get()
            ->filter(fn (VehicleClass $c) => $c->combinations() !== [] && $c->rates->isNotEmpty())->values();
        $branches = Branch::active()->get();

        if ($classes->isEmpty() || $branches->count() < 1) {
            $this->command?->warn('No vehicle classes with prices or branches: only the clients were created.');

            return;
        }

        $quotes = app(QuoteBuilder::class);
        $created = 0;

        // 36 bookings: from 6 weeks ago until 5 weeks ahead. Older ones are confirmed, the newest still wait for the company.
        for ($i = 0; $i < 36; $i++) {
            $class = $classes[mt_rand(0, $classes->count() - 1)];
            $priced = array_values(array_filter($class->combinations(), fn ($c) => $class->rateFor($c['fuel_type'], $c['transmission']) !== null));
            if ($priced === []) {
                continue;
            }
            $combo = $priced[mt_rand(0, count($priced) - 1)];
            $pickup = $this->workday(Carbon::today()->addDays(mt_rand(-42, 35)))->setTime(mt_rand(8, 16), [0, 30][mt_rand(0, 1)]);
            $return = $pickup->copy()->addDays(mt_rand(1, 9))->addHours(mt_rand(-2, 3));
            $extras = Extra::active()->availableFor($class)->inRandomOrder()->limit(mt_rand(0, 2))->get()->map(fn ($e) => ['id' => $e->id, 'quantity' => 1])->all();

            $quote = $quotes->build($class, $combo['fuel_type'], $combo['transmission'], $pickup, $return, $extras, mt_rand(0, 3) === 0, mt_rand(0, 2) === 0 ? mt_rand(1, 6) * 50 : 0);
            if ($quote === null) {
                continue;
            }

            $status = match (true) {
                mt_rand(1, 12) === 1 => BookingStatus::Cancelled,
                $pickup->lt(Carbon::today()->addDays(2)) => BookingStatus::Confirmed,           // past or imminent: long since answered
                mt_rand(0, 2) === 0 => BookingStatus::Confirmed,
                default => BookingStatus::Unconfirmed,
            };
            $booked = $pickup->copy()->subDays(mt_rand(3, 20))->min(now())->setTime(mt_rand(8, 21), mt_rand(0, 59));

            $booking = Booking::create([
                'reference' => Booking::nextReference(),
                'customer_id' => $customers[mt_rand(0, $customers->count() - 1)]->id,
                'vehicle_class_id' => $class->id,
                'fuel_type' => $combo['fuel_type'],
                'transmission' => $combo['transmission'],
                'pickup_branch_id' => $branches->random()->id,
                'return_branch_id' => $branches->random()->id,
                'pickup_at' => $pickup,
                'return_at' => $return,
                'status' => $status,
                'own_risk_reduced' => $quote['own_risk']['reduction_chosen'],
                'extra_km' => $quote['km']['extra'],
                'rental_cents' => $quote['rental']['cents'],
                'own_risk_reduction_cents' => $quote['own_risk']['reduction_cents'] ?? 0,
                'extras_cents' => array_sum(array_column($quote['extras'], 'cents')),
                'extra_km_cents' => $quote['km']['extra_cents'],
                'subtotal_cents' => $quote['subtotal_cents'],
                'vat_cents' => $quote['vat_cents'],
                'total_cents' => $quote['total_cents'],
                'deposit_cents' => $quote['deposit']['cents'],
                'confirmed_at' => $status === BookingStatus::Confirmed ? $booked->copy()->addHours(mt_rand(2, 20)) : null,
                'cancelled_at' => $status === BookingStatus::Cancelled ? $booked->copy()->addHours(mt_rand(2, 40)) : null,
            ]);
            $booking->forceFill(['created_at' => $booked, 'updated_at' => $booked])->saveQuietly();

            foreach ($quote['extras'] as $line) {
                $booking->extras()->create(['title' => $line['title'], 'quantity' => $line['quantity'], 'price_unit' => $line['price_unit'], 'cents' => $line['cents']]);
            }
            $created++;
        }

        $this->command?->info("Created {$customers->count()} demo clients and {$created} demo bookings.");
    }

    /** The next Monday-Friday on or after the date. */
    private function workday(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }
}
