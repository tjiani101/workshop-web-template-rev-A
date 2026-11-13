const progress = document.querySelector('.booking-progress');

if (progress) {
    const steps = Array.from(progress.querySelectorAll('a')).map((link) => ({
        link,
        target: document.querySelector(link.getAttribute('href')),
    })).filter((step) => step.target);
    let pendingFrame = null;

    const updateCurrentStep = () => {
        const checkpoint = Math.min(160, window.innerHeight * .25);
        const currentStep = steps.filter(({ target }) => target.getBoundingClientRect().top <= checkpoint).at(-1) || steps[0];

        steps.forEach(({ link }) => {
            if (link === currentStep?.link) {
                link.setAttribute('aria-current', 'step');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    const scheduleProgressUpdate = () => {
        if (pendingFrame !== null) return;

        pendingFrame = window.requestAnimationFrame(() => {
            pendingFrame = null;
            updateCurrentStep();
        });
    };

    window.addEventListener('scroll', scheduleProgressUpdate, { passive: true });
    window.addEventListener('resize', scheduleProgressUpdate);
    progress.addEventListener('click', scheduleProgressUpdate);
    updateCurrentStep();
}
