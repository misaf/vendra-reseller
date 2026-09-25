<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Misaf\VendraReseller\Filament\Pages\Invoices;
use Misaf\VendraSubscription\Models\SubscriptionInvoice;
use Spatie\Multitenancy\Jobs\NotTenantAware;

final class InvoiceIssuedNotification extends Notification implements NotTenantAware, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly SubscriptionInvoice $invoice)
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
        return (new MailMessage)
            ->subject("Invoice {$this->invoice->number}")
            ->line("Invoice {$this->invoice->number} for {$this->invoice->formattedTotal()} has been issued and paid from your wallet.")
            ->action('View invoices', Invoices::getUrl(panel: 'reseller'));
    }
}
