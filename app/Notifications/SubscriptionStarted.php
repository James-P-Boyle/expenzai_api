<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionStarted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public Subscription $subscription,
        public bool $isTrialing = false
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name;
        $subject = $this->isTrialing 
            ? "🎉 Welcome to your {$planName} free trial!" 
            : "🎉 Welcome to {$planName}!";

        $greeting = $this->isTrialing
            ? "Your {$planName} free trial has started!"
            : "Your {$planName} subscription is now active!";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hi {$this->user->name}!")
            ->line($greeting)
            ->line("Here's what you now have access to:")
            ->line('• ' . implode("\n• ", $this->subscription->plan->features));

        if ($this->isTrialing) {
            $trialEndsAt = $this->subscription->trial_end->format('M j, Y');
            $message->line("Your free trial ends on {$trialEndsAt}. You can cancel anytime before then without being charged.");
        }

        $message->action('Go to Dashboard', config('app.frontend_url') . '/dashboard')
                ->line('Thank you for choosing ExpenzAI!')
                ->line('If you have any questions, just reply to this email.');

        return $message;
    }
}