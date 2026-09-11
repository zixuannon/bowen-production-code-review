<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApiFamily
{
    private const FAMILY_ABILITIES = [
        'student' => 'student-api',
        'guardian' => 'guardian-api',
        'teacher' => 'teacher-api',
        'staff' => 'staff-api',
    ];

    public function handle(Request $request, Closure $next, string $family): Response
    {
        $user = $request->user();
        $abilities = (array) ($user?->currentAccessToken()?->abilities ?? []);

        if (!$user || in_array('*', $abilities, true)) {
            abort(403, 'This API token is not scoped to an authorized API family.');
        }

        $families = $family === 'any' ? array_keys(self::FAMILY_ABILITIES) : [$family];
        foreach ($families as $candidate) {
            if ($this->matches($user, $abilities, $candidate)) {
                return $next($request);
            }
        }

        abort(403, 'This API token is not authorized for this API family.');
    }

    private function matches(object $user, array $abilities, string $family): bool
    {
        $ability = self::FAMILY_ABILITIES[$family] ?? null;
        if (!$ability || !in_array($ability, $abilities, true)) {
            return false;
        }

        return match ($family) {
            'student' => $user->hasRole('Student'),
            'guardian' => $user->hasRole('Guardian'),
            'teacher' => $user->hasRole('Teacher'),
            'staff' => !$user->hasAnyRole(['Student', 'Guardian', 'Teacher'])
                && $user->getRoleNames()->isNotEmpty(),
            default => false,
        };
    }
}
