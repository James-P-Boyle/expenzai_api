<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public Subscription $subscription
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $endDate = $this->subscription->current_period_end->format('M j, Y');
        $planName = $this->subscription->plan->name;

        return (new MailMessage)
            ->subject("Your {$planName} subscription has been cancelled")
            ->greeting("Hi {$this->user->name},")
            ->line("We're sorry to see you go! Your {$planName} subscription has been cancelled.")
            ->line("Don't worry - you'll continue to have access to all {$planName} features until {$endDate}.")
            ->line("After that, your account will automatically switch to our free plan.")
            ->line("")
            ->line("**What happens next:**")
            ->line("• You keep {$planName} access until {$endDate}")
            ->line("• Your account switches to the free plan")
            ->line("• You get 8 receipt uploads per month")
            ->line("• All your existing data stays safe")
            ->line("")
            ->action('Reactivate Subscription', config('app.frontend_url') . '/pricing')
            ->line("Changed your mind? You can reactivate anytime before {$endDate}.")
            ->line('We\'d love to hear your feedback - just reply to this email!');
    }
}