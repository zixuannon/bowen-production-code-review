<div class="card" id="central-finance-cutover-readiness">
    <div class="card-body">
        <h5 class="mb-1">{{ __('Central Finance Cutover Readiness') }}</h5>
        @if($cutoverStatus === 'legacy')
            <p class="text-warning">{{ __('开账准备尚未完成。完成必要配置并通过检查后，可标记为 Ready。') }}</p>
        @elseif($cutoverStatus === 'ready')
            <p class="text-info">{{ __('已准备，等待正式启用。') }}</p>
        @else
            <p class="text-success">{{ __('Central Finance 已启用。历史 readiness/audit 保留供查阅。') }}</p>
        @endif
        <div class="table-responsive">
            <table class="table table-sm mb-3"><thead><tr><th>{{ __('Check') }}</th><th>{{ __('Status') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>
                @foreach($cutoverChecklist as $check)<tr><td>{{ __($check['label']) }}</td><td><span class="badge badge-{{ $check['status'] === 'pass' ? 'success' : 'warning' }}">{{ strtoupper($check['status']) }}</span></td><td>{{ $check['reason'] ? __($check['reason']) : '—' }}</td></tr>@endforeach
            </tbody></table>
        </div>
        @php($allCutoverChecksPass = collect($cutoverChecklist)->every(fn ($check) => $check['status'] === 'pass'))
        @if($cutoverStatus === 'legacy')
            @php($freshStartCutoffInput = $cutoverRecord?->getRawOriginal('receivable_sync_effective_at') ? \App\Services\CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime($cutoverRecord->getRawOriginal('receivable_sync_effective_at'))->format('Y-m-d\\TH:i') : null)
            <details class="mb-3"><summary>{{ __('Fresh Start receivable cutoff') }}</summary><p class="text-muted mt-2 mb-2">{{ __('Only tenant Fee Assignments created at or after this explicitly approved datetime are eligible for Central Receivable sync. Earlier assignments remain legacy history unless carried forward through a separate approved process.') }}</p><form method="POST" action="{{ route('central-finance.cutover-receivable-effective-at') }}">@csrf<div class="form-row"><div class="col-md-5 mb-2"><input name="receivable_sync_effective_at" type="datetime-local" class="form-control" value="{{ $freshStartCutoffInput }}" required></div><div class="col-md-5 mb-2"><input name="receivable_sync_effective_reason" class="form-control" maxlength="2000" value="{{ $cutoverRecord?->receivable_sync_effective_reason }}" placeholder="{{ __('Approved cutoff reference / reason') }}" required></div><div class="col-md-2 mb-2"><button class="btn btn-outline-primary btn-block">{{ __('Save cutoff') }}</button></div></div></form></details>
            <form method="POST" action="{{ route('central-finance.cutover-state') }}" class="d-inline">@csrf<input type="hidden" name="status" value="ready"><button class="btn btn-outline-primary" @disabled(!$allCutoverChecksPass)>{{ __('Mark Ready') }}</button></form>
        @elseif($cutoverStatus === 'ready')
            <form method="POST" action="{{ route('central-finance.cutover-state') }}" class="d-inline">@csrf<input type="hidden" name="status" value="central"><button class="btn btn-theme" @disabled(!$allCutoverChecksPass)>{{ __('Activate Central Finance') }}</button></form>
            <form method="POST" action="{{ route('central-finance.cutover-state') }}" class="d-inline ml-2">@csrf<input type="hidden" name="status" value="legacy"><button class="btn btn-outline-secondary">{{ __('Return to Legacy') }}</button></form>
        @endif
    </div>
</div>
