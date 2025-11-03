<x-filament-panels::page>
    <form wire:submit="importConfigs">
        {{ $this->form }}
        
        <div class="mt-4 flex gap-2">
            <x-filament::button type="submit" color="success" icon="heroicon-o-arrow-down-tray">
                وارد کردن کانفیگ‌ها
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
