<?php

namespace App\Services;

use App\Models\School;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Resolves public tenant hosts only through an active canonical Registry row. */
final class SchoolHostResolver
{
    public function resolveTenantHost(string $host): ?School
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }

        $baseHost = $this->applicationHost();
        if ($baseHost !== null && $host !== $baseHost && str_ends_with($host, '.'.$baseHost)) {
            $prefix = substr($host, 0, -strlen('.'.$baseHost));
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $prefix)) {
                return null;
            }

            return $this->singleActiveSchool(
                School::on('mysql')->where('domain_type', 'default')->whereRaw('LOWER(domain) = ?', [$prefix]),
            );
        }

        return $this->singleActiveSchool(
            School::on('mysql')->where('domain_type', 'custom')->whereRaw('LOWER(domain) = ?', [$host]),
        );
    }

    /** Resolve or reject the request host; only configured central/local hosts are non-tenant exceptions. */
    public function schoolForRequestHost(string $host): ?School
    {
        $localHost = strtolower(rtrim(trim($host), '.'));
        if (!app()->environment('production') && in_array($localHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }
        $normalized = $this->normalizeHost($host);
        if ($normalized === null) {
            throw new NotFoundHttpException('Unknown host.');
        }

        if ($this->isConfiguredApplicationHost($normalized)) {
            return null;
        }

        $school = $this->resolveTenantHost($normalized);
        if ($school !== null) {
            return $school;
        }

        throw new NotFoundHttpException('Unknown or inactive School host.');
    }

    private function singleActiveSchool($query): ?School
    {
        $rows = $query->where('status', 1)
            ->where('installed', 1)
            ->whereNotNull('database_name')
            ->where('database_name', '<>', '')
            ->limit(2)
            ->get();

        // Ambiguous Registry identities are a denial, never a first-row fallback.
        return $rows->count() === 1 ? $rows->first() : null;
    }

    private function isConfiguredApplicationHost(string $host): bool
    {
        return in_array($host, array_filter([
            $this->applicationHost(),
            $this->normalizeHost((string) config('app.bowen_public_site_host')),
        ]), true);
    }

    private function applicationHost(): ?string
    {
        return $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    private function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        return $host;
    }
}
