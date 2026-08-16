<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class PropertiesSuspendedNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly int $suspendedCount)
    {
        $this->onQueue('transactional-email');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your properties have been suspended')
            ->line("{$this->suspendedCount} property(s) were suspended because your subscription lapsed.")
            ->line('Renew your subscription to bring them back online.');
    }
}
