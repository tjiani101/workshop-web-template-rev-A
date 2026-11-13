const rupiahLabel = (value) => `Rp ${new Intl.NumberFormat('id-ID').format(value)}`;

const updatePackagePrices = async (form, variantId, year) => {
    const endpoint = form.dataset.modelPricingEndpoint;
    if (!endpoint || !variantId || !year) return;
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('vehicle_variant_id', variantId);
    url.searchParams.set('model_year', year);
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Unable to load model-specific package prices.');
        const result = await response.json();
        const prices = new Map(result.packages.map((item) => [item.slug, item]));
        form.querySelectorAll('[data-quote-package]').forEach((packageElement) => {
            const price = prices.get(packageElement.dataset.packageSlug);
            const label = packageElement.querySelector('[data-package-price-label]');
            if (price) {
                packageElement.dataset.basePrice = price.base_rupiah;
                packageElement.dataset.laborPrice = price.labor_rupiah;
                packageElement.dataset.discount = price.discount_rupiah;
                label.textContent = `${rupiahLabel(price.total_rupiah)} for ${result.vehicle.name}`;
            } else if (packageElement.dataset.packageSlug?.startsWith('bore-up-')) {
                packageElement.dataset.basePrice = '';
                packageElement.dataset.laborPrice = '';
                label.textContent = 'Configuration requires mechanic assessment';
            }
        });
        form.querySelector('[data-booking-service]')?.dispatchEvent(new Event('change', { bubbles: true }));
        form.querySelector('input[name="package_ids[]"]')?.dispatchEvent(new Event('change', { bubbles: true }));
    } catch {
        form.querySelectorAll('[data-package-price-label]').forEach((label) => {
            if (label.closest('[data-quote-package]')?.dataset.packageSlug?.startsWith('bore-up-')) label.textContent = 'Select motorcycle for a tailored estimate';
        });
    }
};

document.querySelectorAll('[data-booking-form]').forEach((form) => {
    form.addEventListener('change', (event) => {
        const picker = form.querySelector('[data-vehicle-picker]');
        if (picker && event.target.matches('[data-vehicle-year], [data-vehicle-variant]')) {
            updatePackagePrices(form, picker.querySelector('[data-vehicle-variant]')?.value, picker.querySelector('[data-vehicle-year]')?.value);
        }
        if (event.target.name === 'customer_vehicle_id') {
            updatePackagePrices(form, event.target.dataset.vehicleVariantId, event.target.dataset.modelYear);
        }
    });
    const selectedGarageVehicle = form.querySelector('[name="customer_vehicle_id"]:checked');
    if (selectedGarageVehicle) updatePackagePrices(form, selectedGarageVehicle.dataset.vehicleVariantId, selectedGarageVehicle.dataset.modelYear);
});

document.querySelectorAll('[data-model-pricing]').forEach((root) => {
    const picker = root.querySelector('[data-vehicle-picker]');
    const results = root.querySelector('[data-model-price-results]');
    let requestId = 0;
    picker?.addEventListener('change', async (event) => {
        if (!event.target.matches('[data-vehicle-year], [data-vehicle-variant]')) return;
        const variantId = picker.querySelector('[data-vehicle-variant]')?.value;
        const year = picker.querySelector('[data-vehicle-year]')?.value;
        if (!variantId || !year) return;
        const currentRequest = ++requestId;
        results.replaceChildren(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Checking verified package configurations…' }));
        const url = new URL(root.dataset.endpoint, window.location.origin);
        url.searchParams.set('vehicle_variant_id', variantId);
        url.searchParams.set('model_year', year);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('We could not verify package prices for this motorcycle. Request a mechanic assessment.');
            const result = await response.json();
            if (currentRequest !== requestId) return;
            const cards = result.packages.filter((item) => item.slug.startsWith('bore-up-')).map((item) => {
                const article = document.createElement('article');
                article.className = 'model-price-card';
                const heading = document.createElement('h3'); heading.textContent = item.name;
                const price = document.createElement('strong'); price.textContent = `${rupiahLabel(item.total_rupiah)} for ${result.vehicle.name}`;
                const details = document.createElement('dl');
                [['Parts', item.parts_rupiah], ['Fixed labor', item.labor_rupiah], ['Consumables', item.consumables_rupiah], ['Discount', item.discount_rupiah]].forEach(([name, value]) => {
                    const line = document.createElement('div'); const term = document.createElement('dt'); const description = document.createElement('dd');
                    term.textContent = name; description.textContent = rupiahLabel(value); line.append(term, description); details.append(line);
                });
                const list = document.createElement('ul'); item.inclusions.forEach((inclusion) => { const li = document.createElement('li'); li.textContent = inclusion; list.append(li); });
                const link = document.createElement('a'); link.href = item.url; link.textContent = 'Explore package';
                article.append(heading, price, details, list, link);
                return article;
            });
            if (cards.length) results.replaceChildren(...cards);
            else results.replaceChildren(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Configuration requires mechanic assessment. No published Bore-Up price is verified for this motorcycle.' }));
        } catch (error) {
            if (currentRequest === requestId) results.replaceChildren(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: error.message }));
        }
    });
});
