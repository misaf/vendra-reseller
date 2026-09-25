<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Listeners;

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\RenewSubscriptionAction;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraTransaction\Enums\TransactionTypeEnum;
use Misaf\VendraTransaction\Events\TransactionApproved;
use Misaf\VendraTransaction\Models\Wallet;

/**
 * Retry an auto-renewing plan that lapsed for lack of funds once money arrives.
 *
 * The hourly enforcement only renews a period as it lapses; after a failed
 * renewal the period is expired and nothing would try again. Synchronous, as
 * the event already dispatches after commit.
 */
final readonly class RenewAfterWalletDeposit
{
    public function __construct(private RenewSubscriptionAction $renewSubscriptionAction) {}

    public function handle(TransactionApproved $event): void
    {
        if ($event->transaction->transaction_type !== TransactionTypeEnum::Deposit) {
            return;
        }

        $wallet = Wallet::query()->withoutGlobalScopes()->find($event->transaction->wallet_id);
        $reseller = $wallet === null ? null : Reseller::query()->where('user_id', $wallet->user_id)->first();

        if ($reseller === null || ! $reseller->canHoldUnits() || $reseller->activeSubscription() !== null) {
            return;
        }

        if ($reseller->subscriptions()->where('status', SubscriptionStatus::PendingPayment)->exists()) {
            return;
        }

        $latest = $reseller->latestActivatedSubscription();

        if (! $latest instanceof Subscription
            || ! $latest->auto_renews
            || ! in_array($latest->status, [SubscriptionStatus::Expired, SubscriptionStatus::PastDue], true)) {
            return;
        }

        try {
            $this->renewSubscriptionAction->execute($latest);
        } catch (SubscriptionLimitException|SubscriptionPaymentException) {
            return;
        }
    }
}
