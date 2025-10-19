<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #ff5722; margin-bottom: 20px; }
        .info { background-color: #ffebee; padding: 15px; border-radius: 5px; margin: 20px 0; border-right: 4px solid #ff5722; }
        .cta { margin: 30px 0; text-align: center; }
        .button { display: inline-block; padding: 12px 30px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ هشدار محدودیت پنل ریسلر</h1>
        
        <p>سلام {{ $reseller->user->name }} عزیز،</p>
        
        <p>پنل ریسلر شما در شرف رسیدن به محدودیت است:</p>
        
        <div class="info">
            <strong>نام کاربری:</strong> {{ $reseller->username_prefix }}<br>
            
            @if($daysRemaining !== null)
            <strong>⏰ روزهای باقیمانده:</strong> {{ $daysRemaining }} روز<br>
            <strong>تاریخ پایان:</strong> {{ $reseller->window_ends_at->format('Y-m-d H:i') }}<br>
            @endif
            
            @if($trafficRemainingPercent !== null)
            <strong>📊 ترافیک باقیمانده:</strong> {{ number_format($trafficRemainingPercent, 1) }}%<br>
            <strong>ترافیک استفاده شده:</strong> {{ number_format($reseller->traffic_used_bytes / (1024**3), 2) }} GB از {{ number_format($reseller->traffic_total_bytes / (1024**3), 2) }} GB
            @endif
        </div>
        
        <p>برای جلوگیری از قطع سرویس، لطفاً هرچه زودتر با پشتیبانی تماس بگیرید.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/reseller/dashboard" class="button">مشاهده پنل ریسلر</a>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

        <h1>⚠️ Reseller Panel Limit Warning</h1>
        
        <p>Hello {{ $reseller->user->name }},</p>
        
        <p>Your reseller panel is approaching its limits:</p>
        
        <div class="info">
            <strong>Username:</strong> {{ $reseller->username_prefix }}<br>
            
            @if($daysRemaining !== null)
            <strong>⏰ Days Remaining:</strong> {{ $daysRemaining }} days<br>
            <strong>End Date:</strong> {{ $reseller->window_ends_at->format('Y-m-d H:i') }}<br>
            @endif
            
            @if($trafficRemainingPercent !== null)
            <strong>📊 Traffic Remaining:</strong> {{ number_format($trafficRemainingPercent, 1) }}%<br>
            <strong>Traffic Used:</strong> {{ number_format($reseller->traffic_used_bytes / (1024**3), 2) }} GB of {{ number_format($reseller->traffic_total_bytes / (1024**3), 2) }} GB
            @endif
        </div>
        
        <p>To prevent service interruption, please contact support as soon as possible.</p>
        
        <div class="cta">
            <a href="{{ config('app.url') }}/reseller/dashboard" class="button">View Reseller Panel</a>
        </div>
        
        <div class="footer">
            <p>این ایمیل به صورت خودکار ارسال شده است / This email was sent automatically</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </div>
    </div>
</body>
</html>
