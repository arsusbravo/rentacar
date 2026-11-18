<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

trait AttachesSeedImage
{
    /**
     * Use database/seeders/images/<folder>/<name>.jpg (name = the slug unless given) as the model's image: copy it to the public
     * disk (<folder>/<name>.jpg) and store the path. A model that already has an image (uploaded in
     * the admin or seeded before) keeps it, and a model without a file in the folder keeps the placeholder.
     */
    private function attachSeedImage(Model $model, string $folder, ?string $name = null): void
    {
        $name ??= $model->slug;
        $source = database_path("seeders/images/$folder/$name.jpg");

        if ($model->image_path || ! is_file($source)) {
            return;
        }

        $path = Storage::disk('public')->putFileAs($folder, new File($source), "$name.jpg");
        $model->update(['image_path' => $path]);
    }
}
