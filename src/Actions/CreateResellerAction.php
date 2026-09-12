<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraUser\Models\User;

final readonly class CreateResellerAction
{
    public function __construct(
        private CreateResellerUserAction $createResellerUserAction,
        private SubscribeAction $subscribeAction,
    ) {}

    /**
     * Create a billing reseller and subscribe it to the given plan.
     *
     * @return array{reseller: Reseller, user: User, subscription: Subscription}
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
                'name' => $username,
                'slug' => $username,
                'active' => $active,
                'email' => $email,
            ]);

            $user = $this->createResellerUserAction->execute(
                $reseller,
                $username,
                $email,
                $password,
                $emailVerified,
            );

            $subscription = $this->subscribeAction->execute($reseller, $plan, $startsAt);

            return [
                'reseller' => $reseller,
                'user' => $user,
                'subscription' => $subscription,
            ];
        });
    }
}
