<?php
//
//namespace App\Providers\Filament;
//
//use Filament\FontProviders\LocalFontProvider;
//use Filament\Http\Middleware\Authenticate;
//use Filament\Http\Middleware\AuthenticateSession;
//use Filament\Http\Middleware\DisableBladeIconComponents;
//use Filament\Http\Middleware\DispatchServingFilamentEvent;
//use Filament\Pages;
//use Filament\Panel;
//use Filament\PanelProvider;
//use Filament\Support\Colors\Color;
//use Filament\Widgets;
//use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
//use Illuminate\Cookie\Middleware\EncryptCookies;
//use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
//use Illuminate\Routing\Middleware\SubstituteBindings;
//use Illuminate\Session\Middleware\StartSession;
//use Illuminate\View\Middleware\ShareErrorsFromSession;
//use Nwidart\Modules\Facades\Module;
//
//
//class AdminPanelProvider extends PanelProvider
//{
//    public function panel(Panel $panel): Panel
//    {
//
//
//
//        return $panel
//            ->default()
//            ->id('admin')
//            ->path('admin')
//            ->login()
//
//            ->colors([
//                'primary' => '#7C3AED',
//                'gray' => '#1E293B', // خاکستری تیره مدرن
//                'danger' => '#F43F5E', // صورتی-قرمز
//                'info' => '#38BDF8', // آبی sky
//                'success' => '#10B981', // سبز emerald
//                'warning' => '#FACC15', // زرد روشن
//
//            ])
//            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
//            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
//            ->pages([
//                Pages\Dashboard::class,
//            ])
//            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
//            ->widgets([
//                Widgets\AccountWidget::class,
//                Widgets\FilamentInfoWidget::class,
//            ])
//
//            ->font(
//                'Vaz',
//                url: asset('css/font.css'),
//                provider: LocalFontProvider::class,
//            )
//            ->middleware([
//                EncryptCookies::class,
//                AddQueuedCookiesToResponse::class,
//                StartSession::class,
//                AuthenticateSession::class,
//                ShareErrorsFromSession::class,
//                VerifyCsrfToken::class,
//                SubstituteBindings::class,
//                DisableBladeIconComponents::class,
//                DispatchServingFilamentEvent::class,
//            ])
//            ->authMiddleware([
//                Authenticate::class,
//            ]);
//
//
//
//    }
//}


namespace App\Providers\Filament;

use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nwidart\Modules\Facades\Module;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {

        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => '#7C3AED',
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font(
                'Vaz',
                url: asset('css/font.css'),
                provider: LocalFontProvider::class,
            )

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([

                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);


        foreach (Module::getOrdered() as $module) {
            if ($module->isEnabled()) {

                $panel->discoverResources(in: $module->getPath() . '/Filament/Resources', for: 'Modules\\' . $module->getName() . '\\Filament\\Resources');
                $panel->discoverPages(in: $module->getPath() . '/Filament/Pages', for: 'Modules\\' . $module->getName() . '\\Filament\\Pages');
                $panel->discoverWidgets(in: $module->getPath() . '/Filament/Widgets', for: 'Modules\\' . $module->getName() . '\\Filament\\Widgets');
            }
        }

        return $panel;
    }
}
