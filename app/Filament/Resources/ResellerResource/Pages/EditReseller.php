<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReseller extends EditRecord
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('extend_window')
                ->label('تمدید بازه (Extend Window)')
                ->icon('heroicon-o-calendar')
                ->color('info')
                ->visible(fn () => $this->record->type === 'traffic')
                ->requiresConfirmation()
                ->modalHeading('تمدید بازه زمانی (Extend Time Window)')
                ->modalDescription('آیا مطمئن هستید که می‌خواهید بازه زمانی این ریسلر را تمدید کنید؟ / Are you sure you want to extend this reseller\'s time window?')
                ->modalSubmitActionLabel('تمدید (Extend)')
                ->modalCancelActionLabel('انصراف (Cancel)')
                ->form([
                    \Filament\Forms\Components\TextInput::make('days_to_extend')
                        ->label('افزایش روز (Extend by days)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->integer()
                        ->helperText('تعداد روزی که می‌خواهید به بازه زمانی اضافه کنید'),
                ])
                ->action(function (array $data) {
                    try {
                        $daysToExtend = (int) $data['days_to_extend'];
                        $oldEndDate = $this->record->window_ends_at;

                        // Use model method to get base date
                        $baseDate = $this->record->getExtendWindowBaseDate();
                        // Normalize to start of day for calendar-day boundaries
                        $newEndDate = $baseDate->copy()->addDays($daysToExtend)->startOfDay();

                        $this->record->update([
                            'window_ends_at' => $newEndDate,
                            'window_starts_at' => $this->record->window_starts_at ?? now()->startOfDay(),
                        ]);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_window_extended',
                            targetType: 'reseller',
                            targetId: $this->record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_window_ends_at' => $oldEndDate?->toDateTimeString(),
                                'new_window_ends_at' => $newEndDate->toDateTimeString(),
                                'days_added' => $daysToExtend,
                            ]
                        );

                        // If reseller was suspended and now has remaining quota and valid window,
                        // dispatch job to re-enable configs
                        if ($this->record->status === 'suspended' && $this->record->hasTrafficRemaining() && $this->record->isWindowValid()) {
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch();
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('بازه زمانی با موفقیت تمدید شد')
                            ->body("{$daysToExtend} روز به بازه زمانی ریسلر اضافه شد")
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('خطا در تمدید بازه')
                            ->body('خطایی رخ داده است: '.$e->getMessage())
                            ->send();
                    }
                }),
            Actions\Action::make('reset_usage')
                ->label('بازنشانی مصرف (Reset Usage)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->record->type === 'traffic' && auth()->user()?->is_admin)
                ->requiresConfirmation()
                ->modalHeading('بازنشانی مصرف ترافیک (Reset Traffic Usage)')
                ->modalDescription('این عملیات مصرف ترافیک فعلی و قبلی (settled) ریسلر را به صفر تنظیم می‌کند و همه کانفیگ‌ها را نیز ریست می‌کند. این تغییر محدودیت کل ترافیک را تغییر نمی‌دهد. آیا مطمئن هستید؟ / This will reset both current and settled traffic usage to 0 for all configs. This does not change the total traffic limit. Continue?')
                ->modalSubmitActionLabel('بله، بازنشانی شود (Yes, Reset)')
                ->modalCancelActionLabel('انصراف (Cancel)')
                ->action(function () {
                    try {
                        $oldUsedBytes = $this->record->traffic_used_bytes;
                        $configs = $this->record->configs()->get();
                        $configCount = $configs->count();
                        $resetResults = [];
                        
                        // Reset each config
                        foreach ($configs as $config) {
                            $oldConfigUsage = $config->usage_bytes;
                            $oldSettledUsage = (int) data_get($config->meta, 'settled_usage_bytes', 0);
                            
                            // Clear usage and settled usage
                            $meta = $config->meta ?? [];
                            $meta['settled_usage_bytes'] = 0;
                            $meta['last_reset_at'] = now()->toDateTimeString();
                            
                            // For Eylandoo configs, clear meta usage fields
                            if ($config->panel_type === 'eylandoo') {
                                $meta['used_traffic'] = 0;
                                $meta['data_used'] = 0;
                            }
                            
                            $config->update([
                                'usage_bytes' => 0,
                                'meta' => $meta,
                            ]);
                            
                            // Try to reset on remote panel (best-effort)
                            $remoteSuccess = false;
                            $remoteError = null;
                            
                            if ($config->panel_id && $config->panel_user_id) {
                                try {
                                    $panel = \App\Models\Panel::find($config->panel_id);
                                    if ($panel) {
                                        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;
                                        $result = $provisioner->resetUserUsage(
                                            $panel->panel_type,
                                            $panel->getCredentials(),
                                            $config->panel_user_id
                                        );
                                        $remoteSuccess = $result['success'] ?? false;
                                        $remoteError = $result['last_error'] ?? null;
                                    }
                                } catch (\Exception $e) {
                                    $remoteError = $e->getMessage();
                                    \Illuminate\Support\Facades\Log::error("Failed to reset config {$config->id} on remote panel: {$remoteError}");
                                }
                            }
                            
                            $resetResults[] = [
                                'config_id' => $config->id,
                                'config_username' => $config->external_username,
                                'old_usage_bytes' => $oldConfigUsage,
                                'old_settled_bytes' => $oldSettledUsage,
                                'remote_success' => $remoteSuccess,
                                'remote_error' => $remoteError,
                            ];
                        }
                        
                        // Recompute reseller traffic_used_bytes from configs
                        // After reset, this should be 0 since all configs are zeroed
                        $totalUsageBytes = $this->record->configs()
                            ->get()
                            ->sum(function ($c) {
                                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
                            });
                        
                        $this->record->update([
                            'traffic_used_bytes' => $totalUsageBytes,
                        ]);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_usage_admin_reset',
                            targetType: 'reseller',
                            targetId: $this->record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_traffic_used_bytes' => $oldUsedBytes,
                                'new_traffic_used_bytes' => $totalUsageBytes,
                                'traffic_total_bytes' => $this->record->traffic_total_bytes,
                                'configs_count' => $configCount,
                                'reset_results' => $resetResults,
                            ]
                        );

                        // If reseller was suspended and now has remaining quota and valid window,
                        // dispatch job to re-enable configs
                        if ($this->record->status === 'suspended' && $this->record->hasTrafficRemaining() && $this->record->isWindowValid()) {
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch();
                        }

                        $remoteFailures = collect($resetResults)->where('remote_success', false)->count();
                        
                        if ($remoteFailures > 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('مصرف ترافیک بازنشانی شد')
                                ->body("مصرف ریسلر و {$configCount} کانفیگ به صفر تنظیم شد، اما {$remoteFailures} کانفیگ در پنل ریموت با خطا مواجه شد.")
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('مصرف ترافیک با موفقیت بازنشانی شد')
                                ->body("مصرف ریسلر و {$configCount} کانفیگ به صفر تنظیم شد")
                                ->send();
                        }
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('خطا در بازنشانی مصرف')
                            ->body('خطایی رخ داده است: '.$e->getMessage())
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Convert traffic bytes to GB for display if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_bytes'])) {
            $data['traffic_total_gb'] = $data['traffic_total_bytes'] / (1024 * 1024 * 1024);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert traffic GB to bytes if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_gb'])) {
            $data['traffic_total_bytes'] = (int) ($data['traffic_total_gb'] * 1024 * 1024 * 1024);
            unset($data['traffic_total_gb']);
        }

        // Treat config_limit of 0 as null (unlimited)
        if (isset($data['config_limit']) && $data['config_limit'] === 0) {
            $data['config_limit'] = null;
        }

        return $data;
    }
}
