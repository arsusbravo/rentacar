<?php

namespace App\Http\Controllers;

use App\Http\Requests\RentalPeriodRequest;
use Illuminate\Http\Response;

class RentalPeriodController extends Controller
{
    /** Validates a pick-up/return choice; 204 when valid, 422 with errors otherwise. */
    public function validate(RentalPeriodRequest $request): Response
    {
        return response()->noContent();
    }
}
