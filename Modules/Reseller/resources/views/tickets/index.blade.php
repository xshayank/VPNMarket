<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ __('تیکت‌های ریسلر') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <x-reseller-back-button :fallbackRoute="route('reseller.dashboard')" label="بازگشت به داشبورد ریسلر" />
            
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">موفقیت!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative text-right" role="alert">
                    <strong class="font-bold">خطا!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <div class="p-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-right">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">تیکت‌های پشتیبانی</h3>
                    <a href="{{ route('reseller.tickets.create') }}" 
                       class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">
                        تیکت جدید
                    </a>
                </div>

                @if ($tickets->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b dark:border-gray-700">
                                    <th class="text-right pb-3 text-gray-700 dark:text-gray-100 font-semibold">موضوع</th>
                                    <th class="text-right pb-3 text-gray-700 dark:text-gray-100 font-semibold">وضعیت</th>
                                    <th class="text-right pb-3 text-gray-700 dark:text-gray-100 font-semibold">اولویت</th>
                                    <th class="text-right pb-3 text-gray-700 dark:text-gray-100 font-semibold">آخرین بروزرسانی</th>
                                    <th class="text-right pb-3 text-gray-700 dark:text-gray-100 font-semibold">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        <td class="py-4 text-gray-900 dark:text-gray-100">
                                            <a href="{{ route('reseller.tickets.show', $ticket->id) }}" 
                                               class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                {{ $ticket->subject }}
                                            </a>
                                        </td>
                                        <td class="py-4">
                                            <span class="px-3 py-1 rounded-full text-sm font-medium
                                                {{ $ticket->status === 'open' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100' : '' }}
                                                {{ $ticket->status === 'answered' ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : '' }}
                                                {{ $ticket->status === 'closed' ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100' : '' }}">
                                                {{ $ticket->status === 'open' ? 'باز' : '' }}
                                                {{ $ticket->status === 'answered' ? 'پاسخ داده شده' : '' }}
                                                {{ $ticket->status === 'closed' ? 'بسته شده' : '' }}
                                            </span>
                                        </td>
                                        <td class="py-4">
                                            <span class="px-3 py-1 rounded-full text-sm font-medium
                                                {{ $ticket->priority === 'low' ? 'bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100' : '' }}
                                                {{ $ticket->priority === 'medium' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100' : '' }}
                                                {{ $ticket->priority === 'high' ? 'bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100' : '' }}">
                                                {{ $ticket->priority === 'low' ? 'کم' : '' }}
                                                {{ $ticket->priority === 'medium' ? 'متوسط' : '' }}
                                                {{ $ticket->priority === 'high' ? 'بالا' : '' }}
                                            </span>
                                        </td>
                                        <td class="py-4 text-gray-900 dark:text-gray-100">
                                            {{ $ticket->updated_at->diffForHumans() }}
                                        </td>
                                        <td class="py-4">
                                            <a href="{{ route('reseller.tickets.show', $ticket->id) }}" 
                                               class="text-blue-600 dark:text-blue-400 hover:underline">
                                                مشاهده
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $tickets->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">هیچ تیکتی وجود ندارد</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">برای شروع یک تیکت جدید ایجاد کنید.</p>
                        <div class="mt-6">
                            <a href="{{ route('reseller.tickets.create') }}" 
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">
                                <svg class="ml-2 -mr-1 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                تیکت جدید
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
