<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class StoreLimitApproachedNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly string $storeName,
        private readonly string $limitLabel,
        private readonly int $percent,
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
        if ($this->percent >= 100) {
            return (new MailMessage)
                ->subject("{$this->storeName} reached its plan limit")
                ->line("{$this->storeName} has used all of its {$this->limitLabel}.")
                ->line('Upgrade your plan to keep adding more.');
        }

        return (new MailMessage)
            ->subject("{$this->storeName} is nearing its plan limit")
            ->line("{$this->storeName} has used {$this->percent}% of its {$this->limitLabel}.")
            ->line('Upgrade your plan before it runs out.');
    }
}
