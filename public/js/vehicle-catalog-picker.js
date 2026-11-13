const vehicleCatalogCache = new Map();

const loadVehicleCatalog = async (endpoint) => {
    if (!vehicleCatalogCache.has(endpoint)) {
        vehicleCatalogCache.set(endpoint, fetch(endpoint, { headers: { Accept: 'application/json' } }).then((response) => {
            if (!response.ok) throw new Error('Vehicle catalog is temporarily unavailable.');
            return response.json();
        }));
    }
    return vehicleCatalogCache.get(endpoint);
};

const replaceOptions = (select, placeholder, values) => {
    select.replaceChildren(new Option(placeholder, ''));
    values.forEach(({ label, value }) => select.add(new Option(label, value)));
    select.disabled = values.length === 0;
};

document.querySelectorAll('[data-vehicle-picker]').forEach(async (picker) => {
    const brandSelect = picker.querySelector('[data-vehicle-brand]');
    const categorySelect = picker.querySelector('[data-vehicle-category]');
    const modelSelect = picker.querySelector('[data-vehicle-model]');
    const generationSelect = picker.querySelector('[data-vehicle-generation]');
    const yearSelect = picker.querySelector('[data-vehicle-year]');
    const variantSelect = picker.querySelector('[data-vehicle-variant]');
    const summary = picker.querySelector('[data-vehicle-summary] span');
    const source = picker.querySelector('[data-vehicle-source]');
    const getVehicleRequestForm = () => picker.closest('form')?.querySelector('[data-vehicle-model-request]')
        || picker.closest('.booking-layout')?.querySelector('[data-vehicle-model-request]');
    let brands = [];
    let selectedModel;
    let selectedGeneration;
    let selectedYear;
    let selectedVariant;

    const clearBelowBrand = () => {
        replaceOptions(categorySelect, 'Choose manufacturer first', []);
        replaceOptions(modelSelect, 'Choose category first', []);
        replaceOptions(generationSelect, 'Choose model first', []);
        replaceOptions(yearSelect, 'Choose generation first', []);
        replaceOptions(variantSelect, 'Choose model year first', []);
        selectedModel = selectedGeneration = selectedYear = selectedVariant = undefined;
        summary.textContent = 'Choose a manufacturer, model year, and trim.';
        source.textContent = '';
    };

    const clearBelowCategory = () => {
        replaceOptions(modelSelect, 'Choose category first', []);
        replaceOptions(generationSelect, 'Choose model first', []);
        replaceOptions(yearSelect, 'Choose generation first', []);
        replaceOptions(variantSelect, 'Choose model year first', []);
        selectedModel = selectedGeneration = selectedYear = selectedVariant = undefined;
        summary.textContent = 'Choose a model family.';
        source.textContent = '';
    };

    const clearBelowModel = () => {
        replaceOptions(generationSelect, 'Choose model first', []);
        replaceOptions(yearSelect, 'Choose generation first', []);
        replaceOptions(variantSelect, 'Choose model year first', []);
        selectedGeneration = selectedYear = selectedVariant = undefined;
        summary.textContent = selectedModel ? `${selectedModel.brandName} ${selectedModel.name} — model-year data needs workshop review.` : 'Choose a model family.';
        source.textContent = selectedModel?.verification_status === 'verified' ? '' : 'Model family is listed for a request; exact generation and trim need verification.';
    };

    const clearBelowGeneration = () => {
        replaceOptions(yearSelect, 'Choose generation first', []);
        replaceOptions(variantSelect, 'Choose model year first', []);
        selectedYear = selectedVariant = undefined;
        summary.textContent = 'Choose a valid model year.';
    };

    const clearBelowYear = () => {
        replaceOptions(variantSelect, 'Choose model year first', []);
        selectedVariant = undefined;
        summary.textContent = 'Choose a trim for this model year.';
    };

    try {
        brands = await loadVehicleCatalog(picker.dataset.endpoint);
        replaceOptions(brandSelect, 'Choose manufacturer', brands.map((brand) => ({ label: brand.name, value: brand.id })));
    } catch (error) {
        source.textContent = error.message;
    }

    brandSelect.addEventListener('change', () => {
        clearBelowBrand();
        const brand = brands.find((item) => String(item.id) === brandSelect.value);
        const categories = new Map((brand?.models || []).map((model) => [model.category.id, model.category]));
        replaceOptions(categorySelect, 'Choose category', [...categories.values()].sort((a, b) => a.name.localeCompare(b.name)).map((category) => ({ label: category.name, value: category.id })));
    });

    categorySelect.addEventListener('change', () => {
        clearBelowCategory();
        const brand = brands.find((item) => String(item.id) === brandSelect.value);
        const models = (brand?.models || []).filter((model) => String(model.category_id) === categorySelect.value);
        replaceOptions(modelSelect, models.length ? 'Choose model' : 'No models in this category', models.map((model) => ({ label: `${model.name}${model.verification_status === 'verified' ? '' : ' · needs verification'}`, value: model.id })));
    });

    modelSelect.addEventListener('change', () => {
        clearBelowModel();
        const brand = brands.find((item) => String(item.id) === brandSelect.value);
        selectedModel = brand?.models.find((item) => String(item.id) === modelSelect.value);
        const requestForm = getVehicleRequestForm();
        if (requestForm && selectedModel && brand) {
            requestForm.querySelector('[name="manufacturer"]').value = brand.name;
            requestForm.querySelector('[name="model_name"]').value = selectedModel.name;
            requestForm.querySelector('[name="model_year"]').value = '';
            requestForm.querySelector('[name="variant_details"]').value = '';
        }
        const generations = (selectedModel?.generations || []).filter((item) => item.years?.length);
        replaceOptions(generationSelect, generations.length ? 'Choose generation' : 'No verified model years yet', generations.map((item) => ({ label: `${item.name}${item.verification_status === 'verified' ? '' : ' · needs verification'}`, value: item.id })));
    });

    generationSelect.addEventListener('change', () => {
        clearBelowGeneration();
        selectedGeneration = selectedModel?.generations.find((item) => String(item.id) === generationSelect.value);
        replaceOptions(yearSelect, 'Choose model year', (selectedGeneration?.years || []).map((year) => ({ label: `${year.model_year}${year.verification_status === 'verified' ? '' : ' · needs verification'}`, value: year.model_year })));
    });

    yearSelect.addEventListener('change', () => {
        clearBelowYear();
        selectedYear = selectedGeneration?.years.find((year) => String(year.model_year) === yearSelect.value);
        const requestForm = getVehicleRequestForm();
        if (requestForm) requestForm.querySelector('[name="model_year"]').value = selectedYear?.model_year || '';
        const variants = selectedYear?.variants || [];
        replaceOptions(variantSelect, variants.length ? 'Choose trim' : 'No verified trims for this year', variants.map((variant) => ({ label: `${variant.name}${variant.verification_status === 'verified' ? '' : ' · needs verification'}`, value: variant.id })));
    });

    variantSelect.addEventListener('change', () => {
        selectedVariant = selectedYear?.variants.find((item) => String(item.id) === variantSelect.value);
        if (!selectedVariant || !selectedModel || !selectedGeneration || !selectedYear) {
            summary.textContent = 'Choose a manufacturer, model year, and trim.';
            return;
        }
        const brand = brands.find((item) => String(item.id) === brandSelect.value);
        const attributes = (selectedVariant.attributes || []).map((item) => `${item.name}: ${item.value}${item.unit ? ` ${item.unit}` : ''}`).join(' · ');
        summary.textContent = `${brand.name} ${selectedModel.name} · ${selectedGeneration.name} · ${selectedYear.model_year} · ${selectedVariant.name}${attributes ? ` — ${attributes}` : ''}`;
        source.textContent = selectedVariant.verification_status === 'verified' ? `Source: ${selectedVariant.data_source}` : 'This trim is not verified; MINEX will confirm exact fitment before approval.';
        const requestForm = getVehicleRequestForm();
        if (requestForm) requestForm.querySelector('[name="variant_details"]').value = selectedVariant.name;
    });

    const chosenVariant = picker.dataset.selectedVariant;
    const chosenYear = picker.dataset.selectedYear;
    if (chosenVariant) {
        const modelMatch = brands.flatMap((brand) => brand.models.map((model) => ({ brand, model }))).find(({ model }) => model.generations.some((generation) => generation.years.some((year) => year.variants.some((variant) => String(variant.id) === String(chosenVariant)))));
        if (modelMatch) {
            brandSelect.value = String(modelMatch.brand.id);
            brandSelect.dispatchEvent(new Event('change'));
            categorySelect.value = String(modelMatch.model.category_id);
            categorySelect.dispatchEvent(new Event('change'));
            modelSelect.value = String(modelMatch.model.id);
            modelSelect.dispatchEvent(new Event('change'));
            const generation = modelMatch.model.generations.find((item) => item.years.some((year) => year.variants.some((variant) => String(variant.id) === String(chosenVariant))));
            if (generation) {
                generationSelect.value = String(generation.id);
                generationSelect.dispatchEvent(new Event('change'));
                yearSelect.value = String(chosenYear);
                yearSelect.dispatchEvent(new Event('change'));
                variantSelect.value = String(chosenVariant);
                variantSelect.dispatchEvent(new Event('change'));
            }
        }
    }
});

document.querySelectorAll('[data-vehicle-model-request]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = form.querySelector('[data-request-status]');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        status.textContent = 'Sending request…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Could not send the request. Check the details and try again.');
            status.textContent = result.message;
            form.reset();
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
});
