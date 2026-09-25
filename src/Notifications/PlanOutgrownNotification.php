<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraReseller\Filament\Pages\Billing;
use Misaf\VendraSubscription\Models\Subscription;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class PlanOutgrownNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
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
        $plan = $this->subscription->plan->name ?? 'your plan';
        $endsAt = $this->subscription->ends_at?->toFormattedDateString() ?? 'the end of the period';

        return (new MailMessage)
            ->subject('Your stores have outgrown your plan')
            ->line("The {$plan} plan has changed, and your stores now use more than it allows, so it cannot renew.")
            ->line("Change to a plan that fits before {$endsAt} to keep your stores online.")
            ->action('Choose a plan', Billing::getUrl(panel: 'reseller'));
    }
}
