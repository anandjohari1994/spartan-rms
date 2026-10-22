<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditService
{
    public function record(?User $actor, Model $subject, string $event, array $old = [], array $new = []): void
    {
        $omit = ['password', 'remember_token', 'private_path'];
        $log = new ActivityLog;
        $log->forceFill(['actor_id' => $actor?->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'event' => $event,
            'old_values' => Arr::except($old, $omit), 'new_values' => Arr::except($new, $omit), 'ip_address' => request()?->ip(), 'created_at' => now()])->save();
    }
}
