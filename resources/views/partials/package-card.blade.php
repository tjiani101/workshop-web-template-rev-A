<article class="package-card">
    <div class="package-card-image"><img src="{{ $package->image_url }}" alt="Original MINEX illustration for {{ $package->name }}" loading="lazy"></div>
    <div class="package-card-top">
        @php($packageCategory = str_starts_with($package->slug, 'bore-up-') ? 'Engine performance' : (str_contains($package->slug, 'oil-cooler') ? 'Oil cooling' : (str_contains($package->slug, 'rewir') || str_contains($package->slug, 'underglow') ? 'Electrical system' : 'Routine maintenance')))
        <span class="eyebrow">{{ $packageCategory }}</span>
        <h3>{{ $package->name }}</h3>
        <p>{{ $package->summary }}</p>
    </div>
    <ul class="package-item-list">
        @foreach($package->items->take(4) as $item)
            <li><span class="check-mark">✓</span>{{ $item->label }}</li>
        @endforeach
        @if($package->items->count() > 4)<li class="muted-list-item">+ {{ $package->items->count() - 4 }} more configured tasks</li>@endif
    </ul>
    <div class="package-card-bottom">
        <span>{{ $package->duration_minutes ? $package->duration_minutes.' min estimated' : 'Scope and timing confirmed first' }}</span>
        <strong>{{ $package->starting_price_rupiah !== null ? 'Mulai dari Rp '.number_format($package->starting_price_rupiah, 0, ',', '.') : (str_starts_with($package->slug, 'bore-up-') ? 'Select motorcycle for estimate' : 'Assessment before quote') }}</strong>
        <a href="{{ route('packages.show', $package) }}" aria-label="View {{ $package->name }}">Explore package <span aria-hidden="true">↗</span></a>
    </div>
</article>
