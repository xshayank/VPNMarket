@extends('layouts.app')

@section('content')
<div class="container mx-auto py-10">
    <h1 class="text-2xl font-bold mb-4">{{ __('Reseller Dashboard') }}</h1>
    <div class="bg-white shadow rounded p-6">
        <p class="mb-2">{{ __('Status: :status', ['status' => ucfirst($reseller->status)]) }}</p>
        @if($reseller->type === 'plan')
            <p>{{ __('Plan-based reseller. View available plans to start bulk purchases.') }}</p>
            <a class="text-blue-600" href="{{ route('reseller.plans.index') }}">{{ __('Browse Plans') }}</a>
        @else
            <p>{{ __('Traffic remaining: :traffic GB', ['traffic' => number_format(max(0, ($reseller->traffic_total_bytes - $reseller->traffic_used_bytes) / 1024 / 1024 / 1024), 2)]) }}</p>
            <p>{{ __('Window ends at: :date', ['date' => optional($reseller->window_ends_at)->toDateTimeString()]) }}</p>
            <a class="text-blue-600" href="{{ route('reseller.configs.index') }}">{{ __('Manage Configs') }}</a>
        @endif
    </div>
</div>
@endsection
