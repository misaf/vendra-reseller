<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

final class CreateResellerAction
{
    public function __construct(
        private readonly CreateResellerOwnerAction $createResellerOwnerAction,
        private readonly SubscribeAction $subscribeAction,
    ) {}

    /**
     * Create a billing reseller and subscribe it to the given plan.
     *
     * @return array{reseller: Reseller, owner: ResellerUser, subscription: Subscription}
     */
    public function execute(
        Plan $plan,
        string $username,
        string $email,
        string $password,
        ?Carbon $startsAt = null,
        bool $active = true,
        bool $emailVerified = true,
    ): array {
        return DB::transaction(function () use ($plan, $username, $email, $password, $startsAt, $active, $emailVerified): array {
            $reseller = Reseller::query()->create([
                'name'   => $username,
                'slug'   => $username,
                'active' => $active,
                'email'  => $email,
            ]);

            $owner = $this->createResellerOwnerAction->execute(
                $reseller,
                $username,
                $email,
                $password,
                $emailVerified,
            );

            $subscription = $this->subscribeAction->execute($reseller, $plan, $startsAt);

            return [
                'reseller'     => $reseller,
                'owner'        => $owner,
                'subscription' => $subscription,
            ];
        });
    }
}
