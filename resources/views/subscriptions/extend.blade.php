<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('تمدید سرویس') }}
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
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">تاریخ انقضای فعلی:</span>
                            <span class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ \Carbon\Carbon::parse($order->expires_at)->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">قیمت تمدید:</span>
                            <span class="font-bold text-green-600 dark:text-green-400">{{ number_format($order->plan->price) }} تومان</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">موجودی کیف پول شما:</span>
                            <span class="font-bold text-blue-600 dark:text-blue-400">{{ number_format(auth()->user()->balance) }} تومان</span>
                        </div>
                    </div>

                    @if ($eligibility['allowed'])
                        <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <p class="text-blue-800 dark:text-blue-200">
                                <strong>نوع تمدید:</strong> {{ $eligibility['type'] === 'extend' ? 'افزایش زمان' : 'بازنشانی کامل' }}
                            </p>
                            <p class="text-sm text-blue-700 dark:text-blue-300 mt-2">{{ $eligibility['message'] }}</p>
                            
                            @if ($eligibility['type'] === 'extend')
                                <p class="text-sm text-blue-700 dark:text-blue-300 mt-2">
                                    <strong>تاریخ انقضای جدید:</strong> 
                                    <span dir="ltr">{{ \Carbon\Carbon::parse($order->expires_at)->addDays($order->plan->duration_days)->format('Y-m-d H:i') }}</span>
                                </p>
                            @else
                                <p class="text-sm text-blue-700 dark:text-blue-300 mt-2">
                                    <strong>تاریخ انقضای جدید:</strong> 
                                    <span dir="ltr">{{ now()->addDays($order->plan->duration_days)->format('Y-m-d H:i') }}</span>
                                </p>
                            @endif
                        </div>

                        @if (auth()->user()->balance >= $order->plan->price)
                            <form method="POST" action="{{ route('subscription.extend', $order->id) }}">
                                @csrf
                                <button type="submit" class="w-full px-6 py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition">
                                    تأیید و پرداخت از کیف پول
                                </button>
                            </form>
                        @else
                            <div class="mb-4 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                                <p class="text-yellow-800 dark:text-yellow-200">
                                    موجودی کیف پول شما کافی نیست. لطفاً ابتدا کیف پول خود را شارژ کنید.
                                </p>
                            </div>
                            <a href="{{ route('wallet.charge.form') }}" class="block w-full px-6 py-3 bg-blue-600 text-white text-center font-semibold rounded-lg hover:bg-blue-700 transition">
                                شارژ کیف پول
                            </a>
                        @endif
                    @else
                        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                            <p class="text-red-800 dark:text-red-200">
                                <strong>تمدید مجاز نیست</strong>
                            </p>
                            <p class="text-sm text-red-700 dark:text-red-300 mt-2">{{ $eligibility['message'] }}</p>
                        </div>
                        
                        <a href="{{ route('dashboard') }}" class="block w-full px-6 py-3 bg-gray-600 text-white text-center font-semibold rounded-lg hover:bg-gray-700 transition">
                            بازگشت به داشبورد
                        </a>
                    @endif

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
