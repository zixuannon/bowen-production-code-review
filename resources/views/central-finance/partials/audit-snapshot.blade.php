@php($snapshotRows = app(\App\Services\CentralFinanceLedgerPresentationService::class)->auditDiff($audit->before_values, $audit->after_values))
<details class="cf-audit-snapshot">
    <summary>{{ __('View snapshot') }}</summary>
    <div class="table-responsive mt-2">
        <table class="table table-sm cf-audit-diff mb-0">
            <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Before') }}</th><th>{{ __('After') }}</th></tr></thead>
            <tbody>
            @forelse($snapshotRows as $snapshot)
                <tr><th scope="row">{{ __($snapshot['field']) }}</th><td>{{ $snapshot['before'] }}</td><td>{{ $snapshot['after'] }}</td></tr>
            @empty
                <tr><td colspan="3" class="text-muted">{{ __('No field-level changes recorded.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <details class="cf-technical-detail mt-2">
        <summary>{{ __('Raw JSON technical detail') }}</summary>
        <pre class="small">{{ json_encode(['before' => $audit->before_values, 'after' => $audit->after_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>
</details>
