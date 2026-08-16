<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Resources\Properties\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Component as Livewire;
use Misaf\LaravelEmailValidation\Rules\EmailValidation;
use Misaf\VendraProperty\Filament\Schemas\StorefrontConfigurationFields;
use Misaf\VendraTenant\Models\TenantDomain;

final class PropertyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('domain')
                    ->afterStateUpdated(fn(Livewire $livewire) => $livewire->validateOnly('data.domain'))
                    ->helperText(__('console.domain_helper_text'))
                    ->label(__('console.domain'))
                    ->live(onBlur: true)
                    ->maxLength(255)
                    ->required()
                    ->rules(TenantDomain::activeDomainRules())
                    ->dehydrateStateUsing(fn(?string $state): ?string => null === $state
                        ? null
                        : TenantDomain::normalizeDomain($state)),

                TextInput::make('email')
                    ->afterStateUpdated(fn(Livewire $livewire) => $livewire->validateOnly('data.email'))
                    ->label(__('console.email'))
                    ->email()
                    ->extraAttributes(['dir' => 'ltr'])
                    ->live(onBlur: true)
                    ->maxLength(255)
                    ->required()
                    ->rules([
                        'bail',
                        'email:rfc,strict,spoof,filter,filter_unicode',
                        new EmailValidation(),
                    ]),

                ...StorefrontConfigurationFields::make(optional: false),
            ])
            ->columns(2);
    }
}
