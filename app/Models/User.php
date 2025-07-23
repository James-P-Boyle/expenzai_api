<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

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
        'user_tier',
        'email_verification_token',
        'total_uploads',
        'daily_uploads',
        'last_upload_date',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }
    
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * Check if user can upload receipts based on their tier limits
     */
    public function canUpload(): bool
    {
        $uploadLimits = [
            'free' => 8,      // 8 receipts per month
            'premium' => 30,  // 30 receipts per month
            'pro' => -1,       // unlimited
        ];

        $userTier = $this->user_tier ?? 'free';
        $monthlyLimit = $uploadLimits[$userTier] ?? $uploadLimits['free'];

        // Unlimited uploads for pro tier
        if ($monthlyLimit === -1) {
            return true;
        }

        // Count uploads this month
        $uploadsThisMonth = $this->receipts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return $uploadsThisMonth < $monthlyLimit;
    }

    /**
     * Get remaining uploads for current month
     */
    public function getRemainingUploads(): int
    {
        $uploadLimits = [
            'free' => 8,     
            'premium' => 30,  
            'pro' => -1,// unlimited
        ];

        $userTier = $this->user_tier ?? 'free';
        $monthlyLimit = $uploadLimits[$userTier] ?? $uploadLimits['free'];

        if ($monthlyLimit === -1) {
            return -1;
        }

        $uploadsThisMonth = $this->receipts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return max(0, $monthlyLimit - $uploadsThisMonth);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }
        
}
