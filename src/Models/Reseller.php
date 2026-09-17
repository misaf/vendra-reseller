<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
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
use Misaf\VendraStore\Actions\AlignStorefrontWithStoreAction;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Contracts\ShouldLogActivity;
use Misaf\VendraSupport\Tenancy\Scopes\TeamScope;
use Misaf\VendraSupport\Tenancy\Scopes\TenantScope;
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
     * The stores owned by this reseller.
     *
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

    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions()->latest('starts_at')->first();
    }

    public function hasSubscriptions(): bool
    {
        return $this->subscriptions()->exists();
    }

    /**
     * The reseller's main account: a canonical platform user (`tenant_id` null).
     *
     * The tenant scopes are lifted because the account belongs to no tenant, so
     * resolving it inside a tenant context (a notification raised while a store
     * is current) must not hide it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)
            ->withoutGlobalScopes([TenantScope::class, TeamScope::class]);
    }

    /**
     * A reseller has no name of its own: it is shown by its main account's username.
     */
    public function displayName(): string
    {
        return $this->user->username;
    }

    /**
     * Display names keyed by reseller id for every reseller the query matches.
     *
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

    /**
     * The reseller the given user is the main account of, if any.
     *
     * An offboarded reseller is soft-deleted, so its former account resolves
     * to null and sees nothing in the reseller panel.
     */
    public static function forUser(User $user): ?self
    {
        return self::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * The reseller's currently active subscription, if any.
     */
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

    /**
     * The reseller has no contact details of its own: its main account is always the contact.
     */
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

    /**
     * Store by store rather than one bulk update, so each storefront is taken
     * down along with its store instead of serving on while billing is lapsed.
     */
    public function suspendActiveUnits(): int
    {
        $stores = $this->stores()->accessible()->get();

        $stores->each(function (Store $store): void {
            $store->forceFill(['billing_suspended_at' => now()])->save();

            resolve(AlignStorefrontWithStoreAction::class)->execute($store);
        });

        return $stores->count();
    }

    public function reactivateSuspendedUnits(): int
    {
        $stores = $this->stores()->whereNotNull('billing_suspended_at')->get();

        $stores->each(function (Store $store): void {
            $store->forceFill(['billing_suspended_at' => null])->save();

            resolve(AlignStorefrontWithStoreAction::class)->execute($store);
        });

        return $stores->count();
    }

    /**
     * Whether the reseller's active plan grants the given feature entitlement.
     */
    public function allows(string $feature): bool
    {
        return $this->activeSubscription()?->plan?->allows($feature) ?? false;
    }
}
