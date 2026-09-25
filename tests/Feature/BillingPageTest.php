<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Misaf\VendraReseller\Actions\CreditResellerWalletAction;
use Misaf\VendraReseller\Filament\Pages\Billing;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Enums\PlanFeature;
use Misaf\VendraSupport\Enums\PlanLimit;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;
use Misaf\VendraUser\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Queue::fake();
    TransactionGatewayFactory::new()->active()->internal()->create();
    $this->travelTo(Date::parse('2026-04-11 00:00:00'));
});

function billingReseller(): Reseller
{
    $reseller = Reseller::factory()->active()->create();
    $reseller->user()->associate(User::factory()->create(['tenant_id' => null]))->save();

    return $reseller;
}

function actAsBillingReseller(Reseller $reseller): void
{
    actingAs($reseller->user, 'reseller');
    Filament::setCurrentPanel(Filament::getPanel('reseller'));
}

function billingSubscription(Reseller $reseller, Plan $plan, array $attributes = []): Subscription
{
    return Subscription::factory()->forSubscriber($reseller)->for($plan)->create([
        'price' => $plan->price,
        'currency_code' => $plan->currency_code,
        'starts_at' => Date::parse('2026-04-01 00:00:00'),
        'ends_at' => Date::parse('2026-05-01 00:00:00'),
        ...$attributes,
    ]);
}

it('shows only the signed-in reseller its plan and wallet', function (): void {
    $reseller = billingReseller();
    billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->create(['name' => 'Starter']));
    resolve(CreditResellerWalletAction::class)->execute($reseller, 4_200, 'USD', 'Bank transfer');
    billingSubscription(billingReseller(), Plan::factory()->active()->create(['name' => 'Someone else']));
    actAsBillingReseller($reseller);

    $this->get(Billing::getUrl())->assertOk();

    livewire(Billing::class)
        ->assertSee('Starter')
        ->assertSee('$42.00')
        ->assertDontSee('Someone else');
});

it('upgrades from the wallet and refuses when the wallet is short', function (): void {
    $reseller = billingReseller();
    billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->maxUnits(1)->create());
    $upgrade = Plan::factory()->active()->priced(6_000)->maxUnits(5)->create();
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->callAction('changePlan', ['plan_id' => $upgrade->id])
        ->assertNotified(__('vendra-reseller::attributes.insufficient_wallet_balance'));

    expect($reseller->subscriptions()->count())->toBe(1);

    resolve(CreditResellerWalletAction::class)->execute($reseller, 2_000, 'USD', 'Bank transfer');

    livewire(Billing::class)
        ->callAction('changePlan', ['plan_id' => $upgrade->id])
        ->assertHasNoFormErrors();

    expect($reseller->subscriptions()->where('status', SubscriptionStatus::PendingPayment)->sole()->plan_id)->toBe($upgrade->id);
});

it('lists what the chosen plan includes', function (): void {
    $reseller = billingReseller();
    billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->create());
    $pro = Plan::factory()->active()->priced(6_000)->maxUnits(5)->withLimits([PlanLimit::ProductsPerStore->value => 1000])->create(['features' => [PlanFeature::CustomDomain->value]]);
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->mountAction('changePlan')
        ->fillForm(['plan_id' => $pro->id])
        ->assertFormFieldExists('plan_id', fn (Select $field): bool => str_contains((string) $field->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString(), __('vendra-reseller::attributes.plan_includes', ['items' => implode(' · ', [
            trans_choice('vendra-reseller::attributes.plan_includes_stores', 5, ['count' => 5]),
            __('vendra-reseller::attributes.plan_limit_unlimited', ['limit' => PlanLimit::DomainsPerStore->getLabel()]),
            __('vendra-reseller::attributes.plan_limit_value', ['limit' => PlanLimit::ProductsPerStore->getLabel(), 'value' => 1000]),
            __('vendra-reseller::attributes.plan_limit_unlimited', ['limit' => PlanLimit::StorageMegabytesPerStore->getLabel()]),
            __('vendra-reseller::attributes.plan_limit_unlimited', ['limit' => PlanLimit::StaffPerStore->getLabel()]),
            PlanFeature::CustomDomain->getLabel(),
        ])])));
});

