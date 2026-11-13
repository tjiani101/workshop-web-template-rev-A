@extends('layouts.app')

@section('title', $service->name.' — MINEX')
@section('meta_description', $service->summary)

@section('content')
<section class="detail-hero">
    <div class="detail-image"><img src="{{ $service->image_url ?: asset('images/service-mechanic.jpg') }}" alt="{{ $service->name }} motorcycle service" fetchpriority="high"></div><div class="detail-image-overlay"></div>
    <div class="container detail-hero-copy"><div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span>／</span><a href="{{ route('services.index') }}">Services</a><span>／</span><span>{{ $service->category->name }}</span></div><p class="eyebrow red-eyebrow"><span class="red-rule"></span>{{ $service->category->name }}</p><h1>{{ $service->name }}</h1><p>{{ $service->summary }}</p><a class="button button-red" href="{{ route('bookings.create', ['service' => $service->slug]) }}">Request this service <span aria-hidden="true">↗</span></a></div>
</section>
<section class="detail-body section-pad"><div class="container detail-grid">
    <div class="detail-main"><p class="eyebrow">SERVICE NOTES</p><h2>Know the work<br>before it starts.</h2><p class="detail-description">{{ $service->description }}</p><div class="detail-inclusions"><div><h3>What we check</h3><ul>@foreach($service->included_items ?? [] as $item)<li>{{ $item }}</li>@endforeach</ul></div><div><h3>Outside this scope</h3><ul>@foreach($service->excluded_items ?? [] as $item)<li>{{ $item }}</li>@endforeach</ul></div></div><div class="compatibility-note"><span>FITMENT / 01</span><p>Compatibility is confirmed by a technician for your exact motorcycle before parts are selected.</p></div></div>
    <aside class="detail-aside"><span class="eyebrow">SERVICE SUMMARY</span><dl><div><dt>Category</dt><dd>{{ $service->category->name }}</dd></div><div><dt>Estimated duration</dt><dd>{{ $service->duration_minutes ? $service->duration_minutes.' minutes' : 'Confirmed with workshop' }}</dd></div><div><dt>Labor price</dt><dd>{{ $service->labor_price_rupiah !== null ? 'Rp '.number_format($service->labor_price_rupiah, 0, ',', '.') : 'Confirmed after assessment' }}</dd></div></dl><p>Any additional parts or work are shown to you before approval.</p><a class="button button-red button-full" href="{{ route('bookings.create', ['service' => $service->slug]) }}">Request service <span aria-hidden="true">↗</span></a></aside>
</div></section>
@endsection
