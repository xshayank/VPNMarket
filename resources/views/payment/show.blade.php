<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            پرداخت سفارش #{{ $order->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">

                {{-- نمایش پیام اطلاع‌رسانی در صورت وجود --}}
                @if (session('status'))
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-xl mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-bold">اطلاعیه</p>
                                <p>{{ session('status') }}</p>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove()" class="text-yellow-700 font-bold">
                                ✖
                            </button>
                        </div>
                    </div>
                @endif

                {{-- بخش جزئیات فاکتور (همون قبلی) --}}

                {{-- بخش انتخاب روش پرداخت --}}
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 text-right">
                        انتخاب روش پرداخت
                    </h3>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">

                        {{-- گزینه کارت به کارت --}}
                        <form method="POST" action="{{ route('payment.card.process', $order->id) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full text-center p-6 border-2 rounded-lg hover:border-blue-500 transition dark:border-gray-600 dark:hover:border-blue-500">
                                <h4 class="font-bold text-gray-900 dark:text-gray-100">پرداخت با کارت به کارت</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                                    ثبت دستی فیش واریزی
                                </p>
                            </button>
                        </form>

                        {{-- گزینه ارز دیجیتال --}}
                        <form method="POST" action="{{ route('payment.crypto.process', $order->id) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full text-center p-6 border-2 rounded-lg hover:border-green-500 transition dark:border-gray-600 dark:hover:border-green-500">
                                <h4 class="font-bold text-gray-900 dark:text-gray-100">پرداخت با ارز دیجیتال (USDT)</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                                    پرداخت آنی و خودکار (به زودی)
                                </p>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
