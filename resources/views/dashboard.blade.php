<x-app-layout>
    <!-- Powered by VPNMarket CMS | v1.0 -->

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('داشبورد کاربری') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('status') }}</span>
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <div x-data="{ tab: 'my_services' }" class="bg-white/70 dark:bg-gray-900/70 rounded-2xl shadow-lg backdrop-blur-md p-4 sm:p-6">
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="-mb-px flex space-x-4 space-x-reverse px-4 sm:px-8" aria-label="Tabs">
                        <button @click="tab = 'my_services'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'my_services', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'my_services'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            سرویس‌های من
                        </button>
                        <button @click="tab = 'new_service'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'new_service', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'new_service'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            خرید سرویس جدید
                        </button>
                        <button @click="tab = 'tutorials'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'tutorials', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'tutorials'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                            راهنمای اتصال
                        </button>
                        @if (Module::isEnabled('Ticketing'))
                            <button @click="tab = 'support'" :class="{'border-indigo-500 text-indigo-600 dark:text-indigo-400': tab === 'support', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200': tab !== 'support'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                                پشتیبانی
                            </button>
                        @endif


                    </nav>
                </div>

                <div class="p-2 sm:p-4">
                    <div x-show="tab === 'my_services'" x-transition.opacity>
                        @if($orders->isNotEmpty())
                            <div class="space-y-4">
                                @foreach ($orders as $order)
                                    <div class="p-5 rounded-xl bg-gray-50 dark:bg-gray-800/50 shadow-md transition-shadow hover:shadow-lg" x-data="{ open: false }">
                                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 items-center text-right">
                                            <div>
                                                <span class="text-xs text-gray-500">پلن</span>
                                                <p class="font-bold text-gray-900 dark:text-white">{{ $order->plan->name }}</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">حجم</span>
                                                <p class="font-bold text-gray-900 dark:text-white">{{ $order->plan->volume_gb }} GB</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">وضعیت</span>
                                                <p class="font-semibold {{ $order->status == 'paid' ? 'text-green-500' : 'text-yellow-500' }}">{{ $order->status == 'paid' ? 'فعال' : 'در انتظار پرداخت' }}</p>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500">تاریخ انقضا</span>
                                                <p class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ $order->expires_at ? \Carbon\Carbon::parse($order->expires_at)->format('Y-m-d') : '-' }}</p>
                                            </div>
                                            <div class="text-left">
                                                @if($order->status == 'paid' && $order->config_details)
                                                    <div class="flex items-center justify-end space-x-2 space-x-reverse">
                                                        <form method="POST" action="{{ route('order.renew', $order->id) }}">
                                                            @csrf
                                                            <button type="submit" class="px-3 py-2 bg-yellow-500 text-white text-xs rounded-lg hover:bg-yellow-600 focus:outline-none" title="تمدید سرویس">
                                                                تمدید
                                                            </button>
                                                        </form>
                                                        <button @click="open = !open" class="px-3 py-2 bg-gray-700 text-white text-xs rounded-lg hover:bg-gray-600 focus:outline-none">
                                                            <span x-show="!open">کانفیگ</span>
                                                            <span x-show="open">بستن</span>
                                                        </button>
                                                    </div>
                                                @elseif($order->status == 'pending')
                                                    <a href="{{ route('order.show', $order->id) }}" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">پرداخت</a>
                                                @endif
                                            </div>
                                        </div>
                                        <div x-show="open" x-transition x-cloak class="mt-4 pt-4 border-t dark:border-gray-700">
                                            <h4 class="font-bold mb-2 text-gray-900 dark:text-white text-right">اطلاعات سرویس:</h4>
                                            <div class="p-3 bg-gray-100 dark:bg-gray-900 rounded-lg relative" x-data="{copied: false, copyToClipboard(text) { navigator.clipboard.writeText(text); this.copied = true; setTimeout(() => { this.copied = false }, 2000); }}">
                                                <pre class="text-left text-sm text-gray-800 dark:text-gray-300 whitespace-pre-wrap" dir="ltr">{{ $order->config_details }}</pre>
                                                <button @click="copyToClipboard(`{{ $order->config_details }}`)" class="absolute top-2 right-2 px-2 py-1 text-xs bg-gray-300 dark:bg-gray-700 rounded hover:bg-gray-400"><span x-show="!copied">کپی</span><span x-show="copied" class="text-green-500 font-bold">کپی شد!</span></button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-gray-500 dark:text-gray-400 text-center py-10">🚀 شما هنوز هیچ سرویسی خریداری نکرده‌اید.</p>
                        @endif
                    </div>
                    {{-- تب خرید سرویس جدید --}}
                    <div x-show="tab === 'new_service'" x-transition.opacity x-cloak>
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white text-right">خرید سرویس جدید</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($plans as $plan)
                                <div class="p-6 rounded-xl bg-white dark:bg-gray-800 shadow-lg hover:shadow-blue-500/20 hover:-translate-y-1 transition-all text-right">
                                    <h3 class="font-bold text-lg text-gray-900 dark:text-white">{{ $plan->name }}</h3>
                                    <p class="text-3xl font-bold my-3 text-gray-900 dark:text-white">{{ $plan->price }} <span class="text-base font-normal text-gray-500 dark:text-gray-400">{{ $plan->currency }}</span></p>
                                    <ul class="text-sm space-y-2 text-gray-600 dark:text-gray-300 my-4">
                                        @foreach(explode("\n", $plan->features) as $feature)
                                            <li class="flex items-start"><svg class="w-4 h-4 text-green-500 ml-2 shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg><span>{{ trim($feature) }}</span></li>
                                        @endforeach
                                    </ul>
                                    <form method="POST" action="{{ route('order.store', $plan->id) }}" class="mt-6">
                                        @csrf
                                        <button type="submit" class="w-full px-4 py-3 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 transition">خرید این پلن</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- تب راهنمای اتصال --}}
                    <div x-show="tab === 'tutorials'" x-transition.opacity x-cloak class="text-right">
                        <h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white">راهنمای استفاده از سرویس‌ها</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 mb-6">برای استفاده از کانفیگ‌ها، ابتدا باید نرم‌افزار V2Ray-Client مناسب دستگاه خود را نصب کنید.</p>

                        <div class="space-y-6" x-data="{ app: 'android' }">
                            <div class="flex justify-center p-1 bg-gray-200 dark:bg-gray-800 rounded-xl">
                                <button @click="app = 'android'" :class="app === 'android' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">اندروید</button>
                                <button @click="app = 'ios'" :class="app === 'ios' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">آیفون (iOS)</button>
                                <button @click="app = 'windows'" :class="app === 'windows' ? 'bg-white dark:bg-gray-700 shadow' : ''" class="w-full text-center py-2 px-4 rounded-lg transition">ویندوز</button>
                            </div>

                            <div x-show="app === 'android'" class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای اندروید (V2RayNG)</h3>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا نرم‌افزار <a href="https://github.com/2dust/v2rayNG/releases" target="_blank" class="text-blue-500 hover:underline">V2RayNG</a> را از این لینک دانلود و نصب کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>وارد برنامه V2RayNG شوید و روی علامت بعلاوه (+) در بالای صفحه ضربه بزنید.</li>
                                    <li>گزینه `Import config from Clipboard` را انتخاب کنید.</li>
                                    <li>برای اتصال، روی دایره خاکستری در پایین صفحه ضربه بزنید تا سبز شود.</li>
                                </ol>
                            </div>

                            <div x-show="app === 'ios'" x-cloak class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای آیفون (Streisand / V2Box)</h3>
                                <p class="mb-2 text-sm">برای iOS می‌توانید از چندین برنامه استفاده کنید. ما V2Box را پیشنهاد می‌کنیم.</p>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا یکی از نرم‌افزارهای <a href="https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690" target="_blank" class="text-blue-500 hover:underline">V2Box</a> یا <a href="https://apps.apple.com/us/app/streisand/id6450534064" target="_blank" class="text-blue-500 hover:underline">Streisand</a> را از اپ استور نصب کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>وارد برنامه شده، به بخش کانفیگ‌ها (Configs) بروید.</li>
                                    <li>روی علامت بعلاوه (+) بزنید و گزینه `Import from Clipboard` را انتخاب کنید.</li>
                                    <li>برای اتصال، سرویس اضافه شده را انتخاب و آن را فعال کنید.</li>
                                </ol>
                            </div>

                            <div x-show="app === 'windows'" x-cloak class="p-6 bg-gray-50 dark:bg-gray-800/50 rounded-xl animate-fadeIn">
                                <h3 class="font-bold text-lg mb-3">راهنمای ویندوز (V2RayN)</h3>
                                <ol class="list-decimal list-inside space-y-2 text-gray-700 dark:text-gray-300">
                                    <li>ابتدا نرم‌افزار <a href="https://github.com/2dust/v2rayN/releases" target="_blank" class="text-blue-500 hover:underline">V2RayN</a> را از این لینک دانلود و از حالت فشرده خارج کنید.</li>
                                    <li>در تب "سرویس‌های من"، روی دکمه "مشاهده کانفیگ" کلیک کرده و سپس دکمه "کپی" را بزنید.</li>
                                    <li>در برنامه V2RayN، کلیدهای `Ctrl+V` را فشار دهید تا کانفیگ به صورت خودکار اضافه شود.</li>
                                    <li>روی آیکون برنامه در تسک‌بار راست کلیک کرده، از منوی `System proxy` گزینه `Set system proxy` را انتخاب کنید.</li>
                                    <li>برای اتصال، سرور اضافه شده را انتخاب کرده و کلید `Enter` را بزنید.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    {{-- تب پشتیبانی --}}
                    @if (Module::isEnabled('Ticketing'))
                        <div x-show="tab === 'support'" x-transition.opacity x-cloak>
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold text-gray-900 dark:text-white text-right">تیکت‌های پشتیبانی</h2>
                                <a href="{{ route('tickets.create') }}" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">ارسال تیکت جدید</a>
                            </div>

                            <div class="space-y-4">
                                @forelse ($tickets as $ticket)
                                    <a href="{{ route('tickets.show', $ticket->id) }}" class="block p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                        <div class="flex justify-between items-center">
                                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $ticket->subject }}</p>
                                            <span class="text-xs font-mono text-gray-500">{{ $ticket->created_at->format('Y-m-d') }}</span>
                                        </div>
                                        <div class="mt-2 flex justify-between items-center">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">آخرین بروزرسانی: {{ $ticket->updated_at->diffForHumans() }}</span>
                                            <span class="text-xs px-2 py-1 rounded-full
                                                @switch($ticket->status)
                                                    @case('open') bg-blue-100 text-blue-800 @break
                                                    @case('answered') bg-green-100 text-green-800 @break
                                                    @case('closed') bg-gray-200 text-gray-700 @break
                                                @endswitch">
                                                {{ $ticket->status == 'open' ? 'باز' : ($ticket->status == 'answered' ? 'پاسخ داده شده' : 'بسته شده') }}
                                            </span>
                                        </div>
                                    </a>
                                @empty
                                    <p class="text-gray-500 dark:text-gray-400 text-center py-10">هیچ تیکتی یافت نشد.</p>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
