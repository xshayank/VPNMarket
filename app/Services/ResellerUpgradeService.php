<?php

namespace App\Services;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerUpgradeService
{
    /**
     * Check if user qualifies for reseller upgrade and perform it if needed
     *
     * @return array{upgraded: bool, reseller: ?Reseller, message: string}
     */
    public function checkAndUpgrade(User $user): array
    {
        $minBalance = config('billing.reseller.min_wallet_upgrade', 100000);

        // Check if user already has a reseller account
        if ($user->reseller) {
            return [
                'upgraded' => false,
                'reseller' => $user->reseller,
                'message' => 'User already has a reseller account',
            ];
        }

        // Check if user balance meets the threshold
        if ($user->balance < $minBalance) {
            return [
                'upgraded' => false,
                'reseller' => null,
                'message' => 'User balance below threshold',
            ];
        }

        // Perform the upgrade
        try {
            $reseller = DB::transaction(function () use ($user) {
                // Create wallet-based reseller
                $reseller = Reseller::create([
                    'user_id' => $user->id,
                    'type' => Reseller::TYPE_WALLET,
                    'status' => 'active',
                    'wallet_balance' => $user->balance,
                ]);

                // Transfer user balance to reseller wallet
                $user->update(['balance' => 0]);

                Log::info('User automatically upgraded to reseller', [
                    'action' => 'tg_reseller_upgraded',
                    'user_id' => $user->id,
                    'reseller_id' => $reseller->id,
                    'wallet_balance' => $reseller->wallet_balance,
                ]);

                return $reseller;
            });

            return [
                'upgraded' => true,
                'reseller' => $reseller,
                'message' => 'User successfully upgraded to reseller',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to upgrade user to reseller', [
                'action' => 'tg_reseller_upgrade_failed',
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'upgraded' => false,
                'reseller' => null,
                'message' => 'Upgrade failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get amount needed to reach reseller threshold
     */
    public function getAmountNeeded(User $user): int
    {
        $minBalance = config('billing.reseller.min_wallet_upgrade', 100000);
        $currentBalance = $user->balance ?? 0;

        if ($user->reseller) {
            return 0;
        }

        return max(0, $minBalance - $currentBalance);
    }

    /**
     * Check if user can be upgraded to reseller
     */
    public function canUpgrade(User $user): bool
    {
        return ! $user->reseller && $this->getAmountNeeded($user) <= 0;
    }
}
