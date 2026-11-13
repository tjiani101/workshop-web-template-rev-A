const closeEnhancedSelect = (component) => {
    component.classList.remove('is-open');
    component.querySelector('.custom-select-menu')?.setAttribute('hidden', '');
    component.querySelector('.custom-select-trigger')?.setAttribute('aria-expanded', 'false');
};

let customSelectSequence = 0;

const enhanceSelect = (select) => {
    if (select.dataset.customized || select.multiple || select.size > 1) return;
    select.dataset.customized = 'true';
    const component = document.createElement('div');
    component.className = 'custom-select';
    const trigger = document.createElement('button');
    trigger.className = 'custom-select-trigger';
    trigger.type = 'button';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-autocomplete', 'list');
    const label = select.closest('label');
    trigger.setAttribute('aria-label', (label?.childNodes[0]?.textContent || 'Choose an option').trim());
    const menu = document.createElement('div');
    menu.className = 'custom-select-menu';
    menu.hidden = true;
    const listbox = document.createElement('div');
    listbox.id = `custom-select-options-${++customSelectSequence}`;
    listbox.setAttribute('role', 'listbox');
    trigger.setAttribute('aria-controls', listbox.id);
    menu.append(listbox);
    const nativeLabel = document.createElement('span');
    trigger.append(nativeLabel);
    select.classList.add('custom-select-native');
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;
    select.after(component);
    component.append(trigger, menu);

    let hasBeenValidated = false;
    const options = () => Array.from(select.options).filter((option) => !option.disabled && option.value !== '');
    const ensureSearch = () => {
        const currentSearch = menu.querySelector('.custom-select-search');
        if (select.options.length > 8 && !currentSearch) {
            const search = document.createElement('input');
            search.className = 'custom-select-search';
            search.type = 'search';
            search.placeholder = 'Search options';
            search.setAttribute('aria-label', 'Search options');
            menu.prepend(search);
            search.addEventListener('input', () => render(search.value));
            search.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown') { event.preventDefault(); listbox.querySelector('[role="option"]')?.focus(); }
                if (event.key === 'Escape') { event.preventDefault(); closeEnhancedSelect(component); trigger.focus(); }
            });
        } else if (select.options.length <= 8 && currentSearch) {
            currentSearch.remove();
        }
    }

    const sync = () => {
        const selected = select.selectedOptions[0];
        nativeLabel.textContent = selected && selected.value !== '' ? selected.textContent : (select.options[0]?.textContent || 'Choose an option');
        trigger.disabled = select.disabled;
        trigger.setAttribute('aria-required', String(select.required));
        ensureSearch();
        render(menu.querySelector('.custom-select-search')?.value || '');
        const isInvalid = hasBeenValidated && !select.disabled && !select.validity.valid;
        trigger.setAttribute('aria-invalid', String(isInvalid));
        component.classList.toggle('has-error', isInvalid);
    };
    function render(query = '') {
        const normalized = query.trim().toLocaleLowerCase();
        listbox.replaceChildren();
        const filtered = options().filter((option) => option.textContent.toLocaleLowerCase().includes(normalized));
        filtered.forEach((option, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'custom-select-option';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(option.value === select.value));
            item.tabIndex = 0;
            item.textContent = option.textContent;
            item.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                closeEnhancedSelect(component);
                trigger.focus();
            });
            item.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    filtered[(index + (event.key === 'ArrowDown' ? 1 : filtered.length - 1)) % filtered.length]?.focus();
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault(); item.click();
                } else if (event.key === 'Escape') {
                    event.preventDefault(); closeEnhancedSelect(component); trigger.focus();
                }
            });
            listbox.append(item);
        });
        if (!filtered.length) {
            const empty = document.createElement('p');
            empty.className = 'custom-select-empty';
            empty.textContent = 'No matching options.';
            listbox.append(empty);
        }
    }
    trigger.addEventListener('click', () => {
        if (trigger.disabled) return;
        const isOpening = menu.hidden;
        document.querySelectorAll('.custom-select.is-open').forEach(closeEnhancedSelect);
        menu.hidden = !isOpening;
        component.classList.toggle('is-open', isOpening);
        trigger.setAttribute('aria-expanded', String(isOpening));
        if (isOpening) {
            const search = menu.querySelector('.custom-select-search');
            (search || listbox.querySelector('[aria-selected="true"]') || listbox.querySelector('[role="option"]'))?.focus();
        }
    });
    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            event.preventDefault(); closeEnhancedSelect(component); trigger.focus();
        } else if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key) && menu.hidden) {
            event.preventDefault(); trigger.click();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const visibleOptions = Array.from(listbox.querySelectorAll('[role="option"]'));
            (event.key === 'ArrowDown' ? visibleOptions[0] : visibleOptions.at(-1))?.focus();
        }
    });
    select.addEventListener('change', sync);
    select.addEventListener('invalid', (event) => {
        event.preventDefault();
        hasBeenValidated = true;
        component.classList.add('has-error');
        trigger.setAttribute('aria-invalid', 'true');
        window.setTimeout(() => trigger.focus(), 0);
    });
    new MutationObserver(sync).observe(select, { childList: true, subtree: true, attributes: true });
    document.addEventListener('click', (event) => { if (!component.contains(event.target)) closeEnhancedSelect(component); });
    sync();
};

document.querySelectorAll('select').forEach(enhanceSelect);
