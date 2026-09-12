@props([
    'message',
    'title' => null,
    'icon' => 'fa-inbox',
    'actionHref' => null,
    'actionLabel' => null,
])

<div {{ $attributes->merge(['class' => 'ui-empty-state', 'role' => 'status']) }}>
    <i class="fa {{ $icon }} ui-empty-state__icon" aria-hidden="true"></i>
    @if($title)
        <div class="ui-empty-state__title">{{ $title }}</div>
    @endif
    <div>{{ $message }}</div>
    @if($actionHref && $actionLabel)
        <a class="btn btn-outline-primary btn-sm mt-3" href="{{ $actionHref }}">{{ $actionLabel }}</a>
    @endif
</div>
