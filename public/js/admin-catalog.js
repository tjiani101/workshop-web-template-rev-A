document.querySelectorAll('[data-add-row]').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target);
        const template = document.getElementById(button.dataset.template);
        if (!target || !template) return;

        const index = Number(target.dataset.nextIndex || target.children.length);
        target.dataset.nextIndex = String(index + 1);
        const html = template.innerHTML
            .replaceAll('__index__', String(index))
            .replaceAll('__variant__', String(index));
        target.insertAdjacentHTML('beforeend', html);
    });
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-attribute]');
    if (!button) return;

    const key = button.dataset.target;
    const editor = document.querySelector(`[data-variant-key="${key}"]`);
    const template = document.getElementById('part-attribute-template');
    if (!editor || !template) return;

    const index = Number(editor.dataset.nextIndex || editor.querySelectorAll('.attribute-row').length);
    editor.dataset.nextIndex = String(index + 1);
    const html = template.innerHTML.replaceAll('__variant__', key.replace('variant-', '')).replaceAll('__attr__', String(index));
    editor.insertAdjacentHTML('beforeend', html);
});
