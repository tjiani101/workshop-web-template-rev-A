@extends('layouts.app')

@section('title', 'Transparent Service Pricing — MINEX')
@section('meta_description', 'Clear fixed labor costs, configurable motorcycle parts, and model-specific package estimates from MINEX.')

@section('content')
<section class="plain-page-hero pricing-hero"><div class="container"><p class="eyebrow"><span class="red-rule"></span>MINEX / PRICING</p><h1>Transparent service pricing</h1><p>Clear labor costs. Configurable parts. Approved work only.</p><div class="breadcrumbs"><a href="{{ route('home') }}">Home</a><span>／</span><span>Pricing</span></div></div></section>
<section class="section-pad pricing-vehicle-lookup" data-model-pricing data-endpoint="{{ route('pricing.vehicle-packages') }}">
    <div class="container pricing-vehicle-layout"><div><p class="eyebrow">MODEL-SPECIFIC ENGINE CONFIGURATIONS</p><h2>Choose your motorcycle<br>for a tailored estimate.</h2><p class="booking-note">Only verified, published package configurations for your model and year appear here. If none match, MINEX will assess the build before quoting.</p></div><div class="pricing-vehicle-panel">@include('partials.vehicle-picker', ['vehicleCatalogUrl' => route('vehicle-catalog.index'), 'variantName' => 'vehicle_variant_id', 'selectedVariant' => null, 'selectedYear' => null])<div class="model-price-results" data-model-price-results aria-live="polite"><p class="booking-note">Select model year and factory variant to check published Bore-Up configurations.</p></div></div></div>
</section>
<section class="pricing-rate-band"><div class="container"><div><span class="eyebrow">FIXED ESTIMATES</span><p>Standard labor references are used to prepare a fixed quote before work starts. Actual time on the job never changes your approved labor price.</p></div><div class="pricing-rate-list">@foreach(['cvt' => 'CVT / transmission', 'frame' => 'Frame', 'engine' => 'Engine', 'electrical_diagnosis' => 'Electrical diagnosis · proposed', 'electrical_installation' => 'Electrical installation · proposed'] as $key => $label)<span><small>{{ $label }}</small>@if($laborRates[$key]['active'] && ! $laborRates[$key]['provisional'])<strong>Rp {{ number_format($laborRates[$key]['amount_rupiah'], 0, ',', '.') }} <small>/ {{ $laborRates[$key]['interval_minutes'] }} min unit</small></strong>@elseif($laborRates[$key]['provisional'])<strong>Proposed: Rp {{ number_format($laborRates[$key]['amount_rupiah'], 0, ',', '.') }}</strong><small>Pending owner confirmation</small>@else<strong>Inactive</strong>@endif</span>@endforeach</div></div></section>
<section class="section-pad pricing-card-catalog"><div class="container">
    @foreach($pricingGroups as $group)
        @if($group['services']->isNotEmpty() || $group['packages']->isNotEmpty())
            <section class="pricing-category" data-reveal><div class="catalog-intro"><div><p class="eyebrow">SERVICE CATALOG</p><h2>{{ $group['heading'] }}</h2></div><p>Parts and work are confirmed for your motorcycle. Any added work is quoted separately for your approval.</p></div>
                <div class="pricing-card-grid">
                    @foreach($group['services'] as $service)
                        @php($rate = $laborRates[$service->labor_category] ?? null)
                        <article class="pricing-card service-pricing-card"><img src="{{ $service->image_url ?? asset('images/service-mechanic.jpg') }}" alt="Illustration for {{ $service->name }}" loading="lazy"><div class="pricing-card-copy"><span class="eyebrow">{{ $service->category->name }}</span><h3>{{ $service->name }}</h3><p>{{ $service->summary }}</p><strong class="pricing-card-price">{{ $service->labor_pricing_mode === 'fixed' && $service->labor_price_rupiah !== null ? 'Mulai dari Rp '.number_format($service->labor_price_rupiah, 0, ',', '.') : ($rate && $rate['provisional'] ? 'Pending owner confirmation' : ($rate && $rate['active'] ? 'From Rp '.number_format($rate['amount_rupiah'], 0, ',', '.').' / '.$rate['interval_minutes'].' min standard unit' : 'Pilih motor untuk estimasi yang sesuai')) }}</strong><small>{{ $service->duration_minutes ? $service->duration_minutes.' min estimated' : 'Duration confirmed after assessment' }}</small><a href="{{ route('services.show', $service) }}">Explore details <span>↗</span></a></div></article>
                    @endforeach
                    @foreach($group['packages'] as $package)
                        @php($isBoreUp = str_starts_with($package->slug, 'bore-up-'))
                        <article class="pricing-card package-pricing-card"><img src="{{ $package->image_url }}" alt="Original illustration for {{ $package->name }}" loading="lazy"><div class="pricing-card-copy"><span class="eyebrow">{{ $package->category?->name ?? 'Workshop package' }}</span><h3>{{ $package->name }}</h3><p>{{ $package->summary }}</p><ul>@foreach($package->items->where('is_required', true)->take(4) as $item)<li>{{ $item->label }}</li>@endforeach</ul><strong class="pricing-card-price">{{ $isBoreUp ? 'Select your motorcycle for a tailored estimate' : ($package->starting_price_rupiah !== null ? 'Mulai dari Rp '.number_format($package->starting_price_rupiah, 0, ',', '.') : 'Price to be confirmed after assessment') }}</strong><small>{{ $package->duration_minutes ? $package->duration_minutes.' min estimated' : 'Duration confirmed after fitment review' }}</small><a href="{{ route('packages.show', $package) }}">Explore details <span>↗</span></a></div></article>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
    @if($services->isEmpty() && $packages->isEmpty())<div class="empty-state">Service configurations are being prepared. Contact MINEX for a motorcycle-specific assessment.</div>@endif
</div></section>
<section class="simple-cta"><div class="container simple-cta-inner"><div><span class="eyebrow">NO VERIFIED CONFIGURATION YET?</span><h2>Request a mechanic assessment.</h2></div><a class="button button-red" href="{{ route('bookings.create') }}">Request an estimate <span aria-hidden="true">↗</span></a></div></section>
@endsection
@push('scripts')<script src="{{ asset('js/vehicle-catalog-picker.js') }}" defer></script><script src="{{ asset('js/custom-select.js') }}" defer></script><script src="{{ asset('js/package-pricing.js') }}" defer></script>@endpush
