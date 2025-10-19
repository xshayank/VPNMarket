<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #ff9800; margin-bottom: 20px; }
        .info { background-color: #fff3e0; padding: 15px; border-radius: 5px; margin: 20px 0; border-right: 4px solid #ff9800; }
        .warning { background-color: #ffe0b2; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .cta { margin: 30px 0; text-align: center; }
        .button { display: inline-block; padding: 12px 30px; background-color: #28a745; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⏰ یادآوری تمدید اشتراک</h1>
        
        <p>سلام {{ $user->name }} عزیز،</p>
        
        <p>اشتراک VPN شما در {{ $daysRemaining }} روز آینده منقضی می‌شود:</p>
        
        <div class="info">
            <strong>پلن:</strong> {{ $planName }}<br>
            <strong>تاریخ انقضا:</strong> {{ $expiresAt }}<br>
            <strong>روزهای باقیمانده:</strong> {{ $daysRemaining }} روز
        </div>
        
        @if($user->balance < 10000)
        <div class="warning">
            <strong>⚠️ توجه:</strong> موجودی کیف پول شما کم است ({{ number_format($user->balance) }} تومان). لطفاً قبل از تمدید، کیف پول خود را شارژ کنید.
        </div>
        @endif
        
        <p>برای اطمینان از عدم قطع سرویس، لطفاً اشتراک خود را هرچه زودتر تمدید کنید.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/wallet" class="button">شارژ کیف پول</a>
            <a href="{{ config('app.url') }}/dashboard" class="button" style="background-color: #007bff;">تمدید اشتراک</a>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

        <h1>⏰ Subscription Renewal Reminder</h1>
        
        <p>Hello {{ $user->name }},</p>
        
        <p>Your VPN subscription will expire in {{ $daysRemaining }} days:</p>
        
        <div class="info">
            <strong>Plan:</strong> {{ $planName }}<br>
            <strong>Expiry Date:</strong> {{ $expiresAt }}<br>
            <strong>Days Remaining:</strong> {{ $daysRemaining }} days
        </div>
        
        @if($user->balance < 10000)
        <div class="warning">
            <strong>⚠️ Notice:</strong> Your wallet balance is low ({{ number_format($user->balance) }} Toman). Please charge your wallet before renewal.
        </div>
        @endif
        
        <p>To ensure uninterrupted service, please renew your subscription as soon as possible.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/wallet" class="button">Charge Wallet</a>
            <a href="{{ config('app.url') }}/dashboard" class="button" style="background-color: #007bff;">Renew Subscription</a>
        </div>
        
        <div class="footer">
            <p>این ایمیل به صورت خودکار ارسال شده است / This email was sent automatically</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </div>
    </div>
</body>
</html>
