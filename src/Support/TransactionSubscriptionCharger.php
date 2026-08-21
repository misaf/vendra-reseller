<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use LogicException;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraSupport\Contracts\SubscriptionCharger;
use Misaf\VendraSupport\Data\SubscriptionCharge;
use Misaf\VendraSupport\Data\SubscriptionChargeResult;
use Misaf\VendraSupport\Enums\SubscriptionChargeStatus;
use Misaf\VendraTransaction\Actions\CreateTransactionAction;
use Misaf\VendraTransaction\Enums\TransactionTypeEnum;
use Misaf\VendraTransaction\Exceptions\InsufficientBalanceException;
use Misaf\VendraTransaction\Facades\TransactionGatewayRegistry;
use Misaf\VendraTransaction\Facades\WalletResolver;
use Misaf\VendraTransaction\Models\Transaction;
use Misaf\VendraTransaction\Services\TransactionGatewayRegistry as TransactionGatewayRegistryClass;
use Misaf\VendraTransaction\States\Approved;
use Misaf\VendraTransaction\States\Declined;
use Misaf\VendraTransaction\States\Failed;

/**
 * Collects subscription payments through vendra-transaction by posting an
 * internal withdrawal against the payer's wallet.
 */
final class TransactionSubscriptionCharger implements SubscriptionCharger
{
    public function __construct(private readonly CreateTransactionAction $createTransactionAction) {}

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
        $result = (new RequestJobContext(
            traceId: RequestJobContext::resolveTraceId(),
            operation: 'subscription_charge',
            metadata: [
                ContextKeys::RESELLER_ID => $charge->payer instanceof ResellerUser
                    ? $charge->payer->reseller_id
                    : null,
            ],
        ))->scope(fn(): SubscriptionChargeResult => $this->chargeWithinContext($charge));

        if ( ! $result instanceof SubscriptionChargeResult) {
            throw new LogicException('The scoped subscription charge did not return a result.');
        }

        return $result;
    }

    private function chargeWithinContext(SubscriptionCharge $charge): SubscriptionChargeResult
    {
        $wallet = WalletResolver::walletFor($charge->payer, $charge->currencyCode);

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
                $transaction->approve();
            } catch (InsufficientBalanceException $exception) {
                if ($transaction->status->canTransitionTo(Declined::class)) {
                    $transaction->decline();
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
     * Re-resolve a previously initiated charge by its idempotency reference.
     *
     * Delegating to {@see charge()} is safe and non-duplicating: the internal
     * gateway keys transactions by {@see SubscriptionCharge::$reference}, so
     * {@see CreateTransactionAction::execute()} returns the *existing* transaction rather than
     * posting a new withdrawal, and the `canTransitionTo(Approved::class)` guard
     * prevents re-approving an already-settled transaction. The result therefore
     * reflects the current stored status of the original operation.
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
            $transaction->status instanceof Failed  => SubscriptionChargeStatus::Failed,
            default                                 => SubscriptionChargeStatus::Processing,
        };

        return new SubscriptionChargeResult(
            $status,
            providerReference: (string) $transaction->id,
        );
    }
}
