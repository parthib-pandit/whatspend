<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'monthly_limit',
        'alert_threshold_percent',
        'last_alerted_period',
    ];

    protected $casts = [
        'monthly_limit' => 'decimal:2',
        'alert_threshold_percent' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOverall(): bool
    {
        return $this->category === null;
    }
}