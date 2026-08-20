<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use LogicException;
use Misaf\VendraReseller\Database\Factories\ResellerFactory;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Models\Subscription;
use Misaf\VendraSupport\Contracts\ShouldLogActivity;
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
#[UseFactory(ResellerFactory::class)]
final class Reseller extends Model implements ShouldLogActivity, SubscriptionSubscriber
{
    /** @use HasFactory<ResellerFactory> */
    use HasFactory;

    use HasSlug;
    use Notifiable;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (Reseller $reseller): void {
            if (null === $reseller->offboarded_at) {
                throw new LogicException("Reseller [{$reseller->id}] must be offboarded through OffboardResellerAction before deletion.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id'                 => 'integer',
            'name'               => 'string',
            'description'        => 'string',
            'slug'               => 'string',
            'active'             => 'boolean',
            'email'              => 'string',
            'offboarding_reason' => 'string',
            'offboarded_at'      => 'datetime',
        ];
    }

    /**
     * Route mail notifications to the reseller owner's email.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * Whether the reseller has an owner contact to notify.
     */
    public function hasOwnerContact(): bool
    {
        return null !== $this->email;
    }

    /**
     * @param  Builder<Reseller>  $query
     * @return Builder<Reseller>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Reseller>  $query
     * @return Builder<Reseller>
     */
    public function scopeInactive(Builder $query): Builder
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
     * @return HasOne<ResellerUser, $this>
     */
    public function ownerUser(): HasOne
    {
        return $this->hasOne(ResellerUser::class);
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

    public function notifyOwner(Notification $notification): void
    {
        $this->notify($notification);
    }

    public function subscriptionPayer(): ?ResellerUser
    {
        return $this->ownerUser()->first();
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
