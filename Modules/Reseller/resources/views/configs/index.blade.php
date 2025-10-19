<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('کانفیگ‌های من') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            <div class="flex justify-end mb-4">
                <a href="{{ route('reseller.configs.create') }}" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    ایجاد کانفیگ جدید
                </a>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr class="text-right">
                            <th class="px-4 py-3">نام کاربری</th>
                            <th class="px-4 py-3">محدودیت ترافیک</th>
                            <th class="px-4 py-3">مصرف شده</th>
                            <th class="px-4 py-3">تاریخ انقضا</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-4 py-3">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="text-right">
                        @forelse ($configs as $config)
                            <tr class="border-b dark:border-gray-700">
                                <td class="px-4 py-3">{{ $config->external_username }}</td>
                                <td class="px-4 py-3">{{ round($config->traffic_limit_bytes / (1024 * 1024 * 1024), 2) }} GB</td>
                                <td class="px-4 py-3">{{ round($config->usage_bytes / (1024 * 1024 * 1024), 2) }} GB</td>
                                <td class="px-4 py-3">{{ $config->expires_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded text-sm {{ $config->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $config->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        @if ($config->isActive())
                                            <form action="{{ route('reseller.configs.disable', $config) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="px-3 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600 text-sm">
                                                    غیرفعال
                                                </button>
                                            </form>
                                        @elseif ($config->isDisabled())
                                            <form action="{{ route('reseller.configs.enable', $config) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 text-sm">
                                                    فعال
                                                </button>
                                            </form>
                                        @endif
                                        <form action="{{ route('reseller.configs.destroy', $config) }}" method="POST" class="inline" 
                                            onsubmit="return confirm('آیا مطمئن هستید؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-3 py-1 bg-red-500 text-white rounded hover:bg-red-600 text-sm">
                                                حذف
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    هیچ کانفیگی وجود ندارد.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $configs->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
