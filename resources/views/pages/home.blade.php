@extends('layouts.app')

@section('title', 'MINEX — Motorcycle service, made clear')
@section('meta_description', 'Precise motorcycle maintenance and technical service for everyday riding. See the work, understand the options, and approve each next step.')

@section('content')
@php($hero = $content['home.hero'] ?? [])
@php($intro = $content['home.intro'] ?? [])
@php($principles = $content['home.principles'] ?? [])
@php($process = $content['home.process'] ?? [])
@php($gallery = $content['home.gallery'] ?? [])
@php($announcement = $content['home.announcement'] ?? [])
@php($cta = $content['home.cta'] ?? [])
@if($announcement && !empty($announcement['title']))<aside class="announcement-banner"><div class="container"><p class="eyebrow">{{ $announcement['eyebrow'] ?? 'MINEX UPDATE' }}</p><strong>{{ $announcement['title'] }}</strong><p>{{ $announcement['body'] ?? '' }}</p></div></aside>@endif
<section class="home-hero">
    <div class="hero-photo"><img src="{{ asset($hero['image'] ?? 'images/hero-workshop.jpg') }}" alt="Motorcycle rear wheel and drivetrain on a service lift"></div>
    <div class="hero-scrim"></div>
    <div class="container hero-content" data-reveal>
        <p class="eyebrow red-eyebrow"><span class="red-rule"></span>{{ $hero['eyebrow'] ?? 'MINEX MOTORCYCLE WORKSHOP' }}</p>
        <h1>{{ $hero['title'] ?? 'Engineered for everyday riding.' }}</h1>
        <p class="hero-lede">{{ $hero['body'] ?? 'Clear service, careful inspection, and work approved by you.' }}</p>
        <div class="hero-actions"><a class="button button-red" href="{{ route('services.index') }}">Explore services <span aria-hidden="true">↗</span></a><a class="button button-outline" href="{{ route('contact') }}">Ask MINEX</a></div>
        <div class="hero-caption"><span>01 / CAREFUL BY DESIGN</span><span>Built for the ride you take every day</span></div>
    </div>
    <div class="hero-side-label" aria-hidden="true">MOTORCYCLE CARE — MINEX</div>
</section>

<section class="intro-section section-pad">
    <div class="container intro-grid">
        <div class="intro-image" data-reveal><img src="{{ asset('images/service-transmission.jpg') }}" alt="Motorcycle frame and drivetrain in a MINEX style workshop" loading="lazy"><span class="image-note">INSPECT / EXPLAIN / APPROVE</span></div>
        <div class="intro-copy" data-reveal>
            <p class="eyebrow"><span class="red-rule"></span>{{ $intro['eyebrow'] ?? 'MAINTENANCE, MADE CLEAR' }}</p>
            <h2>{{ $intro['title'] ?? 'Know what your motorcycle needs.' }}</h2>
            <p class="large-copy">{{ $intro['body'] ?? 'MINEX brings precise checks and straightforward recommendations together, so every decision stays in your hands.' }}</p>
            <a class="arrow-link" href="{{ route('about') }}">The MINEX approach <span aria-hidden="true">↗</span></a>
            <div class="intro-note"><span class="note-mark">M</span><p>We explain each finding before additional work begins. You choose what happens next.</p></div>
        </div>
    </div>
</section>

<section class="category-section section-pad section-graphite">
    <div class="container">
        <div class="section-heading heading-split"><div><p class="eyebrow"><span class="red-rule"></span>START WITH THE SYSTEM</p><h2>Service for every<br><span class="text-muted">part of your ride.</span></h2></div><p class="heading-note">From scheduled care to a careful diagnosis, explore the service areas MINEX can configure for your motorcycle.</p></div>
        <div class="category-grid">
            @forelse($categories as $index => $category)
                <a class="category-tile" href="{{ route('services.index') }}#{{ $category->slug }}" data-reveal>
                    <span class="category-number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    @if($category->image_url)<img class="category-photo" src="{{ $category->image_url }}" alt="" loading="lazy">@endif
                    <span class="category-mark" aria-hidden="true">{{ ['01','02','03','04','05','06'][$index % 6] }}</span>
                    <span class="category-title">{{ $category->name }}</span>
                    <span class="category-arrow" aria-hidden="true">↗</span>
                    @if(app()->environment('local') && $category->is_demo)<span class="demo-tag demo-tag-dark">Demo</span>@endif
                </a>
            @empty
                <div class="empty-state">Service categories will appear here once published by MINEX.</div>
            @endforelse
        </div>
    </div>
</section>

