<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

class SyncController extends Controller
{
    public function sync(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (!$reseller->isTrafficBased()) {
            return back()->with('error', 'This feature is only available for traffic-based resellers.');
        }

        // Dispatch sync job
        SyncResellerUsageJob::dispatch();

        return back()->with('success', 'Usage sync initiated. Please refresh in a few moments to see updated statistics.');
    }
}
