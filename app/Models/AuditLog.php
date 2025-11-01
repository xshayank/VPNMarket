<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the actor (user/admin) that performed the action
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the target entity (reseller/config/etc)
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create an audit log entry with automatic actor detection
     */
    public static function log(
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?string $reason = null,
        array $meta = [],
        ?string $actorType = null,
        ?int $actorId = null
    ): self {
        // Auto-detect actor from authenticated user if not provided
        if ($actorType === null && $actorId === null) {
            $user = auth()->user();
            if ($user) {
                $actorType = get_class($user);
                $actorId = $user->id;
            }
        }

        // Capture request metadata if available
        $request = request();
        $requestId = $request?->header('X-Request-ID');
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();

        return self::create([
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'request_id' => $requestId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'meta' => $meta,
        ]);
    }
}
