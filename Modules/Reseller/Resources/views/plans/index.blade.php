@extends('layouts.app')

@section('content')
<div class="container mx-auto py-10">
    <h1 class="text-2xl font-bold mb-4">{{ __('Available Plans') }}</h1>
    <form method="POST" action="{{ route('reseller.plans.store') }}" class="bg-white shadow rounded p-6">
        @csrf
        <div class="mb-4">
            <label class="block font-semibold mb-1" for="plan_id">{{ __('Plan') }}</label>
            <select name="plan_id" id="plan_id" class="border rounded w-full p-2">
                @foreach($plans as $entry)
                    <option value="{{ $entry['plan']->id }}">{{ $entry['plan']->name }} - {{ number_format($entry['pricing']['price'], 2) }} {{ $entry['plan']->currency }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-4">
            <label class="block font-semibold mb-1" for="quantity">{{ __('Quantity') }}</label>
            <input type="number" min="1" max="{{ $maxQuantity }}" name="quantity" id="quantity" class="border rounded w-full p-2" value="1">
            <p class="text-sm text-gray-600">{{ __('Maximum per purchase: :max', ['max' => $maxQuantity]) }}</p>
        </div>
        <div class="mb-4">
            <label class="block font-semibold mb-1">{{ __('Delivery Mode') }}</label>
            <label class="mr-4"><input type="radio" name="delivery_mode" value="download" checked> {{ __('Download') }}</label>
            <label><input type="radio" name="delivery_mode" value="onscreen"> {{ __('On screen') }}</label>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">{{ __('Create Bulk Order') }}</button>
    </form>
</div>
@endsection
