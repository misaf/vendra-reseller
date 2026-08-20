<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraSubscription\Models\Subscription;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class SubscriptionExpiringNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription)
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
        $endsAt = $this->subscription->ends_at?->toFormattedDateString() ?? 'soon';

        return (new MailMessage())
            ->subject('Your subscription is expiring soon')
            ->line("Your subscription expires on {$endsAt}.")
            ->line('Renew now to keep your stores online.');
    }
}
