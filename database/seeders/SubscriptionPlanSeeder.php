<?php
namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Perfect for personal use and getting started with expense tracking.',
                'price_monthly' => 0.00,
                'price_yearly' => 0.00,
                'stripe_price_id_monthly' => null,
                'stripe_price_id_yearly' => null,
                'features' => [
                    '8 receipt uploads per month',
                    'AI-powered categorization',
                    'Basic expense analytics',
                    'Monthly spending summaries',
                    'Manual categorization editing',
                ],
                'upload_limit' => 8,
                'is_popular' => false,
                'coming_soon' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Unlimited uploads and advanced features for power users.',
                'price_monthly' => 4.99,
                'price_yearly' => 49.99,
                'stripe_price_id_monthly' => 'price_1RoL4r2WPEskKzhKWZyRsmTU', 
                'stripe_price_id_yearly' => 'price_1RoL5P2WPEskKzhKxADXOmkv', 
                'features' => [
                    'Unlimited receipt uploads',
                    'AI-powered categorization',
                    'Advanced expense analytics',
                    'Weekly & monthly summaries',
                    'Category insights and trends',
                    'Export data (CSV, PDF)',
                    'Priority email support',
                    '30-day free trial',
                ],
                'upload_limit' => -1, // unlimited
                'is_popular' => true,
                'coming_soon' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Advanced features for teams and businesses.',
                'price_monthly' => 29.99,
                'price_yearly' => 299.99,
                'stripe_price_id_monthly' => null, // Will be added later
                'stripe_price_id_yearly' => null,  // Will be added later
                'features' => [
                    'Everything in Pro',
                    'Team management',
                    'Multi-user accounts',
                    'Advanced reporting',
                    'API access',
                    'Custom categories',
                    'SSO integration',
                    'Dedicated account manager',
                    'Priority phone support',
                ],
                'upload_limit' => -1,
                'is_popular' => false,
                'coming_soon' => true,
                'is_active' => false,
            ],
        ];

        foreach ($plans as $planData) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}
