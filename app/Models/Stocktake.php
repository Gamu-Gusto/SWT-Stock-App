<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    public const DRAFT = 'draft';

    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const TYPES = ['full', 'brand', 'size', 'location', 'used'];

    protected $fillable = ['stocktake_no', 'title', 'type', 'scope', 'is_blind', 'status', 'notes', 'created_by'];

    protected $casts = [
        'scope' => 'array',
        'is_blind' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'review_started_at' => 'datetime',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isAssigned(User $user): bool
    {
        return $this->assignees()->whereKey($user->id)->exists();
    }
}
