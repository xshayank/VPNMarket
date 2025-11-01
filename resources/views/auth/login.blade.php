<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} - ورود</title>

    <link rel="stylesheet" href="{{ asset('themes/auth/modern/style.css') }}">
    <style>
        /* Floating particles */
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: var(--particle-color-1);
            border-radius: 50%;
            pointer-events: none;
            opacity: 0;
        }
        
        .particle:nth-child(2n) { background: var(--particle-color-2); }
        .particle:nth-child(3n) { background: var(--particle-color-3); }
        
        .particle:nth-child(1) { left: 10%; animation: float 8s ease-in-out infinite; animation-delay: 0s; }
        .particle:nth-child(2) { left: 20%; animation: floatReverse 10s ease-in-out infinite; animation-delay: 1s; }
        .particle:nth-child(3) { left: 30%; animation: float 12s ease-in-out infinite; animation-delay: 2s; }
        .particle:nth-child(4) { left: 40%; animation: floatReverse 9s ease-in-out infinite; animation-delay: 1.5s; }
        .particle:nth-child(5) { left: 50%; animation: float 11s ease-in-out infinite; animation-delay: 0.5s; }
        .particle:nth-child(6) { left: 60%; animation: floatReverse 13s ease-in-out infinite; animation-delay: 2.5s; }
        .particle:nth-child(7) { left: 70%; animation: float 10s ease-in-out infinite; animation-delay: 1s; }
        .particle:nth-child(8) { left: 80%; animation: floatReverse 14s ease-in-out infinite; animation-delay: 3s; }
        .particle:nth-child(9) { left: 90%; animation: float 9s ease-in-out infinite; animation-delay: 0.8s; }
        .particle:nth-child(10) { left: 15%; animation: floatReverse 11s ease-in-out infinite; animation-delay: 2s; }
    </style>
</head>
<body class="modern-auth-body">

<!-- Floating particles -->
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>
<div class="particle"></div>

<div class="auth-card">

    <div class="auth-logo">{{ $settings->get('auth_brand_name', 'ARV') }}</div>

    <h2 class="auth-title">ورود به حساب کاربری</h2>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="input-group">
            <input id="email" class="input-field" type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="ایمیل خود را وارد کنید">
            <x-input-error :messages="$errors->get('email')" class="input-error-message" />
        </div>

        <div class="input-group">
            <input id="password" class="input-field" type="password" name="password" required autocomplete="current-password" placeholder="رمز عبور">
            <x-input-error :messages="$errors->get('password')" class="input-error-message" />
        </div>

        <div class="form-row">
            <label for="remember_me" class="remember-me">
                <input id="remember_me" type="checkbox" name="remember">
                <span>مرا به خاطر بسپار</span>
            </label>
        </div>

        <div class="input-group">
            <button type="submit" class="btn-submit">ورود</button>
        </div>

        <hr class="separator">

        <div class="register-link">
            حساب کاربری ندارید؟ <a class="auth-link" href="{{ route('register') }}">یک حساب بسازید</a>
        </div>
    </form>
</div>
</body>
</html>
