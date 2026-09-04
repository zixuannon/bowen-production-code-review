@props(['title', 'description' => null, 'school' => null, 'status' => null, 'eyebrow' => null, 'schoolFinanceFacade' => null])

@php
    if ($schoolFinanceFacade === null && $school !== null && \Illuminate\Support\Facades\Auth::check()) {
        try {
            $schoolFinanceFacade = app(\App\Services\CentralFinanceWorkspaceService::class)
                ->usesSchoolFinanceFacade(app(\App\Services\CentralFinanceWorkspaceService::class)
                    ->actor(\Illuminate\Support\Facades\Auth::user()));
        } catch (\Throwable) {
            $schoolFinanceFacade = false;
        }
    }
@endphp

<header class="cf-page-header">
    <div>
        <p class="cf-page-header__eyebrow">{{ $schoolFinanceFacade ? __('School Finance') : ($eyebrow ?: __('Central Finance')) }}</p>
        <h1 class="cf-page-header__title">{{ $title }}</h1>
        @if($description)<p class="cf-page-header__description">{{ $description }}</p>@endif
        <div class="cf-page-header__meta">
            <span class="cf-context-chip">{{ $school ? __('Current School').': '.$school->name : __('All Schools') }}</span>
            @if($school && $status)<span class="badge cf-status-badge badge-{{ $status === 'central' ? 'success' : ($status === 'ready' ? 'info' : 'warning') }}">{{ __($status) }}</span>@endif
        </div>
    </div>
    @if(trim($slot) !== '')<div class="cf-page-header__actions">{{ $slot }}</div>@endif
</header>
