<?php

namespace App\Http\Controllers;

use App\Models\VehicleClass;
use App\Pricing\QuoteBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Public: the price build-up of a rental for the checkout. The booking will use the same QuoteBuilder. */
class QuoteController extends Controller
{
    public function __invoke(Request $request, QuoteBuilder $quotes): JsonResponse
    {
        $validator = validator($request->all(), [
            'class_id' => ['required', Rule::exists('vehicle_classes', 'id')->where('is_active', true)],
            'fuel_type' => ['required', 'string'],
            'transmission' => ['required', 'string'],
            'pickup_date' => ['required', 'date_format:Y-m-d'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:pickup_date'],
            'return_time' => ['required', 'date_format:H:i'],
            'extras' => ['nullable', 'array', 'max:50'],
            'extras.*.id' => ['required', 'integer'],
            'extras.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reduce_own_risk' => ['nullable', 'boolean'],
            'extra_km' => ['nullable', 'integer', 'min:0', 'max:'.config('pricing.km.max_extra')],
        ]);

        $validator->after(function (Validator $v) use ($request) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $pickup = Carbon::createFromFormat('Y-m-d H:i', $request->pickup_date.' '.$request->pickup_time);
            $return = Carbon::createFromFormat('Y-m-d H:i', $request->return_date.' '.$request->return_time);

            if ($return->lessThanOrEqualTo($pickup)) {
                $v->errors()->add('return_time', __('rental.return_before_pickup'));
            } elseif ($pickup->diffInDays($return) > config('pricing.max_rental_days')) {
                $v->errors()->add('return_date', __('A rental can be at most :days days.', ['days' => config('pricing.max_rental_days')]));
            }
        });

        $data = $validator->validate();

        $class = VehicleClass::with(['translations', 'fuelOptions', 'transmissionOptions', 'rates'])->findOrFail($data['class_id']);

        if (! $class->offers($data['fuel_type'], $data['transmission'])) {
            return response()->json([
                'message' => __('This vehicle is not offered with that fuel type and transmission.'),
                'errors' => ['fuel_type' => [__('This vehicle is not offered with that fuel type and transmission.')]],
            ], 422);
        }

        $quote = $quotes->build(
            $class,
            $data['fuel_type'],
            $data['transmission'],
            Carbon::createFromFormat('Y-m-d H:i', $data['pickup_date'].' '.$data['pickup_time']),
            Carbon::createFromFormat('Y-m-d H:i', $data['return_date'].' '.$data['return_time']),
            $data['extras'] ?? [],
            (bool) ($data['reduce_own_risk'] ?? false),
            (int) ($data['extra_km'] ?? 0),
        );

        if ($quote === null) {
            return response()->json([
                'message' => __('No price is available for this vehicle yet.'),
                'errors' => ['price' => [__('No price is available for this vehicle yet.')]],
            ], 422);
        }

        // extra kilometres need a per-km price; do not silently ignore them
        if (($data['extra_km'] ?? 0) > 0 && ! $quote['km']['extra_available']) {
            return response()->json([
                'message' => __('Extra kilometres cannot be booked for this vehicle.'),
                'errors' => ['extra_km' => [__('Extra kilometres cannot be booked for this vehicle.')]],
            ], 422);
        }

        return response()->json($quote);
    }
}
