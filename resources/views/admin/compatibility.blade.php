@extends('layouts.app')

@section('title', 'Product compatibility — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / PRODUCT FITMENT</p><h1>Verify before recommending.</h1><p>Record the source, part number, applicable trim and years. Only documented fitments can be marked compatible.</p></div><a class="text-link" href="{{ route('admin.vehicles.index') }}">← Vehicle catalog</a></div>
    @if($errors->any())<div class="admin-error">{{ $errors->first() }}</div>@endif
    <div class="compatibility-list">@forelse($variants as $variant)<article class="admin-card compatibility-card"><div><span class="eyebrow">{{ strtoupper($variant->part->part_type) }} / {{ $variant->part->brand?->name ?? 'Unbranded' }}</span><h2>{{ $variant->part->name }} — {{ $variant->name }}</h2><p>{{ $variant->part->category->name }} · SKU {{ $variant->sku ?? $variant->part->sku ?? 'not set' }}@if(app()->environment('local') && $variant->is_demo) · DEMO DATA @endif</p><small>@foreach($variant->attributeValues as $value){{ $value->attribute->name }}: {{ $value->value }} {{ $value->attribute->unit }}@if(!$loop->last) · @endif @endforeach</small>
        @forelse($variant->compatibleModels as $model)@php($fitmentTrim = $model->variants->firstWhere('id', $model->pivot->vehicle_variant_id))<div class="fitment-record"><span class="fitment-{{ $model->pivot->compatibility_status }}">{{ strtoupper($model->pivot->compatibility_status) }}</span><strong>{{ $model->brand->name }} {{ $model->name }}@if($fitmentTrim) · {{ $fitmentTrim->name }}@endif</strong><small>{{ ucfirst(str_replace('_', ' ', $model->pivot->source_type)) }} · {{ $model->pivot->source_name ?: 'No source recorded' }}{{ $model->pivot->source_reference ? ' · '.$model->pivot->source_reference : '' }}{{ $model->pivot->part_number ? ' · Part no. '.$model->pivot->part_number : '' }}@if($model->pivot->compatible_year_from || $model->pivot->compatible_year_to) · {{ $model->pivot->compatible_year_from ?? '…' }}–{{ $model->pivot->compatible_year_to ?? '…' }}@endif @if($model->pivot->verified_at) · verified {{ $model->pivot->verified_at->format('d M Y') }}@endif</small></div>@empty<div class="fitment-record"><span class="fitment-unverified">UNVERIFIED</span><strong>No vehicle fitment recorded</strong></div>@endforelse
    </div><form class="admin-form compatibility-form" action="{{ route('admin.compatibility.update', $variant) }}" method="POST">@csrf
        <label>Motorcycle model<select name="vehicle_model_id" data-fitment-model required><option value="">Choose model</option>@foreach($models as $model)<option value="{{ $model->id }}">{{ $model->brand->name }} {{ $model->name }}</option>@endforeach</select></label>
        <label>Specific trim (optional)<select name="vehicle_variant_id" data-fitment-variant><option value="">All trims for this model</option>@foreach($models as $model)@foreach($model->variants as $trim)<option value="{{ $trim->id }}" data-model-id="{{ $model->id }}">{{ $model->brand->name }} {{ $model->name }} · {{ $trim->name }}</option>@endforeach @endforeach</select></label>
        <label>Status<select name="compatibility_status" data-fitment-status required><option value="unverified">Unverified</option><option value="compatible">Verified compatible</option><option value="incompatible">Verified incompatible</option></select></label>
        <label>Source type<select name="source_type" required><option value="unverified">Unverified / suggestion</option><option value="manufacturer_catalog">Manufacturer application catalog</option><option value="aftermarket_catalog">Aftermarket application catalog</option><option value="mechanic_confirmed">Mechanic confirmed</option></select></label>
        <label>Source name<input name="source_name" maxlength="160" placeholder="Manufacturer / catalog publisher"></label><label>Source document or reference<input name="source_reference" maxlength="255" placeholder="URL, document, or catalog reference"></label>
        <label>Matching part number / SKU<input name="part_number" maxlength="120" placeholder="Exact part number, when available"></label><label>Year from<input name="compatible_year_from" type="number" min="1950" max="2100" placeholder="Optional"></label><label>Year to<input name="compatible_year_to" type="number" min="1950" max="2100" placeholder="Optional"></label>
        <label class="span-two">Notes<textarea name="notes" rows="2" maxlength="2000" placeholder="Fitment caveats, market, or verification notes"></textarea></label><button class="button button-outline button-small" type="submit">Save fitment record</button>
    </form></article>@empty<div class="empty-state">Create a part and variant before recording compatibility.</div>@endforelse</div>
</div></section>
<script>
document.querySelectorAll('.compatibility-form').forEach((form) => {
    const model = form.querySelector('[data-fitment-model]');
    const trim = form.querySelector('[data-fitment-variant]');
    const status = form.querySelector('[data-fitment-status]');
    const sourceType = form.querySelector('[name="source_type"]');
    const sourceName = form.querySelector('[name="source_name"]');
    const sourceReference = form.querySelector('[name="source_reference"]');
    const syncTrims = () => {
        trim.value = '';
        [...trim.options].forEach((option) => {
            if (option.value) {
                option.hidden = option.dataset.modelId !== model.value;
                option.disabled = option.hidden;
            }
        });
    };
    const syncSource = () => {
        const verified = status.value !== 'unverified';
        sourceType.value = verified && sourceType.value === 'unverified' ? 'manufacturer_catalog' : sourceType.value;
        if (!verified) sourceType.value = 'unverified';
        sourceName.required = verified;
        sourceReference.required = verified;
    };
    model.addEventListener('change', syncTrims);
    status.addEventListener('change', syncSource);
    syncTrims();
    syncSource();
});
</script>
@endsection
