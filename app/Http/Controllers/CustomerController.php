<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $page = Customer::withCount('bookings')->search($request->q)->orderBy('last_name')->orderBy('first_name')->paginate(15);

        return response()->json([
            'data' => $page->getCollection()->map->toAdminArray()->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Customer::create($this->validated($request))->toAdminArray(), 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validated($request));

        return response()->json($customer->toAdminArray());
    }

    public function destroy(Request $request, Customer $customer): JsonResponse|Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($customer->bookings()->exists()) {
            $message = __('This customer has bookings and cannot be deleted.');

            return response()->json(['message' => $message, 'errors' => ['customer' => [$message]]], 422);
        }

        $customer->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $name = ['required', 'string', 'max:100', "regex:/^[\\pL\\s'.\\-]+$/u"];

        return $request->validate([
            'first_name' => $name,
            'last_name' => $name,
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:120'],
            'house_number' => ['nullable', 'string', 'max:10'],
            'postal_code' => ['nullable', 'string', 'regex:/^[1-9][0-9]{3}\\s?[A-Za-z]{2}$/'],
            'city' => ['nullable', 'string', 'max:100', "regex:/^[\\pL\\s'.\\-]+$/u"],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
