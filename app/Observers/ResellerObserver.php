<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Reseller;

class ResellerObserver
{
    /**
     * Handle the Reseller "created" event.
     */
    public function created(Reseller $reseller): void
    {
        // Log reseller creation
        AuditLog::log(
            action: 'reseller_created',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: null,
            meta: [
                'type' => $reseller->type,
                'status' => $reseller->status,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
                'window_starts_at' => $reseller->window_starts_at?->toDateTimeString(),
                'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                'config_limit' => $reseller->config_limit,
            ]
        );
    }

    /**
     * Handle the Reseller "updated" event.
     */
    public function updated(Reseller $reseller): void
    {
        $changes = $reseller->getChanges();
        
        // Log status changes
        if (isset($changes['status'])) {
            $action = match($changes['status']) {
                'active' => 'reseller_activated',
                'suspended' => 'reseller_suspended',
                default => 'reseller_status_changed',
            };
            
            AuditLog::log(
                action: $action,
                targetType: 'reseller',
                targetId: $reseller->id,
                reason: 'audit_reseller_status_changed',
                meta: [
                    'old_status' => $reseller->getOriginal('status'),
                    'new_status' => $changes['status'],
                    'changes' => $changes,
                ]
            );
        }
        
        // Log traffic recharge
        if (isset($changes['traffic_total_bytes'])) {
            $oldBytes = $reseller->getOriginal('traffic_total_bytes');
            $newBytes = $changes['traffic_total_bytes'];
            
            if ($newBytes > $oldBytes) {
                AuditLog::log(
                    action: 'reseller_recharged',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: null,
                    meta: [
                        'old_traffic_bytes' => $oldBytes,
                        'new_traffic_bytes' => $newBytes,
                        'added_bytes' => $newBytes - $oldBytes,
                        'added_gb' => round(($newBytes - $oldBytes) / (1024 * 1024 * 1024), 2),
                    ]
                );
            }
        }
        
        // Log window extension
        if (isset($changes['window_ends_at'])) {
            AuditLog::log(
                action: 'reseller_window_extended',
                targetType: 'reseller',
                targetId: $reseller->id,
                reason: null,
                meta: [
                    'old_window_ends_at' => $reseller->getOriginal('window_ends_at'),
                    'new_window_ends_at' => $changes['window_ends_at'],
                ]
            );
        }
    }
}
