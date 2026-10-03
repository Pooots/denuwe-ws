<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** `last_read_id` is the newest group message this member has seen. */
class SocietyGroupMember extends Model
{
    protected $fillable = ['society_group_id', 'user_id', 'added_by', 'last_read_id'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SocietyGroup::class, 'society_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
