<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} - ثبت نام</title>

    <link rel="stylesheet" href="{{ asset('themes/auth/dragon/css/style.css') }}">
</head>
<body class="dragon-auth-body">

<div class="embers-container">
    @for ($i = 0; $i < 20; $i++)
        <div class="ember"></div>
    @endfor
</div>

<div class="auth-card">
    <div class="auth-logo">ARV</div>
    <h2 class="auth-title">ایجاد حساب کاربری جدید</h2>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        @if(request()->has('ref'))
            <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
        @endif
        {{-- ========================================================== --}}

        <div class="input-group">
            <input id="name" class="input-field" type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="نام کامل">
            {{-- Note: x-input-error component might not work here if this isn't a standard blade component view --}}
        </div>

        <div class="input-group">
            <input id="email" class="input-field" type="email" name="email" value="{{ old('email') }}" required placeholder="ایمیل">
        </div>

        <div class="input-group">
            <input id="password" class="input-field" type="password" name="password" required placeholder="رمز عبور">
        </div>

        <div class="input-group">
            <input id="password_confirmation" class="input-field" type="password" name="password_confirmation" required placeholder="تکرار رمز عبور">
        </div>

        {{-- Display errors if any --}}
        @if($errors->any())
            <div class="mt-2 text-danger small" style="color: #ff7675; font-size: 0.8rem;">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="input-group mt-4">
            <button type="submit" class="btn-submit">ثبت نام</button>
        </div>

        <hr class="separator">

        <div class="register-link">
            قبلاً ثبت‌نام کرده‌اید؟ <a class="auth-link" href="{{ route('login') }}">وارد شوید</a>
        </div>
    </form>
</div>
</body>
</html>
