document.querySelectorAll('.booking-form').forEach((form) => {
    const lines = form.querySelector('[data-quote-lines]');
    const total = form.querySelector('[data-quote-total]');
    const mobileTotal = form.querySelector('[data-mobile-estimate-total]');
    const message = form.querySelector('[data-quote-message]');
    if (!lines || !total || !message) return;

    const rupiah = new Intl.NumberFormat('id-ID');
    const laborRates = JSON.parse(form.dataset.laborRates || '{}');
    const displayRupiah = (amount) => `Rp ${rupiah.format(amount)}`;
    const parsePrice = (value) => value === '' ? null : BigInt(value);
    const quantityTotal = (quantity, price) => {
        const [whole, fraction = ''] = quantity.split('.');
        const hundredths = BigInt(whole || '0') * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2) || '0');
        return (hundredths * price + 50n) / 100n;
    };

    const render = () => {
        const entries = [];
        let knownTotal = 0n;
        let hasUnknownPrice = false;

        const selectedServices = [];
        const timeGroups = new Map();
        form.querySelectorAll('[data-quote-service]').forEach((item) => {
            if (!item.querySelector('input')?.checked) return;
            const serviceId = item.querySelector('input[name="service_ids[]"]').value;
            const extraMinutes = Array.from(form.querySelectorAll(`[data-quote-part][data-service-id="${serviceId}"]`)).reduce((sum, part) => {
                const selected = part.dataset.required === 'true' || Boolean(part.querySelector('input')?.checked);
                return sum + (selected ? Number(part.dataset.duration || 0) : 0);
            }, 0);
            if (item.dataset.pricingMode !== 'time_based') {
                entries.push({ label: item.dataset.quoteLabel, price: parsePrice(item.dataset.unitPrice || '') });
                return;
            }
            const duration = Number(item.dataset.duration || 0) + extraMinutes;
            const sharedMinutes = item.dataset.laborGroup ? Number(item.dataset.sharedOperationMinutes || 0) : 0;
            const key = `${item.dataset.laborGroup || `service-${serviceId}`}|${item.dataset.laborCategory}`;
            if (!laborRates[item.dataset.laborCategory]?.active || laborRates[item.dataset.laborCategory]?.provisional || duration < 1) {
                entries.push({ label: item.dataset.quoteLabel, price: null });
                return;
            }
            const group = timeGroups.get(key) || { entries: [], totalMinutes: 0, sharedMinutes: 0, rate: laborRates[item.dataset.laborCategory] };
            const exclusiveMinutes = Math.max(0, duration - sharedMinutes);
            group.entries.push({ label: item.dataset.quoteLabel, duration: exclusiveMinutes });
            group.totalMinutes += exclusiveMinutes;
            group.sharedMinutes = Math.max(group.sharedMinutes, sharedMinutes);
            timeGroups.set(key, group);
        });

        timeGroups.forEach((group) => {
            group.entries[0].duration += group.sharedMinutes;
            group.totalMinutes += group.sharedMinutes;
            const interval = Math.max(1, Number(group.rate.interval_minutes));
            const minBlocks = Math.ceil(Number(group.rate.minimum_minutes) / interval);
            const blocks = Math.max(minBlocks, Math.ceil(group.totalMinutes / interval));
            const groupTotal = BigInt(blocks) * BigInt(group.rate.amount_rupiah);
            let allocated = 0n;
            group.entries.forEach((entry, index) => {
                const amount = index === group.entries.length - 1
                    ? groupTotal - allocated
                    : (groupTotal * BigInt(entry.duration)) / BigInt(group.totalMinutes);
                allocated += amount;
                entries.push({ label: `${entry.label} · ${group.totalMinutes} min shared labor`, price: amount });
            });
        });

        form.querySelectorAll('[data-quote-package]').forEach((item) => {
            if (!item.querySelector('input[name="package_ids[]"]')?.checked) return;
            let price = parsePrice(item.dataset.basePrice || '');
            const labor = parsePrice(item.dataset.laborPrice || '');
            const discount = parsePrice(item.dataset.discount || '0') || 0n;
            if (price === null || labor === null) {
                price = null;
            } else {
                price += labor;
                for (const option of item.querySelectorAll('[data-quote-option]')) {
                    if (!option.querySelector('input')?.checked) continue;
                    const unitPrice = parsePrice(option.dataset.unitPrice || '');
                    if (unitPrice === null) {
                        price = null;
                        break;
                    }
                    price += quantityTotal(option.dataset.quantity || '1', unitPrice);
                }
                if (price !== null) price -= discount < price ? discount : price;
            }
            entries.push({ label: item.dataset.quoteLabel, price });
        });

        form.querySelectorAll('[data-quote-part]').forEach((item) => {
            const service = form.querySelector(`[data-booking-service="${item.dataset.serviceId}"] [name="service_ids[]"]`);
            const selected = Boolean(service?.checked) && (item.dataset.required === 'true' || Boolean(item.querySelector('input')?.checked));
            if (!selected) return;
            const unitPrice = parsePrice(item.dataset.unitPrice || '');
            entries.push({ label: item.dataset.quoteLabel, price: unitPrice === null ? null : quantityTotal(item.dataset.quantity || '1', unitPrice) });
        });

        lines.replaceChildren();
        entries.forEach((entry) => {
            const row = document.createElement('div');
            row.className = 'booking-estimate-line';
            const label = document.createElement('span');
            const amount = document.createElement('strong');
            label.textContent = entry.label;
            if (entry.price === null) {
                amount.textContent = 'Price to be confirmed';
                hasUnknownPrice = true;
            } else {
                amount.textContent = displayRupiah(entry.price);
                knownTotal += entry.price;
            }
            row.append(label, amount);
            lines.append(row);
        });

        if (entries.length === 0) {
            lines.innerHTML = '<p class="booking-note">Choose a workshop service or maintenance package to preview labor and parts pricing.</p>';
            total.textContent = '—';
            if (mobileTotal) mobileTotal.textContent = 'Choose work to see total';
            message.textContent = 'This is a catalog preview, not an invoice. Unpriced work, fitment, discounts, and final scope are confirmed by MINEX before approval.';
            return;
        }

        total.textContent = hasUnknownPrice ? 'Price to confirm' : displayRupiah(knownTotal);
        if (mobileTotal) mobileTotal.textContent = hasUnknownPrice ? 'Price to confirm' : displayRupiah(knownTotal);
        message.textContent = hasUnknownPrice
            ? `Current catalog prices are shown where available (${displayRupiah(knownTotal)} known); MINEX confirms the full amount before approval.`
            : 'This is a fixed estimate for the approved work scope. Actual time does not increase labor charges; any added work requires your approval.';
    };

    form.addEventListener('change', (event) => {
        if (event.target.matches('input[type="checkbox"]')) render();
    });
    render();
});
