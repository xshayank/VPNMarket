<div>
    @if ($getState())
        <div class="flex flex-col items-center space-y-2">
            <img src="{{ asset('storage/' . $getState()) }}" 
                 alt="رسید پرداخت" 
                 class="max-w-md rounded-lg shadow-lg"
                 onerror="this.onerror=null; this.src='{{ asset('images/no-image.png') }}';">
            <a href="{{ asset('storage/' . $getState()) }}" 
               target="_blank" 
               class="text-blue-600 hover:underline">
                مشاهده در اندازه کامل
            </a>
        </div>
    @else
        <div class="text-center text-gray-500 py-4">
            هیچ رسیدی آپلود نشده است
        </div>
    @endif
</div>
