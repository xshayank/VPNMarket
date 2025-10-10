<x-guest-layout>
    @php
        $settings = \App\Models\Setting::all()->pluck('value', 'key');
    @endphp
    <div class="auth-card">
        <div class="auth-logo">{{ $settings->get('auth_brand_name') ?: 'VPNMarket' }}</div>
        <h2 class="auth-title">{{ $settings->get('auth_register_title') ?: 'ایجاد حساب کاربری جدید' }}</h2>

        <form method="POST" action="{{ route('register') }}">
            @csrf
            <div class="input-group">
                <input id="name" class="input-field" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="{{ $settings->get('auth_register_name_placeholder') ?: 'نام کامل' }}">
                <x-input-error :messages="$errors->get('name')" class="mt-2 text-danger small" />
            </div>
            <div class="input-group">
                <input id="email" class="input-field" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="{{ $settings->get('auth_login_email_placeholder') ?: 'ایمیل' }}">
                <x-input-error :messages="$errors->get('email')" class="mt-2 text-danger small" />
            </div>
            <div class="input-group">
                <input id="password" class="input-field" type="password" name="password" required autocomplete="new-password" placeholder="{{ $settings->get('auth_login_password_placeholder') ?: 'رمز عبور' }}">
                <x-input-error :messages="$errors->get('password')" class="mt-2 text-danger small" />
            </div>
            <div class="input-group">
                <input id="password_confirmation" class="input-field" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="{{ $settings->get('auth_register_password_confirm_placeholder') ?: 'تکرار رمز عبور' }}">
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2 text-danger small" />
            </div>
            <div class="input-group mt-4">
                <button type="submit" class="btn-submit">
                    {{ $settings->get('auth_register_submit_button') ?: 'ثبت نام' }}
                </button>
            </div>
            <hr class="separator">
            <div class="register-link">
                {!! $settings->get('auth_register_login_link') ?: 'قبلاً ثبت‌نام کرده‌اید؟ <a class="auth-link fw-bold" href="'.route('login').'">وارد شوید</a>' !!}
            </div>
        </form>
    </div>
</x-guest-layout>


