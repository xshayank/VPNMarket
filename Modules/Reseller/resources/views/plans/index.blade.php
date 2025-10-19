<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ __('پلن‌های قابل خرید') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <p class="text-gray-600 dark:text-gray-300 mb-4">
                    حداکثر تعداد قابل خرید در هر سفارش: <strong>{{ $max_quantity }}</strong> اکانت
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse ($plans as $planData)
                    @php
                        $plan = $planData['plan'];
                        $pricing = $planData['pricing'];
                    @endphp
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-right">
                        <h3 class="text-xl font-bold mb-2 text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>
                        <div class="mb-4">
                            <span class="text-3xl font-bold text-green-600 dark:text-green-400">{{ number_format($pricing['price']) }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $plan->currency }}</span>
                            @if ($pricing['original_price'] != $pricing['price'])
                                <div class="text-sm text-gray-400 dark:text-gray-500 line-through">{{ number_format($pricing['original_price']) }} تومان</div>
                            @endif
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                            <div>حجم: {{ $plan->volume_gb }} GB</div>
                            <div>مدت: {{ $plan->duration_days }} روز</div>
                        </div>
                        <form action="{{ route('reseller.bulk.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">مقدار <span class="text-red-500">*</span></label>
                                <input type="number" name="quantity" min="1" max="{{ $max_quantity }}" value="1" required
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                @error('quantity')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">نحوه دریافت</label>
                                <select name="delivery_mode" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                    <option value="onscreen">نمایش در صفحه</option>
                                    <option value="download">دانلود فایل</option>
                                </select>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                خرید
                            </button>
                        </form>
                    </div>
                @empty
                    <div class="col-span-3 text-center py-12">
                        <p class="text-gray-500">هیچ پلنی در دسترس نیست.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
