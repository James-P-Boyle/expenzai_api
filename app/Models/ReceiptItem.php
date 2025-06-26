<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    protected $fillable = [
        'receipt_id',
        'name',
        'price',
        'category',
        'is_uncertain'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_uncertain' => 'boolean'
    ];
    
    protected $appends = ['formatted_price'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category', 'name');
    }

    public function getFormattedPriceAttribute(): string
    {
        return '€' . number_format($this->price, 2);
    }

    public function scopeUncertain($query)
    {
        return $query->where('is_uncertain', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
