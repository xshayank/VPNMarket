@extends('layouts.app')

@section('content')
<div class="container mx-auto py-10">
    <h1 class="text-2xl font-bold mb-4">{{ __('Configs') }}</h1>
    <a href="{{ route('reseller.configs.create') }}" class="inline-block mb-4 bg-blue-600 text-white px-4 py-2 rounded">{{ __('Create Config') }}</a>
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr>
                <th class="px-4 py-2 text-left">{{ __('Username') }}</th>
                <th class="px-4 py-2 text-left">{{ __('Traffic (GB)') }}</th>
                <th class="px-4 py-2 text-left">{{ __('Usage (GB)') }}</th>
                <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                <th class="px-4 py-2 text-left">{{ __('Expires At') }}</th>
                <th class="px-4 py-2 text-left">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($configs as $config)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $config->external_username }}</td>
                    <td class="px-4 py-2">{{ number_format($config->traffic_limit_bytes / 1024 / 1024 / 1024, 2) }}</td>
                    <td class="px-4 py-2">{{ number_format($config->usage_bytes / 1024 / 1024 / 1024, 2) }}</td>
                    <td class="px-4 py-2">{{ ucfirst($config->status) }}</td>
                    <td class="px-4 py-2">{{ optional($config->expires_at)->toDateTimeString() }}</td>
                    <td class="px-4 py-2 space-x-2">
                        @if($config->status === 'active')
                            <form method="POST" action="{{ route('reseller.configs.disable', $config) }}" class="inline">
                                @csrf
                                <button class="text-yellow-600" type="submit">{{ __('Disable') }}</button>
                            </form>
                        @elseif($config->status === 'disabled')
                            <form method="POST" action="{{ route('reseller.configs.enable', $config) }}" class="inline">
                                @csrf
                                <button class="text-green-600" type="submit">{{ __('Enable') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('reseller.configs.destroy', $config) }}" class="inline" onsubmit="return confirm('{{ __('Delete config?') }}');">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600" type="submit">{{ __('Delete') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-4 text-center text-gray-500">{{ __('No configs found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
