<article class="service-card">
    <a class="service-card-image" href="{{ route('services.show', $service) }}">
        <img src="{{ $service->image_url ?: asset('images/service-mechanic.jpg') }}" alt="Motorcycle service: {{ $service->name }}" loading="lazy">
        <span class="card-index">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
        <span class="card-arrow" aria-hidden="true">↗</span>
    </a>
    <div class="service-card-copy">
        <span class="eyebrow">{{ $service->category?->name ?? 'Workshop service' }}</span>
        <h3><a href="{{ route('services.show', $service) }}">{{ $service->name }}</a></h3>
        <p>{{ $service->summary }}</p>
        <div class="card-meta"><span>{{ $service->duration_minutes ? $service->duration_minutes.' min' : 'Timing confirmed with workshop' }}</span><span>{{ $service->labor_price_rupiah !== null ? 'From Rp '.number_format($service->labor_price_rupiah, 0, ',', '.') : 'Price confirmed after assessment' }}</span></div>
    </div>
</article>
