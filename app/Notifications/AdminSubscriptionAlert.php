<?php
namespace App\Notifications;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminSubscriptionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public Subscription $subscription,
        public string $action = 'started' // started, cancelled, resumed, updated
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name;
        $actionText = match($this->action) {
            'started' => 'started a subscription',
            'cancelled' => 'cancelled their subscription',
            'resumed' => 'resumed their subscription',
            'updated' => 'updated their subscription',
            default => $this->action
        };

        $subject = "💰 ExpenzAI: {$this->user->name} {$actionText}";

        $message = (new MailMessage)
            ->subject($subject)
            ->line("**User Details:**")
            ->line("• Name: {$this->user->name}")
            ->line("• Email: {$this->user->email}")
            ->line("• User ID: {$this->user->id}")
            ->line("")
            ->line("**Subscription Details:**")
            ->line("• Plan: {$planName}")
            ->line("• Status: {$this->subscription->status}")
            ->line("• Billing: {$this->subscription->billing_interval}")
            ->line("• Period: {$this->subscription->current_period_start->format('M j')} - {$this->subscription->current_period_end->format('M j, Y')}");

        if ($this->subscription->isOnTrial()) {
            $message->line("• Trial ends: {$this->subscription->trial_end->format('M j, Y')}");
        }

        if ($this->subscription->cancel_at_period_end) {
            $message->line("• ⚠️ Will cancel at period end");
        }

        $message->line("")
                ->line("**Revenue Impact:**")
                ->line("• Monthly value: $" . number_format($this->subscription->plan->price_monthly, 2))
                ->line("• Annual value: $" . number_format($this->subscription->plan->price_yearly, 2))
                ->action('View in Stripe', 'https://dashboard.stripe.com/subscriptions/' . $this->subscription->stripe_subscription_id)
                ->line('Stripe Subscription ID: ' . $this->subscription->stripe_subscription_id);

        return $message;
    }
}
