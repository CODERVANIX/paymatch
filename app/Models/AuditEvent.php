<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = ['actor_id', 'action', 'entity_type', 'entity_id', 'detail'];

    public static function record(?int $actorId, string $action, string $entityType, int $entityId, string $detail): void
    {
        self::create([
            'action' => $action,
            'actor_id' => $actorId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'detail' => $detail,
        ]);
    }
}
