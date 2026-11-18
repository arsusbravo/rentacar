<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Back office: the bookings the company has to confirm. DEMO: no emails are really sent. */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', array_column(BookingStatus::cases(), 'value'))], 'q' => ['nullable', 'string', 'max:100'], 'customer' => ['nullable', 'integer']]);

        $query = Booking::with(['customer', 'vehicleClass.translations', 'pickupBranch', 'returnBranch', 'extras'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->customer, fn ($q, $id) => $q->where('customer_id', $id))
            ->when(trim((string) $request->q) !== '', function ($q) use ($request) {
                $like = '%'.addcslashes(trim($request->q), '%_\\').'%';
                $q->where(fn ($q) => $q->where('reference', 'like', $like)->orWhereHas('customer', fn ($c) => $c->search($request->q)));
            })
            // what needs attention first: unconfirmed, then by pick-up date
            ->orderByRaw("case status when 'unconfirmed' then 0 when 'confirmed' then 1 else 2 end")
            ->orderBy('pickup_at');

        $page = $query->paginate(15);

        return response()->json([
            'data' => $page->getCollection()->map->toAdminArray()->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => Booking::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /** The company checked the availability: the client gets the confirmation. */
    public function confirm(Booking $booking): JsonResponse
    {
        if ($booking->status !== BookingStatus::Unconfirmed) {
            return $this->conflict($booking, __('Only an unconfirmed booking can be confirmed.'));
        }

        $booking->update(['status' => BookingStatus::Confirmed, 'confirmed_at' => now()]);

        return response()->json($this->fresh($booking));
    }

    /** Not available, or cancelled afterwards. */
    public function cancel(Booking $booking): JsonResponse
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return $this->conflict($booking, __('This booking is already cancelled.'));
        }

        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        return response()->json($this->fresh($booking));
    }

    private function fresh(Booking $booking): array
    {
        return $booking->fresh(['customer', 'vehicleClass.translations', 'pickupBranch', 'returnBranch', 'extras'])->toAdminArray();
    }

    private function conflict(Booking $booking, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422);
    }
}
