@extends('layouts.app')

@section('title', 'Create your MINEX account')

@section('content')
<section class="auth-page"><div class="container auth-grid"><div class="auth-visual"><img src="{{ asset('images/hero-workshop.jpg') }}" alt="A motorcycle technician checking a scooter transmission" loading="lazy"><div class="auth-visual-copy"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>YOUR RIDE, ON RECORD</p><h1>A clearer way<br>to care for it.</h1><p>Keep your motorcycle, service details, and approvals together in one place.</p></div></div><div class="auth-form-panel"><a class="back-link" href="{{ route('home') }}">← Back to MINEX</a><div class="auth-heading"><p class="eyebrow">JOIN MINEX</p><h2>Create your account.</h2><p>We'll set up your customer portal so you can keep your service history together.</p></div>
    <form class="form-stack" action="{{ route('register.store') }}" method="POST">@csrf
        <label>Your name<input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="120">@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required maxlength="254">@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>Phone number <span class="optional-label">Optional</span><input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" maxlength="40">@error('phone')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label>Create a password<input type="password" name="password" autocomplete="new-password" required>@error('password')<small class="field-error">{{ $message }}</small>@enderror<small class="field-help">At least 12 characters, including upper and lower case letters and a number.</small></label>
        <label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
        <button class="button button-red button-full" type="submit">Create my account <span aria-hidden="true">↗</span></button>
    </form><p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</div></div></section>
@endsection
