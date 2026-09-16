<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Stores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Misaf\VendraStore\Models\Store;
use Misaf\VendraStore\Models\StorefrontDeployment;
use Misaf\VendraSupport\Filament\Infolists\Components\DescriptionEntry;
use Misaf\VendraSupport\Filament\Infolists\Components\IsActiveEntry;
use Misaf\VendraSupport\Filament\Infolists\Components\NameEntry;
use Misaf\VendraSupport\Filament\Infolists\Components\SlugEntry;

final class StoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('vendra-reseller::attributes.store_identity'))
                ->schema([
                    Grid::make(3)->schema([
                        NameEntry::make(),
                        SlugEntry::make()->label(__('vendra-reseller::attributes.slug'))->copyable(),
                        TextEntry::make('active_domain')->label(__('vendra-reseller::attributes.domain'))
                            ->state(fn (Store $record): ?string => $record->domains->first()?->name)->placeholder('—'),
                        TextEntry::make('admin_url')->label(__('vendra-reseller::attributes.admin_url'))
                            ->state(fn (Store $record): string => $record->adminUrl())
                            ->url(fn (Store $record): string => $record->adminUrl())
                            ->openUrlInNewTab()->copyable(),
                        IsActiveEntry::make(),
                    ]),
                    DescriptionEntry::make()->placeholder('—'),
                ])->columnSpanFull(),
            Section::make(__('vendra-reseller::attributes.storefront_configuration'))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('store_status')->label(__('vendra-reseller::attributes.operational_status'))
                            ->badge()->state(fn (Store $record): string => $record->status()->value)
                            ->formatStateUsing(fn (string $state): string => __("vendra-reseller::attributes.store_status_{$state}")),
                        TextEntry::make('deployment_status')->label(__('vendra-reseller::attributes.storefront_status'))
                            ->badge()->state(fn (Store $record): ?string => self::deployment($record)?->status->value)
                            ->formatStateUsing(fn (string $state): string => __("vendra-reseller::attributes.deployment_status_{$state}"))
                            ->placeholder(__('vendra-reseller::attributes.storefront_not_requested')),
                        TextEntry::make('desired_state')->label(__('vendra-reseller::attributes.desired_state'))
                            ->state(fn (Store $record): ?string => self::deployment($record)?->desired_state->value)
                            ->placeholder('—'),
                    ]),
                    TextEntry::make('provisioning_error')->label(__('vendra-reseller::attributes.provisioning_error'))
                        ->visible(fn (Store $record): bool => filled($record->provisioning_error))
                        ->color('danger')->columnSpanFull(),
                ])->columnSpanFull(),
        ]);
    }

    private static function deployment(Store $store): ?StorefrontDeployment
    {
        $deployment = $store->storefrontDeployments->first();

        return $deployment instanceof StorefrontDeployment ? $deployment : null;
    }
}
