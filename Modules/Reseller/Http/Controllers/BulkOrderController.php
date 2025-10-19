<?php

namespace Modules\Reseller\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Modules\Reseller\Models\ResellerOrder;

class BulkOrderController
{
    public function show(ResellerOrder $order): View
    {
        $user = Auth::user();
        $reseller = $user->reseller;

        abort_if(!$reseller || $order->reseller_id !== $reseller->getKey(), 404);

        return view('reseller::orders.show', [
            'order' => $order,
        ]);
    }
}
