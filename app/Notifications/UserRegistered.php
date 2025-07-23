<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

class UserRegistered extends Notification
{
    use Queueable;

    public $user;
    public $transferredReceipts = 0;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->from('contact@expenzai.app', 'ExpenzAI')
            ->subject('🎉 New ExpenzAI User Signup - ' . $this->user->name)
            ->line('🎉 New ExpenzAI User Signup!')
            ->line('')
            ->line('👤 Name: ' . $this->user->name)
            ->line('📧 Email: ' . $this->user->email)
            ->line('🆔 User ID: ' . $this->user->id)
            ->line('🕐 Signup Time: ' . now()->format('Y-m-d H:i:s T'))
            ->line('🌍 Timezone: ' . now()->timezoneName)
            ->line('📊 Total Users: ' . User::count())
            ->line('')
            ->line('⚠️ Email verification pending')
            ->action('View User (Future Admin)', 'https://api.expenzai.app/admin/users/' . $this->user->id);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'user_email' => $this->user->email,
            'signup_time' => now(),
        ];
    }
}