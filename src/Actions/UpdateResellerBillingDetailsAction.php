<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Misaf\VendraReseller\Models\Reseller;

final class UpdateResellerBillingDetailsAction
{
    /**
     * Only invoices issued afterwards carry the new details; issued ones keep their snapshot.
     */
    public function execute(Reseller $reseller, ?string $billingName, ?string $billingAddress, ?string $taxId): Reseller
    {
        $reseller->forceFill([
            'billing_name' => $billingName,
            'billing_address' => $billingAddress,
            'tax_id' => $taxId,
        ])->save();

        return $reseller;
    }
}
