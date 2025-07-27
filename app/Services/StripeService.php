<?php

namespace App\Services;

use Carbon\Carbon;
use Stripe\Stripe;
use App\Models\User;
use Stripe\Customer;
use App\Models\Subscription;
use Stripe\Checkout\Session;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;
use Stripe\BillingPortal\Session as BillingPortalSession;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createOrGetCustomer(User $user): Customer
    {
        if ($user->stripe_customer_id) {
            try {
                return Customer::retrieve($user->stripe_customer_id);
            } catch (ApiErrorException $e) {
                // Customer doesn't exist, create new one
                $user->stripe_customer_id = null;
                $user->save();
            }
        }

        $customer = Customer::create([
            'name' => $user->name,
            'email' => $user->email,
            'metadata' => [
                'user_id' => $user->id,
            ],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer;
    }

    public function createCheckoutSession(
        User $user, 
        SubscriptionPlan $plan, 
        string $billingInterval
    ): Session {
        $customer = $this->createOrGetCustomer($user);
        $priceId = $plan->getStripePriceId($billingInterval);

        if (!$priceId) {
            throw new \Exception("No Stripe price ID found for plan {$plan->slug} with {$billingInterval} billing");
        }

        $sessionData = [
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => config('app.frontend_url') . '/dashboard?subscription=success',
            'cancel_url' => config('app.frontend_url') . '/pricing?subscription=cancelled',
            'metadata' => [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_interval' => $billingInterval,
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                ],
            ],
        ];

        // Add trial period for non-free plans
        if ($plan->slug !== 'free') {
            $sessionData['subscription_data']['trial_period_days'] = 30;
        }

        return Session::create($sessionData);
    }

    public function createBillingPortalSession(User $user): BillingPortalSession
    {
        $customer = $this->createOrGetCustomer($user);

        return BillingPortalSession::create([
            'customer' => $customer->id,
            'return_url' => config('app.frontend_url') . '/dashboard',
        ]);
    }

    public function cancelSubscription(string $stripeSubscriptionId): StripeSubscription
    {
        return StripeSubscription::update($stripeSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    public function resumeSubscription(string $stripeSubscriptionId): StripeSubscription
    {
        return StripeSubscription::update($stripeSubscriptionId, [
            'cancel_at_period_end' => false,
        ]);
    }

    public function updateSubscription(
        string $stripeSubscriptionId, 
        SubscriptionPlan $newPlan, 
        string $billingInterval
    ): StripeSubscription {
        $stripeSubscription = StripeSubscription::retrieve($stripeSubscriptionId);
        $newPriceId = $newPlan->getStripePriceId($billingInterval);

        if (!$newPriceId) {
            throw new \Exception("No Stripe price ID found for plan {$newPlan->slug} with {$billingInterval} billing");
        }

        return StripeSubscription::update($stripeSubscriptionId, [
            'items' => [[
                'id' => $stripeSubscription->items->data[0]->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'create_prorations',
        ]);
    }

    public function syncSubscriptionFromStripe(string $stripeSubscriptionId): ?Subscription
    {
        try {
            $stripeSubscription = StripeSubscription::retrieve($stripeSubscriptionId);
            $userId = $stripeSubscription->metadata->user_id ?? null;
            $planId = $stripeSubscription->metadata->plan_id ?? null;

            if (!$userId || !$planId) {
                throw new \Exception("Missing metadata in Stripe subscription");
            }

            $user = User::find($userId);
            $plan = SubscriptionPlan::find($planId);

            if (!$user || !$plan) {
                throw new \Exception("User or plan not found");
            }

            // Determine billing interval from the price
            $billingInterval = 'monthly';
            if ($stripeSubscription->items->data[0]->price->id === $plan->stripe_price_id_yearly) {
                $billingInterval = 'yearly';
            }

            $subscription = Subscription::updateOrCreate(
                ['stripe_subscription_id' => $stripeSubscriptionId],
                [
                    'user_id' => $user->id,
                    'subscription_plan_id' => $plan->id,
                    'stripe_customer_id' => $stripeSubscription->customer,
                    'status' => $stripeSubscription->status,
                    'billing_interval' => $billingInterval,
                    'current_period_start' => Carbon::createFromTimestamp($stripeSubscription->current_period_start),
                    'current_period_end' => Carbon::createFromTimestamp($stripeSubscription->current_period_end),
                    'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
                    'trial_start' => $stripeSubscription->trial_start ? Carbon::createFromTimestamp($stripeSubscription->trial_start) : null,
                    'trial_end' => $stripeSubscription->trial_end ? Carbon::createFromTimestamp($stripeSubscription->trial_end) : null,
                ]
            );

            // Update user tier based on subscription
            $user->update(['user_tier' => $plan->slug]);

            return $subscription;
        } catch (\Exception $e) {
            Log::error('Failed to sync subscription from Stripe: ' . $e->getMessage());
            return null;
        }
    }
}