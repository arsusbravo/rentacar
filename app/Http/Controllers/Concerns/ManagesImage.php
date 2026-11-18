<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Image upload for admin controllers whose model has an image_path column. */
trait ManagesImage
{
    protected function imageRules(): array
    {
        return [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'], // KB
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Handle an uploaded or removed image and return the attributes to save:
     * ['image_path' => new path|null], or [] when the image stays as it is.
     */
    protected function imageAttributes(Request $request, string $directory, ?Model $model = null): array
    {
        if ($request->hasFile('image')) {
            $this->deleteImage($model);

            return ['image_path' => $request->file('image')->store($directory, 'public')];
        }

        if ($model && $request->boolean('remove_image')) {
            $this->deleteImage($model);

            return ['image_path' => null];
        }

        return [];
    }

    protected function deleteImage(?Model $model): void
    {
        if ($model?->image_path) {
            Storage::disk('public')->delete($model->image_path);
        }
    }
}
