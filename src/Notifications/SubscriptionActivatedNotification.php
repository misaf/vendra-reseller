<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraSubscription\Models\Plan;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class SubscriptionActivatedNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly Plan $plan)
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
            ->subject('Your subscription is active')
            ->line("Your reseller is now subscribed to the {$this->plan->name} plan.")
            ->line("You can run up to {$this->plan->max_units} property(s).");
    }
}
