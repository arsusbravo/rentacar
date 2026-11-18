<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pick-up/return choice of a client. Pick-up follows the branch opening hours;
 * the return can be at any branch, any day and any time (24 hours).
 */
class RentalPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activeBranch = Rule::exists('branches', 'id')->where('is_active', true);

        return [
            'pickup_branch_id' => ['required', $activeBranch],
            'return_branch_id' => ['required', $activeBranch],
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:pickup_date'],
            'return_time' => ['required', 'date_format:H:i'],
            // chosen on the search form; optional here because the checkout has no category field
            'category' => ['nullable', Rule::exists('vehicle_categories', 'slug')->where('is_active', true)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pickup = Carbon::createFromFormat('Y-m-d H:i', $this->pickup_date.' '.$this->pickup_time);
            $return = Carbon::createFromFormat('Y-m-d H:i', $this->return_date.' '.$this->return_time);
            $hours = Branch::with('openingHours')->find($this->pickup_branch_id)->hoursFor($pickup->dayOfWeekIso);

            if (! $hours || $hours->is_closed) {
                $validator->errors()->add('pickup_date', __('rental.branch_closed'));
            } elseif ($this->pickup_time < $hours->opens_at || $this->pickup_time > $hours->closes_at) {
                $validator->errors()->add('pickup_time', __('rental.outside_hours', ['opens' => $hours->opens_at, 'closes' => $hours->closes_at]));
            } elseif ($pickup->isPast()) {
                $validator->errors()->add('pickup_time', __('rental.pickup_in_past'));
            }

            if ($return->lessThanOrEqualTo($pickup)) {
                $validator->errors()->add('return_time', __('rental.return_before_pickup'));
            } elseif ($pickup->diffInDays($return) > config('pricing.max_rental_days')) {
                $validator->errors()->add('return_date', __('rental.too_long', ['days' => config('pricing.max_rental_days')]));
            }
        }];
    }
}
