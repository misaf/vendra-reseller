<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Billing\Actions\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Support\MoneyFormatter;

trait InteractsWithResellerBilling
{
    use InteractsWithCurrentReseller;

    protected static function reseller(): Reseller
    {
        $reseller = self::currentReseller();

        throw_unless($reseller instanceof Reseller, Halt::class);

        return $reseller;
    }

    /**
     * Stop before charging when the wallet cannot cover the amount, rather
     * than letting the payment fail and cancel the new period.
     */
    protected static function ensureWalletCovers(Reseller $reseller, int $amount, ?string $currencyCode): void
    {
        if ($amount === 0 || $currencyCode === null) {
            return;
        }

        $balance = $reseller->walletBalance($currencyCode);

        if ($balance >= $amount) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('vendra-reseller::attributes.insufficient_wallet_balance'))
            ->body(__('vendra-reseller::attributes.insufficient_wallet_balance_body', [
                'amount' => MoneyFormatter::format($amount, $currencyCode),
                'balance' => MoneyFormatter::format($balance, $currencyCode),
            ]))
            ->send();

        throw new Halt;
    }
}
