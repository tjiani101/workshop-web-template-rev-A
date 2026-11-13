<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#101214">
    <title>@yield('title', 'MINEX — Engineered for Everyday Riding')</title>
    <meta name="description" content="{{ trim($__env->yieldContent('meta_description', 'Clear, careful motorcycle maintenance for everyday riding.')) }}">
    <link rel="icon" href="{{ asset('images/minex-mark.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/minex.css') }}">
    @stack('head')
</head>
<body>
    <div class="utility-bar">
        <div class="container utility-inner">
            <span>Precision service. Clear decisions.</span>
            <span class="utility-note"><i class="status-dot"></i> Built around your ride</span>
        </div>
    </div>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="MINEX home">
                <img src="{{ asset('images/minex-mark.svg') }}" alt="" width="34" height="34">
                <span>MINEX<small>Motorcycle workshop</small></span>
            </a>
            <button class="menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="primary-navigation">
                <span></span><span></span>
            </button>
            <nav class="primary-nav" id="primary-navigation" aria-label="Primary navigation">
                <a @class(['active' => request()->routeIs('home')]) href="{{ route('home') }}">Home</a>
                <a @class(['active' => request()->routeIs('services.*')]) href="{{ route('services.index') }}">Services</a>
                <a @class(['active' => request()->routeIs('packages.*')]) href="{{ route('packages.index') }}">Packages</a>
                <a @class(['active' => request()->routeIs('pricing')]) href="{{ route('pricing') }}">Pricing</a>
                <a @class(['active' => request()->routeIs('about')]) href="{{ route('about') }}">About</a>
                <a @class(['active' => request()->routeIs('contact')]) href="{{ route('contact') }}">Contact</a>
                @auth
                    @if(auth()->user()->hasRole(['owner', 'admin']))
                        <a class="nav-workspace" href="{{ route('admin.dashboard') }}">Owner workspace</a>
                    @elseif(auth()->user()->hasRole('mechanic'))
                        <a class="nav-workspace" href="{{ route('mechanic.dashboard') }}">Mechanic workspace</a>
                    @else
                        <a class="nav-workspace" href="{{ route('portal.home') }}">My portal</a>
                    @endif
                    <a href="{{ route('portal.notifications.index') }}">Updates{{ auth()->user()->internalNotifications()->whereNull('read_at')->exists() ? ' •' : '' }}</a>
                    @if(auth()->user()->hasRole('customer'))<a href="{{ route('portal.payments.index') }}">Payments</a>@endif
                @endauth
            </nav>
            <div class="header-actions">
                @auth
                    @if(auth()->user()->hasRole(['owner', 'admin']))
                        <a class="text-link" href="{{ route('admin.dashboard') }}">Workspace</a>
                    @elseif(auth()->user()->hasRole('mechanic'))
                        <a class="text-link" href="{{ route('mechanic.dashboard') }}">Workspace</a>
                    @else
                        <a class="text-link" href="{{ route('portal.home') }}">My portal</a>
                    @endif
                    <a class="text-link" href="{{ route('portal.notifications.index') }}">Updates</a>
                @else
                    <a class="text-link login-link" href="{{ route('login') }}">Sign in</a>
                @endauth
                <a class="button button-red button-small" href="{{ route('bookings.create') }}">Request service <span aria-hidden="true">↗</span></a>
            </div>
        </div>
    </header>

    <main>
        @if (session('status'))
            <div class="container"><div class="flash-message" role="status">{{ session('status') }}</div></div>
        @endif
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container footer-main">
            <div class="footer-brand">
                <a class="brand" href="{{ route('home') }}">
                    <img src="{{ asset('images/minex-mark.svg') }}" alt="" width="34" height="34">
                    <span>MINEX<small>Motorcycle workshop</small></span>
                </a>
                <p>Careful motorcycle maintenance, explained clearly and approved by you.</p>
            </div>
            <div class="footer-column"><span class="eyebrow">Explore</span><a href="{{ route('services.index') }}">Services</a><a href="{{ route('packages.index') }}">Maintenance packages</a><a href="{{ route('pricing') }}">Pricing</a><a href="{{ route('about') }}">Our approach</a></div>
            <div class="footer-column"><span class="eyebrow">Your MINEX</span><a href="{{ route('register') }}">Create an account</a><a href="{{ route('login') }}">Sign in</a><a href="{{ route('contact') }}">Contact</a></div>
            <div class="footer-column footer-contact"><span class="eyebrow">Workshop details</span><p>Contact channels and hours will appear here once configured by MINEX.</p><a href="{{ route('contact') }}" class="footer-contact-link">Send a message <span aria-hidden="true">↗</span></a></div>
        </div>
        <div class="container footer-bottom"><span>© {{ now()->year }} MINEX</span><span>Engineered for everyday riding.</span><span>Policies will be published by MINEX</span></div>
    </footer>
    <script src="{{ asset('js/minex.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
