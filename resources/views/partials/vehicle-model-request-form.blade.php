<form class="vehicle-model-request-form admin-form" action="{{ $modelRequestUrl }}" method="POST" data-vehicle-model-request>
    @csrf
    <p class="field-help">Requests are reviewed by the workshop. They do not add unverified trims to the customer catalog.</p>
    <label>Manufacturer<select name="manufacturer" required><option value="">Choose manufacturer</option>@foreach(['Honda', 'Yamaha', 'Suzuki', 'Vespa'] as $manufacturer)<option value="{{ $manufacturer }}">{{ $manufacturer }}</option>@endforeach</select></label>
    <label>Model family<input name="model_name" required maxlength="160" placeholder="e.g. FreeGo 125"></label>
    <label>Model year <span class="optional-label">Optional</span><input type="number" name="model_year" min="1950" max="{{ now()->year + 1 }}"></label>
    <label>Variant / trim <span class="optional-label">Optional</span><input name="variant_details" maxlength="255" placeholder="e.g. S ABS"></label>
    <label>Details <span class="optional-label">Optional</span><textarea name="notes" rows="2" maxlength="1500" placeholder="Share a registration document detail or model code if known."></textarea></label>
    <p class="field-help" data-request-status role="status"></p>
    <button class="button button-outline button-small" type="submit">Request this vehicle record</button>
</form>
