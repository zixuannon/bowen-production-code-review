<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>DingTalk Binding || {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header text-center">
                        <h4>Bind DingTalk Account</h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Enter your eSchool teacher credentials to bind your DingTalk account.
                        </p>

                        <p>
                            School code: <strong>{{ $schoolCodeInSession ? 'YES' : 'NO' }}</strong>
                        </p>

                        @if (session('error'))
                            <div class="alert alert-danger">
                                {{ session('error') }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('dingtalk.bind.submit') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="email" class="form-label">Email or Mobile</label>
                                <input id="email" type="text"
                                    class="form-control @error('email') is-invalid @enderror"
                                    name="email" value="{{ old('email') }}" required
                                    autocomplete="username" autofocus
                                    placeholder="Enter your eSchool email or mobile">
                                @error('email')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input id="password" type="password"
                                    class="form-control @error('password') is-invalid @enderror"
                                    name="password" required
                                    autocomplete="current-password"
                                    placeholder="Enter your eSchool password">
                                @error('password')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    Bind DingTalk Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
