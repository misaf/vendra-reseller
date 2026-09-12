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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Misaf\VendraReseller\Database\Factories\ResellerFactory;
use Misaf\VendraReseller\Observers\ResellerObserver;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Contracts\ShouldLogActivity;
use Misaf\VendraUser\Models\User;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $slug
 * @property bool $active
 * @property string|null $email
 * @property string|null $offboarding_reason
 * @property Carbon|null $offboarded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'description', 'slug', 'active', 'email'])]
#[ObservedBy([ResellerObserver::class])]
#[UseFactory(ResellerFactory::class)]
final class Reseller extends Model implements ShouldLogActivity, SubscriptionSubscriber
{
    /** @use HasFactory<ResellerFactory> */
    use HasFactory;

    use HasSlug;
    use Notifiable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'name' => 'string',
            'description' => 'string',
            'slug' => 'string',
            'active' => 'boolean',
            'email' => 'string',
            'offboarding_reason' => 'string',
            'offboarded_at' => 'datetime',
        ];
    }

    /**
     * Route mail notifications to the reseller's contact email.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * Whether the reseller has a contact email to notify.
     */
    public function hasContactEmail(): bool
    {
        return $this->email !== null;
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
     * Canonical users holding an active membership in this reseller.
     *
     * Identity lives on `users`; this pivot only records who may act for
     * the reseller. Disabled and replaced users stay in the table as
     * soft-deleted rows, so this relation resolves the active user only.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reseller_users')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * The reseller's current user, if the single active membership exists.
     *
     * Not a relation — reach for {@see users()} when you need the pivot.
     */
    public function user(): ?User
    {
        return $this->users()->first();
    }

    /**
     * The user of the latest membership, including disabled history.
     *
     * Used by the console to re-enable a disabled user account.
     */
    public function latestUser(): ?User
    {
        $membership = DB::table('reseller_users')
            ->where('reseller_id', $this->getKey())
            ->orderByDesc('id')
            ->first();

        if ($membership === null) {
            return null;
        }

        return User::query()->find($membership->user_id);
    }

    /**
     * Resolve the reseller the given user currently acts for, if any.
     *
     * A user with no active membership — or whose reseller was offboarded —
     * resolves to null and therefore sees nothing in the reseller panel.
     */
    public static function forUser(User $user): ?self
    {
        $membership = DB::table('reseller_users')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            return null;
        }

        return self::query()->find($membership->reseller_id);
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

    public function isSubscriptionActive(): bool
    {
        return $this->active;
    }

    public function notifyContact(Notification $notification): void
    {
        $this->notify($notification);
    }

    public function subscriptionPayer(): ?User
    {
        return $this->user();
    }

    public function subscribedUnitCount(): int
    {
        return $this->stores()->count();
    }

    public function activeSubscribedUnitCount(): int
    {
        return $this->stores()->accessible()->count();
    }

    public function suspendActiveUnits(): int
    {
        return $this->stores()
            ->accessible()
            ->update(['billing_suspended_at' => now()]);
    }

    public function reactivateSuspendedUnits(): int
    {
        return $this->stores()
            ->whereNotNull('billing_suspended_at')
            ->update(['billing_suspended_at' => null]);
    }

    /**
     * Whether the reseller's active plan grants the given feature entitlement.
     */
    public function allows(string $feature): bool
    {
        return $this->activeSubscription()?->plan?->allows($feature) ?? false;
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->preventOverwrite();
    }
}
