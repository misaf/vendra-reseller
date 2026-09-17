<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Misaf\VendraReseller\Filament\Concerns\InteractsWithCurrentReseller;
use Misaf\VendraReseller\Filament\Resources\Stores\StoreResource;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Enums\StorefrontDeploymentStatus;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraSubscription\Models\Subscription;

/**
 * The three steps from a new reseller account to a live storefront, shown until
 * the first storefront is live.
 */
final class GettingStarted extends StatsOverviewWidget
{
    use InteractsWithCurrentReseller;

    public static function canView(): bool
    {
        $reseller = self::currentReseller();

        return $reseller instanceof Reseller && ! self::hasLiveStorefront($reseller);
    }

    protected function getHeading(): string
    {
        return __('vendra-reseller::attributes.getting_started');
    }

    protected function getStats(): array
    {
        $reseller = self::currentReseller();

        if (! $reseller instanceof Reseller) {
            return [];
        }

        $subscribed = $reseller->activeSubscription() instanceof Subscription;
        $hasStore = $reseller->stores()->exists();

        return [
            self::step(1, __('vendra-reseller::attributes.step_subscribe'), $subscribed, __('vendra-reseller::attributes.step_subscribe_hint')),
            self::step(2, __('vendra-reseller::attributes.step_create_store'), $hasStore, __('vendra-reseller::attributes.step_create_store_hint'))
                ->url(! $hasStore && StoreResource::canCreate() ? StoreResource::getUrl('create') : null),
            self::step(3, __('vendra-reseller::attributes.step_storefront_live'), false, __('vendra-reseller::attributes.step_storefront_live_hint'))
                ->url($hasStore ? StoreResource::getUrl('index') : null),
        ];
    }

    private static function step(int $number, string $label, bool $done, string $hint): Stat
    {
        return Stat::make(
            __('vendra-reseller::attributes.step', ['number' => $number, 'label' => $label]),
            $done ? __('vendra-reseller::attributes.step_done') : __('vendra-reseller::attributes.step_to_do'),
        )
            ->description($done ? null : $hint)
            ->icon($done ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedEllipsisHorizontalCircle)
            ->color($done ? 'success' : 'gray');
    }

    private static function hasLiveStorefront(Reseller $reseller): bool
    {
        return once(fn (): bool => Store::query()
            ->where('reseller_id', $reseller->getKey())
            ->whereHas('storefrontDeployment', fn (Builder $query): Builder => $query->where('status', StorefrontDeploymentStatus::Ready))
            ->exists());
    }
}
