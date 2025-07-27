<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'status',
        'billing_interval',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'trial_start',
        'trial_end',
        'cancelled_at',
    ];

    protected $casts = [
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']) && 
               $this->current_period_end->isFuture();
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trialing' && 
               $this->trial_end && 
               $this->trial_end->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled' || $this->cancel_at_period_end;
    }

    public function daysLeftInPeriod(): int
    {
        return max(0, now()->diffInDays($this->current_period_end, false));
    }

    public function daysLeftInTrial(): int
    {
        if (!$this->isOnTrial()) {
            return 0;
        }

        return max(0, now()->diffInDays($this->trial_end, false));
    }

    public function getStatusDisplayAttribute(): string
    {
        if ($this->isOnTrial()) {
            return 'Free Trial';
        }

        return match($this->status) {
            'active' => 'Active',
            'cancelled' => 'Cancelled',
            'past_due' => 'Past Due',
            'trialing' => 'Trial',
            'incomplete' => 'Incomplete',
            default => ucfirst($this->status)
        };
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'trialing'])
                    ->where('current_period_end', '>', now());
    }

    public function scopeOnTrial($query)
    {
        return $query->where('status', 'trialing')
                    ->where('trial_end', '>', now());
    }

    public function scopeCancelled($query)
    {
        return $query->where(function ($query) {
            $query->where('status', 'cancelled')
                  ->orWhere('cancel_at_period_end', true);
        });
    }
}