@extends('layouts.app')

@section('title', $package->name.' — MINEX')
@section('meta_description', $package->summary)

@section('content')
<section class="detail-hero">
    <div class="detail-image"><img src="{{ $package->image_url ?: asset('images/hero-workshop.jpg') }}" alt="Motorcycle maintenance package" fetchpriority="high"></div><div class="detail-image-overlay"></div>
    <div class="container detail-hero-copy"><div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span>／</span><a href="{{ route('packages.index') }}">Packages</a><span>／</span><span>{{ $package->name }}</span></div><p class="eyebrow red-eyebrow"><span class="red-rule"></span>{{ $package->category?->name ?? 'Maintenance plan' }}</p><h1>{{ $package->name }}</h1><p>{{ $package->summary }}</p><a class="button button-red" href="{{ route('bookings.create', ['package' => $package->slug]) }}">Request this plan <span aria-hidden="true">↗</span></a></div>
</section>
<section class="detail-body section-pad"><div class="container detail-grid"><div class="detail-main"><p class="eyebrow">PACKAGE CONTENTS</p><h2>A checklist you<br>can understand.</h2><p class="detail-description">{{ $package->description }}</p><ol class="package-detail-list">@foreach($package->items as $item)<li><span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $item->label }}</h3><p>{{ $item->is_required ? 'Included in the base scope' : 'Optional — approved separately' }}</p></div>@if(!$item->is_required)<b>Optional</b>@endif</li>@endforeach</ol></div>
    <aside class="detail-aside"><span class="eyebrow">PLAN SUMMARY</span><dl><div><dt>Starting price</dt><dd>{{ $package->starting_price_rupiah !== null ? 'Rp '.number_format($package->starting_price_rupiah, 0, ',', '.') : 'Confirmed after assessment' }}</dd></div><div><dt>Estimated duration</dt><dd>{{ $package->duration_minutes ? $package->duration_minutes.' minutes' : 'Confirmed with workshop' }}</dd></div><div><dt>Vehicle fitment</dt><dd>Confirmed by technician</dd></div></dl><p>Parts, fitment, and any optional upgrades are checked for your exact motorcycle.</p><a class="button button-red button-full" href="{{ route('bookings.create', ['package' => $package->slug]) }}">Request this plan <span aria-hidden="true">↗</span></a></aside>
</div></section>
@endsection
