<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('ویرایش کانفیگ') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-3xl mx-auto px-3 sm:px-6 lg:px-8 space-y-3 md:space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.configs.index')" />

            @if ($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <ul class="mt-2 list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 md:p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">اطلاعات کانفیگ</h3>
                    <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                        <p><strong>نام کاربری:</strong> {{ $config->external_username }}</p>
                        @if($config->comment)
                            <p><strong>توضیحات:</strong> {{ $config->comment }}</p>
                        @endif
                        <p><strong>مصرف فعلی:</strong> {{ round($config->usage_bytes / (1024 * 1024 * 1024), 2) }} GB</p>
                        @if($config->getSettledUsageBytes() > 0)
                            <p><strong>مصرف قبلی (ریست شده):</strong> {{ round($config->getSettledUsageBytes() / (1024 * 1024 * 1024), 2) }} GB</p>
                        @endif
                        <p><strong>وضعیت:</strong> {{ $config->status }}</p>
                    </div>
                </div>

                <form action="{{ route('reseller.configs.update', $config) }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="traffic_limit_gb" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 text-right">
                            محدودیت ترافیک (گیگابایت) <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            step="0.1" 
                            min="0.1"
                            id="traffic_limit_gb" 
                            name="traffic_limit_gb" 
                            value="{{ old('traffic_limit_gb', round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2)) }}"
                            class="w-full px-4 py-3 md:py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-right" 
                            required>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 text-right">
                            حداقل: {{ round($config->usage_bytes / (1024 * 1024 * 1024), 2) }} GB (مصرف فعلی)
                        </p>
                    </div>

                    <div>
                        <label for="expires_at" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 text-right">
                            تاریخ انقضا <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="date" 
                            id="expires_at" 
                            name="expires_at" 
                            value="{{ old('expires_at', $config->expires_at->format('Y-m-d')) }}"
                            min="{{ now()->format('Y-m-d') }}"
                            class="w-full px-4 py-3 md:py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-right" 
                            required>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 text-right">
                            حداقل: امروز (تاریخ به ساعت 00:00 ذخیره می‌شود)
                        </p>
                    </div>

                    <!-- Max clients field for Eylandoo -->
                    @if($config->panel_type === 'eylandoo')
                    <div>
                        <label for="max_clients" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 text-right">
                            حداکثر تعداد کلاینت‌های همزمان <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            min="1"
                            max="100"
                            id="max_clients" 
                            name="max_clients" 
                            value="{{ old('max_clients', $config->meta['max_clients'] ?? 1) }}"
                            class="w-full px-4 py-3 md:py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-right" 
                            required>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 text-right">
                            تعداد کلاینت‌هایی که می‌توانند به طور همزمان متصل شوند
                        </p>
                    </div>
                    @endif

                    <div class="flex flex-col sm:flex-row gap-3 pt-4">
                        <button type="submit" class="w-full sm:w-auto px-6 py-3 md:py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            ذخیره تغییرات
                        </button>
                        <a href="{{ route('reseller.configs.index') }}" class="w-full sm:w-auto px-6 py-3 md:py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 text-center">
                            لغو
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
