<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraUser\Actions\CreateUserAction;
use Misaf\VendraUser\Models\User;

final readonly class CreateResellerAction
{
    public function __construct(
        private CreateUserAction $createUserAction,
        private SubscribeAction $subscribeAction,
    ) {}

    /**
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
            $user = $this->createUserAction->execute(
                tenant: null,
                username: $username,
                email: $email,
                password: $password,
                isVerified: $emailVerified,
            );

            $reseller = Reseller::query()->create([
                'user_id' => $user->getKey(),
                'active' => $active,
            ]);

            $subscription = $this->subscribeAction->execute($reseller, $plan, $startsAt);

            return [
                'reseller' => $reseller,
                'user' => $user,
                'subscription' => $subscription,
            ];
        });
    }
}
