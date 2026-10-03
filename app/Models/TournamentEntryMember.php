<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentEntryMember extends Model
{
    protected $fillable = ['tournament_id', 'entry_id', 'user_id'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
