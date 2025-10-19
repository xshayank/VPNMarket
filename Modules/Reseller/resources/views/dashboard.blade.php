<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ __('داشبورد ریسلر') }}
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

            {{-- Reseller Type Badge --}}
            <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <div class="flex justify-between items-center">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">نوع اکانت:</span>
                        <span class="font-bold text-lg text-gray-900 dark:text-gray-100">
                            {{ $reseller->type === 'plan' ? 'ریسلر پلن‌محور' : 'ریسلر ترافیک‌محور' }}
                        </span>
                    </div>
                    <span class="px-4 py-2 bg-green-600 text-white rounded-md">
                        {{ $reseller->status === 'active' ? 'فعال' : 'معلق' }}
                    </span>
                </div>
            </div>

            @if ($reseller->isPlanBased())
                {{-- Plan-based reseller stats --}}
                <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">آمار و اطلاعات</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">موجودی</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['balance']) }} تومان</div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">تعداد سفارشات</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_orders'] }}</div>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">سفارشات تکمیل شده</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['fulfilled_orders'] }}</div>
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">مجموع اکانت‌ها</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_accounts'] }}</div>
                        </div>
                    </div>
                    <div class="mt-6 flex gap-4">
                        <a href="{{ route('reseller.plans.index') }}" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            مشاهده پلن‌ها
                        </a>
                        <a href="{{ route('wallet.charge.form') }}" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                            شارژ کیف پول
                        </a>
                    </div>
                </div>

                {{-- Recent orders --}}
                @if (count($stats['recent_orders']) > 0)
                    <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                        <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">آخرین سفارشات</h3>
                        <table class="w-full">
                            <thead>
                                <tr class="border-b dark:border-gray-700">
                                    <th class="text-right pb-2 text-gray-700 dark:text-gray-100">شناسه</th>
                                    <th class="text-right pb-2 text-gray-700 dark:text-gray-100">پلن</th>
                                    <th class="text-right pb-2 text-gray-700 dark:text-gray-100">تعداد</th>
                                    <th class="text-right pb-2 text-gray-700 dark:text-gray-100">مبلغ کل</th>
                                    <th class="text-right pb-2 text-gray-700 dark:text-gray-100">وضعیت</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stats['recent_orders'] as $order)
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="py-2 text-gray-900 dark:text-gray-100">{{ $order->id }}</td>
                                        <td class="py-2 text-gray-900 dark:text-gray-100">{{ $order->plan->name }}</td>
                                        <td class="py-2 text-gray-900 dark:text-gray-100">{{ $order->quantity }}</td>
                                        <td class="py-2 text-gray-900 dark:text-gray-100">{{ number_format($order->total_price) }} تومان</td>
                                        <td class="py-2">
                                            <span class="px-2 py-1 rounded text-sm {{ $order->status === 'fulfilled' ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100' }}">
                                                {{ $order->status }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                {{-- Traffic-based reseller stats --}}
                <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">ترافیک و زمان</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div class="bg-blue-50 dark:bg-blue-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">ترافیک کل</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_total_gb'] }} GB</div>
                        </div>
                        <div class="bg-red-50 dark:bg-red-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">ترافیک مصرف شده</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_used_gb'] }} GB</div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">ترافیک باقی‌مانده</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['traffic_remaining_gb'] }} GB</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="bg-purple-50 dark:bg-purple-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">تاریخ شروع</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $stats['window_starts_at']->format('Y-m-d') }}</div>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">تاریخ پایان</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $stats['window_ends_at']->format('Y-m-d') }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $stats['days_remaining'] }} روز باقی‌مانده</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-indigo-50 dark:bg-indigo-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">کانفیگ‌های فعال</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['active_configs'] }}</div>
                        </div>
                        <div class="bg-pink-50 dark:bg-pink-900 p-4 rounded-lg">
                            <div class="text-sm text-gray-600 dark:text-gray-300">مجموع کانفیگ‌ها</div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total_configs'] }}</div>
                        </div>
                    </div>
                    <div class="mt-6 flex gap-4">
                        <a href="{{ route('reseller.configs.create') }}" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            ایجاد کانفیگ جدید
                        </a>
                        <a href="{{ route('reseller.configs.index') }}" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                            مشاهده همه کانفیگ‌ها
                        </a>
                        <form action="{{ route('reseller.sync') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                به‌روزرسانی آمار
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
