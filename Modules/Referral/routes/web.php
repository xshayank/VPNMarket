<?php

use Illuminate\Support\Facades\Route;
use Modules\Referral\App\Http\Controllers\ReferralController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('referrals', ReferralController::class)->names('referral');
});
