<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraSupport\Contracts\SubscriptionCharger;
use Misaf\VendraSupport\Data\SubscriptionCharge;
use Misaf\VendraSupport\Data\SubscriptionChargeResult;
use Misaf\VendraSupport\Enums\SubscriptionChargeStatus;
use Misaf\VendraTransaction\Actions\ApproveTransactionAction;
use Misaf\VendraTransaction\Actions\CreateTransactionAction;
use Misaf\VendraTransaction\Actions\DeclineTransactionAction;
use Misaf\VendraTransaction\Enums\TransactionTypeEnum;
use Misaf\VendraTransaction\Exceptions\InsufficientBalanceException;
use Misaf\VendraTransaction\Facades\TransactionGatewayRegistry;
use Misaf\VendraTransaction\Facades\WalletResolver;
use Misaf\VendraTransaction\Models\Transaction;
use Misaf\VendraTransaction\Services\TransactionGatewayRegistry as TransactionGatewayRegistryClass;
use Misaf\VendraTransaction\States\Approved;
use Misaf\VendraTransaction\States\Declined;
use Misaf\VendraTransaction\States\Failed;
use Misaf\VendraUser\Models\User;

final readonly class TransactionSubscriptionCharger implements SubscriptionCharger
{
    public function __construct(
        private CreateTransactionAction $createTransactionAction,
        private ApproveTransactionAction $approveTransactionAction,
        private DeclineTransactionAction $declineTransactionAction,
    ) {}

    public function provider(): string
    {
        return TransactionGatewayRegistryClass::INTERNAL_GATEWAY_SLUG;
    }

    public function available(): bool
    {
        return TransactionGatewayRegistry::hasActive(TransactionGatewayRegistryClass::INTERNAL_GATEWAY_SLUG);
    }

    public function charge(SubscriptionCharge $charge): SubscriptionChargeResult
    {
        $result = new RequestJobContext(
            traceId: RequestJobContext::resolveTraceId(),
            operation: 'subscription_charge',
            metadata: [
                ContextKeys::RESELLER_ID => $charge->payer instanceof User
                    ? Reseller::forUser($charge->payer)?->getKey()
                    : null,
            ],
        )->scope(fn (): SubscriptionChargeResult => $this->chargeWithinContext($charge));

        throw_unless($result instanceof SubscriptionChargeResult, LogicException::class, 'The scoped subscription charge did not return a result.');

        return $result;
    }

    private function chargeWithinContext(SubscriptionCharge $charge): SubscriptionChargeResult
    {
        $wallet = WalletResolver::firstOrCreateWalletFor($charge->payer, $charge->currencyCode);

        $transaction = $this->createTransactionAction->execute(
            TransactionGatewayRegistryClass::INTERNAL_GATEWAY_SLUG,
            $wallet,
            TransactionTypeEnum::Withdrawal,
            $charge->amount,
            ['reference' => $charge->reference],
            idempotencyKey: $charge->reference,
        );

        if ($transaction->status->canTransitionTo(Approved::class)) {
            try {
                $this->approveTransactionAction->execute($transaction);
            } catch (InsufficientBalanceException $exception) {
                if ($transaction->status->canTransitionTo(Declined::class)) {
                    $this->declineTransactionAction->execute($transaction);
                }

                return new SubscriptionChargeResult(
                    SubscriptionChargeStatus::Failed,
                    providerReference: (string) $transaction->id,
                    errorCode: 'insufficient_balance',
                    errorMessage: $exception->getMessage(),
                );
            }
        }

        return $this->resultFor($transaction);
    }

    /**
     * Retrieve a previous charge by its idempotency reference.
     *
     * Delegating to {@see charge()} is safe, since transactions are keyed by the
     * reference and a settled one is never approved twice.
     */
    public function retrieve(SubscriptionCharge $charge): SubscriptionChargeResult
    {
        return $this->charge($charge);
    }

    private function resultFor(Transaction $transaction): SubscriptionChargeResult
    {
        $status = match (true) {
            $transaction->status instanceof Approved => SubscriptionChargeStatus::Paid,
            $transaction->status instanceof Declined,
            $transaction->status instanceof Failed => SubscriptionChargeStatus::Failed,
            default => SubscriptionChargeStatus::Processing,
        };

        return new SubscriptionChargeResult(
            $status,
            providerReference: (string) $transaction->id,
        );
    }
}
