<div class="p-4">
    @if ($record->proof_image_path)
        <div class="flex flex-col items-center space-y-4">
            <img src="{{ asset('storage/' . $record->proof_image_path) }}" 
                 alt="رسید پرداخت" 
                 class="max-w-full rounded-lg shadow-lg"
                 onerror="this.onerror=null; this.src='{{ asset('images/no-image.png') }}';">
            
            <div class="text-sm text-gray-600 dark:text-gray-400">
                <p><strong>کاربر:</strong> {{ $record->user->name ?? $record->user->email }}</p>
                <p><strong>مبلغ:</strong> {{ number_format($record->amount) }} تومان</p>
                <p><strong>تاریخ:</strong> {{ $record->created_at->format('Y-m-d H:i') }}</p>
            </div>

            <a href="{{ asset('storage/' . $record->proof_image_path) }}" 
               target="_blank" 
               download
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                دانلود رسید
            </a>
        </div>
    @else
        <div class="text-center text-gray-500 py-8">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <p>هیچ رسیدی آپلود نشده است</p>
        </div>
    @endif
</div>
