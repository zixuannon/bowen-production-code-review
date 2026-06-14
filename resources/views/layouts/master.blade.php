<!DOCTYPE html>
@php
    $lang = Session::get('language');
@endphp
@if($lang)
    @if ($lang->is_rtl)
        <html lang="{{ $lang->code ?? 'en' }}" dir="rtl">
    @else
        <html lang="{{ $lang->code ?? 'en' }}" dir="ltr">
    @endif
@else
    <html lang="en" dir="ltr">
@endif

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <meta name="favicon" content="{{ $schoolSettings['favicon'] ?? $systemSettings['favicon'] ?? url('assets/vertical-logo.svg') }}">
    <title>
        @yield('title') ||
        {{-- {{ config('app.name') }} --}}
        {{ $systemSettings['system_name'] ?? 'eSchool - Saas' }}
    </title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('layouts.include')
    @yield('css')
</head>

<body class="sidebar-fixed {{ $lang ? 'locale-' . ($lang->code ?? 'en') : 'locale-en' }}">
    <div class="container-scroller">
        {{-- header --}}
        @include('layouts.header')
        <div class="container-fluid page-body-wrapper">
            {{-- siderbar --}}
            @include('layouts.sidebar')
            <div class="main-panel">
                @yield('content')

                {{-- Description modal #Bootstrap-table --}}
                @include('description_modal')
                {{-- footer --}}
                @include('layouts.footer')
            </div>
        </div>
    </div>
    <script>
        let originalFormat = "{{ $originalDateFormat }}";
        let jsReplacedDateFormat = originalFormat; // reset every time
        jsReplacedDateFormat = jsReplacedDateFormat
            .replace(/d/g, "DD")
            .replace(/m/g, "MM")
            .replace(/Y/g, "YYYY");
        let datepickerReplacedFormat = originalFormat
        datepickerReplacedFormat = datepickerReplacedFormat
            .replace(/d/g, "dd")
            .replace(/m/g, "mm")
            .replace(/Y/g, "yyyy");
    </script>
    @include('layouts.footer_js')
    @yield('js')
    @yield('script')
</body>

</html>