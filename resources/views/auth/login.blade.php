@extends('layouts.app')

@section('title', 'Sign in — MINEX')

@section('content')
<section class="auth-page"><div class="container auth-grid"><div class="auth-visual"><img src="{{ asset('images/service-mechanic.jpg') }}" alt="Motorcycle mechanic at work" loading="lazy"><div class="auth-visual-copy"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>YOUR MINEX WORKSPACE</p><h1>Keep your<br>service in view.</h1><p>Bookings, inspection notes, and decisions stay connected to your motorcycle.</p></div></div><div class="auth-form-panel"><a class="back-link" href="{{ route('home') }}">← Back to MINEX</a><div class="auth-heading"><p class="eyebrow">WELCOME BACK</p><h2>Sign in to continue.</h2><p>Use your MINEX email and password.</p></div>
    <form class="form-stack" action="{{ route('login.store') }}" method="POST">@csrf
        <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>Password<input type="password" name="password" autocomplete="current-password" required>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="button button-red button-full" type="submit">Sign in <span aria-hidden="true">↗</span></button>
    </form>
    @if(app()->environment('local'))<div class="demo-login-note"><span class="eyebrow">LOCAL DEVELOPMENT</span><p>Demo accounts are listed in <code>docs/DEPLOYMENT.md</code>. Their shared password is set by <code>MINEX_DEMO_PASSWORD</code> in your local environment.</p></div>@endif
    <p class="auth-switch">New to MINEX? <a href="{{ route('register') }}">Create an account</a></p>
</div></div></section>
@endsection
