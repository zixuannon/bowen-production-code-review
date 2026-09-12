<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('app.name', 'eSchool') }}</title>
    <link rel="stylesheet" href="{{ asset('/assets/fonts/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('/assets/css/style.min.css') }}">
    <link rel="stylesheet" href="{{ asset('/assets/css/custom.css') }}">
</head>
<body class="ui-error-page">
    <main class="ui-error-state" role="main">
        <i class="fa @yield('icon', 'fa-exclamation-circle') ui-error-state__icon" aria-hidden="true"></i>
        <div class="ui-error-state__code">@yield('code')</div>
        <h1 class="h3">@yield('heading')</h1>
        <div class="ui-error-state__message">@yield('message')</div>
        <div class="ui-error-state__actions">
            @auth
                <a href="{{ url('/dashboard') }}" class="btn btn-theme">{{ __('Return to Dashboard') }}</a>
            @else
                <a href="{{ url('/') }}" class="btn btn-theme">{{ __('Return Home') }}</a>
                <a href="{{ url('/login') }}" class="btn btn-outline-primary">{{ __('Login') }}</a>
            @endauth
        </div>
    </main>
</body>
</html>
