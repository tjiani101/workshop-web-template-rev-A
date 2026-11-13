@extends('layouts.app')

@section('title', 'Maintenance Packages — MINEX')
@section('meta_description', 'Explore configurable motorcycle maintenance packages from MINEX.')

@section('content')
<section class="catalog-hero package-hero">
    <div class="catalog-hero-image"><img src="{{ asset('images/hero-workshop.jpg') }}" alt="Motorcycle mechanic inspecting an engine and transmission" fetchpriority="high"></div><div class="catalog-hero-scrim"></div>
    <div class="container catalog-hero-content"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>MINEX / MAINTENANCE PLANS</p><h1>A complete picture<br>of the work.</h1><p>Configurable service plans with required and optional tasks clearly explained.</p><div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span>／</span><span>Packages</span></div></div>
</section>
<section class="section-pad package-catalog"><div class="container"><div class="catalog-intro"><div><p class="eyebrow">BUILT FROM REAL TASKS</p><h2>Understand what's<br>inside the package.</h2></div><p>Each plan is assembled from workshop tasks. The technician confirms fitment, availability, and final scope for your motorcycle before work is approved.</p></div>
    <div class="package-grid package-grid-large">
        @forelse($packages as $package)
            @include('partials.package-card', ['package' => $package])
        @empty
            <div class="empty-state">Maintenance packages are being configured. Please check again soon.</div>
        @endforelse
    </div>
</div></section>
<section class="package-note-strip"><div class="container package-note-inner"><span class="note-symbol">M</span><p>Package contents can change as your technician learns more about the motorcycle. You will see every recommended change and decide before it is added.</p></div></section>
@endsection
