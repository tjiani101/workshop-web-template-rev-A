document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const picker = form.querySelector('.cinema-slot-panel');
    const dateInput = form.querySelector('[data-availability-date]');
    const manualDate = form.querySelector('[data-manual-date]');
    const preferredInput = form.querySelector('[data-preferred-time]');
    const status = form.querySelector('[data-availability-status]');
    const grid = form.querySelector('[data-slot-grid]');
    const durationLabel = form.querySelector('[data-slot-duration]');
    const heading = form.querySelector('[data-slot-heading]');
    const submit = form.querySelector('#booking-submit');
    const arrivalSummary = form.querySelector('[data-arrival-summary]');
    const arrivalTime = form.querySelector('[data-arrival-time]');
    const completionTime = form.querySelector('[data-completion-time]');
    const jobDuration = form.querySelector('[data-job-duration]');
    const operationConsentPanel = form.querySelector('[data-operation-consent-panel]');
    const operationConsent = operationConsentPanel?.querySelector('[name="operation_consent"]');
    let mode = 'automatic';
    let requestController;
    let durationMinutes = 30;

    if (!picker || !preferredInput || !status || !grid || !submit) return;

    const selectedWork = () => {
        const params = new URLSearchParams({ mode });
        if (mode === 'manual' && dateInput.value) params.set('date', dateInput.value);
        const selectedPackages = new Set(Array.from(form.querySelectorAll('[name="package_ids[]"]:checked'), (input) => input.value));
        form.querySelectorAll('[name="service_ids[]"]:checked').forEach((input) => params.append('service_ids[]', input.value));
        form.querySelectorAll('[name="package_ids[]"]:checked').forEach((input) => params.append('package_ids[]', input.value));
        form.querySelectorAll('[name^="package_options["]:checked').forEach((input) => {
            const match = input.name.match(/^package_options\[(\d+)\]\[\]$/);
            if (match && selectedPackages.has(match[1])) params.append(`package_options[${match[1]}][]`, input.value);
        });
        form.querySelectorAll('[name^="service_part_variant_ids["]:checked').forEach((input) => params.append(input.name, input.value));
        return params;
    };

    const selectedWorkExists = () => form.querySelector('[name="service_ids[]"]:checked, [name="package_ids[]"]:checked');
    const syncServicePanels = () => {
        form.querySelectorAll('[data-booking-service]').forEach((card) => {
            const checked = Boolean(card.querySelector('[name="service_ids[]"]:checked'));
            const options = card.querySelector('[data-service-part-selection]');
            if (options) options.hidden = !checked;
            card.querySelectorAll('[name^="service_part_variant_ids["]').forEach((input) => { input.disabled = !checked; });
        });
    };
    const formatDateTime = (value) => {
        const date = new Date(`${value}:00`);
        return new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
    };
    const finishAt = (value) => {
        const date = new Date(`${value}:00`);
        date.setMinutes(date.getMinutes() + durationMinutes);
        return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
    };
    const formatClock = (value) => new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(`${value}:00`));
    const hasFullDayService = () => Array.from(form.querySelectorAll('[data-booking-service][data-requires-full-day="true"]'))
        .some((card) => card.querySelector('[name="service_ids[]"]:checked'));
    const syncOperationConsent = () => {
        const required = Array.from(form.querySelectorAll('[data-booking-service][data-requires-operation-consent="true"]'))
            .some((card) => card.querySelector('[name="service_ids[]"]:checked'));
        if (operationConsentPanel) operationConsentPanel.hidden = !required;
        if (operationConsent) {
            operationConsent.required = required;
            if (!required) operationConsent.checked = false;
        }
    };

    const selectSlot = (slot, endAt = null) => {
        preferredInput.value = slot.start_at;
        grid.querySelectorAll('.cinema-slot').forEach((button) => {
            const selected = button.dataset.startAt === slot.start_at;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        arrivalSummary.hidden = false;
        arrivalTime.textContent = formatDateTime(slot.start_at);
        completionTime.textContent = endAt ? formatClock(endAt) : finishAt(slot.start_at);
        jobDuration.textContent = hasFullDayService() ? 'Full workshop day' : `${durationMinutes} min`;
        status.textContent = mode === 'automatic' ? 'Earliest suitable time selected. MINEX confirms the appointment after reviewing your request.' : 'Arrival time selected. MINEX confirms the appointment after reviewing your request.';
        submit.disabled = false;
    };

    const statusLabels = {
        available: 'Available', limited: 'Limited', recommended: 'Recommended', full: 'Full',
        temporarily_held: 'Held', break: 'Break', closed: 'Closed',
    };

    const renderSlot = (slot) => {
        const button = document.createElement('button');
        const label = statusLabels[slot.status] || 'Full';
        button.type = 'button';
        button.className = `cinema-slot cinema-slot-${slot.status}`;
        button.dataset.startAt = slot.start_at;
        button.disabled = !['available', 'limited', 'recommended'].includes(slot.status);
        button.setAttribute('aria-pressed', 'false');
        const time = document.createElement('strong');
        const state = document.createElement('small');
        time.textContent = slot.start_at.slice(11, 16);
        state.textContent = label;
        button.append(time, state);
        if (!button.disabled) button.addEventListener('click', () => selectSlot(slot));
        return button;
    };

    const refresh = async () => {
        requestController?.abort();
        requestController = new AbortController();
        preferredInput.value = '';
        arrivalSummary.hidden = true;
        submit.disabled = true;
        grid.replaceChildren();

        if (!selectedWorkExists()) {
            durationLabel.textContent = 'Select a service or package to calculate duration';
            status.textContent = 'Choose workshop work before checking appointment times.';
            grid.append(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Select a service or maintenance package above.' }));
            return;
        }
        if (mode === 'manual' && !dateInput.value) {
            status.textContent = 'Choose a visit date to see available arrival times.';
            grid.append(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Choose a date to load the appointment map.' }));
            return;
        }

        status.textContent = 'Checking operating hours, mechanic skills, breaks, and bay capacity…';
        grid.append(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Loading appointment times…' }));
        try {
            const response = await fetch(`/booking/availability?${selectedWork()}`, { headers: { Accept: 'application/json' }, signal: requestController.signal });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Availability could not be checked.');
            durationMinutes = result.duration_minutes || 30;
            durationLabel.textContent = hasFullDayService() ? 'One full workshop day · opening to close' : `Estimated work · ${durationMinutes} minutes`;
            grid.replaceChildren();

            if (mode === 'automatic') {
                heading.textContent = 'EARLIEST SUITABLE ARRIVAL';
                if (result.recommended) {
                    const card = renderSlot({ start_at: result.recommended.start_at, status: 'recommended' });
                    card.classList.add('cinema-slot-recommendation');
                    grid.append(card);
                    selectSlot({ start_at: result.recommended.start_at, status: 'recommended' }, result.recommended.end_at || null);
                } else {
                    status.textContent = 'No suitable arrival time was found in the next 60 days. Contact MINEX to discuss the work.';
                    grid.append(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'No suitable appointment candidate is available yet.' }));
                }
                return;
            }

            heading.textContent = `ARRIVAL OPTIONS · ${new Intl.DateTimeFormat('id-ID', { dateStyle: 'full' }).format(new Date(`${dateInput.value}T12:00:00`))}`;
            if (result.day_status === 'closed') {
                status.textContent = 'The workshop is closed on this date. Choose another operating day.';
                grid.append(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Closed — choose another date.' }));
                return;
            }
            result.slots.forEach((slot) => grid.append(renderSlot(slot)));
            status.textContent = result.slots.some((slot) => ['available', 'limited'].includes(slot.status))
                ? (hasFullDayService() ? 'This reserves the full workshop day from opening until closing.' : 'Select an arrival time. Every available card covers the full estimated work duration.')
                : 'No candidate times are available for this date and job duration. Try another date.';
        } catch (error) {
            if (error.name === 'AbortError') return;
            grid.replaceChildren(Object.assign(document.createElement('p'), { className: 'booking-note', textContent: 'Availability is temporarily unavailable. Try again or contact MINEX.' }));
            status.textContent = error.message;
        }
    };

    form.querySelectorAll('[data-schedule-mode]').forEach((button) => button.addEventListener('click', () => {
        mode = button.dataset.scheduleMode;
        form.querySelectorAll('[data-schedule-mode]').forEach((item) => item.classList.toggle('active', item === button));
        manualDate.hidden = mode !== 'manual';
        refresh();
    }));
    dateInput?.addEventListener('change', refresh);
    form.addEventListener('change', (event) => {
        if (event.target.matches('[name="service_ids[]"]')) syncServicePanels();
        if (event.target.matches('[name="service_ids[]"]')) syncOperationConsent();
        if (event.target.matches('[name="service_ids[]"], [name="package_ids[]"], [name^="package_options["], [name^="service_part_variant_ids["]')) refresh();
    });

    syncServicePanels();
    syncOperationConsent();
    refresh();
});
