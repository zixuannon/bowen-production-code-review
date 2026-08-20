<?php

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * Opaque, central-session state for Scheme B Group Finance operation.
 *
 * A context deliberately contains IDs only. In particular, it must never
 * carry a tenant database name or replace the authenticated central User.
 */
final readonly class FinanceOperatingContext
{
    public const VERSION = 1;

    public function __construct(
        public int $centralActorId,
        public int $groupId,
        public int $schoolId,
        public int $tenantUserId,
    ) {
    }

    /** @return array{version:int,central_actor_id:int,group_id:int,school_id:int,tenant_user_id:int} */
    public function toSession(): array
    {
        return [
            'version' => self::VERSION,
            'central_actor_id' => $this->centralActorId,
            'group_id' => $this->groupId,
            'school_id' => $this->schoolId,
            'tenant_user_id' => $this->tenantUserId,
        ];
    }

    /** @param mixed $value */
    public static function fromSession(mixed $value): self
    {
        if (!is_array($value)
            || array_keys($value) !== ['version', 'central_actor_id', 'group_id', 'school_id', 'tenant_user_id']
            || ($value['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Invalid Finance Operating Context session state.');
        }

        $ids = ['central_actor_id', 'group_id', 'school_id', 'tenant_user_id'];
        foreach ($ids as $key) {
            if (!is_int($value[$key]) && !ctype_digit((string) $value[$key])) {
                throw new InvalidArgumentException('Invalid Finance Operating Context identifier.');
            }
            if ((int) $value[$key] <= 0) {
                throw new InvalidArgumentException('Invalid Finance Operating Context identifier.');
            }
        }

        return new self(
            (int) $value['central_actor_id'],
            (int) $value['group_id'],
            (int) $value['school_id'],
            (int) $value['tenant_user_id'],
        );
    }
}
