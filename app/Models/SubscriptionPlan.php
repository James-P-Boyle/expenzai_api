<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'stripe_price_id_monthly',
        'stripe_price_id_yearly',
        'features',
        'upload_limit',
        'is_popular',
        'coming_soon',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'upload_limit' => 'integer',
        'is_popular' => 'boolean',
        'coming_soon' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function getStripePriceId(string $interval): ?string
    {
        return $interval === 'monthly' 
            ? $this->stripe_price_id_monthly 
            : $this->stripe_price_id_yearly;
    }

    public function getPrice(string $interval): float
    {
        return $interval === 'monthly' 
            ? (float) $this->price_monthly 
            : (float) $this->price_yearly;
    }

    public function isUnlimited(): bool
    {
        return $this->upload_limit === -1;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)->where('coming_soon', false);
    }
}