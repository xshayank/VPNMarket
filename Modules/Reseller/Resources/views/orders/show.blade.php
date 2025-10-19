@extends('layouts.app')

@section('content')
<div class="container mx-auto py-10">
    <h1 class="text-2xl font-bold mb-4">{{ __('Bulk Order #:id', ['id' => $order->id]) }}</h1>
    <div class="bg-white shadow rounded p-6">
        <p>{{ __('Status: :status', ['status' => ucfirst($order->status)]) }}</p>
        <p>{{ __('Quantity: :qty', ['qty' => $order->quantity]) }}</p>
        <p>{{ __('Unit price: :price', ['price' => number_format($order->unit_price, 2)]) }}</p>
        <p>{{ __('Total price: :price', ['price' => number_format($order->total_price, 2)]) }}</p>
        @if($order->artifacts)
            <pre class="bg-gray-100 p-4 rounded mt-4 text-sm">{{ json_encode($order->artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
    </div>
</div>
@endsection
