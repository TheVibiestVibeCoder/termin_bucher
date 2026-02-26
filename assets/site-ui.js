(() => {
    const root = document.documentElement;
    const body = document.body;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const getCurrentTheme = () => root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    const applyTheme = (theme) => {
        root.setAttribute('data-theme', theme);
        try {
            localStorage.setItem('site-theme', theme);
        } catch (e) {
            // ignore storage errors
        }

        const toggle = document.querySelector('#themeToggle');
        if (toggle) {
            const light = theme === 'light';
            toggle.textContent = light ? 'Dark Mode' : 'Light Mode';
            toggle.setAttribute('aria-pressed', light ? 'true' : 'false');
        }
    };

    const toggle = document.querySelector('#themeToggle');
    if (toggle) {
        applyTheme(getCurrentTheme());
        toggle.addEventListener('click', () => {
            applyTheme(getCurrentTheme() === 'light' ? 'dark' : 'light');
        });
    }

    if (!body.classList.contains('page-loaded')) {
        requestAnimationFrame(() => body.classList.add('page-loaded'));
    }

    if (reduceMotion) {
        return;
    }

    document.addEventListener('click', (event) => {
        const anchor = event.target instanceof Element ? event.target.closest('a') : null;
        if (!anchor) return;
        if (event.defaultPrevented) return;
        if (anchor.target === '_blank' || anchor.hasAttribute('download')) return;

        const href = anchor.getAttribute('href') ?? '';
        if (
            href === '' ||
            href.startsWith('#') ||
            href.startsWith('mailto:') ||
            href.startsWith('tel:') ||
            href.startsWith('javascript:')
        ) {
            return;
        }

        let url;
        try {
            url = new URL(anchor.href, window.location.href);
        } catch (e) {
            return;
        }

        if (url.origin !== window.location.origin) return;

        event.preventDefault();
        body.classList.add('page-transition-out');
        window.setTimeout(() => {
            window.location.href = anchor.href;
        }, 360);
    });
})();
