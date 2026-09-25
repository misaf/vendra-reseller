<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraSubscription\Models\Plan;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class ScheduledPlanChangeDroppedNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly Plan $droppedPlan, private readonly ?Plan $currentPlan)
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
        $currentPlan = $this->currentPlan->name ?? 'your current plan';

        return (new MailMessage)
            ->subject('Your scheduled plan change was cancelled')
            ->line("Your stores now use more than the {$this->droppedPlan->name} plan allows, so the change to it was cancelled.")
            ->line("Your subscription renews on {$currentPlan} instead.");
    }
}
