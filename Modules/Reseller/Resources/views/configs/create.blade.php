@extends('layouts.app')

@section('content')
<div class="container mx-auto py-10">
    <h1 class="text-2xl font-bold mb-4">{{ __('Create Config') }}</h1>
    <form method="POST" action="{{ route('reseller.configs.store') }}" class="bg-white shadow rounded p-6">
        @csrf
        <div class="mb-4">
            <label class="block font-semibold mb-1" for="traffic_limit_gb">{{ __('Traffic limit (GB)') }}</label>
            <input type="number" min="1" name="traffic_limit_gb" id="traffic_limit_gb" class="border rounded w-full p-2" value="10">
        </div>
        <div class="mb-4">
            <label class="block font-semibold mb-1" for="expires_at">{{ __('Expires at') }}</label>
            <input type="datetime-local" name="expires_at" id="expires_at" class="border rounded w-full p-2">
        </div>
        <div class="mb-4">
            <label class="block font-semibold mb-1" for="panel_type">{{ __('Panel type') }}</label>
            <select name="panel_type" id="panel_type" class="border rounded w-full p-2">
                <option value="marzban">Marzban</option>
                <option value="marzneshin">Marzneshin</option>
                <option value="xui">X-UI</option>
            </select>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">{{ __('Create') }}</button>
    </form>
</div>
@endsection
