<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class SubscriptionExpiringNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * An outgrown scheduled plan warns that the renewal stays on the current plan.
     */
    public function __construct(
        private readonly Subscription $subscription,
        private readonly ?Plan $outgrownScheduledPlan = null,
    ) {
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

        $message = (new MailMessage)
            ->subject('Your subscription is expiring soon')
            ->line("Your subscription expires on {$endsAt}.")
            ->line('Renew now to keep your stores online.');

        if ($this->outgrownScheduledPlan instanceof Plan) {
            $currentPlan = $this->subscription->plan->name ?? 'your current plan';

            $message->line("Your stores now use more than the {$this->outgrownScheduledPlan->name} plan allows, so your scheduled change to it will not apply. The subscription renews on {$currentPlan} unless you bring usage within {$this->outgrownScheduledPlan->name} first.");
        }

        return $message;
    }
}
