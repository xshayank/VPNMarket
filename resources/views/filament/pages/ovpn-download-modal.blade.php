<div class="space-y-4">
    <div class="text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            برای دانلود فایل .ovpn می‌توانید از لینک زیر استفاده کنید یا QR code را اسکن نمایید
        </p>
        
        <!-- Download Button -->
        <div class="mb-4">
            <a href="{{ $downloadUrl }}" 
               class="inline-flex items-center px-4 py-2 bg-primary-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-700 focus:bg-primary-700 active:bg-primary-900 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition ease-in-out duration-150"
               download="{{ $username }}.ovpn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                دانلود فایل .ovpn
            </a>
        </div>

        <!-- QR Code -->
        <div class="flex justify-center mb-4">
            <div id="qrcode" class="inline-block p-4 bg-white rounded-lg shadow-md"></div>
        </div>

        <!-- Download URL (for copy) -->
        <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                لینک دانلود:
            </label>
            <div class="flex items-center space-x-2 rtl:space-x-reverse">
                <input type="text" 
                       value="{{ $downloadUrl }}" 
                       readonly
                       class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                       id="download-url-input">
                <button type="button"
                        onclick="copyToClipboard()"
                        class="px-3 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Load QR code library from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    // Generate QR code
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof QRCode !== 'undefined') {
            new QRCode(document.getElementById("qrcode"), {
                text: "{{ $downloadUrl }}",
                width: 200,
                height: 200,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
        }
    });

    // Copy to clipboard function
    function copyToClipboard() {
        const input = document.getElementById('download-url-input');
        input.select();
        input.setSelectionRange(0, 99999); // For mobile devices
        
        try {
            document.execCommand('copy');
            // Show success message
            alert('لینک کپی شد!');
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    }
</script>
