<?php

namespace App\Http\Controllers;

use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/** Back office: the prices of the vehicle classes (per fuel type x transmission) and the own risk. */
class RateController extends Controller
{
    /** Every class with its price sets, grouped by category. */
    public function index(): JsonResponse
    {
        $groups = VehicleCategory::ordered()
            ->with(['translations', 'classes.translations', 'classes.fuelOptions', 'classes.transmissionOptions', 'classes.rates'])
            ->get()
            ->filter(fn (VehicleCategory $c) => $c->classes->isNotEmpty())
            ->map(fn (VehicleCategory $c) => [
                'category_id' => $c->id,
                'label' => $c->name,
                'classes' => $c->classes->map(fn (VehicleClass $class) => $this->present($class))->values(),
            ])->values();

        return response()->json([
            'data' => $groups,
            'vat_rate' => (float) config('pricing.vat_rate'),
            'half_day_hours' => (int) config('pricing.half_day_hours'),
            'deposit_margin' => (int) config('pricing.deposit.margin'),
            'deposit_round_to' => (int) config('pricing.deposit.round_to'),
            'deposit_surcharge' => (int) config('pricing.deposit.own_risk_surcharge'),
            'deposit_leasing_days' => (int) config('pricing.deposit.leasing_from_days'),
        ]);
    }

    /** Save the price sets and the own risk of one class. */
    public function update(Request $request, VehicleClass $class): JsonResponse
    {
        $class->load(['fuelOptions', 'transmissionOptions', 'rates']);

        $money = ['nullable', 'numeric', 'min:0', 'max:99999.99'];

        $data = $request->validate([
            'rates' => ['present', 'array'],
            'rates.*.fuel_type' => ['required', 'string'],
            'rates.*.transmission' => ['required', 'string'],
            'rates.*.price_half_day' => $money,
            'rates.*.price_day' => $money,
            'rates.*.price_week' => $money,
            'rates.*.price_month' => $money,
            'rates.*.price_year' => $money,
            'rates.*.price_km' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'own_risk' => $money,
            'own_risk_reduced' => $money,
            'own_risk_reduction_price' => $money,
            'deposit' => $money,
            'km_included' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ]);

        $this->validateBusinessRules($request, $class);

        DB::transaction(function () use ($class, $data, $request) {
            foreach ($data['rates'] as $row) {
                $values = collect(VehicleClassRate::PRICES)->mapWithKeys(fn ($c) => [$c => $row[$c] ?? null])->all();
                $keys = ['vehicle_class_id' => $class->id, 'fuel_type' => $row['fuel_type'], 'transmission' => $row['transmission']];

                // A combination without any price is not stored at all.
                if (collect($values)->every(fn ($v) => $v === null)) {
                    VehicleClassRate::where($keys)->delete();
                } else {
                    VehicleClassRate::updateOrCreate($keys, $values);
                }
            }

            $class->update([
                'own_risk' => $data['own_risk'] ?? null,
                'own_risk_reduced' => $data['own_risk_reduced'] ?? null,
                'own_risk_reduction_price' => $data['own_risk_reduction_price'] ?? null,
                'deposit' => $data['deposit'] ?? null,
            ] + ($request->has('km_included') ? ['km_included' => $data['km_included'] ?? 0] : []));
        });

        return response()->json($this->present($class->fresh(['fuelOptions', 'transmissionOptions', 'rates'])));
    }

    // ---------------------------------------------------------------------------------------------

    /** Rules that need the class: only offered combinations, a day price, a sensible own-risk buy-down. */
    private function validateBusinessRules(Request $request, VehicleClass $class): void
    {
        $validator = validator($request->all(), []);
        $validator->after(function (Validator $v) use ($request, $class) {
            foreach ($request->input('rates', []) as $i => $row) {
                if (! $class->offers((string) ($row['fuel_type'] ?? ''), (string) ($row['transmission'] ?? ''))) {
                    $v->errors()->add("rates.$i.fuel_type", __('This class is not offered with that fuel type and transmission.'));

                    continue;
                }

                $filled = collect(VehicleClassRate::PRICES)->contains(fn ($c) => ($row[$c] ?? null) !== null && $row[$c] !== '');
                $hasTime = collect(VehicleClassRate::TARIFFS)->contains(fn ($c) => ($row[$c] ?? null) !== null && $row[$c] !== '');

                if ($filled && ! $hasTime) {
                    $v->errors()->add("rates.$i.price_day", __('Enter at least one time-based price (half day, day, week, month or year).'));
                }
            }

            $reduced = $request->input('own_risk_reduced');
            $price = $request->input('own_risk_reduction_price');
            $ownRisk = $request->input('own_risk');

            if (($reduced === null || $reduced === '') !== ($price === null || $price === '')) {
                $v->errors()->add('own_risk_reduced', __('The own-risk reduction needs both the lower amount and the price per day.'));
            }

            if ($ownRisk !== null && $ownRisk !== '' && $reduced !== null && $reduced !== '' && (float) $reduced > (float) $ownRisk) {
                $v->errors()->add('own_risk_reduced', __('The reduced own risk cannot be higher than the own risk.'));
            }
        });

        $validator->validate();
    }

    private function present(VehicleClass $class): array
    {
        $fromDay = $class->fromDayPriceCents();

        return [
            'id' => $class->id,
            'name' => $class->name,
            'slug' => $class->slug,
            'is_active' => $class->is_active,
            'own_risk' => $class->own_risk === null ? null : (float) $class->own_risk,
            'own_risk_reduced' => $class->own_risk_reduced === null ? null : (float) $class->own_risk_reduced,
            'own_risk_reduction_price' => $class->own_risk_reduction_price === null ? null : (float) $class->own_risk_reduction_price,
            'deposit' => $class->deposit === null ? null : (float) $class->deposit,
            'km_included' => (int) $class->km_included,
            'from_day_price' => $fromDay === null ? null : $fromDay / 100,
            'combinations' => collect($class->combinations())->map(function (array $c) use ($class) {
                $rate = $class->rateFor($c['fuel_type'], $c['transmission']);

                return $c + [
                    'fuel_label' => __("vehicles.fuel_types.{$c['fuel_type']}"),
                    'transmission_label' => __("vehicles.transmissions.{$c['transmission']}"),
                    'priced' => (bool) $rate?->isPriced(),
                ] + collect(VehicleClassRate::PRICES)->mapWithKeys(
                    fn ($col) => [$col => $rate?->{$col} === null ? null : (float) $rate->{$col}]
                )->all();
            })->values(),
        ];
    }
}
