<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['visited_at', 'visitor', 'path', 'referrer_host', 'campaign', 'device', 'locale'])]
class PageVisit extends Model
{
    use Prunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    /** Old visits are deleted by `model:prune` (scheduled daily). */
    public function prunable(): Builder
    {
        return static::where('visited_at', '<', now()->subDays((int) config('visitors.retention_days')));
    }
}
