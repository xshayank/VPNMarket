<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('ایجاد کانفیگ جدید') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-3xl mx-auto px-3 sm:px-6 lg:px-8">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.configs.index')" />
            
            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-3 md:p-6 text-right">
                <form action="{{ route('reseller.configs.store') }}" method="POST">
                    @csrf

                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">انتخاب پنل</label>
                        <select name="panel_id" id="panel_id" required class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base">
                            <option value="">-- انتخاب کنید --</option>
                            @foreach ($panels as $panel)
                                <option value="{{ $panel->id }}" data-panel-type="{{ $panel->panel_type }}">{{ $panel->name }} ({{ $panel->panel_type }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div class="mb-4 md:mb-0">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">محدودیت ترافیک (GB)</label>
                            <input type="number" name="traffic_limit_gb" step="0.1" min="0.1" required 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: 10">
                        </div>

                        <div class="mb-4 md:mb-0">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">مدت اعتبار (روز)</label>
                            <input type="number" name="expires_days" min="1" required 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: 30">
                        </div>
                    </div>

                    <!-- Connections field for Eylandoo -->
                    <div id="connections_field" class="mb-4 md:mb-6" style="display: none;">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                            تعداد اتصالات همزمان
                            <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="connections" id="connections_input" min="1" max="10" value="1"
                            class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                            placeholder="مثال: 2">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">تعداد دستگاه‌هایی که می‌توانند به طور همزمان متصل شوند (فقط برای پنل Eylandoo)</p>
                    </div>

                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">توضیحات (اختیاری - حداکثر 200 کاراکتر)</label>
                        <input type="text" name="comment" maxlength="200" 
                            class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                            placeholder="توضیحات کوتاه درباره این کانفیگ">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">می‌توانید توضیحات کوتاهی برای شناسایی بهتر این کانفیگ وارد کنید</p>
                    </div>

                    @can('configs.set_prefix')
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">پیشوند سفارشی (اختیاری)</label>
                            <input type="text" name="prefix" maxlength="50" 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: myprefix"
                                pattern="[a-zA-Z0-9_-]+">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">نام نهایی: prefix_resellerId_cfg_configId (فقط حروف انگلیسی، اعداد، خط تیره و زیرخط مجاز است)</p>
                        </div>
                    @endcan

                    @can('configs.set_custom_name')
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">نام سفارشی کامل (اختیاری)</label>
                            <input type="text" name="custom_name" maxlength="100" 
                                class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                                placeholder="مثال: custom_username"
                                pattern="[a-zA-Z0-9_-]+">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">این نام به طور کامل جایگزین نام خودکار می‌شود (فقط حروف انگلیسی، اعداد، خط تیره و زیرخط مجاز است)</p>
                        </div>
                    @endcan

                    @if (count($marzneshin_services) > 0)
                        <div class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">سرویس‌های Marzneshin (اختیاری)</label>
                            <div class="space-y-3">
                                @foreach ($marzneshin_services as $serviceId)
                                    <label class="flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0">
                                        <input type="checkbox" name="service_ids[]" value="{{ $serviceId }}" 
                                            class="w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                        <span>Service ID: {{ $serviceId }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Eylandoo Nodes selection -->
                    {{-- This field is shown/hidden dynamically via JavaScript based on selected panel type.
                         Server-side flag: showNodesSelector = {{ $showNodesSelector ? 'true' : 'false' }}
                         When panel_type === 'eylandoo', the field appears even if no nodes are available. --}}
                    <div id="eylandoo_nodes_field" class="mb-4 md:mb-6" style="display: none;">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                            نودهای Eylandoo (اختیاری)
                        </label>
                        <div class="space-y-3" id="eylandoo_nodes_container">
                            <!-- Nodes will be populated dynamically based on selected panel -->
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" id="eylandoo_nodes_helper">انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.</p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 md:gap-4 mt-6">
                        <button type="submit" class="w-full sm:w-auto px-4 py-3 md:py-2 h-12 md:h-10 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm md:text-base font-medium">
                            ایجاد کانفیگ
                        </button>
                        <a href="{{ route('reseller.configs.index') }}" class="w-full sm:w-auto px-4 py-3 md:py-2 h-12 md:h-10 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center text-sm md:text-base font-medium flex items-center justify-center">
                            انصراف
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const panelSelect = document.getElementById('panel_id');
            const connectionsField = document.getElementById('connections_field');
            const connectionsInput = document.getElementById('connections_input');
            const eylandooNodesField = document.getElementById('eylandoo_nodes_field');
            const eylandooNodesContainer = document.getElementById('eylandoo_nodes_container');
            const eylandooNodesHelper = document.getElementById('eylandoo_nodes_helper');
            
            // Eylandoo nodes data from server
            const eylandooNodesData = @json($eylandoo_nodes ?? []);
            const showNodesSelector = @json($showNodesSelector ?? false);
            
            // Log initial state for debugging
            console.log('Config create page initialized', {
                showNodesSelector: showNodesSelector,
                eylandooNodesDataKeys: Object.keys(eylandooNodesData),
                eylandooNodesData: eylandooNodesData
            });
            
            function toggleConnectionsField() {
                const selectedOption = panelSelect.options[panelSelect.selectedIndex];
                const panelType = selectedOption.getAttribute('data-panel-type');
                const panelId = selectedOption.value;
                
                console.log('Panel selection changed', {
                    panelId: panelId,
                    panelType: panelType,
                    hasNodesForPanel: eylandooNodesData[panelId] !== undefined,
                    nodesCount: eylandooNodesData[panelId] ? eylandooNodesData[panelId].length : 0
                });
                
                if (panelType === 'eylandoo') {
                    connectionsField.style.display = 'block';
                    connectionsInput.required = true;
                    
                    // Always show nodes field for Eylandoo panels
                    eylandooNodesField.style.display = 'block';
                    
                    // Populate nodes if available, otherwise show empty state message
                    if (eylandooNodesData[panelId] && eylandooNodesData[panelId].length > 0) {
                        populateEylandooNodes(eylandooNodesData[panelId]);
                        eylandooNodesHelper.textContent = 'انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.';
                        console.log('Populated Eylandoo nodes', { count: eylandooNodesData[panelId].length });
                    } else {
                        // Create empty state message using DOM methods (XSS-safe)
                        eylandooNodesContainer.replaceChildren(); // Clear container
                        const emptyMsg = document.createElement('p');
                        emptyMsg.className = 'text-sm text-gray-600 dark:text-gray-400 p-3 bg-gray-100 dark:bg-gray-700 rounded';
                        emptyMsg.textContent = 'هیچ نودی برای این پنل یافت نشد. کانفیگ بدون محدودیت نود ایجاد خواهد شد.';
                        eylandooNodesContainer.appendChild(emptyMsg);
                        eylandooNodesHelper.textContent = 'در صورت عدم وجود نود، کانفیگ با تمام نودهای موجود در پنل کار خواهد کرد.';
                        console.log('Showing empty state for Eylandoo nodes');
                    }
                } else {
                    connectionsField.style.display = 'none';
                    connectionsInput.required = false;
                    connectionsInput.value = '1'; // Reset to default
                    
                    eylandooNodesField.style.display = 'none';
                    eylandooNodesContainer.replaceChildren(); // Clear container
                    console.log('Hidden Eylandoo nodes field (non-Eylandoo panel)');
                }
            }
            
            function populateEylandooNodes(nodes) {
                eylandooNodesContainer.replaceChildren(); // Clear container
                
                nodes.forEach(function(node) {
                    const label = document.createElement('label');
                    label.className = 'flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0';
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'node_ids[]';
                    checkbox.value = node.id;
                    checkbox.className = 'w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2';
                    // Nodes are now optional - do not check by default
                    
                    const span = document.createElement('span');
                    // Use node name if available, otherwise fallback to ID (matches backend behavior)
                    const nodeName = node.name || node.id || 'Unknown';
                    span.textContent = nodeName + ' (ID: ' + node.id + ')';
                    
                    label.appendChild(checkbox);
                    label.appendChild(span);
                    eylandooNodesContainer.appendChild(label);
                });
            }
            
            panelSelect.addEventListener('change', toggleConnectionsField);
            
            // Initial check on page load
            toggleConnectionsField();
        });
    </script>
    @endpush
</x-app-layout>
