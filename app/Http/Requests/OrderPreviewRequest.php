<?php

namespace App\Http\Requests;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Everything the checkout sends when the client presses "Book": the rental period (validated by the
 * parent request: opening hours, return after pick-up, ...), the chosen vehicle, fuel, transmission,
 * extras, and the client's own details.
 */
class OrderPreviewRequest extends RentalPeriodRequest
{
    public function rules(): array
    {
        // Letters (also accented), spaces, apostrophes, dots and hyphens: "Van der Berg", "O'Neil", "Jean-Luc".
        $name = ['required', 'string', 'max:100', "regex:/^[\\pL\\s'.\\-]+$/u"];

        return array_merge(parent::rules(), [
            'class_id' => ['required', Rule::exists('vehicle_classes', 'id')->where('is_active', true)],
            'fuel_type' => ['required', 'string'],
            'transmission' => ['required', 'string'],
            'extras' => ['nullable', 'array', 'max:50'],
            'extras.*.id' => ['required', 'integer'],
            'extras.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reduce_own_risk' => ['nullable', 'boolean'],
            'extra_km' => ['nullable', 'integer', 'min:0', 'max:'.config('pricing.km.max_extra')],

            'first_name' => $name,
            'last_name' => $name,
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'street' => ['required', 'string', 'max:120'],
            // 12, 12a, 12-14, 12 bis
            'house_number' => ['required', 'string', 'max:10', 'regex:/^\\d{1,5}([\\s\\-\\/]?[A-Za-z0-9]{1,5})?$/'],
            // Dutch postal code: 1234 AB
            'postal_code' => ['required', 'string', 'regex:/^[1-9][0-9]{3}\\s?[A-Za-z]{2}$/'],
            'city' => ['required', 'string', 'max:100', "regex:/^[\\pL\\s'.\\-]+$/u"],

            'has_valid_license' => ['accepted'],
            'accept_terms' => ['accepted'],
        ]);
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['date_of_birth', 'pickup_date']) || ! $this->date_of_birth || ! $this->pickup_date) {
                    return;
                }

                $age = config('booking.min_driver_age');

                if (Carbon::parse($this->date_of_birth)->addYears($age)->greaterThan(Carbon::parse($this->pickup_date))) {
                    $validator->errors()->add('date_of_birth', __('You must be at least :age years old on the pick-up date.', ['age' => $age]));
                }
            },
        ];
    }

    /** The postal code as "1234 AB". */
    public function postalCode(): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $this->postal_code));

        return substr($code, 0, 4).' '.substr($code, 4);
    }
}
