<?php

declare(strict_types=1);

use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;

it('starts a trial on the reseller\'s first subscription only', function (): void {
    $reseller = Reseller::factory()->create();

    $first = app(SubscribeAction::class)->execute($reseller, Plan::factory()->trialDays(14)->create());
    $second = app(SubscribeAction::class)->execute($reseller, Plan::factory()->trialDays(14)->create());

    expect($first->trial_ends_at)->not->toBeNull()
        ->and($first->isOnTrial())->toBeTrue()
        ->and($second->trial_ends_at)->toBeNull();
});

it('resolves plan and reseller feature entitlements', function (): void {
    $reseller = Reseller::factory()->create();
    $plan = Plan::factory()->withFeatures(['custom_domain'])->create();
    Subscription::factory()->forSubscriber($reseller)->for($plan)->create();

    expect($plan->allows('custom_domain'))->toBeTrue()
        ->and($plan->allows('priority_support'))->toBeFalse()
        ->and($reseller->allows('custom_domain'))->toBeTrue()
        ->and($reseller->allows('priority_support'))->toBeFalse();
});
