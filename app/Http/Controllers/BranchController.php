<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesImage;
use App\Models\Branch;
use App\Models\OpeningHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    use ManagesImage;

    /** Public: active branches for the website. */
    public function publicIndex(): JsonResponse
    {
        return response()->json(Branch::publicList());
    }

    /** Back office: all branches. */
    public function index(): JsonResponse
    {
        return response()->json(Branch::with('openingHours')->orderBy('city')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $hours = $data['opening_hours'] ?? OpeningHour::defaults();
        unset($data['opening_hours']);

        $branch = DB::transaction(function () use ($data, $hours) {
            $branch = Branch::create($data);
            $branch->syncOpeningHours($hours);

            return $branch;
        });

        return response()->json($branch->load('openingHours'), 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $this->validated($request);
        $hours = $data['opening_hours'] ?? null;
        unset($data['opening_hours']);

        DB::transaction(function () use ($branch, $data, $hours) {
            $branch->update($data);
            if ($hours) {
                $branch->syncOpeningHours($hours);
            }
        });

        return response()->json($branch->load('openingHours'));
    }

    public function destroy(Request $request, Branch $branch): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $this->deleteImage($branch);
        $branch->delete();

        return response()->noContent();
    }

    /** Upload (or replace) the picture of a branch. Sent separately from the other fields: multipart + JSON do not mix well. */
    public function storeImage(Request $request, Branch $branch): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);

        $branch->update($this->imageAttributes($request, 'branches', $branch));

        return response()->json($branch->load('openingHours'));
    }

    public function destroyImage(Branch $branch): JsonResponse
    {
        $this->deleteImage($branch);
        $branch->update(['image_path' => null]);

        return response()->json($branch->load('openingHours'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'is_active' => ['boolean'],
            'opening_hours' => ['sometimes', 'array', 'size:7'],
            'opening_hours.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'opening_hours.*.is_closed' => ['boolean'],
            'opening_hours.*.opens_at' => ['nullable', 'required_if:opening_hours.*.is_closed,false', 'date_format:H:i'],
            'opening_hours.*.closes_at' => ['nullable', 'required_if:opening_hours.*.is_closed,false', 'date_format:H:i', 'after:opening_hours.*.opens_at'],
        ]);
    }
}
