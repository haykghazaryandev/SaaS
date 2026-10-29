<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel at /admin, only for the product owner (users.is_admin).
 * No own login form: the panel shares the web session, guests are sent to /login (phone + Telegram code).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->brandName('ZoneSpot Admin')
            ->favicon('/favicon.ico')   // a path: asset() runs before the https setting and would give http:// behind Railway's proxy
            ->font('Manrope')
            ->darkMode(true, isForced: true)
            ->colors([
                'primary' => Color::hex('#c8ff3d'),
                'gray' => Color::Zinc,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'info' => Color::Sky,
            ])
            // solid primary buttons in the Bookball lime with dark text (as on the player and owner web)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<style>
                .fi-btn.fi-color-primary:not(.fi-outlined){background-color:#c8ff3d!important;color:#0d1404!important}
                .fi-btn.fi-color-primary:not(.fi-outlined):hover{background-color:#b6f02a!important}
                .fi-btn.fi-color-primary:not(.fi-outlined)>.fi-icon{color:#0d1404!important}
            </style>'))
            // opened inside the Telegram Mini App (Profile → Admin panel): Telegram's back button returns to Bookball
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(<<<'HTML'
                <script>
                    try {
                        if (sessionStorage.getItem('bb.tg')) document.write('<script src="https://telegram.org/js/telegram-web-app.js"><\/script>');
                    } catch (e) {}
                </script>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var w = window.Telegram && window.Telegram.WebApp;
                        if (!w || !w.initData) return;
                        w.ready(); w.expand();
                        try { w.setHeaderColor('#0a0d0b'); w.setBackgroundColor('#0a0d0b'); } catch (e) {}
                        w.BackButton.show();
                        w.BackButton.onClick(function () { if (history.length > 1) history.back(); else location.href = '/me/profile'; });
                    });
                </script>
                HTML))
            // back to the app (phones, the Telegram Mini App: no browser back button): the player app, the owner panel
            ->navigationItems([
                NavigationItem::make('player_app')->label(fn () => __('admin.nav.player_app'))->icon('heroicon-o-device-phone-mobile')
                    ->url(fn () => route('switch', ['to' => 'player']))->sort(-20),
                NavigationItem::make('owner_panel')->label(fn () => __('admin.nav.owner_panel'))->icon('heroicon-o-building-storefront')
                    ->url(fn () => route('switch', ['to' => 'owner']))->visible(fn () => (bool) auth()->user()?->venues()->exists())->sort(-19),
            ])
            ->userMenuItems([
                Action::make('player_app')->label(fn () => __('admin.nav.player_app'))->icon('heroicon-o-device-phone-mobile')->url(fn () => route('switch', ['to' => 'player'])),
                Action::make('owner_panel')->label(fn () => __('admin.nav.owner_panel'))->icon('heroicon-o-building-storefront')
                    ->url(fn () => route('switch', ['to' => 'owner']))->visible(fn () => (bool) auth()->user()?->venues()->exists()),
                Action::make('staff_view')->label(fn () => __('switch.staff'))->icon('heroicon-o-identification')
                    ->url(fn () => route('switch', ['to' => 'staff']))->visible(fn () => (bool) auth()->user()?->venues()->wherePivot('role', 'owner')->exists()),
            ])
            ->navigationGroups([
                NavigationGroup::make()->label(fn () => __('admin.nav.places')),
                NavigationGroup::make()->label(fn () => __('admin.nav.people')),
                NavigationGroup::make()->label(fn () => __('common.bookings')),
                NavigationGroup::make()->label(fn () => __('admin.nav.system')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // no AuthenticateSession: our users have no passwords (phone + code login)
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                AdminAuthenticate::class,
                AdminLocale::class,
            ]);
    }
}
