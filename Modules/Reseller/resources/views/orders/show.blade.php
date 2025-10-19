<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('جزئیات سفارش') }} #{{ $order->id }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.plans.index')" />
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <h3 class="text-lg font-semibold mb-4">اطلاعات سفارش</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <span class="text-gray-600">پلن:</span>
                        <strong>{{ $order->plan->name }}</strong>
                    </div>
                    <div>
                        <span class="text-gray-600">تعداد:</span>
                        <strong>{{ $order->quantity }}</strong>
                    </div>
                    <div>
                        <span class="text-gray-600">قیمت واحد:</span>
                        <strong>{{ number_format($order->unit_price) }} تومان</strong>
                    </div>
                    <div>
                        <span class="text-gray-600">مبلغ کل:</span>
                        <strong>{{ number_format($order->total_price) }} تومان</strong>
                    </div>
                    <div>
                        <span class="text-gray-600">وضعیت:</span>
                        <strong class="{{ $order->status === 'fulfilled' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $order->status }}
                        </strong>
                    </div>
                    <div>
                        <span class="text-gray-600">تاریخ:</span>
                        <strong>{{ $order->created_at->format('Y-m-d H:i') }}</strong>
                    </div>
                </div>
            </div>

            @if ($order->status === 'fulfilled' && $order->artifacts)
                <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                    <h3 class="text-lg font-semibold mb-4">اکانت‌های ایجاد شده</h3>
                    
                    @if ($order->delivery_mode === 'download')
                        <div class="mb-4">
                            <button onclick="downloadCSV()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 ml-2">
                                دانلود CSV
                            </button>
                            <button onclick="downloadJSON()" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                دانلود JSON
                            </button>
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-700">
                                    <th class="border p-2 text-right">نام کاربری</th>
                                    <th class="border p-2 text-right">لینک اشتراک</th>
                                    <th class="border p-2 text-right">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->artifacts as $artifact)
                                    <tr>
                                        <td class="border p-2">{{ $artifact['username'] }}</td>
                                        <td class="border p-2">
                                            <input type="text" value="{{ $artifact['subscription_url'] }}" 
                                                class="w-full text-sm bg-gray-50 dark:bg-gray-700 rounded px-2 py-1" readonly>
                                        </td>
                                        <td class="border p-2">
                                            <button onclick="copyToClipboard('{{ $artifact['subscription_url'] }}')" 
                                                class="px-3 py-1 bg-blue-500 text-white rounded hover:bg-blue-600 text-sm">
                                                کپی
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    function copyToClipboard(text) {
                        navigator.clipboard.writeText(text).then(() => {
                            alert('لینک کپی شد!');
                        });
                    }

                    function downloadCSV() {
                        const artifacts = @json($order->artifacts);
                        let csv = 'Username,Subscription URL\n';
                        artifacts.forEach(a => {
                            csv += `${a.username},"${a.subscription_url}"\n`;
                        });
                        const blob = new Blob([csv], { type: 'text/csv' });
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'order_{{ $order->id }}.csv';
                        a.click();
                    }

                    function downloadJSON() {
                        const artifacts = @json($order->artifacts);
                        const blob = new Blob([JSON.stringify(artifacts, null, 2)], { type: 'application/json' });
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'order_{{ $order->id }}.json';
                        a.click();
                    }
                </script>
            @elseif ($order->status === 'provisioning')
                <div class="p-6 bg-yellow-50 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 rounded-lg text-right">
                    <p class="font-semibold">در حال پردازش...</p>
                    <p class="text-sm">اکانت‌ها در حال ساخت هستند. لطفاً چند دقیقه دیگر صفحه را رفرش کنید.</p>
                </div>
            @elseif ($order->status === 'failed')
                <div class="p-6 bg-red-50 dark:bg-red-900 text-red-800 dark:text-red-200 rounded-lg text-right">
                    <p class="font-semibold">خطا در پردازش</p>
                    <p class="text-sm">متأسفانه در ساخت اکانت‌ها خطایی رخ داده است. لطفاً با پشتیبانی تماس بگیرید.</p>
                </div>
            @endif

            <div class="text-center">
                <a href="{{ route('reseller.dashboard') }}" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                    بازگشت به داشبورد
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
