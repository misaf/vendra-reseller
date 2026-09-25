<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Misaf\VendraReseller\Actions\CreditResellerWalletAction;
use Misaf\VendraReseller\Filament\Pages\Billing;
use Misaf\VendraReseller\Filament\Pages\Invoices;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Notifications\InvoiceIssuedNotification;
use Misaf\VendraSubscription\Actions\IssueSubscriptionInvoiceAction;
use Misaf\VendraSubscription\Actions\SubscribeAction;
use Misaf\VendraSubscription\Contracts\BillingProfile;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Models\SubscriptionInvoice;
use Misaf\VendraTransaction\Database\Factories\TransactionGatewayFactory;
use Misaf\VendraUser\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Notification::fake();
    TransactionGatewayFactory::new()->active()->internal()->create();
    app()->instance(BillingProfile::class, new class implements BillingProfile
    {
        public function taxRate(): int
        {
            return 1_900;
        }

        public function taxLabel(): string
        {
            return 'VAT';
        }

        public function seller(): array
        {
            return ['name' => 'Vendra GmbH', 'address' => null, 'tax_id' => 'DE123'];
        }
    });
    $this->travelTo(Date::parse('2026-04-11 00:00:00'));
});

function invoicedReseller(array $attributes = []): Reseller
{
    $reseller = Reseller::factory()->active()->create($attributes);
    $reseller->user()->associate(User::factory()->create(['tenant_id' => null]))->save();

    return $reseller;
}

function actAsInvoicedReseller(Reseller $reseller): void
{
    actingAs($reseller->user, 'reseller');
    Filament::setCurrentPanel(Filament::getPanel('reseller'));
}

it('charges the plan price with tax and issues a numbered invoice for it', function (): void {
    $reseller = invoicedReseller(['billing_name' => 'Acme Ltd', 'tax_id' => 'GB999']);
    resolve(CreditResellerWalletAction::class)->execute($reseller, 5_000, 'USD', 'Bank transfer');

    resolve(SubscribeAction::class)->execute($reseller, Plan::factory()->active()->priced(3_000)->create(['name' => 'Starter']));

    $invoice = $reseller->invoices()->sole();

    expect($reseller->activeSubscription()?->status)->toBe(SubscriptionStatus::Active)
        ->and($reseller->walletBalance('USD'))->toBe(1_430)
        ->and($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->net_amount)->toBe(3_000)
        ->and($invoice->tax_amount)->toBe(570)
        ->and($invoice->total_amount)->toBe(3_570)
        ->and($invoice->seller)->toMatchArray(['name' => 'Vendra GmbH', 'tax_id' => 'DE123'])
        ->and($invoice->buyer)->toMatchArray(['name' => 'Acme Ltd', 'email' => $reseller->user->email, 'tax_id' => 'GB999'])
        ->and(Arr::get($invoice->lines, 0))->toMatchArray(['description' => 'Starter', 'prorated' => false, 'amount' => 3_000]);

    Notification::assertSentTo($reseller->user, InvoiceIssuedNotification::class);
});

it('numbers invoices without gaps and restarts each year', function (): void {
    $reseller = invoicedReseller();
    resolve(CreditResellerWalletAction::class)->execute($reseller, 20_000, 'USD', 'Bank transfer');
    $plan = Plan::factory()->active()->priced(1_000)->create();

    resolve(SubscribeAction::class)->execute($reseller, $plan);
    resolve(SubscribeAction::class)->execute($reseller, $plan);
    $this->travelTo(Date::parse('2027-01-02 00:00:00'));
    resolve(SubscribeAction::class)->execute($reseller, $plan);

    expect($reseller->invoices()->orderBy('id')->pluck('number')->all())->toBe(['INV-2026-000001', 'INV-2026-000002', 'INV-2027-000001']);
});

it('issues one invoice however often the payment is reported paid', function (): void {
    $reseller = invoicedReseller();
    resolve(CreditResellerWalletAction::class)->execute($reseller, 5_000, 'USD', 'Bank transfer');
    resolve(SubscribeAction::class)->execute($reseller, Plan::factory()->active()->priced(1_000)->create());
    $invoice = $reseller->invoices()->sole();

    expect(resolve(IssueSubscriptionInvoiceAction::class)->execute($invoice->payment)->is($invoice))->toBeTrue()
        ->and($reseller->invoices()->count())->toBe(1);
});

it('issues no invoice for a free plan', function (): void {
    $reseller = invoicedReseller();

    resolve(SubscribeAction::class)->execute($reseller, Plan::factory()->active()->create());

    expect(SubscriptionInvoice::query()->count())->toBe(0);
});

it('lists and downloads only the signed-in reseller its invoices', function (): void {
    $reseller = invoicedReseller();
    $mine = SubscriptionInvoice::factory()->forSubscriber($reseller)->create();
    $theirs = SubscriptionInvoice::factory()->forSubscriber(invoicedReseller())->create();
    actAsInvoicedReseller($reseller);

    $this->get(Invoices::getUrl())->assertOk();

    livewire(Invoices::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->callAction(TestAction::make('download')->table($mine))
        ->assertFileDownloaded($mine->downloadName());
});

it('saves the billing details new invoices name', function (): void {
    $reseller = invoicedReseller();
    actAsInvoicedReseller($reseller);

    livewire(Billing::class)
        ->callAction('editBillingDetails', ['billing_name' => ' Acme Ltd ', 'billing_address' => '', 'tax_id' => 'GB999'])
        ->assertHasNoFormErrors();

    expect($reseller->refresh()->billingDetails())->toMatchArray(['name' => 'Acme Ltd', 'address' => null, 'tax_id' => 'GB999']);
});

it('refuses a renewal the wallet covers only before tax', function (): void {
    $reseller = invoicedReseller();
    Subscription::factory()->forSubscriber($reseller)->for(Plan::factory()->active()->priced(3_000)->graceDays(5))->expired()->create(['price' => 3_000, 'currency_code' => 'USD', 'auto_renews' => false]);
    resolve(CreditResellerWalletAction::class)->execute($reseller, 3_000, 'USD', 'Bank transfer');
    actAsInvoicedReseller($reseller);

    livewire(Billing::class)
        ->callAction('renew')
        ->assertNotified(__('vendra-reseller::attributes.insufficient_wallet_balance'));

    expect($reseller->subscriptions()->count())->toBe(1);
});
