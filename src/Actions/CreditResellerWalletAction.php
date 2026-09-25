<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraTransaction\Actions\ApproveTransactionAction;
use Misaf\VendraTransaction\Actions\CreateTransactionAction;
use Misaf\VendraTransaction\Enums\TransactionTypeEnum;
use Misaf\VendraTransaction\Facades\WalletResolver;
use Misaf\VendraTransaction\Models\Transaction;
use Misaf\VendraTransaction\Services\TransactionGatewayRegistry;

final readonly class CreditResellerWalletAction
{
    public function __construct(
        private CreateTransactionAction $createTransactionAction,
        private ApproveTransactionAction $approveTransactionAction,
    ) {}

    /**
     * Record money the reseller paid outside the platform as a settled deposit.
     */
    public function execute(Reseller $reseller, int $amount, string $currencyCode, string $note): Transaction
    {
        $transaction = new RequestJobContext(
            traceId: RequestJobContext::resolveTraceId(),
            operation: 'reseller_wallet_credit',
            metadata: [ContextKeys::RESELLER_ID => $reseller->id],
        )->scope(fn (): Transaction => DB::transaction(function () use ($reseller, $amount, $currencyCode, $note): Transaction {
            $wallet = WalletResolver::firstOrCreateWalletFor($reseller->user, $currencyCode);

            $transaction = $this->createTransactionAction->execute(
                TransactionGatewayRegistry::INTERNAL_GATEWAY_SLUG,
                $wallet,
                TransactionTypeEnum::Deposit,
                $amount,
                ['note' => $note],
            );

            $this->approveTransactionAction->execute($transaction);

            return $transaction->refresh();
        }));

        throw_unless($transaction instanceof Transaction, LogicException::class, 'The scoped wallet credit did not return a transaction.');

        return $transaction;
    }
}
