<?php

namespace Modules\Reseller\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

class SyncController
{
    public function __invoke(): RedirectResponse
    {
        $reseller = Auth::user()?->reseller;
        abort_unless($reseller && $reseller->type === 'traffic', 404);

        SyncResellerUsageJob::dispatch($reseller);

        return Redirect::back()->with('status', __('Sync requested.'));
    }
}