it('asks the reseller to change plan when the stores have outgrown the current one', function (): void {
    $reseller = billingReseller();
    billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->maxUnits(1)->create(['name' => 'Starter']), [
        'status' => SubscriptionStatus::Expired,
        'ends_at' => Date::parse('2026-04-10 00:00:00'),
    ]);
    Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);
    resolve(CreditResellerWalletAction::class)->execute($reseller, 10_000, 'USD', 'Bank transfer');
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->assertSee(__('vendra-reseller::attributes.plan_outgrown_renewal', ['plan' => 'Starter']))
        ->assertActionVisible('renew')
        ->assertActionDisabled('renew');
});

it('refuses a plan the reseller stores have outgrown before it is chosen', function (): void {
    $reseller = billingReseller();
    $current = billingSubscription($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());
    $tooSmall = Plan::factory()->active()->priced(3_000)->maxUnits(1)->create();
    Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->callAction('changePlan', ['plan_id' => $tooSmall->id])
        ->assertHasFormErrors(['plan_id']);

    expect($current->refresh()->scheduled_plan_id)->toBeNull();
});

it('schedules a downgrade and cancels it again', function (): void {
    $reseller = billingReseller();
    $current = billingSubscription($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create());
    $cheaper = Plan::factory()->active()->priced(3_000)->maxUnits(1)->create();
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->callAction('changePlan', ['plan_id' => $cheaper->id])
        ->assertActionVisible('cancelScheduledChange');

    expect($current->refresh()->scheduled_plan_id)->toBe($cheaper->id);

    livewire(Billing::class)->callAction('cancelScheduledChange');

    expect($current->refresh()->scheduled_plan_id)->toBeNull();
});

it('warns when the stores have outgrown the scheduled downgrade', function (): void {
    $reseller = billingReseller();
    billingSubscription($reseller, Plan::factory()->active()->priced(6_000)->maxUnits(5)->create(['name' => 'Growth']), [
        'scheduled_plan_id' => Plan::factory()->active()->priced(3_000)->maxUnits(1)->create(['name' => 'Starter'])->id,
    ]);
    Store::factory()->count(2)->create(['reseller_id' => $reseller->getKey()]);
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->assertSee(__('vendra-reseller::attributes.scheduled_plan_outgrown', ['plan' => 'Starter', 'current' => 'Growth']));
});

it('turns auto-renew off and on', function (): void {
    $reseller = billingReseller();
    $current = billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->create());
    actAsBillingReseller($reseller);

    livewire(Billing::class)->callAction('toggleAutoRenew');

    expect($current->refresh()->auto_renews)->toBeFalse();

    livewire(Billing::class)->callAction('toggleAutoRenew');

    expect($current->refresh()->auto_renews)->toBeTrue();
});

it('offers renewal only when nothing is running', function (): void {
    $reseller = billingReseller();
    $expired = billingSubscription($reseller, Plan::factory()->active()->priced(3_000)->graceDays(5)->create(), [
        'status' => SubscriptionStatus::Expired,
        'ends_at' => Date::parse('2026-04-09 00:00:00'),
        'auto_renews' => false,
    ]);
    resolve(CreditResellerWalletAction::class)->execute($reseller, 3_000, 'USD', 'Bank transfer');
    actAsBillingReseller($reseller);

    livewire(Billing::class)
        ->assertActionHidden('toggleAutoRenew')
        ->callAction('renew');

    $renewal = $reseller->subscriptions()->whereKeyNot($expired->id)->sole();

    expect($renewal->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($renewal->starts_at->equalTo($expired->ends_at))->toBeTrue();

    livewire(Billing::class)->assertActionHidden('renew');
});
