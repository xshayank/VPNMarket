<?php

namespace App\Services;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class ResellerAutoUpgradeService
{
    /**
     * Ensure a user is upgraded to a wallet-based reseller when they meet the balance threshold.
     *
     * @return array{upgraded: bool, reactivated: bool, transferred_amount: int, reseller: Reseller|null}
     */
    public function upgradeIfEligible(User $user): array
    {
        $threshold = (int) config('billing.reseller.min_wallet_upgrade', 100000);

        $result = [
            'upgraded' => false,
            'reactivated' => false,
            'transferred_amount' => 0,
            'reseller' => null,
        ];

        return DB::transaction(function () use ($user, $threshold, $result) {
            $mutableResult = $result;

            $freshUser = User::query()->lockForUpdate()->with('reseller')->find($user->id);

            if (! $freshUser) {
                return $mutableResult;
            }

            $reseller = $freshUser->reseller;

            // Existing wallet-based reseller path
            if ($reseller instanceof Reseller && $reseller->isWalletBased()) {
                $transferred = 0;

                if ($freshUser->balance > 0) {
                    $reseller->increment('wallet_balance', $freshUser->balance);
                    $transferred = $freshUser->balance;
                    $freshUser->balance = 0;
                    $freshUser->save();
                }

                if ($reseller->status === 'suspended_wallet'
                    && $reseller->wallet_balance > config('billing.wallet.suspension_threshold', -1000)) {
                    $reseller->status = 'active';
                    $reseller->save();
                    $mutableResult['reactivated'] = true;
                }

                if ($transferred > 0) {
                    $mutableResult['transferred_amount'] = $transferred;
                    Log::info('Wallet balance transferred to wallet reseller', [
                        'action' => 'reseller_wallet_transfer',
                        'user_id' => $freshUser->id,
                        'reseller_id' => $reseller->id,
                        'amount' => $transferred,
                    ]);
                }

                $mutableResult['reseller'] = $reseller->fresh();

                return $mutableResult;
            }

            // Skip if user already has a non-wallet reseller record
            if ($reseller instanceof Reseller) {
                return $mutableResult;
            }

            $balance = (int) $freshUser->balance;

            if ($balance < $threshold) {
                return $mutableResult;
            }

            $newReseller = Reseller::create([
                'user_id' => $freshUser->id,
                'type' => Reseller::TYPE_WALLET,
                'status' => 'active',
                'wallet_balance' => $balance,
            ]);

            $freshUser->balance = 0;
            $freshUser->save();

            $mutableResult['upgraded'] = true;
            $mutableResult['transferred_amount'] = $balance;
            $mutableResult['reseller'] = $newReseller->fresh();

            $this->assignResellerRole($freshUser);

            Log::info('User auto-upgraded to wallet reseller', [
                'action' => 'reseller_auto_upgrade',
                'user_id' => $freshUser->id,
                'reseller_id' => $newReseller->id,
                'transferred_amount' => $balance,
                'threshold' => $threshold,
            ]);

            return $mutableResult;
        });
    }

    protected function assignResellerRole(User $user): void
    {
        try {
            if (class_exists(Role::class) && Role::where('name', 'reseller')->exists() && ! $user->hasRole('reseller')) {
                $user->assignRole('reseller');
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to assign reseller role during auto-upgrade', [
                'action' => 'reseller_auto_upgrade_role_assignment_failed',
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
