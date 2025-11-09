<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfigEvent;
use App\Models\ResellerUsageSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChargeWalletResellersHourly extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:charge-wallet-hourly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge wallet-based resellers for hourly traffic usage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Starting hourly wallet-based reseller charging');

        // Find all wallet-based resellers
        $walletResellers = Reseller::where('billing_type', 'wallet')->get();

        $this->info("Found {$walletResellers->count()} wallet-based resellers");
        Log::info("Found {$walletResellers->count()} wallet-based resellers to charge");

        $charged = 0;
        $suspended = 0;
        $totalCost = 0;

        foreach ($walletResellers as $reseller) {
            try {
                $result = $this->chargeReseller($reseller);
                
                if ($result['charged']) {
                    $charged++;
                    $totalCost += $result['cost'];
                }
                
                if ($result['suspended']) {
                    $suspended++;
                }
            } catch (\Exception $e) {
                Log::error("Error charging reseller {$reseller->id}: " . $e->getMessage());
                $this->error("Error charging reseller {$reseller->id}: " . $e->getMessage());
            }
        }

        $summary = "Wallet charging completed: {$charged} charged, {$suspended} suspended, total cost: {$totalCost} تومان";
        $this->info($summary);
        Log::info($summary);

        return Command::SUCCESS;
    }

    /**
     * Charge a single wallet-based reseller
     */
    protected function chargeReseller(Reseller $reseller): array
    {
        // Calculate total current usage from all configs
        $currentTotalBytes = $reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        // Get the last snapshot
        $lastSnapshot = $reseller->usageSnapshots()
            ->orderBy('measured_at', 'desc')
            ->first();

        // Calculate delta (traffic used since last snapshot)
        $deltaBytes = 0;
        if ($lastSnapshot) {
            $deltaBytes = max(0, $currentTotalBytes - $lastSnapshot->total_bytes);
        } else {
            // First snapshot - charge for all current usage
            $deltaBytes = $currentTotalBytes;
        }

        // Create new snapshot
        ResellerUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'total_bytes' => $currentTotalBytes,
            'measured_at' => now(),
        ]);

        // Convert bytes to GB and calculate cost
        $deltaGB = $deltaBytes / (1024 * 1024 * 1024);
        $pricePerGB = $reseller->getWalletPricePerGb();
        
        // Calculate cost in تومان (use ceiling to avoid undercharging)
        $cost = (int) ceil($deltaGB * $pricePerGB);

        // Deduct from wallet balance
        $oldBalance = $reseller->wallet_balance;
        $newBalance = $oldBalance - $cost;
        
        $reseller->update(['wallet_balance' => $newBalance]);

        Log::info("Charged reseller {$reseller->id}", [
            'reseller_id' => $reseller->id,
            'delta_bytes' => $deltaBytes,
            'delta_gb' => round($deltaGB, 4),
            'price_per_gb' => $pricePerGB,
            'cost' => $cost,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
        ]);

        // Check if reseller should be suspended
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);
        $wasSuspended = false;

        if ($newBalance <= $suspensionThreshold && !$reseller->isSuspendedWallet()) {
            $this->suspendWalletReseller($reseller);
            $wasSuspended = true;
            
            Log::warning("Reseller {$reseller->id} suspended due to low wallet balance", [
                'balance' => $newBalance,
                'threshold' => $suspensionThreshold,
            ]);
        }

        return [
            'charged' => $cost > 0,
            'cost' => $cost,
            'suspended' => $wasSuspended,
            'delta_bytes' => $deltaBytes,
            'new_balance' => $newBalance,
        ];
    }

    /**
     * Suspend a wallet-based reseller and disable all their configs
     */
    protected function suspendWalletReseller(Reseller $reseller): void
    {
        // Update reseller status
        $reseller->update(['status' => 'suspended_wallet']);

        // Create audit log for suspension
        AuditLog::log(
            action: 'reseller_suspended_wallet',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: 'wallet_balance_exhausted',
            meta: [
                'wallet_balance' => $reseller->wallet_balance,
                'suspension_threshold' => config('billing.wallet.suspension_threshold', -1000),
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Disable all active configs
        $this->disableResellerConfigs($reseller);

        $this->warn("Suspended reseller {$reseller->id} (balance: {$reseller->wallet_balance} تومان)");
    }

    /**
     * Disable all active configs for a reseller
     */
    protected function disableResellerConfigs(Reseller $reseller): void
    {
        $configs = $reseller->configs()->where('status', 'active')->get();

        if ($configs->isEmpty()) {
            return;
        }

        Log::info("Disabling {$configs->count()} configs for suspended wallet reseller {$reseller->id}");

        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;
        $disabledCount = 0;

        foreach ($configs as $config) {
            try {
                // Apply rate limiting
                $provisioner->applyRateLimit($disabledCount);

                // Disable on remote panel if possible
                $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $remoteResult = $provisioner->disableUser(
                            $panel->panel_type,
                            $panel->getCredentials(),
                            $config->panel_user_id
                        );
                    }
                }

                // Update local status
                $meta = $config->meta ?? [];
                $meta['disabled_by_wallet_suspension'] = true;
                $meta['disabled_by_reseller_id'] = $reseller->id;
                $meta['disabled_at'] = now()->toIso8601String();

                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'meta' => $meta,
                ]);

                // Log event
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_disabled',
                    'meta' => [
                        'reason' => 'wallet_balance_exhausted',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'config_auto_disabled',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'wallet_balance_exhausted',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'remote_success' => $remoteResult['success'],
                    ],
                    actorType: null,
                    actorId: null
                );

                $disabledCount++;
            } catch (\Exception $e) {
                Log::error("Error disabling config {$config->id}: " . $e->getMessage());
            }
        }

        Log::info("Disabled {$disabledCount} configs for wallet reseller {$reseller->id}");
    }
}
