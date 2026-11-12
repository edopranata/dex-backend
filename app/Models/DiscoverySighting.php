<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One token seen by one discovery source in one discovery run, kept to measure later whether tokens that several
 * independent sources agree on perform better. Recording only: sightings do not change which tokens are tracked.
 */
#[Fillable(['chain_id', 'token_address', 'symbol', 'source', 'list_rank', 'organic_score', 'seen_at'])]
class DiscoverySighting extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('seen_at', '<', now()->subDays((int) config('market.discovery.sighting_retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
            'list_rank' => 'integer',
            'organic_score' => 'float',
        ];
    }
}
