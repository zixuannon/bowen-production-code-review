<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = [
        'school_id',
        'name',
        'token_hash',
        'permissions',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'permissions'   => 'array',
        'last_used_at'  => 'datetime',
        'expires_at'    => 'datetime',
        'is_active'     => 'boolean',
    ];

    /**
     * Token prefix for Dify API tokens.
     */
    public const TOKEN_PREFIX = 'dify_sk_';

    /**
     * Generate a plain-text token and return the hash for storage.
     *
     * @return array{plain: string, hash: string}
     */
    public static function generateToken(): array
    {
        $plain = self::TOKEN_PREFIX . Str::random(48);
        $hash  = hash('sha256', $plain);

        return [
            'plain' => $plain,
            'hash'  => $hash,
        ];
    }

    /**
     * Find an active token by plain-text value.
     */
    public static function findValid(string $plainToken): ?self
    {
        $hash = hash('sha256', $plainToken);

        return self::where('token_hash', $hash)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Check if this token has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        // Wildcard: '*' grants all
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
