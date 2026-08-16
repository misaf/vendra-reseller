<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Providers;

use Filament\FontProviders\SpatieGoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Uri;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Misaf\VendraLocalization\Http\Middleware\SetLocale;
use Misaf\VendraReseller\Filament\Pages\Auth\Login;
use Misaf\VendraReseller\Filament\Pages\Auth\Register;
use Misaf\VendraReseller\Http\Middleware\AddResellerToRequestJobContext;
use Misaf\VendraSupport\Http\Middleware\AddPanelToRequestJobContext;

/**
 * The reseller (self-service) panel.
 *
 * A reseller owner manages their own billing reseller here: they see their
 * subscription and create/list properties within their plan's quota. It runs
 * outside the tenant middleware because a reseller spans multiple properties.
 */
final class ResellerPanelServiceProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reseller')
            ->brandLogo(fn(): string => asset('images/vendra-logo.svg'))
            ->brandLogoHeight('2rem')
            ->brandName('Vendra Reseller')
            ->darkModeBrandLogo(fn(): string => asset('images/vendra-logo-dark.svg'))
            ->databaseNotifications()
            ->databaseTransactions()
            ->discoverResources(__DIR__ . '/../Filament/Resources', 'Misaf\\VendraReseller\\Filament\\Resources')
            ->discoverPages(__DIR__ . '/../Filament/Pages', 'Misaf\\VendraReseller\\Filament\\Pages')
            ->discoverWidgets(__DIR__ . '/../Filament/Widgets', 'Misaf\\VendraReseller\\Filament\\Widgets')
            ->pages([Dashboard::class])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->homeUrl('/')
            ->authGuard('reseller')
            ->authPasswordBroker('reseller_users')
            ->domain('reseller.' . Uri::of(config()->string('app.url'))->host())
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset()
            ->emailVerification(isRequired: true)
            ->maxContentWidth(Width::Full)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                AddResellerToRequestJobContext::class,
                AddPanelToRequestJobContext::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->font(
                fn(): string => app()->isLocale('fa') ? 'Vazirmatn' : 'Google',
                provider: SpatieGoogleFontProvider::class,
            )
            ->path('')
            ->profile()
            ->spa(hasPrefetching: true)
            ->topNavigation();
    }
}
