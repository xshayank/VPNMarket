<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('تأیید تمدید سرویس') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-right">
                    <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-white">جزئیات سرویس</h3>
                    
                    <div class="mb-6 space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">نام پلن:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ $order->plan->name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">حجم:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ $order->plan->volume_gb }} GB</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">مدت زمان:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ $order->plan->duration_days }} روز</span>
                        </div>
                        @if($order->expires_at)
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">تاریخ انقضای فعلی:</span>
                            <span class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ \Carbon\Carbon::parse($order->expires_at)->format('Y-m-d H:i') }}</span>
                        </div>
                        @endif
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">قیمت تمدید:</span>
                            <span class="font-bold text-green-600 dark:text-green-400">{{ number_format($order->plan->price) }} تومان</span>
                        </div>
                    </div>

                    <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <p class="text-blue-800 dark:text-blue-200">
                            با تأیید، یک سفارش تمدید جدید برای این سرویس ایجاد می‌شود و به صفحه انتخاب روش پرداخت منتقل خواهید شد.
                        </p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4">
                        <form method="POST" action="{{ route('order.renew', $order->id) }}" class="flex-1">
                            @csrf
                            <button type="submit" class="w-full px-6 py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition">
                                تأیید و ادامه
                            </button>
                        </form>
                        
                        <a href="{{ route('dashboard') }}" class="flex-1 px-6 py-3 bg-gray-600 text-white text-center font-semibold rounded-lg hover:bg-gray-700 transition">
                            انصراف
                        </a>
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('dashboard') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                            ← بازگشت به داشبورد
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
