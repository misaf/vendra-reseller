<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Misaf\VendraReseller\Database\Factories\ResellerFactory;
use Misaf\VendraReseller\Observers\ResellerObserver;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Enums\SubscriptionStatus;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSubscription\Support\MoneyFormatter;
use Misaf\VendraSupport\Contracts\ShouldLogActivity;
use Misaf\VendraSupport\Tenancy\Scopes\TeamScope;
use Misaf\VendraSupport\Tenancy\Scopes\TenantScope;
use Misaf\VendraTransaction\Models\Wallet;
use Misaf\VendraUser\Models\User;

/**
 * @property int $id
 * @property int $user_id
 * @property bool $active
 * @property string|null $offboarding_reason
 * @property Carbon|null $offboarded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Collection<int, Wallet> $wallets
 */
#[Fillable(['user_id', 'active'])]
#[ObservedBy([ResellerObserver::class])]
#[UseFactory(ResellerFactory::class)]
final class Reseller extends Model implements ShouldLogActivity, SubscriptionSubscriber
{
    /** @use HasFactory<ResellerFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'active' => 'boolean',
            'offboarding_reason' => 'string',
            'offboarded_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function inactive(Builder $query): Builder
    {
        return $query->where('active', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function withActiveSubscription(Builder $query): Builder
    {
        return $query->whereHas('subscriptions', fn (Builder $query): Builder => $query->active());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function withoutActiveSubscription(Builder $query): Builder
    {
        return $query->whereDoesntHave('subscriptions', fn (Builder $query): Builder => $query->active());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function withSubscriptionEndingWithin(Builder $query, int $days): Builder
    {
        return $query->whereHas('subscriptions', fn (Builder $query): Builder => $query->endingWithin($days));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function withPastDueSubscription(Builder $query): Builder
    {
        return $query->whereHas('subscriptions', fn (Builder $query): Builder => $query->where('status', SubscriptionStatus::PastDue));
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    /**
     * Get the main account's platform wallets, one per currency.
     *
     * Reseller users are tenantless, so their wallets are too; the tenant
     * scopes are dropped for queries that run inside a store's context.
     *
     * @return HasMany<Wallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'user_id', 'user_id')
            ->withoutGlobalScopes([TenantScope::class, TeamScope::class]);
    }

    /**
     * @return list<string>
     */
    public function formattedWalletBalances(): array
    {
        return array_values($this->wallets
            ->map(fn (Wallet $wallet): string => MoneyFormatter::format($wallet->balance, $wallet->currency_code))
            ->all());
    }

    public function walletBalance(string $currencyCode): int
    {
        return $this->wallets()->where('currency_code', $currencyCode)->first()->balance ?? 0;
    }

    /**
     * Get the latest period that was ever live, which renewal continues from.
     */
    public function latestActivatedSubscription(): ?Subscription
    {
        return $this->subscriptions()->activated()->latest('starts_at')->first();
    }

    /**
     * Get the period a renewal would continue from: the last live one, while
     * nothing is running and no renewal is awaiting payment.
     */
    public function renewableSubscription(): ?Subscription
    {
        if ($this->activeSubscription() instanceof Subscription) {
            return null;
        }

        if ($this->subscriptions()->where('status', SubscriptionStatus::PendingPayment)->exists()) {
            return null;
        }

        return $this->latestActivatedSubscription();
    }

    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions()->latest('starts_at')->first();
    }

    public function hasSubscriptions(): bool
    {
        return $this->subscriptions()->exists();
    }

    /**
     * Get the reseller's main account, without tenant scopes.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)
            ->withoutGlobalScopes([TenantScope::class, TeamScope::class]);
    }

    /**
     * Get the main account's username, since a reseller has no name of its own.
     */
    public function displayName(): string
    {
        return $this->user->username;
    }

    /**
     * @param  Builder<self>  $query
     * @return array<int, string>
     */
    public static function displayNames(Builder $query): array
    {
        return $query
            ->with('user')
            ->get()
            ->mapWithKeys(fn (self $reseller): array => [$reseller->id => $reseller->displayName()])
            ->all();
    }

    public static function forUser(User $user): ?self
    {
        return self::query()->where('user_id', $user->getKey())->first();
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->latest('starts_at')
            ->first();
    }

    public function canHoldUnits(): bool
    {
        return $this->active;
    }

    public function notifyContact(Notification $notification): void
    {
        $this->user->notify($notification);
    }

    public function subscriptionPayer(): User
    {
        return $this->user;
    }

    public function subscribedUnitCount(): int
    {
        return $this->stores()->count();
    }

    public function activeSubscribedUnitCount(): int
    {
        return $this->stores()->accessible()->count();
    }

    public function allows(string $feature): bool
    {
        return $this->activeSubscription()?->plan?->allows($feature) ?? false;
    }
}
