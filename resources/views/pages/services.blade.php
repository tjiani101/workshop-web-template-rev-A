@extends('layouts.app')

@section('title', 'Services — MINEX Motorcycle Workshop')
@section('meta_description', 'Explore motorcycle maintenance, inspection, and technical services from MINEX.')

@section('content')
<section class="catalog-hero">
    <div class="catalog-hero-image"><img src="{{ asset('images/service-transmission.jpg') }}" alt="Motorcycle drivetrain being serviced" fetchpriority="high"></div><div class="catalog-hero-scrim"></div>
    <div class="container catalog-hero-content"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>MINEX / SERVICE INDEX</p><h1>Service for the<br>ride you rely on.</h1><p>Careful motorcycle maintenance, with scope and options made clear before work begins.</p><div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span>／</span><span>Services</span></div></div>
</section>
<section class="catalog-body section-pad">
    <div class="container">
        <div class="catalog-intro"><div><p class="eyebrow">A CLEAR STARTING POINT</p><h2>Every service begins<br>with an honest check.</h2></div><p>Published services show the work MINEX can configure. Exact fit, parts, and timing are confirmed for your motorcycle before a booking is finalized.</p></div>
        <div class="service-area-index"><span class="eyebrow">SERVICE AREAS</span>@foreach($categories as $category)<span class="service-area-pill">{{ $category->name }}</span>@endforeach</div>
        <div class="service-grid">
            @forelse($services as $index => $service)
                @include('partials.service-card', ['service' => $service, 'index' => $index])
            @empty
                <div class="empty-state">The public service catalog is being configured. Please check again soon.</div>
            @endforelse
        </div>
    </div>
</section>
<section class="simple-cta"><div class="container simple-cta-inner"><div><span class="eyebrow">NOT SURE WHERE TO START?</span><h2>Tell us what feels different.</h2></div><a class="button button-red" href="{{ route('bookings.create') }}">Request service <span aria-hidden="true">↗</span></a></div></section>
@endsection
