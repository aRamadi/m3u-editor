<?php

namespace App\Pivots;

use App\Models\Channel;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class MergedPlaylistPivot extends Pivot
{
    protected $table = 'merged_playlist_playlist';

    protected $casts = [
        'sort' => 'integer',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists || $this->sort !== null) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            MergedPlaylist::query()
                ->whereKey($this->merged_playlist_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->sort === null) {
                $this->sort = (static::where('merged_playlist_id', $this->merged_playlist_id)->max('sort') ?? 0) + 1;
            }

            return parent::save($options);
        }, attempts: 3);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            Playlist::class
        );
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            Group::class,
            Playlist::class
        );
    }

    public function enabledChannels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }
}
