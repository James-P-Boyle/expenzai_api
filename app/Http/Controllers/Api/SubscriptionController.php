<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Services\StripeService;
use Illuminate\Validation\Rule;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class SubscriptionController extends Controller
{
    protected StripeService $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
        $this->middleware('auth:sanctum')->except(['plans']);
        $this->middleware('verified')->except(['plans']);
    }

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('price_monthly')
            ->get()
            ->map(function ($plan) {
                return [
                    'id' => $plan->slug,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price_monthly' => $plan->price_monthly,
                    'price_yearly' => $plan->price_yearly,
                    'features' => $plan->features,
                    'stripe_price_id_monthly' => $plan->stripe_price_id_monthly,
                    'stripe_price_id_yearly' => $plan->stripe_price_id_yearly,
                    'upload_limit' => $plan->upload_limit,
                    'is_popular' => $plan->is_popular,
                    'coming_soon' => $plan->coming_soon,
                ];
            });

        return response()->json(['data' => $plans]);
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id,
                'stripe_customer_id' => $subscription->stripe_customer_id,
                'plan_id' => $subscription->plan->slug,
                'status' => $subscription->status,
                'billing_interval' => $subscription->billing_interval,
                'current_period_start' => $subscription->current_period_start->toISOString(),
                'current_period_end' => $subscription->current_period_end->toISOString(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'trial_start' => $subscription->trial_start?->toISOString(),
                'trial_end' => $subscription->trial_end?->toISOString(),
                'created_at' => $subscription->created_at->toISOString(),
                'updated_at' => $subscription->updated_at->toISOString(),
                'plan' => [
                    'id' => $subscription->plan->slug,
                    'name' => $subscription->plan->name,
                    'description' => $subscription->plan->description,
                    'price_monthly' => $subscription->plan->price_monthly,
                    'price_yearly' => $subscription->plan->price_yearly,
                    'features' => $subscription->plan->features,
                    'upload_limit' => $subscription->plan->upload_limit,
                ],
            ]
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        // Add debug logging for troubleshooting
        Log::info('Subscription creation request', [
            'request_data' => $request->all(),
            'user_id' => $request->user()->id,
            'user_email' => $request->user()->email,
        ]);

        $validated = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists('subscription_plans', 'slug')->where('is_active', true)],
            'billing_interval' => ['required', 'in:monthly,yearly'],
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::where('slug', $validated['plan_id'])->first();

        // Additional safety check
        if (!$plan) {
            $availablePlans = SubscriptionPlan::where('is_active', true)->pluck('slug')->toArray();
            Log::error('Plan not found', [
                'requested_plan' => $validated['plan_id'],
                'available_plans' => $availablePlans
            ]);
            
            return response()->json([
                'error' => 'Plan not found',
                'requested_plan' => $validated['plan_id'],
                'available_plans' => $availablePlans
            ], 400);
        }

        // Check if user already has an active subscription
        if ($user->hasActiveSubscription()) {
            return response()->json([
                'error' => 'You already have an active subscription. Please cancel it first or use the update endpoint.'
            ], 400);
        }

        // Check if plan is coming soon
        if ($plan->coming_soon) {
            return response()->json([
                'error' => 'This plan is not yet available.'
            ], 400);
        }

        // Handle free plan
        if ($plan->slug === 'free') {
            // Update user tier to free (shouldn't normally happen but handle it)
            $user->update(['user_tier' => 'free']);
            
            return response()->json([
                'subscription' => null,
                'message' => 'Switched to free plan successfully.'
            ]);
        }

        try {
            $checkoutSession = $this->stripeService->createCheckoutSession(
                $user, 
                $plan, 
                $validated['billing_interval']
            );

            Log::info('Stripe checkout session created', [
                'user_id' => $user->id,
                'plan_id' => $plan->slug,
                'session_id' => $checkoutSession->id,
                'checkout_url' => $checkoutSession->url,
            ]);

            return response()->json([
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription creation failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Failed to create subscription. Please try again.',
                'details' => config('app.debug') ? $e->getMessage() : null, // Show details only in debug mode
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists('subscription_plans', 'slug')->where('is_active', true)],
            'billing_interval' => ['required', 'in:monthly,yearly'],
        ]);

        $user = $request->user();
        $newPlan = SubscriptionPlan::where('slug', $validated['plan_id'])->first();
        $currentSubscription = $user->activeSubscription;

        if (!$currentSubscription) {
            return response()->json([
                'error' => 'No active subscription found.'
            ], 400);
        }

        if ($newPlan->coming_soon) {
            return response()->json([
                'error' => 'This plan is not yet available.'
            ], 400);
        }

        // Check if they're trying to "update" to the same plan
        if ($currentSubscription->plan->slug === $validated['plan_id'] && 
            $currentSubscription->billing_interval === $validated['billing_interval']) {
            return response()->json([
                'error' => 'You are already on this plan with this billing interval.'
            ], 400);
        }

        try {
            $stripeSubscription = $this->stripeService->updateSubscription(
                $currentSubscription->stripe_subscription_id,
                $newPlan,
                $validated['billing_interval']
            );

            // Sync the updated subscription
            $subscription = $this->stripeService->syncSubscriptionFromStripe(
                $stripeSubscription->id
            );

            return response()->json([
                'message' => 'Subscription updated successfully.',
                'subscription' => $subscription
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription update failed', [
                'user_id' => $user->id,
                'current_plan' => $currentSubscription->plan->slug,
                'new_plan' => $newPlan->slug,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to update subscription. Please try again.'
            ], 500);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return response()->json([
                'error' => 'No active subscription found.'
            ], 400);
        }

        // Check if already cancelled
        if ($subscription->cancel_at_period_end) {
            return response()->json([
                'error' => 'Subscription is already scheduled for cancellation.',
                'message' => 'Your subscription will end on ' . $subscription->current_period_end->format('M j, Y'),
            ], 400);
        }

        try {
            $stripeSubscription = $this->stripeService->cancelSubscription(
                $subscription->stripe_subscription_id
            );

            // Update local subscription
            $subscription->update([
                'cancel_at_period_end' => true,
            ]);

            Log::info('Subscription cancelled', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'end_date' => $subscription->current_period_end,
            ]);

            return response()->json([
                'message' => 'Subscription cancelled successfully. You will retain access until the end of your billing period.',
                'subscription' => $subscription->fresh()->load('plan')
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to cancel subscription. Please try again.'
            ], 500);
        }
    }

    public function resume(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (!$subscription || !$subscription->cancel_at_period_end) {
            return response()->json([
                'error' => 'No cancelled subscription found.'
            ], 400);
        }

        try {
            $stripeSubscription = $this->stripeService->resumeSubscription(
                $subscription->stripe_subscription_id
            );

            // Update local subscription
            $subscription->update([
                'cancel_at_period_end' => false,
            ]);

            Log::info('Subscription resumed', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
            ]);

            return response()->json([
                'message' => 'Subscription resumed successfully.',
                'subscription' => $subscription->fresh()->load('plan')
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription resumption failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Failed to resume subscription. Please try again.'
            ], 500);
        }
    }

    public function billingPortal(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if user has an active subscription first
        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'error' => 'You need an active subscription to access the billing portal.'
            ], 400);
        }

        try {
            Log::info('Creating billing portal session', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'has_subscription' => $user->hasActiveSubscription(),
            ]);

            $session = $this->stripeService->createBillingPortalSession($user);

            Log::info('Billing portal session created successfully', [
                'user_id' => $user->id,
                'session_url' => $session->url,
            ]);

            return response()->json([
                'url' => $session->url
            ]);
        } catch (\Exception $e) {
            Log::error('Billing portal creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Failed to create billing portal session. Please try again.'
            ], 500);
        }
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentMonthUploads = $user->receipts()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $limit = $user->getUploadLimit();
        $remaining = $limit === -1 ? -1 : max(0, $limit - $currentMonthUploads);

        return response()->json([
            'current_month_uploads' => $currentMonthUploads,
            'limit' => $limit,
            'remaining' => $remaining,
            'tier' => $user->getEffectiveTier(),
        ]);
    }
}