<div class="vehicle-catalog-picker" data-vehicle-picker data-endpoint="{{ $vehicleCatalogUrl }}" data-variant-name="{{ $variantName }}" data-selected-variant="{{ $selectedVariant ?? '' }}" data-selected-year="{{ $selectedYear ?? '' }}">
    <div class="vehicle-picker-grid">
        <label>Manufacturer<select data-vehicle-brand required><option value="">Choose manufacturer</option></select></label>
        <label>Motorcycle category<select data-vehicle-category required disabled><option value="">Choose manufacturer first</option></select></label>
        <label>Model family<select data-vehicle-model required disabled><option value="">Choose category first</option></select></label>
        <label>Generation<select data-vehicle-generation required disabled><option value="">Choose model first</option></select></label>
        <label>Model year<select name="model_year" data-vehicle-year required disabled><option value="">Choose generation first</option></select></label>
        <label>Factory variant / trim<select name="{{ $variantName }}" data-vehicle-variant required disabled><option value="">Choose model year first</option></select></label>
    </div>
    <div class="vehicle-selection-summary" data-vehicle-summary aria-live="polite"><strong>Motorcycle summary</strong><span>Choose a manufacturer, model year, and trim.</span></div>
    <p class="field-help" data-vehicle-source></p>
    <noscript><p class="booking-note">Vehicle selection requires JavaScript so each generation and trim can be checked against its model year.</p></noscript>
</div>
