<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'stripe_customer_id',
        'user_tier',
        'email_verification_token',
        'total_uploads',
        'daily_uploads',
        'last_upload_date',
        'receipt_email_address',
        'email_receipts_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'stripe_customer_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_receipts_enabled' => 'boolean'
        ];
    }
    
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latest();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
                    ->whereIn('status', ['active', 'trialing'])
                    ->where('current_period_end', '>', now());
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function getEffectiveTier(): string
    {
        $activeSubscription = $this->activeSubscription;
        
        if ($activeSubscription && $activeSubscription->isActive()) {
            return $activeSubscription->plan->slug;
        }
        
        return 'free';
    }

    public function getUploadLimit(): int
    {
        $activeSubscription = $this->activeSubscription;
        
        if ($activeSubscription && $activeSubscription->isActive()) {
            return $activeSubscription->plan->upload_limit;
        }
        
        // Default free tier limit
        return 8;
    }

    public function canUpload(): bool
    {
        $limit = $this->getUploadLimit();
        
        // Unlimited uploads
        if ($limit === -1) {
            return true;
        }
        
        $uploadsThisMonth = $this->receipts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        
        return $uploadsThisMonth < $limit;
    }

    public function getRemainingUploads(): int
    {
        $limit = $this->getUploadLimit();
        
        if ($limit === -1) {
            return -1;
        }
        
        $uploadsThisMonth = $this->receipts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        
        return max(0, $limit - $uploadsThisMonth);
    }

    // Generate unique email address when user upgrades to Pro
    public function generateReceiptEmailAddress(): string
    {
        $address = "receipts-{$this->id}@expenzai.app";
        $this->update(['receipt_email_address' => $address]);
        return $address;
    }

}
