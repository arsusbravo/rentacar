<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderPreviewRequest;
use App\Models\Branch;
use App\Models\VehicleClass;
use App\Pricing\QuoteBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Checks a complete checkout (client details, period, vehicle, extras) and returns the order as it would
 * be booked. DEMO ONLY: nothing is stored or charged. The real booking will use the same request + QuoteBuilder.
 */
class OrderPreviewController extends Controller
{
    public function __invoke(OrderPreviewRequest $request, QuoteBuilder $quotes): JsonResponse
    {
        $class = VehicleClass::with(['translations', 'fuelOptions', 'transmissionOptions', 'rates', 'category.translations'])->findOrFail($request->class_id);

        if (! $class->offers($request->fuel_type, $request->transmission)) {
            return $this->unprocessable('fuel_type', __('This vehicle is not offered with that fuel type and transmission.'));
        }

        $pickup = Carbon::createFromFormat('Y-m-d H:i', $request->pickup_date.' '.$request->pickup_time);
        $return = Carbon::createFromFormat('Y-m-d H:i', $request->return_date.' '.$request->return_time);

        $quote = $quotes->build($class, $request->fuel_type, $request->transmission, $pickup, $return, $request->input('extras', []), $request->boolean('reduce_own_risk'), (int) $request->input('extra_km', 0));

        if ($quote === null) {
            return $this->unprocessable('price', __('No price is available for this vehicle yet.'));
        }

        if ((int) $request->input('extra_km', 0) > 0 && ! $quote['km']['extra_available']) {
            return $this->unprocessable('extra_km', __('Extra kilometres cannot be booked for this vehicle.'));
        }

        return response()->json([
            'demo' => true, // nothing was booked
            'client' => [
                'first_name' => trim($request->first_name),
                'last_name' => trim($request->last_name),
                'date_of_birth' => $request->date_of_birth,
                'street' => trim($request->street),
                'house_number' => trim($request->house_number),
                'postal_code' => $request->postalCode(),
                'city' => trim($request->city),
            ],
            'vehicle' => [
                'name' => $class->name,
                'example_models' => $class->example_models,
                'category_name' => $class->category->name,
                'fuel_label' => __("vehicles.fuel_types.{$request->fuel_type}"),
                'transmission_label' => __("vehicles.transmissions.{$request->transmission}"),
            ],
            'pickup' => ['branch' => Branch::findOrFail($request->pickup_branch_id)->label(), 'at' => $pickup->format('Y-m-d H:i')],
            'return' => ['branch' => Branch::findOrFail($request->return_branch_id)->label(), 'at' => $return->format('Y-m-d H:i')],
            'quote' => $quote,
        ]);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
