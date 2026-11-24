<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AccountSecurityNotification extends Notification
{
    public function __construct(private readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event;
    }

    public function toDatabase(object $notifiable): array
    {
        return ['category' => 'system', 'event' => $this->event];
    }
}
