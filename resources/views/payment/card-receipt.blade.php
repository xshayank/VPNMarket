<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            ثبت رسید کارت به کارت
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <!-- بخش اطلاعات پرداخت -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 md:p-8 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0 bg-primary-500/10 dark:bg-primary-400/10 rounded-lg p-3">
                            <svg class="h-6 w-6 text-primary-500 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15A2.25 2.25 0 002.25 6.75v10.5A2.25 2.25 0 004.5 19.5z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                اطلاعات واریز
                            </h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                لطفاً مبلغ <strong class="font-mono text-primary-600 dark:text-primary-400">{{ number_format($order->plan->price) }} تومان</strong> را به کارت زیر واریز کنید.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="p-6 md:p-8 space-y-4">
                    <div class="text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">شماره کارت</p>
                        <div class="group relative mt-2">
                            <p id="cardNumber" class="text-2xl font-bold font-mono text-gray-800 dark:text-gray-200 tracking-widest bg-gray-100 dark:bg-gray-700/50 rounded-lg py-3 px-4 inline-block">
                                {{ $settings->get('payment_card_number') ?: 'شماره کارتی تنظیم نشده است' }}
                            </p>
                            <button onclick="copyToClipboard('cardNumber')" class="absolute -right-2 -top-2 opacity-0 group-hover:opacity-100 transition-opacity p-1.5 bg-gray-200 dark:bg-gray-600 rounded-full text-gray-600 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-500">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a2.25 2.25 0 01-2.25 2.25h-1.5a2.25 2.25 0 01-2.25-2.25v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
                            </button>
                        </div>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                            به نام: <strong>{{ $settings->get('payment_card_holder_name') ?: '-' }}</strong>
                        </p>
                    </div>

                    @if($settings->get('payment_card_instructions'))
                        <div class="!mt-6 border-t border-gray-200 dark:border-gray-700 pt-4">
                            <p class="text-sm text-center text-gray-600 dark:text-gray-400">
                                {!! nl2br(e($settings->get('payment_card_instructions'))) !!}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- بخش فرم آپلود رسید -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <form method="POST" action="{{ route('payment.card.submit', $order->id) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="p-6 md:p-8">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 text-right">
                            آپلود تصویر رسید
                        </h3>
                        <div class="mt-4">
                            <label for="receipt" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">فایل رسید را انتخاب کنید</label>
                            <input id="receipt"
                                   class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 dark:file:bg-primary-500/10 file:text-primary-700 dark:file:text-primary-400 hover:file:bg-primary-100 dark:hover:file:bg-primary-500/20"
                                   type="file" name="receipt" required accept="image/*">
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-300">
                                فرمت‌های مجاز: PNG, JPG (حداکثر حجم: 2MB).
                            </p>
                            <x-input-error :messages="$errors->get('receipt')" class="mt-2" />
                        </div>
                    </div>
                    <div class="flex items-center justify-end p-6 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700">
                        <x-primary-button>
                            ثبت و ارسال رسید
                        </x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function copyToClipboard(elementId) {
            const textToCopy = document.getElementById(elementId).innerText.replace(/\s/g, ''); // Remove spaces
            navigator.clipboard.writeText(textToCopy).then(() => {
                alert('شماره کارت کپی شد!');
            }).catch(err => {
                console.error('Failed to copy text: ', err);
            });
        }
    </script>
</x-app-layout>
