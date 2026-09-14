<nav class="navbar default-layout-navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
    @php
        // Settings are deliberately cached as raw database values. File settings
        // are storage-relative, so normalize them here before emitting an img URL.
        $resolveLogoUrl = static function ($path, string $fallback): string {
            $path = trim((string) $path);
            if ($path === '') {
                return asset($fallback);
            }
            if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])) {
                $urlHost = strtolower((string) parse_url($path, PHP_URL_HOST));
                $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
                $requestHost = strtolower((string) request()->getHost());
                $urlPath = (string) parse_url($path, PHP_URL_PATH);

                // Stored settings may contain an absolute URL back to this app.
                // Treat same-origin /storage URLs as local files so a stale DB
                // value falls back before the browser emits a 404.
                if (! in_array($urlHost, array_filter([$appHost, $requestHost]), true)
                    || ! \Illuminate\Support\Str::startsWith($urlPath, '/storage/')) {
                    return $path;
                }

                $path = $urlPath;
            }

            $relativePath = ltrim($path, '/');
            if (\Illuminate\Support\Str::startsWith($relativePath, 'assets/')) {
                return is_file(public_path($relativePath)) ? asset('/'.$relativePath) : asset($fallback);
            }

            $relativePath = \Illuminate\Support\Str::after($relativePath, 'storage/');
            if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath)) {
                return asset($fallback);
            }

            return \Illuminate\Support\Facades\Storage::disk('public')->url($relativePath);
        };

        $horizontalPath = $schoolSettings['horizontal_logo'] ?? null;
        $verticalPath = $schoolSettings['vertical_logo'] ?? null;
        $horizontalLogo = $resolveLogoUrl($horizontalPath ?: ($systemSettings['horizontal_logo'] ?? null), '/assets/horizontal-logo2.svg');
        $verticalLogo = $resolveLogoUrl($verticalPath ?: ($systemSettings['vertical_logo'] ?? null), '/assets/vertical-logo.svg');
        $profileImage = $resolveLogoUrl(Auth::user()->getRawOriginal('image'), '/assets/no_image_available.jpg');
    @endphp
    <div class="text-center navbar-brand-wrapper d-flex align-items-center justify-content-center">
        <a class="navbar-brand brand-logo" href="{{ URL::to('/dashboard') }}" aria-label="{{ __('Dashboard') }}">
            <img src="{{ $horizontalLogo }}" alt="" data-custom-image="{{ asset('/assets/horizontal-logo2.svg') }}" class="custom-default-image"
                 onerror="this.onerror=null;this.setAttribute('data-error-handled','true');this.src='{{ asset('/assets/horizontal-logo2.svg') }}';">
        </a>
        <a class="navbar-brand brand-logo-mini" href="{{ URL::to('/dashboard') }}" aria-label="{{ __('Dashboard') }}">
            <img src="{{ $verticalLogo }}" alt="" data-custom-image="{{ asset('/assets/vertical-logo.svg') }}" class="custom-default-image"
                 onerror="this.onerror=null;this.setAttribute('data-error-handled','true');this.src='{{ asset('/assets/vertical-logo.svg') }}';">
        </a>
    </div>
    <div class="navbar-menu-wrapper d-flex align-items-stretch">
        <button class="navbar-toggler navbar-toggler align-self-center" type="button" data-toggle="minimize"
                aria-label="{{ __('Toggle sidebar navigation') }}" title="{{ __('Toggle sidebar navigation') }}">
            <span class="fa fa-bars"></span>
        </button>

        @if ($schoolSettings['school_name'] ?? '')
            <div class="align-items-stretch cache-clear app-header-context">
                <span class="ml-3" title="{{ $schoolSettings['school_name'] ?? '' }}">{{ $schoolSettings['school_name'] ?? '' }}</span>
            </div>
        @endif  
        @if (isset($systemSettings['email_verified']) && !$systemSettings['email_verified'])
            @can('email-setting-create')
                <div class="mx-auto order-0">
                    <div class="alert alert-fill-danger my-2" role="alert">
                        <i class="fa fa-exclamation"></i>
                        {{ __('Email Configuration is not verified') }} <a href="{{ route('system-settings.email.index') }}" class="alert-link">{{ __('Click here to redirect to email configuration') }}</a>.
                    </div>
                </div>
            @endcan
        @endif
        <ul class="navbar-nav navbar-nav-right">
            @can('class-teacher')
                <li class="nav-item">
                    {{-- TODO :: CLASS TEACHER CLASS NAME --}}
                    {{-- @php $class_section = Auth::user()->teacher->class_section @endphp
                    <div class="text-dark">{{__('Class').' : '.$class_section->class->name.' '.$class_section->section->name.' - '.$class_section->class->medium->name}}</div> --}}
                </li>
            @endcan

            @if (isset($sessionYear) && !Auth::user()->hasRole('Super Admin'))
                <li class="d-none d-md-block d-sm-block nav-item">
                    <div class="text-dark">
                        {{ __('Academic Year') . ' : '}}
                        <span id="sessionYearNameHeader">{{ $sessionYear->name }}</span>
                        <span id="semesterNameHeader">
                            @if(isset($semester) && !empty($semester->name))
                                - {{ $semester->name }}
                            @endif
                        </span>
                    </div>
                </li>
            @endif

            {{-- <li class="d-none d-md-block d-sm-block nav-item ml-4">
                <div class="text-dark">
                    <span><i class="mdi mdi-weather-sunny fa-2x cursor-pointer theme"></i></span>
                </div>
            </li> --}}

            <li class="nav-item dropdown">
                <a class="nav-link count-indicator dropdown-toggle" id="messageDropdown" href="#" data-toggle="dropdown" aria-expanded="false"
                   aria-label="{{ __('Change language') }}" title="{{ __('Change language') }}">
                    <i class="fa fa-language"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-right navbar-dropdown preview-list" aria-labelledby="messageDropdown">
                    @foreach ($languages as $key => $language)
                        <a class="dropdown-item preview-item" href="{{ url('set-language') . '/' . $language->code }}">
                            <div class="preview-thumbnail">
                                {{-- <img src="../../../assets/images/faces/face3.jpg" alt="image" class="profile-pic"> --}}
                            </div>
                            <div class="preview-item-content d-flex align-items-start flex-column justify-content-center">
                                <h6 class="preview-subject ellipsis mb-1 font-weight-normal">{{ $language->name }}</h6>
                                {{-- <p class="text-gray mb-0"> 18 Minutes ago </p> --}}
                            </div>
                        </a>
                        <div class="dropdown-divider"></div>
                    @endforeach
                </div>
            </li>
            <li class="nav-item nav-profile dropdown">
                <a class="nav-link dropdown-toggle" id="profileDropdown" href="#" data-toggle="dropdown" aria-expanded="false"
                   aria-label="{{ __('Open user menu') }}" title="{{ __('Open user menu') }}">
                    <div class="nav-profile-img">
                        <img src="{{ $profileImage }}" alt="" onerror="this.onerror=null;this.setAttribute('data-error-handled','true');this.src='{{ asset('/assets/no_image_available.jpg') }}';">
                    </div>
                    <div class="nav-profile-text">
                        <p class="mb-1 text-black">{{ Auth::user()->first_name }}</p>
                    </div>
                </a>
                <div class="dropdown-menu navbar-dropdown" aria-labelledby="profileDropdown">
                    {{-- @can('update-admin-profile') --}}
                        <a class="dropdown-item" href="{{ route('auth.profile.edit') }}"><i class="fa fa-user mr-2"></i>{{ __('profile') }}</a>
                        <div class="dropdown-divider"></div>
                    {{-- @endcan --}}
                    <a class="dropdown-item" href="{{ route('auth.change-password.index') }}">
                        <i class="fa fa-refresh mr-2 text-success"></i>{{ __('change_password') }}</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ url('cache-flush') }}">
                        <i class="fa fa-refresh mr-2 text-muted"></i>{{ __('cache_clear') }}</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ route('auth.logout') }}">
                        <i class="fa fa-sign-out mr-2 text-primary"></i> {{ __('signout') }}
                    </a>
                </div>
            </li>
        </ul>
        <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas"
                aria-label="{{ __('Open navigation menu') }}" title="{{ __('Open navigation menu') }}">
            <span class="fa fa-bars"></span>
        </button>
    </div>
</nav>
