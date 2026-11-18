<?php

namespace App\Models\Concerns;

/** A model with an uploaded image (public disk, column image_path) and a shared placeholder. */
trait HasImage
{
    public function imageUrl(): string
    {
        return $this->image_path
            ? asset('storage/'.$this->image_path)
            : asset('images/category-placeholder.svg');
    }
}
