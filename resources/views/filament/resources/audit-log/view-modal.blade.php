<div class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold mb-2">Audit Log Details</h3>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-sm font-medium text-gray-700">Action</p>
            <p class="text-sm text-gray-900">{{ $record->action }}</p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-700">Created At</p>
            <p class="text-sm text-gray-900">{{ $record->created_at->format('Y-m-d H:i:s') }}</p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-700">Actor</p>
            <p class="text-sm text-gray-900">
                @if($record->actor_id && $record->actor_type)
                    {{ class_basename($record->actor_type) }} #{{ $record->actor_id }}
                @else
                    System
                @endif
            </p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-700">Target</p>
            <p class="text-sm text-gray-900">
                {{ class_basename($record->target_type) }}
                @if($record->target_id)
                    #{{ $record->target_id }}
                @endif
            </p>
        </div>

        @if($record->reason)
        <div>
            <p class="text-sm font-medium text-gray-700">Reason</p>
            <p class="text-sm text-gray-900">{{ $record->reason }}</p>
        </div>
        @endif

        @if($record->ip)
        <div>
            <p class="text-sm font-medium text-gray-700">IP Address</p>
            <p class="text-sm text-gray-900">{{ $record->ip }}</p>
        </div>
        @endif

        @if($record->request_id)
        <div>
            <p class="text-sm font-medium text-gray-700">Request ID</p>
            <p class="text-sm text-gray-900">{{ $record->request_id }}</p>
        </div>
        @endif

        @if($record->user_agent)
        <div class="col-span-2">
            <p class="text-sm font-medium text-gray-700">User Agent</p>
            <p class="text-sm text-gray-900 break-all">{{ $record->user_agent }}</p>
        </div>
        @endif
    </div>

    @if($record->meta && count($record->meta) > 0)
    <div class="border-t pt-4">
        <p class="text-sm font-medium text-gray-700 mb-2">Metadata</p>
        <pre class="text-xs bg-gray-100 p-3 rounded overflow-auto">{{ json_encode($record->meta, JSON_PRETTY_PRINT) }}</pre>
    </div>
    @endif
</div>
