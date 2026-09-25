<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\InvoiceIssuedNotification;
use Misaf\VendraSubscription\Events\SubscriptionInvoiceIssued;

final class NotifyInvoiceIssued
{
    public function handle(SubscriptionInvoiceIssued $event): void
    {
        $subscriber = $event->invoice->subscriber;

        if (! $subscriber instanceof Reseller) {
            return;
        }

        $subscriber->notifyContact(new InvoiceIssuedNotification($event->invoice));
    }
}
