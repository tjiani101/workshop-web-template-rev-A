const vehicleDialog = document.querySelector('[data-vehicle-dialog]');
const openVehicleDialog = document.querySelector('[data-open-vehicle-dialog]');
const closeVehicleDialog = vehicleDialog?.querySelector('[data-close-vehicle-dialog]');
const quickVehicleForm = vehicleDialog?.querySelector('[data-quick-vehicle-form]');

openVehicleDialog?.addEventListener('click', () => vehicleDialog?.showModal());
closeVehicleDialog?.addEventListener('click', () => vehicleDialog?.close());

quickVehicleForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = quickVehicleForm.querySelector('[data-quick-vehicle-status]');
    const submit = quickVehicleForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    status.textContent = 'Saving your motorcycle…';
    try {
        const response = await fetch(quickVehicleForm.action, {
            method: 'POST',
            body: new FormData(quickVehicleForm),
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'Could not save this motorcycle. Check the selected model year and trim.');

        const vehicle = result.vehicle;
        const label = document.createElement('label');
        label.className = 'garage-booking-choice';
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'customer_vehicle_id';
        radio.value = vehicle.id;
        radio.required = true;
        radio.checked = true;
        const details = document.createElement('span');
        const title = document.createElement('strong');
        const subtitle = document.createElement('small');
        const model = document.createElement('b');
        title.textContent = quickVehicleForm.querySelector('[name="nickname"]').value || vehicle.label;
        subtitle.textContent = `${vehicle.generation} · ${vehicle.variant} · ${vehicle.model_year}${vehicle.registration_number ? ` · ${vehicle.registration_number}` : ''}`;
        model.textContent = `${vehicle.brand} ${vehicle.model}`;
        details.append(title, subtitle);
        label.append(radio, details, model);
        document.querySelector('[data-garage-vehicles]')?.append(label);
        vehicleDialog.close();
        quickVehicleForm.reset();
        quickVehicleForm.querySelector('[data-quick-vehicle-status]').textContent = '';
        const mainVehiclePicker = document.querySelector('[data-booking-form] [data-vehicle-picker]');
        if (mainVehiclePicker) mainVehiclePicker.hidden = true;
    } catch (error) {
        status.textContent = error.message;
    } finally {
        submit.disabled = false;
    }
});
