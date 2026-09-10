<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RejectEmailHeaderInjection
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->inspect($request->all());

        return $next($request);
    }

    private function inspect(array $input, string $prefix = ''): void
    {
        foreach ($input as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->inspect($value, $field);
                continue;
            }

            if ($this->isEmailField((string) $key)
                && is_string($value)
                && preg_match('/[\r\n]/', $value)
            ) {
                throw ValidationException::withMessages([
                    $field => ['The email address contains invalid characters.'],
                ]);
            }
        }
    }

    private function isEmailField(string $key): bool
    {
        return preg_match('/(?:^|_)email(?:$|_)/i', $key) === 1;
    }
}
