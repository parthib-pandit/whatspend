<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ConversationContext extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scope: only contexts that are still "live" (no expiry, or expiry in the future)
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    // Helper used constantly by the router: "does this user have an active
    // context of this type right now?"
    public static function activeFor(int $userId, string $type): ?self
    {
        return static::where('user_id', $userId)
            ->where('type', $type)
            ->active()
            ->latest()
            ->first();
    }

    // Upsert pattern: clear any existing context of this type for the user,
    // then create the new one. Keeps "only one active pending_review per user"
    // as an explicit code invariant rather than a DB constraint.
    public static function setFor(int $userId, string $type, array $payload, ?\DateTimeInterface $expiresAt = null): self
    {
        static::where('user_id', $userId)->where('type', $type)->delete();

        return static::create([
            'user_id' => $userId,
            'type' => $type,
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ]);
    }
}