<section class="services-section section-pad">
    <div class="container">
        <div class="section-heading heading-split"><div><p class="eyebrow"><span class="red-rule"></span>THE SERVICE CATALOG</p><h2>Thoughtful work.<br><span class="text-muted">Done with purpose.</span></h2></div><a class="arrow-link heading-link" href="{{ route('services.index') }}">View all services <span aria-hidden="true">↗</span></a></div>
        <div class="service-grid">
            @forelse($services as $index => $service)
                @include('partials.service-card', ['service' => $service, 'index' => $index])
            @empty
                <div class="empty-state">Published services will appear here once MINEX configures the catalog.</div>
            @endforelse
        </div>
    </div>
</section>

<section class="package-section section-pad section-graphite">
    <div class="container">
        <div class="section-heading heading-split"><div><p class="eyebrow"><span class="red-rule"></span>MAINTENANCE PLANS</p><h2>One clear plan.<br><span class="text-muted">Built around the bike.</span></h2></div><p class="heading-note">Choose a starting point. The final checklist and compatible parts are confirmed with your technician.</p></div>
        <div class="package-grid">
            @forelse($packages as $package)
                @include('partials.package-card', ['package' => $package])
            @empty
                <div class="empty-state">Maintenance packages will appear here once published by MINEX.</div>
            @endforelse
        </div>
    </div>
</section>

<section class="principle-section section-pad">
    <div class="container principle-grid">
        <div class="principle-title" data-reveal><p class="eyebrow"><span class="red-rule"></span>{{ $principles['eyebrow'] ?? 'THE MINEX STANDARD' }}</p><h2>{{ $principles['title'] ?? 'Mechanical work with a human check.' }}</h2><p>{{ $principles['body'] ?? 'Your technician brings the judgment that makes a service personal to your motorcycle.' }}</p><a class="arrow-link" href="{{ route('about') }}">How we work <span aria-hidden="true">↗</span></a></div>
        <div class="principle-list">
            @foreach(($principles['items'] ?? []) as $index => $item)
                <article class="principle-item" data-reveal><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $item['title'] }}</h3><p>{{ $item['body'] }}</p></div><b aria-hidden="true">↗</b></article>
            @endforeach
        </div>
    </div>
</section>

<section class="process-section">
    <div class="process-image"><img src="{{ asset('images/service-mechanic.jpg') }}" alt="Technician's hands working carefully on a motorcycle engine" loading="lazy"></div>
    <div class="container process-overlay">
        <div class="process-heading"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>{{ $process['eyebrow'] ?? 'A BETTER SERVICE VISIT' }}</p><h2>{{ $process['title'] ?? 'Three steps. One less thing to guess.' }}</h2></div>
        <div class="process-steps">@foreach(($process['steps'] ?? []) as $index => $step)<article><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></article>@endforeach</div>
    </div>
</section>

<section class="gallery-section section-pad">
    <div class="container gallery-head"><div><p class="eyebrow"><span class="red-rule"></span>{{ $gallery['eyebrow'] ?? 'IN THE WORKSHOP' }}</p><h2>{{ $gallery['title'] ?? 'Focused on the details.' }}</h2></div><p>{{ $gallery['body'] ?? 'Good work is often quiet. It lives in careful checks, clean parts, and a clear handover.' }}</p></div>
    <div class="container gallery-grid"><figure class="gallery-large"><img src="{{ $galleryImages[0] ?? asset('images/hero-workshop.jpg') }}" alt="Motorcycle rear wheel and drivetrain on a service lift" loading="lazy"><figcaption>{{ $gallery['captions'][0] ?? 'Inspection, before recommendation' }}</figcaption></figure><figure><img src="{{ $galleryImages[1] ?? asset('images/service-transmission.jpg') }}" alt="Motorcycle in a workshop" loading="lazy"><figcaption>{{ $gallery['captions'][1] ?? 'Everyday motorcycles, properly cared for' }}</figcaption></figure><figure><img src="{{ $galleryImages[2] ?? asset('images/service-mechanic.jpg') }}" alt="Hands working on a motorcycle engine" loading="lazy"><figcaption>{{ $gallery['captions'][2] ?? 'Work explained along the way' }}</figcaption></figure></div>
</section>

<section class="closing-cta"><div class="container closing-inner"><div><p class="eyebrow red-eyebrow"><span class="red-rule"></span>{{ $cta['eyebrow'] ?? 'READY WHEN YOU ARE' }}</p><h2>{{ $cta['title'] ?? "Let's get your ride back to feeling right." }}</h2></div><a class="button button-light" href="{{ route('bookings.create') }}">{{ $cta['button'] ?? 'Request service' }} <span aria-hidden="true">↗</span></a></div></section>
@endsection
