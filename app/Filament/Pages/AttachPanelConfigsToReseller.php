<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use Filament\Forms\Components\MultiSelect;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class AttachPanelConfigsToReseller extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static string $view = 'filament.pages.attach-panel-configs-to-reseller';

    protected static ?string $navigationLabel = 'اتصال کانفیگ‌های پنل به ریسلر';

    protected static ?string $title = 'اتصال کانفیگ‌های پنل به ریسلر';

    protected static ?string $navigationGroup = 'مدیریت فروشندگان';

    protected static ?int $navigationSort = 50;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('انتخاب ریسلر و ادمین پنل')
                ->description('ابتدا ریسلر را انتخاب کنید، سپس ادمین پنل و کانفیگ‌های مورد نظر را انتخاب کنید')
                ->schema([
                    Select::make('reseller_id')
                        ->label('ریسلر')
                        ->options(function () {
                            return Reseller::whereHas('panel', function ($query) {
                                $query->whereIn('panel_type', ['marzban', 'marzneshin']);
                            })
                                ->with('panel', 'user')
                                ->get()
                                ->mapWithKeys(function ($reseller) {
                                    $panelName = $reseller->panel->name ?? 'N/A';
                                    $userName = $reseller->user->name ?? $reseller->user->username ?? 'N/A';

                                    return [
                                        $reseller->id => "{$userName} - {$panelName} ({$reseller->panel->panel_type})",
                                    ];
                                });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (callable $set) {
                            $set('panel_admin', null);
                            $set('remote_configs', []);
                        }),

                    Select::make('panel_admin')
                        ->label('ادمین پنل')
                        ->options(function (Get $get) {
                            $resellerId = $get('reseller_id');
                            if (! $resellerId) {
                                return [];
                            }

                            $reseller = Reseller::with('panel')->find($resellerId);
                            if (! $reseller || ! $reseller->panel) {
                                return [];
                            }

                            $admins = $this->fetchPanelAdmins($reseller->panel);

                            return collect($admins)->mapWithKeys(function ($admin) {
                                return [$admin['username'] => $admin['username']];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->visible(fn (Get $get) => $get('reseller_id') !== null)
                        ->afterStateUpdated(function (callable $set) {
                            $set('remote_configs', []);
                        }),

                    MultiSelect::make('remote_configs')
                        ->label('کانفیگ‌های پنل')
                        ->options(function (Get $get) {
                            $resellerId = $get('reseller_id');
                            $adminUsername = $get('panel_admin');

                            if (! $resellerId || ! $adminUsername) {
                                return [];
                            }

                            $reseller = Reseller::with('panel')->find($resellerId);
                            if (! $reseller || ! $reseller->panel) {
                                return [];
                            }

                            $configs = $this->fetchConfigsByAdmin($reseller->panel, $adminUsername);

                            return collect($configs)->mapWithKeys(function ($config) {
                                $status = $config['status'] ?? 'unknown';

                                return [
                                    $config['username'] => "{$config['username']} ({$status})",
                                ];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->visible(fn (Get $get) => $get('panel_admin') !== null)
                        ->helperText('کانفیگ‌هایی که قبلاً وارد شده‌اند، به صورت خودکار نادیده گرفته می‌شوند'),
                ])
                ->columns(1),
        ])->statePath('data');
    }

    protected function fetchPanelAdmins(Panel $panel): array
    {
        $credentials = $panel->getCredentials();

        if ($panel->panel_type === 'marzban') {
            $service = new MarzbanService(
                $credentials['url'],
                $credentials['username'],
                $credentials['password'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            return $service->listAdmins();
        } elseif ($panel->panel_type === 'marzneshin') {
            $service = new MarzneshinService(
                $credentials['url'],
                $credentials['username'],
                $credentials['password'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            return $service->listAdmins();
        }

        return [];
    }

    protected function fetchConfigsByAdmin(Panel $panel, string $adminUsername): array
    {
        $credentials = $panel->getCredentials();

        if ($panel->panel_type === 'marzban') {
            $service = new MarzbanService(
                $credentials['url'],
                $credentials['username'],
                $credentials['password'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            return $service->listConfigsByAdmin($adminUsername);
        } elseif ($panel->panel_type === 'marzneshin') {
            $service = new MarzneshinService(
                $credentials['url'],
                $credentials['username'],
                $credentials['password'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            return $service->listConfigsByAdmin($adminUsername);
        }

        return [];
    }

    public function importConfigs(): void
    {
        $this->form->validate();

        $formData = $this->form->getState();
        $resellerId = $formData['reseller_id'];
        $adminUsername = $formData['panel_admin'];
        $selectedConfigUsernames = $formData['remote_configs'];

        $reseller = Reseller::with('panel')->findOrFail($resellerId);
        $panel = $reseller->panel;

        // Validate panel type
        if (! in_array($panel->panel_type, ['marzban', 'marzneshin'])) {
            Notification::make()
                ->title('خطا')
                ->body('این عملیات فقط برای پنل‌های Marzban و Marzneshin پشتیبانی می‌شود')
                ->danger()
                ->send();

            return;
        }

        // Fetch all configs by admin to get full details
        $allConfigs = $this->fetchConfigsByAdmin($panel, $adminUsername);
        $configsToImport = collect($allConfigs)->whereIn('username', $selectedConfigUsernames);

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($reseller, $panel, $adminUsername, $configsToImport, &$imported, &$skipped) {
            foreach ($configsToImport as $remoteConfig) {
                $remoteUserId = $remoteConfig['id'];
                $remoteUsername = $remoteConfig['username'];

                // Check if already exists
                $exists = ResellerConfig::where('panel_id', $panel->id)
                    ->where(function ($query) use ($remoteUserId, $remoteUsername) {
                        $query->where('panel_user_id', $remoteUserId)
                            ->orWhere('external_username', $remoteUsername);
                    })
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                // Map status
                $status = 'active';
                $disabledAt = null;
                if (isset($remoteConfig['status'])) {
                    $remoteStatus = strtolower($remoteConfig['status']);
                    if (in_array($remoteStatus, ['disabled', 'inactive', 'limited'])) {
                        $status = 'disabled';
                        $disabledAt = now();
                    } elseif (in_array($remoteStatus, ['expired'])) {
                        $status = 'expired';
                    }
                }

                // Create ResellerConfig
                $config = ResellerConfig::create([
                    'reseller_id' => $reseller->id,
                    'panel_id' => $panel->id,
                    'panel_type' => $panel->panel_type,
                    'panel_user_id' => $remoteUserId,
                    'external_username' => $remoteUsername,
                    'status' => $status,
                    'usage_bytes' => $remoteConfig['used_traffic'] ?? 0,
                    'traffic_limit_bytes' => $remoteConfig['data_limit'] ?? 0,
                    'disabled_at' => $disabledAt,
                    'expires_at' => now()->addDays(30), // Default expiry
                    'created_by' => auth()->id(),
                ]);

                // Create event
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'imported_from_panel',
                    'meta' => [
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'remote_admin_username' => $adminUsername,
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'config_imported_from_panel',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'manual_import',
                    meta: [
                        'panel_id' => $panel->id,
                        'panel_type' => $panel->panel_type,
                        'remote_admin_username' => $adminUsername,
                        'reseller_id' => $reseller->id,
                    ]
                );

                $imported++;
            }
        });

        Notification::make()
            ->title('عملیات موفق')
            ->body("تعداد {$imported} کانفیگ وارد شد و {$skipped} کانفیگ تکراری نادیده گرفته شد")
            ->success()
            ->send();

        // Reset form
        $this->form->fill();
    }
}
