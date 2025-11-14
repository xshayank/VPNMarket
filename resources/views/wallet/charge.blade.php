<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            شارژ کیف پول
        </h2>
    </x-slot>

    @php
        $cardToCardEnabled = $cardToCardEnabled ?? true;
        $availableMethods = $availableMethods ?? [];
        $tetraSettings = $tetraSettings ?? ['enabled' => false, 'min_amount' => 10000];
        $methodCount = count($availableMethods);
        $tetraMinAmount = (int) ($tetraSettings['min_amount'] ?? 10000);
        $tetraHasOldContext = old('tetra98_context');
        $tetraDefaultAmount = $tetraHasOldContext ? (int) old('amount', $tetraMinAmount) : $tetraMinAmount;
        $tetraDefaultPhone = $tetraHasOldContext ? old('phone', '') : '';
    @endphp

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white/50 dark:bg-gray-800/50 backdrop-blur-xl overflow-hidden shadow-2xl sm:rounded-2xl">
                <div
                    class="p-6 md:p-8 text-gray-900 dark:text-gray-100 text-right space-y-8"
                    x-data="walletChargePage({
                        starsefarMinAmount: {{ (int) ($starsefarSettings['min_amount'] ?? 25000) }},
                        starsefarEnabled: {{ $starsefarSettings['enabled'] ? 'true' : 'false' }},
                        cardEnabled: {{ $cardToCardEnabled ? 'true' : 'false' }},
                        tetraEnabled: {{ $tetraSettings['enabled'] ? 'true' : 'false' }},
                        tetraMinAmount: {{ $tetraMinAmount }},
                        tetraDefaultAmount: {{ $tetraDefaultAmount }},
                        tetraDefaultPhone: @json($tetraDefaultPhone),
                        csrfToken: '{{ csrf_token() }}',
                        defaultMethod: '{{ $cardToCardEnabled ? 'card' : ($starsefarSettings['enabled'] ? 'starsefar' : ($tetraSettings['enabled'] ? 'tetra98' : '')) }}',
                        availableMethods: @json($availableMethods),
                        methodLabels: @json([
                            'card' => 'کارت به کارت',
                            'starsefar' => 'درگاه پرداخت (استارز تلگرام)',
                            'tetra98' => 'درگاه پرداخت (Tetra98)'
                        ]),
                    })"
                    x-init="init()"
                >
                    {{-- نمایش موجودی فعلی --}}
                    <div class="text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">موجودی فعلی شما</p>
                        <p class="font-bold text-3xl text-green-500 mt-1">
                            {{ number_format($walletBalance ?? 0) }}
                            <span class="text-lg font-normal">تومان</span>
                        </p>
                        @if(isset($isResellerWallet) && $isResellerWallet)
                            <p class="text-xs text-gray-400 mt-1">موجودی کیف پول ریسلر</p>
                        @endif
                    </div>

                    {{-- انتخاب روش پرداخت --}}
                    <div class="space-y-3">
                        <h3 class="text-lg font-medium text-center">انتخاب روش پرداخت</h3>
                        @php
                            $gridColumnsClass = match (true) {
                                $methodCount >= 3 => 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
                                $methodCount === 2 => 'grid-cols-1 sm:grid-cols-2',
                                default => 'grid-cols-1',
                            };
                        @endphp

                        @if ($methodCount === 0)
                            <div class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4 text-center">
                                در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً دوباره تلاش کنید.
                            </div>
                        @else
                            <div class="grid gap-3 {{ $gridColumnsClass }}">
                                @if($cardToCardEnabled)
                                    <button
                                        type="button"
                                        @click="selectMethod('card')"
                                        :class="method === 'card' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-credit-card class="w-6 h-6" />
                                            <span class="font-semibold">کارت به کارت</span>
                                        </div>
                                    </button>
                                @endif

                                @if($starsefarSettings['enabled'])
                                    <button
                                        type="button"
                                        @click="selectMethod('starsefar')"
                                        :class="method === 'starsefar' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-sparkles class="w-6 h-6" />
                                            <span class="font-semibold">درگاه پرداخت (استارز تلگرام)</span>
                                        </div>
                                    </button>
                                @endif

                                @if($tetraSettings['enabled'])
                                    <button
                                        type="button"
                                        @click="selectMethod('tetra98')"
                                        :class="method === 'tetra98' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200'"
                                        class="p-4 rounded-xl transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div class="flex flex-col items-center space-y-1">
                                            <x-heroicon-o-device-phone-mobile class="w-6 h-6" />
                                            <span class="font-semibold">درگاه پرداخت (Tetra98)</span>
                                        </div>
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div
                        id="payment-method-error"
                        x-show="fallback.visible"
                        x-transition.opacity
                        x-cloak
                        class="bg-red-100 border border-red-300 text-red-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
                        role="alert"
                        aria-live="polite"
                    >
                        <div class="space-y-1 text-right">
                            <p class="font-semibold">عدم نمایش فرم پرداخت</p>
                            <p class="text-sm leading-relaxed" x-text="fallback.message"></p>
                            <template x-if="fallback.method">
                                <p class="text-xs text-red-700/80">
                                    روش انتخاب‌شده: <span x-text="getMethodLabel(fallback.method)"></span>
                                </p>
                            </template>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="retryRender()"
                                class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition"
                            >
                                تلاش مجدد
                            </button>
                        </div>
                    </div>

                    {{-- کارت به کارت --}}
                    @if($cardToCardEnabled)
                        <div
                            x-show="activeMethod === 'card'"
                            x-transition.opacity
                            x-cloak
                            x-init="$nextTick(() => registerSection('card', $el))"
                            data-method="card"
                            class="space-y-6 payment-method-section"
                        >
                            <h3 class="text-lg font-semibold text-center">پرداخت از طریق کارت به کارت</h3>

                        @if ($errors->any())
                            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif

                        @if($cardDetails['number'] || $cardDetails['holder'] || $cardDetails['instructions'])
                            <div class="bg-white/70 dark:bg-gray-900/40 border border-indigo-200 dark:border-indigo-500/30 rounded-xl p-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">اطلاعات کارت</span>
                                    <x-heroicon-o-shield-check class="w-5 h-5 text-indigo-500" />
                                </div>
                                @if($cardDetails['number'])
                                    <div class="font-mono text-lg tracking-widest text-center">{{ $cardDetails['number'] }}</div>
                                @endif
                                @if($cardDetails['holder'])
                                    <p class="text-sm text-center text-gray-600 dark:text-gray-300">نام صاحب حساب: {{ $cardDetails['holder'] }}</p>
                                @endif
                                @if($cardDetails['instructions'])
                                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{!! nl2br(e($cardDetails['instructions'])) !!}</p>
                                @endif
                            </div>
                        @endif

                        <form
                            method="POST"
                            action="{{ route('wallet.charge.create') }}"
                            enctype="multipart/form-data"
                            class="space-y-6"
                        >
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <button type="button" @click="cardAmount = 50000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۵۰,۰۰۰</button>
                                <button type="button" @click="cardAmount = 100000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۱۰۰,۰۰۰</button>
                                <button type="button" @click="cardAmount = 250000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۲۵۰,۰۰۰</button>
                            </div>

                            <div class="relative">
                                <label for="amount" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">یا مبلغ دلخواه را وارد کنید (تومان)</label>
                                <input
                                    id="amount"
                                    name="amount"
                                    x-model="cardAmount"
                                    type="number"
                                    class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="حداقل ۱۰,۰۰۰"
                                    min="10000"
                                    required
                                >
                            </div>

                            <div class="mt-4">
                                <label for="proof" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    آپلود رسید پرداخت <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="proof"
                                    name="proof"
                                    type="file"
                                    accept="image/*"
                                    required
                                    class="block w-full text-sm text-gray-500 dark:text-gray-400
                                        file:mr-4 file:py-2 file:px-4
                                        file:rounded-lg file:border-0
                                        file:text-sm file:font-semibold
                                        file:bg-indigo-50 file:text-indigo-700
                                        hover:file:bg-indigo-100
                                        dark:file:bg-gray-700 dark:file:text-gray-300
                                        dark:hover:file:bg-gray-600"
                                >
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">فرمت‌های مجاز: JPEG, PNG, WEBP, JPG - حداکثر 4 مگابایت</p>
                            </div>

                            <div>
                                <button type="submit" class="w-full flex items-center justify-center px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold rounded-lg shadow-lg hover:scale-105 transform transition-transform duration-300">
                                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                    <span>ثبت و ادامه جهت پرداخت</span>
                                </button>
                            </div>
                        </form>
                        </div>
                    @endif

                    {{-- درگاه استارز --}}
                    @if($starsefarSettings['enabled'])
                        @include('wallet.partials.starsefar-form', ['starsefarSettings' => $starsefarSettings])
                    @else
                        <div x-show="activeMethod === 'starsefar'" class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4" x-cloak>
                            درگاه استارز در حال حاضر غیر فعال است.
                        </div>
                    @endif

                    @if($tetraSettings['enabled'])
                        @include('wallet.partials.tetra98-form', [
                            'tetraSettings' => $tetraSettings,
                            'tetraMinAmount' => $tetraMinAmount,
                            'tetraHasOldContext' => $tetraHasOldContext,
                        ])
                    @else
                        <div x-show="activeMethod === 'tetra98'" class="bg-amber-100 border border-amber-300 text-amber-700 rounded-xl p-4" x-cloak>
                            درگاه Tetra98 در حال حاضر غیر فعال است.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        function walletChargePage(config) {
            return {
                method: config.defaultMethod || (config.cardEnabled ? 'card' : (config.starsefarEnabled ? 'starsefar' : (config.tetraEnabled ? 'tetra98' : null))),
                activeMethod: null,
                cardAmount: @json(old('amount', '')),
                starAmount: '',
                starPhone: '',
                tetraAmount: config.tetraDefaultAmount || '',
                tetraPhone: config.tetraDefaultPhone || '',
                starStatusMessage: '',
                starStatusType: 'info',
                isInitiating: false,
                pollTimer: null,
                availableMethods: Array.isArray(config.availableMethods) ? config.availableMethods.filter(Boolean) : [],
                methodLabels: config.methodLabels || {},
                fallback: {
                    visible: false,
                    message: '',
                    reason: null,
                    method: null,
                },
                sectionRegistry: {},
                pendingSectionChecks: {},
                selectionLog: [],
                init() {
                    if (this.cardAmount === null) {
                        this.cardAmount = '';
                    }
                    if (!this.starAmount && config.starsefarMinAmount) {
                        this.starAmount = config.starsefarMinAmount;
                    }
                    if (this.starPhone === null) {
                        this.starPhone = '';
                    }
                    if (!this.tetraAmount && config.tetraMinAmount) {
                        this.tetraAmount = config.tetraMinAmount;
                    }
                    if (!this.method) {
                        this.method = config.cardEnabled ? 'card' : (config.starsefarEnabled ? 'starsefar' : (config.tetraEnabled ? 'tetra98' : null));
                    }
                    this.$watch('method', (value) => {
                        this.logSelection(value, 'watch');
                        this.handleMethodChange(value);
                    });
                    this.$nextTick(() => {
                        this.ensureMethodSelected();
                    });
                },
                selectMethod(method) {
                    if (
                        (method === 'card' && !config.cardEnabled) ||
                        (method === 'starsefar' && !config.starsefarEnabled) ||
                        (method === 'tetra98' && !config.tetraEnabled)
                    ) {
                        return;
                    }
                    this.logSelection(method, 'select');
                    this.method = method;
                    if (method !== 'starsefar') {
                        this.clearStarsefarFeedback();
                    } else if (!this.starAmount || Number(this.starAmount) < config.starsefarMinAmount) {
                        this.starAmount = config.starsefarMinAmount;
                    }
                    if (method === 'tetra98' && (!this.tetraAmount || Number(this.tetraAmount) < config.tetraMinAmount)) {
                        this.tetraAmount = config.tetraMinAmount;
                    }
                    this.handleMethodChange(method);
                },
                clearStarsefarFeedback() {
                    this.starStatusMessage = '';
                    this.starStatusType = 'info';
                    if (this.pollTimer) {
                        clearInterval(this.pollTimer);
                        this.pollTimer = null;
                    }
                },
                ensureMethodSelected() {
                    if (!this.availableMethods.length) {
                        this.activeMethod = null;
                        this.showFallback('no_methods');
                        return;
                    }

                    if (!this.method || !this.availableMethods.includes(this.method)) {
                        const firstMethod = this.availableMethods[0];
                        if (this.method !== firstMethod) {
                            this.logSelection(firstMethod, 'auto-select');
                        }
                        this.method = firstMethod;
                    } else {
                        this.handleMethodChange(this.method);
                    }
                },
                handleMethodChange(method, options = {}) {
                    const { attempt = 0, skipLog = false } = options;
                    if (!method) {
                        this.activeMethod = null;
                        this.showFallback('none_selected');
                        return;
                    }

                    if (!this.availableMethods.includes(method)) {
                        this.activeMethod = null;
                        this.showFallback('unsupported', method);
                        return;
                    }

                    const section = this.sectionRegistry[method];

                    if (!section) {
                        this.showFallback(attempt > 0 ? 'missing_section' : 'loading', method);
                        this.scheduleSectionRetry(method, attempt);
                        if (!skipLog) {
                            this.logIssue('missing-section', { method, attempt });
                        }
                        return;
                    }

                    this.activeMethod = method;
                    this.hideFallback();
                },
                scheduleSectionRetry(method, attempt = 0) {
                    if (this.pendingSectionChecks[method]) {
                        clearTimeout(this.pendingSectionChecks[method]);
                    }

                    if (attempt >= 3) {
                        return;
                    }

                    this.pendingSectionChecks[method] = setTimeout(() => {
                        delete this.pendingSectionChecks[method];
                        if (this.method === method) {
                            this.handleMethodChange(method, { attempt: attempt + 1, skipLog: true });
                        }
                    }, 120);
                },
                registerSection(method, element) {
                    if (!method || !element) {
                        return;
                    }

                    this.sectionRegistry[method] = element;

                    if (this.pendingSectionChecks[method]) {
                        clearTimeout(this.pendingSectionChecks[method]);
                        delete this.pendingSectionChecks[method];
                    }

                    if (this.method === method) {
                        this.handleMethodChange(method, { skipLog: true });
                    }
                },
                showFallback(reason, method = null) {
                    const message = this.getFallbackMessage(reason, method);
                    this.fallback = {
                        visible: true,
                        message,
                        reason,
                        method,
                    };
                    this.logIssue('fallback-visible', { reason, method });
                },
                hideFallback() {
                    this.fallback = {
                        visible: false,
                        message: '',
                        reason: null,
                        method: null,
                    };
                },
                retryRender() {
                    if (!this.method) {
                        this.ensureMethodSelected();
                        return;
                    }

                    this.handleMethodChange(this.method, { attempt: 0 });
                },
                getFallbackMessage(reason, method = null) {
                    const label = method ? this.getMethodLabel(method) : null;
                    switch (reason) {
                        case 'no_methods':
                            return 'هیچ روش پرداخت فعالی در دسترس نیست. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.';
                        case 'none_selected':
                            return 'برای ادامه، لطفاً یکی از روش‌های پرداخت را انتخاب کنید.';
                        case 'unsupported':
                            return label
                                ? `نمایش فرم مربوط به روش «${label}» امکان‌پذیر نیست. لطفاً روش دیگری را انتخاب کنید یا دوباره تلاش کنید.`
                                : 'نمایش فرم روش انتخاب‌شده امکان‌پذیر نیست. لطفاً روش دیگری را انتخاب کنید یا دوباره تلاش کنید.';
                        case 'missing_section':
                            return label
                                ? `فرم مربوط به روش «${label}» بارگذاری نشد. روی «تلاش مجدد» کلیک کنید یا روش دیگری را انتخاب کنید.`
                                : 'فرم مربوط به روش انتخاب‌شده بارگذاری نشد. روی «تلاش مجدد» کلیک کنید یا روش دیگری را انتخاب کنید.';
                        case 'loading':
                            return label
                                ? `در حال آماده‌سازی فرم «${label}» هستیم. در صورت عدم نمایش، روی «تلاش مجدد» کلیک کنید.`
                                : 'در حال آماده‌سازی فرم پرداخت هستیم. در صورت عدم نمایش، روی «تلاش مجدد» کلیک کنید.';
                        default:
                            return 'خطایی در نمایش فرم پرداخت رخ داد. لطفاً دوباره تلاش کنید یا روش دیگری را انتخاب کنید.';
                    }
                },
                getMethodLabel(method) {
                    return this.methodLabels?.[method] || method || '';
                },
                logSelection(method, source) {
                    if (!method) {
                        return;
                    }
                    const entry = {
                        method,
                        source,
                        timestamp: Date.now(),
                        available: this.availableMethods,
                    };
                    this.selectionLog.push(entry);
                    if (this.selectionLog.length > 25) {
                        this.selectionLog.shift();
                    }
                    if (typeof window !== 'undefined' && window.console) {
                        window.console.debug?.('[wallet-charge] method-select', entry);
                    }
                },
                logIssue(type, context = {}) {
                    if (typeof window !== 'undefined' && window.console) {
                        window.console.error?.('[wallet-charge] ' + type, {
                            ...context,
                            activeMethod: this.activeMethod,
                            method: this.method,
                            availableMethods: this.availableMethods,
                        });
                    }
                },
                async initiateStarsefar() {
                    this.clearStarsefarFeedback();
                    this.isInitiating = true;

                    try {
                        const response = await fetch('{{ route('wallet.charge.starsefar.initiate') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': config.csrfToken,
                            },
                            body: JSON.stringify({
                                amount: this.starAmount,
                                phone: this.starPhone || null,
                            }),
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || 'خطا در ایجاد لینک پرداخت');
                        }

                        window.open(data.link, '_blank');
                        this.starStatusType = 'info';
                        this.starStatusMessage = 'لینک پرداخت در پنجره جدید باز شد. لطفاً پرداخت را تکمیل کنید.';
                        this.pollStatus(data.statusEndpoint);
                    } catch (error) {
                        this.starStatusType = 'error';
                        this.starStatusMessage = error.message || 'خطایی رخ داد. دوباره تلاش کنید.';
                    } finally {
                        this.isInitiating = false;
                    }
                },
                pollStatus(url) {
                    if (this.pollTimer) {
                        clearInterval(this.pollTimer);
                    }

                    const checkStatus = async () => {
                        try {
                            const response = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json',
                                },
                            });

                            if (!response.ok) {
                                return;
                            }

                            const data = await response.json();
                            if (data.status === 'paid') {
                                this.starStatusType = 'success';
                                this.starStatusMessage = 'پرداخت با موفقیت تایید شد و کیف پول شما شارژ گردید.';
                                clearInterval(this.pollTimer);
                                this.pollTimer = null;
                            }
                        } catch (error) {
                            console.error('poll error', error);
                        }
                    };

                    checkStatus();
                    this.pollTimer = setInterval(checkStatus, 5000);
                },
            };
        }
    </script>
</x-app-layout>
