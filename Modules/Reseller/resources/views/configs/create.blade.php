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

                    <!-- Max clients field for Eylandoo -->
                    <div id="max_clients_field" class="mb-4 md:mb-6" style="display: none;">
                        <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                            حداکثر تعداد کلاینت‌های همزمان
                            <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="max_clients" id="max_clients_input" min="1" max="100" value="{{ old('max_clients', 1) }}"
                            class="w-full h-12 md:h-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm md:text-base"
                            placeholder="مثال: 2">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">تعداد کلاینت‌هایی که می‌توانند به طور همزمان متصل شوند (فقط برای پنل Eylandoo)</p>
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

                    <!-- Eylandoo Nodes selection - Shown for Eylandoo panels (matches Marzneshin pattern) -->
                    {{-- This field mirrors the Marzneshin services selector pattern:
                         - Server-side rendered and visible when nodes are available
                         - Also supports dynamic visibility via JavaScript for multi-panel resellers
                         - Node IDs are always treated as integers per API specification --}}
                    @if (isset($showNodesSelector) && $showNodesSelector)
                        <div id="eylandoo_nodes_field" class="mb-4 md:mb-6">
                            <label class="block text-xs md:text-sm font-medium mb-2 text-gray-900 dark:text-gray-100">
                                نودهای Eylandoo (اختیاری)
                            </label>
                            <div class="space-y-3" id="eylandoo_nodes_container">
                                @php
                                    // For resellers with a single Eylandoo panel, render nodes server-side
                                    $hasEylandooPanel = false;
                                    $eylandooPanelId = null;
                                    foreach ($panels as $panel) {
                                        if (isset($panel->panel_type) && strtolower(trim($panel->panel_type)) === 'eylandoo') {
                                            $hasEylandooPanel = true;
                                            $eylandooPanelId = $panel->id;
                                            break;
                                        }
                                    }
                                    
                                    // If reseller has only one panel and it's Eylandoo, render nodes immediately
                                    $renderNodesServerSide = count($panels) === 1 && $hasEylandooPanel;
                                    $initialNodes = $renderNodesServerSide && isset($nodesOptions[$eylandooPanelId]) 
                                        ? (is_array($nodesOptions[$eylandooPanelId]) ? $nodesOptions[$eylandooPanelId] : [])
                                        : [];
                                @endphp
                                
                                @if ($renderNodesServerSide && count($initialNodes) > 0)
                                    @foreach ($initialNodes as $node)
                                        @if(is_array($node) && isset($node['id']))
                                        <label class="flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0">
                                            <input type="checkbox" name="node_ids[]" value="{{ $node['id'] }}" 
                                                class="w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2">
                                            <span>{{ $node['name'] ?? $node['id'] }} (ID: {{ $node['id'] }})</span>
                                        </label>
                                        @endif
                                    @endforeach
                                @elseif ($renderNodesServerSide)
                                    <p class="text-sm text-gray-600 dark:text-gray-400 p-3 bg-gray-100 dark:bg-gray-700 rounded">
                                        هیچ نودی برای این پنل یافت نشد. کانفیگ بدون محدودیت نود ایجاد خواهد شد.
                                    </p>
                                @endif
                                <!-- For multi-panel resellers, nodes will be populated dynamically via JavaScript -->
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" id="eylandoo_nodes_helper">
                                @if ($renderNodesServerSide && count($initialNodes) > 0)
                                    @php
                                        $isUsingDefaults = !empty(array_filter($initialNodes, fn($node) => is_array($node) && ($node['is_default'] ?? false)));
                                    @endphp
                                    @if ($isUsingDefaults)
                                        نودهای پیش‌فرض (1 و 2) نمایش داده شده‌اند. در صورت نیاز می‌توانید نودهای دیگر را در پنل تنظیم کنید.
                                    @else
                                        انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.
                                    @endif
                                @else
                                    انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.
                                @endif
                            </p>
                        </div>
                    @endif

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
            const maxClientsField = document.getElementById('max_clients_field');
            const maxClientsInput = document.getElementById('max_clients_input');
            const eylandooNodesField = document.getElementById('eylandoo_nodes_field');
            const eylandooNodesContainer = document.getElementById('eylandoo_nodes_container');
            const eylandooNodesHelper = document.getElementById('eylandoo_nodes_helper');
            
            // Early exit if essential elements don't exist
            if (!panelSelect) {
                console.warn('Panel select element not found');
                return;
            }
            
            // Check if we have multiple panels or if nodes field exists (server-side rendered)
            // Count actual panel options (excluding empty default option)
            const actualPanelCount = Array.from(panelSelect.options).filter(opt => opt.value !== '').length;
            const hasMultiplePanels = actualPanelCount > 1;
            const nodesFieldExists = eylandooNodesField !== null;
            
            // Eylandoo nodes data from server - all node IDs are integers
            const nodesOptions = @json($nodesOptions ?? []);
            
            // Debug logging (only in development - can be disabled via APP_DEBUG)
            const debugMode = @json(config('app.debug', false));
            if (debugMode) {
                console.log('Config create page initialized', {
                    hasMultiplePanels: hasMultiplePanels,
                    nodesFieldExists: nodesFieldExists,
                    nodesOptionsKeys: Object.keys(nodesOptions || {}),
                    nodesOptions: nodesOptions
                });
            }
            
            // Only attach dynamic behavior if we have multiple panels
            // For single-panel resellers, the field is already server-side rendered
            if (hasMultiplePanels && nodesFieldExists && eylandooNodesContainer) {
                function updateNodesAndMaxClients() {
                    const selectedOption = panelSelect.options[panelSelect.selectedIndex];
                    const panelType = selectedOption.getAttribute('data-panel-type');
                    const panelId = selectedOption.value;
                    
                    if (debugMode) {
                        console.log('Panel selection changed', {
                            panelId: panelId,
                            panelType: panelType,
                            hasNodesForPanel: nodesOptions[panelId] !== undefined,
                            nodesCount: nodesOptions[panelId] ? nodesOptions[panelId].length : 0
                        });
                    }
                    
                    // Handle Eylandoo-specific fields (max_clients and nodes)
                    if (panelType === 'eylandoo') {
                        // Show max_clients field
                        if (maxClientsField) {
                            maxClientsField.style.display = 'block';
                            maxClientsInput.required = true;
                        }
                        
                        // Show nodes field for Eylandoo panels
                        eylandooNodesField.style.display = 'block';
                        
                        // Populate nodes if available for this Eylandoo panel
                        if (nodesOptions[panelId] && Array.isArray(nodesOptions[panelId]) && nodesOptions[panelId].length > 0) {
                            populateEylandooNodes(nodesOptions[panelId]);
                            
                            // Check if using default nodes (has is_default property)
                            const isUsingDefaults = nodesOptions[panelId].some(node => node && node.is_default === true);
                            
                            if (eylandooNodesHelper) {
                                if (isUsingDefaults) {
                                    eylandooNodesHelper.textContent = 'نودهای پیش‌فرض (1 و 2) نمایش داده شده‌اند. در صورت نیاز می‌توانید نودهای دیگر را در پنل تنظیم کنید.';
                                } else {
                                    eylandooNodesHelper.textContent = 'انتخاب نود اختیاری است. اگر هیچ نودی انتخاب نشود، کانفیگ بدون محدودیت نود ایجاد می‌شود.';
                                }
                            }
                            
                            if (debugMode) {
                                console.log('Populated Eylandoo nodes', { count: nodesOptions[panelId].length });
                            }
                        } else {
                            // Show empty state message for Eylandoo panel with no nodes
                            if (eylandooNodesContainer) {
                                eylandooNodesContainer.replaceChildren(); // Clear container
                                const emptyMsg = document.createElement('p');
                                emptyMsg.className = 'text-sm text-gray-600 dark:text-gray-400 p-3 bg-gray-100 dark:bg-gray-700 rounded';
                                emptyMsg.textContent = 'هیچ نودی برای این پنل یافت نشد. کانفیگ بدون محدودیت نود ایجاد خواهد شد.';
                                eylandooNodesContainer.appendChild(emptyMsg);
                            }
                            if (eylandooNodesHelper) {
                                eylandooNodesHelper.textContent = 'در صورت عدم وجود نود، کانفیگ با تمام نودهای موجود در پنل کار خواهد کرد.';
                            }
                            
                            if (debugMode) {
                                console.log('Showing empty state for Eylandoo nodes');
                            }
                        }
                    } else {
                        // Non-Eylandoo panel: hide both max_clients and nodes fields
                        if (maxClientsField) {
                            maxClientsField.style.display = 'none';
                            maxClientsInput.required = false;
                            maxClientsInput.value = '1'; // Reset to default
                        }
                        
                        // Hide nodes field for non-Eylandoo panels
                        eylandooNodesField.style.display = 'none';
                        if (eylandooNodesContainer) {
                            eylandooNodesContainer.replaceChildren(); // Clear container
                        }
                        
                        if (debugMode) {
                            console.log('Hidden Eylandoo fields for non-Eylandoo panel');
                        }
                    }
                }
                
                function populateEylandooNodes(nodes) {
                    if (!eylandooNodesContainer || !Array.isArray(nodes)) {
                        return;
                    }
                    
                    eylandooNodesContainer.replaceChildren(); // Clear container
                    
                    nodes.forEach(function(node) {
                        // Validate node structure
                        if (!node || typeof node !== 'object' || !node.id) {
                            return;
                        }
                        
                        const label = document.createElement('label');
                        label.className = 'flex items-center text-sm md:text-base text-gray-900 dark:text-gray-100 min-h-[44px] sm:min-h-0';
                        
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.name = 'node_ids[]';
                        // Ensure node.id is treated as integer - already guaranteed by backend parsing
                        checkbox.value = node.id;
                        checkbox.className = 'w-5 h-5 md:w-4 md:h-4 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 ml-2';
                        
                        const span = document.createElement('span');
                        // Use node name if available, otherwise fallback to ID (matches backend behavior)
                        const nodeName = node.name || node.id || 'Unknown';
                        span.textContent = nodeName + ' (ID: ' + node.id + ')';
                        
                        label.appendChild(checkbox);
                        label.appendChild(span);
                        eylandooNodesContainer.appendChild(label);
                    });
                }
                
                panelSelect.addEventListener('change', updateNodesAndMaxClients);
                
                // Initial check on page load for multi-panel resellers
                updateNodesAndMaxClients();
            } else {
                // For single-panel resellers, handle max_clients field visibility
                if (maxClientsField) {
                    const selectedOption = panelSelect.options[panelSelect.selectedIndex];
                    const panelType = selectedOption ? selectedOption.getAttribute('data-panel-type') : null;
                    
                    if (panelType === 'eylandoo') {
                        maxClientsField.style.display = 'block';
                        maxClientsInput.required = true;
                    }
                    
                    // Update max_clients field when panel changes
                    panelSelect.addEventListener('change', function() {
                        const selectedOption = panelSelect.options[panelSelect.selectedIndex];
                        const panelType = selectedOption.getAttribute('data-panel-type');
                        
                        if (panelType === 'eylandoo') {
                            maxClientsField.style.display = 'block';
                            maxClientsInput.required = true;
                        } else {
                            maxClientsField.style.display = 'none';
                            maxClientsInput.required = false;
                            maxClientsInput.value = '1';
                        }
                    });
                }
            }
        });
    </script>
    @endpush
</x-app-layout>
