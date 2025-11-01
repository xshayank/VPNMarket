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
            Actions\Action::make('reset_usage')
                ->label('بازنشانی مصرف (Reset Usage)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->record->type === 'traffic' && auth()->user()?->is_admin)
                ->requiresConfirmation()
                ->modalHeading('بازنشانی مصرف ترافیک (Reset Traffic Usage)')
                ->modalDescription('این عملیات مصرف ترافیک ریسلر را به صفر تنظیم می‌کند. این تغییر محدودیت کل ترافیک را تغییر نمی‌دهد. آیا مطمئن هستید؟ / This will set the reseller\'s used traffic to 0. This does not change their total traffic limit. Continue?')
                ->modalSubmitActionLabel('بله، بازنشانی شود (Yes, Reset)')
                ->modalCancelActionLabel('انصراف (Cancel)')
                ->action(function () {
                    try {
                        $oldUsedBytes = $this->record->traffic_used_bytes;
                        
                        // Reset usage to zero
                        $this->record->update([
                            'traffic_used_bytes' => 0,
                        ]);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_usage_reset',
                            targetType: 'reseller',
                            targetId: $this->record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_traffic_used_bytes' => $oldUsedBytes,
                                'new_traffic_used_bytes' => 0,
                                'traffic_total_bytes' => $this->record->traffic_total_bytes,
                            ]
                        );

                        // If reseller was suspended and now has remaining quota and valid window,
                        // dispatch job to re-enable configs
                        if ($this->record->status === 'suspended' && $this->record->hasTrafficRemaining() && $this->record->isWindowValid()) {
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch();
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('مصرف ترافیک با موفقیت بازنشانی شد')
                            ->body('مصرف ترافیک ریسلر به صفر تنظیم شد')
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('خطا در بازنشانی مصرف')
                            ->body('خطایی رخ داده است: ' . $e->getMessage())
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
