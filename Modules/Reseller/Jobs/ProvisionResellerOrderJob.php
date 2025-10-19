<?php

namespace Modules\Reseller\Jobs;

use App\Models\ResellerOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

class ProvisionResellerOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(
        public ResellerOrder $order
    ) {}

    public function handle(): void
    {
        Log::info("Starting provisioning for reseller order {$this->order->id}");

        if (!$this->order->isPaid()) {
            Log::error("Order {$this->order->id} is not paid. Status: {$this->order->status}");
            return;
        }

        DB::transaction(function () {
            $this->order->update(['status' => 'provisioning']);

            $provisioner = new ResellerProvisioner();
            $plan = $this->order->plan;
            $reseller = $this->order->reseller;
            $panel = $plan->panel;

            if (!$panel) {
                Log::error("Plan {$plan->id} has no panel assigned");
                $this->order->update(['status' => 'failed']);
                $this->refundOrder();
                return;
            }

            $artifacts = [];
            $successCount = 0;
            $failedCount = 0;

            for ($i = 1; $i <= $this->order->quantity; $i++) {
                $username = $provisioner->generateUsername($reseller, 'order', $this->order->id, $i);
                
                $result = $provisioner->provisionUser($panel, $plan, $username);

                if ($result) {
                    $artifacts[] = $result;
                    $successCount++;
                    Log::info("Provisioned user {$username} for order {$this->order->id}");
                } else {
                    $failedCount++;
                    Log::error("Failed to provision user {$username} for order {$this->order->id}");
                }
            }

            $orderStatus = $failedCount === 0 ? 'fulfilled' : 'failed';
            
            $this->order->update([
                'status' => $orderStatus,
                'fulfilled_at' => now(),
                'artifacts' => $artifacts,
            ]);

            // Refund if order failed
            if ($orderStatus === 'failed') {
                $this->refundOrder();
            }

            Log::info("Provisioning completed for order {$this->order->id}. Success: {$successCount}, Failed: {$failedCount}");
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Provisioning job failed for order {$this->order->id}: " . $exception->getMessage());
        
        DB::transaction(function () {
            $this->order->update([
                'status' => 'failed',
            ]);
            
            $this->refundOrder();
        });
    }

    /**
     * Refund the order amount to the reseller's user account
     */
    protected function refundOrder(): void
    {
        $user = $this->order->reseller->user;
        $amount = $this->order->total_price;
        
        $user->increment('balance', $amount);
        
        Log::info("Refunded {$amount} to user {$user->id} for failed order {$this->order->id}");
    }
}
