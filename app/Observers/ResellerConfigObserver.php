<?php

namespace App\Observers;

use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Support\Facades\Log;

class ResellerConfigObserver
{
    /**
     * Handle the ResellerConfig "updated" event.
     * 
     * This observer provides an audit safety net that ensures any status change
     * is tracked in the database, even if the code path doesn't explicitly create
     * an event. It only creates an audit event if no recent domain event was already
     * recorded (to avoid duplicates).
     */
    public function updated(ResellerConfig $config): void
    {
        // Check if status changed
        if (!$config->isDirty('status')) {
            return;
        }

        $fromStatus = $config->getOriginal('status');
        $toStatus = $config->status;

        // Check if a recent relevant event already exists (within last 2 seconds)
        $recentEvent = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->whereIn('type', ['auto_disabled', 'manual_disabled', 'auto_enabled', 'manual_enabled', 'expired'])
            ->where('created_at', '>=', now()->subSeconds(2))
            ->exists();

        // If a proper domain event was already recorded, skip creating audit event
        if ($recentEvent) {
            return;
        }

        // Create audit event as a fallback
        $meta = [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor' => auth()->id() ?? 'system',
        ];

        // Add request context if available
        if (request()) {
            if (request()->route()) {
                $meta['route'] = request()->route()->getName();
            }
            $meta['ip'] = request()->ip();
        }

        // Add panel context if available
        if ($config->panel_id) {
            $meta['panel_id'] = $config->panel_id;
            $meta['panel_type'] = $config->panel?->panel_type;
        }

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'audit_status_changed',
            'meta' => $meta,
        ]);

        // Always log the status change at notice level for visibility
        // Sanitize any sensitive data
        $sanitizedMeta = $meta;
        unset($sanitizedMeta['ip']); // Remove IP from log for privacy

        Log::notice("ResellerConfig status changed (audit event created)", [
            'config_id' => $config->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor' => $meta['actor'],
            'panel_id' => $meta['panel_id'] ?? null,
            'route' => $meta['route'] ?? null,
        ]);
    }

    /**
     * Handle the ResellerConfig "deleting" event.
     * 
     * This logs the deletion and captures the final usage snapshot.
     * CRITICAL: This prevents the accounting bug where deleting a config would erase its historical usage.
     * The usage is already included in reseller's traffic_used_bytes and will remain there
     * because we use withTrashed() in aggregation queries.
     */
    public function deleting(ResellerConfig $config): void
    {
        // Log the deletion with usage snapshot
        Log::notice("ResellerConfig being deleted - usage preserved in aggregate", [
            'config_id' => $config->id,
            'reseller_id' => $config->reseller_id,
            'usage_bytes' => $config->usage_bytes,
            'settled_usage_bytes' => (int) data_get($config->meta, 'settled_usage_bytes', 0),
            'total_usage_bytes' => $config->getTotalUsageBytes(),
            'usage_gb' => round($config->getTotalUsageBytes() / (1024 * 1024 * 1024), 2),
            'actor' => auth()->id() ?? 'system',
        ]);
    }
}
