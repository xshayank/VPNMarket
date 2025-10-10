<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            ثبت رسید کارت به کارت
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-right">


                <div class="mb-6 p-4 bg-blue-100 dark:bg-gray-700/50 border border-blue-200 dark:border-gray-600 rounded-lg">
                    <p class="font-bold text-gray-900 dark:text-gray-100">
                        لطفاً مبلغ <span class="font-mono">{{ $order->plan->price }}</span> تومان را به شماره کارت زیر واریز نمایید:
                    </p>


                    <p class="text-xl font-mono my-2 text-center text-blue-800 dark:text-blue-300 tracking-widest">
                        {{ $settings->get('payment_card_number') ?: 'شماره کارتی تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید.' }}
                    </p>


                    <p class="text-sm text-center text-gray-700 dark:text-gray-300">
                        به نام: {{ $settings->get('payment_card_holder_name') ?: '-' }}
                    </p>


                    @if($settings->get('payment_card_instructions'))
                        <p class="mt-2 text-xs text-center text-gray-600 dark:text-gray-400">
                            {!! nl2br(e($settings->get('payment_card_instructions'))) !!}
                        </p>
                    @endif
                </div>


                <form method="POST" action="{{ route('payment.card.submit', $order->id) }}" enctype="multipart/form-data">
                    @csrf
                    <div>
                        <x-input-label for="receipt" value="آپلود تصویر رسید پرداخت" class="mb-2" />


                        <input id="receipt"
                               class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400"
                               type="file"
                               name="receipt"
                               required
                               accept="image/*">

                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300" id="file_input_help">
                            فرمت‌های مجاز: PNG, JPG, GIF (حداکثر حجم: 2MB).
                        </p>


                        <x-input-error :messages="$errors->get('receipt')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-end mt-6">
                        <x-primary-button>
                            ثبت و ارسال رسید
                        </x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-app-layout>
