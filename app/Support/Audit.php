<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes audit log entries. Sensitive attributes are always stripped.
 */
final class Audit
{
    private const REDACTED = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'secret', 'api_key'];

    public static function log(string $action, Model|string|null $entity = null, ?string $description = null, ?array $old = null, ?array $new = null, ?int $userId = null): ActivityLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return ActivityLog::query()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entity instanceof Model ? class_basename($entity) : $entity,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : null,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            'old_values' => $old ? self::clean($old) : null,
            'new_values' => $new ? self::clean($new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /** Log a model update with only the changed attributes. */
    public static function changes(string $action, Model $model, array $original, ?string $description = null): void
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);
        if ($changes === []) {
            return;
        }
        self::log($action, $model, $description, array_intersect_key($original, $changes), $changes);
    }

    private static function clean(array $values): array
    {
        return Arr::except($values, self::REDACTED);
    }
}
