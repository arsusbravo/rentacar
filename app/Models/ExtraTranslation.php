<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['extra_id', 'locale', 'title', 'description'])]
class ExtraTranslation extends Model
{
    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }
}
