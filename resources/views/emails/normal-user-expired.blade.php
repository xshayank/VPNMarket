<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #e74c3c; margin-bottom: 20px; }
        .info { background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-right: 4px solid #ffc107; }
        .cta { margin: 30px 0; text-align: center; }
        .button { display: inline-block; padding: 12px 30px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔴 اشتراک VPN شما منقضی شده است</h1>
        
        <p>سلام {{ $user->name }} عزیز،</p>
        
        <p>اشتراک VPN شما با مشخصات زیر منقضی شده است:</p>
        
        <div class="info">
            <strong>پلن:</strong> {{ $planName }}<br>
            <strong>تاریخ انقضا:</strong> {{ $expiresAt }}
        </div>
        
        <p>برای ادامه استفاده از خدمات VPN، لطفاً اشتراک خود را تمدید کنید.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/dashboard" class="button">تمدید اشتراک</a>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

        <h1>🔴 Your VPN Subscription Has Expired</h1>
        
        <p>Hello {{ $user->name }},</p>
        
        <p>Your VPN subscription with the following details has expired:</p>
        
        <div class="info">
            <strong>Plan:</strong> {{ $planName }}<br>
            <strong>Expiry Date:</strong> {{ $expiresAt }}
        </div>
        
        <p>To continue using VPN services, please renew your subscription.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/dashboard" class="button">Renew Subscription</a>
        </div>
        
        <div class="footer">
            <p>این ایمیل به صورت خودکار ارسال شده است / This email was sent automatically</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </div>
    </div>
</body>
</html>
