<?php

namespace App\Services;

use App\Models\Reseller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Credit the user or reseller wallet and create a completed transaction record.
     *
     * @param  \App\Models\User  $user
     * @param  int                 $amount
     * @param  string|null         $description
     * @param  array<string,mixed> $metadata
     * @return \App\Models\Transaction
     */
    public function credit(User $user, int $amount, ?string $description = null, array $metadata = []): Transaction
    {
        return DB::transaction(function () use ($user, $amount, $description, $metadata) {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_COMPLETED,
                'description' => $description ?? 'شارژ کیف پول (درگاه)',
                'metadata' => $metadata,
            ]);

            $reseller = $user->reseller;

            if ($reseller instanceof Reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                $reseller->increment('wallet_balance', $amount);

                Log::info('Wallet credited for reseller', [
                    'user_id' => $user->id,
                    'reseller_id' => $reseller->id,
                    'amount' => $amount,
                    'new_balance' => $reseller->fresh()->wallet_balance,
                ]);
            } else {
                $user->increment('balance', $amount);

                Log::info('Wallet credited for user', [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'new_balance' => $user->fresh()->balance,
                ]);
            }

            return $transaction;
        });
    }

}
