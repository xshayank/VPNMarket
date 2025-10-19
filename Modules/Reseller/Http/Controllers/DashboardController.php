<?php

namespace Modules\Reseller\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Modules\Reseller\Models\Reseller;

class DashboardController
{
    public function __invoke(): View
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        /** @var Reseller $reseller */
        $reseller = $user->reseller;

        return view('reseller::dashboard.index', [
            'reseller' => $reseller,
        ]);
    }
}
