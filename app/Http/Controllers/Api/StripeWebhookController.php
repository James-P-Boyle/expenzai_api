<?php

namespace App\Http\Controllers\Api;

use Stripe\Webhook;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\StripeService;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use App\Http\Controllers\Controller;

class StripeWebhookController extends Controller
{
    protected StripeService $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid payload in Stripe webhook', ['error' => $e->getMessage()]);
            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid signature in Stripe webhook', ['error' => $e->getMessage()]);
            return response('Invalid signature', 400);
        }

        Log::info('Stripe webhook received', ['type' => $event->type, 'id' => $event->id]);

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($event->data->object);
                    break;

                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdate($event->data->object);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event->data->object);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;

                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;

                default:
                    Log::info('Unhandled Stripe webhook event', ['type' => $event->type]);
            }
        } catch (\Exception $e) {
            Log::error('Error processing Stripe webhook', [
                'type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response('Webhook handler failed', 500);
        }

        return response('Webhook handled', 200);
    }

    protected function handleCheckoutSessionCompleted($session): void
    {
        if ($session->mode !== 'subscription') {
            return;
        }

        $stripeSubscriptionId = $session->subscription;
        
        if ($stripeSubscriptionId) {
            $subscription = $this->stripeService->syncSubscriptionFromStripe($stripeSubscriptionId);
            
            // Update email receipts access after checkout completion
            if ($subscription && $subscription->user) {
                $this->updateEmailReceiptsAccess($subscription->user);
            }
        }
    }

    protected function handleSubscriptionUpdate($stripeSubscription): void
    {
        $subscription = $this->stripeService->syncSubscriptionFromStripe($stripeSubscription->id);
        
        if ($subscription) {
            Log::info('Subscription synced from Stripe', [
                'subscription_id' => $subscription->id,
                'stripe_id' => $stripeSubscription->id,
                'status' => $stripeSubscription->status
            ]);
            
            // Update email receipts access when subscription changes
            $this->updateEmailReceiptsAccess($subscription->user);
        }
    }

    protected function handleSubscriptionDeleted($stripeSubscription): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            // Downgrade user to free tier
            $subscription->user->update(['user_tier' => 'free']);
            
            // Disable email receipts when subscription is cancelled
            $subscription->user->disableEmailReceipts();

            Log::info('Subscription cancelled and email receipts disabled', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id
            ]);
        }
    }

    protected function handlePaymentSucceeded($invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();
        
        if ($subscription) {
            // Update subscription status to active if it was past_due
            if ($subscription->status === 'past_due') {
                $subscription->update(['status' => 'active']);
            }

            Log::info('Payment succeeded for subscription', [
                'subscription_id' => $subscription->id,
                'amount' => $invoice->amount_paid / 100, // Convert from cents
                'currency' => $invoice->currency
            ]);

            // Update email receipts access after successful payment
            $this->updateEmailReceiptsAccess($subscription->user);
        }
    }

    protected function handlePaymentFailed($invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();
        
        if ($subscription) {
            // Update subscription status to past_due
            $subscription->update(['status' => 'past_due']);

            Log::warning('Payment failed for subscription', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'amount' => $invoice->amount_due / 100,
                'currency' => $invoice->currency
            ]);

            // Optionally disable email receipts for past_due subscriptions
            // $subscription->user->disableEmailReceipts();
        }
    }

    // NEW METHOD: Helper method to manage email receipt access
    private function updateEmailReceiptsAccess($user): void
    {
        if (!$user) {
            return;
        }

        $effectiveTier = $user->getEffectiveTier();
        
        if ($effectiveTier === 'pro') {
            // Enable email receipts for Pro users
            if (!$user->email_receipts_enabled) {
                $emailAddress = $user->enableEmailReceipts();
                Log::info('Email receipts enabled for Pro user', [
                    'user_id' => $user->id,
                    'email_address' => $emailAddress
                ]);
            }
        } else {
            // Disable for non-Pro users
            if ($user->email_receipts_enabled) {
                $user->disableEmailReceipts();
                Log::info('Email receipts disabled for non-Pro user', [
                    'user_id' => $user->id,
                    'tier' => $effectiveTier
                ]);
            }
        }
    }
}