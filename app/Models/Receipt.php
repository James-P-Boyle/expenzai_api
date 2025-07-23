<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'image_path',
        'original_filename',
        'file_size',
        'storage_disk',
        'store_name',
        'receipt_date',
        'total_amount',
        'status',
        'week_of'
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'week_of' => 'date',
        'total_amount' => 'decimal:2'
    ];

    protected $appends = ['formatted_total'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function getFormattedTotalAttribute(): string
    {
        return $this->total_amount ? '€' . number_format($this->total_amount, 2) : '€0.00';
    }

    public function scopeForWeek($query, Carbon $date)
    {
        $startOfWeek = $date->startOfWeek();
        return $query->where('week_of', $startOfWeek->toDateString());
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($receipt) {
            if (!$receipt->week_of && $receipt->receipt_date) {
                $receipt->week_of = Carbon::parse($receipt->receipt_date)->startOfWeek();
            }
        });
    }
}
