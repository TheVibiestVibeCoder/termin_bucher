(() => {
    const root = document.documentElement;
    const body = document.body;
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const mobileQuery = window.matchMedia("(max-width: 768px)");

    const getCurrentTheme = () => (root.getAttribute("data-theme") === "light" ? "light" : "dark");

    const getToggleLabel = (theme, mobile) => {
        if (mobile) {
            return theme === "light" ? "Dark Modus" : "Light Modus";
        }
        return theme === "light" ? "\u2600" : "\u263E";
    };

    const getToggleHint = (theme) => (theme === "light" ? "Zum Dark Mode wechseln" : "Zum Light Mode wechseln");

    const updateToggle = (theme) => {
        const toggle = document.querySelector("#themeToggle");
        if (!toggle) return;
        toggle.textContent = getToggleLabel(theme, mobileQuery.matches);
        toggle.setAttribute("aria-pressed", theme === "light" ? "true" : "false");
        const hint = getToggleHint(theme);
        toggle.setAttribute("aria-label", hint);
        toggle.setAttribute("title", hint);
    };

    const persistTheme = (theme) => {
        root.setAttribute("data-theme", theme);
        try {
            localStorage.setItem("site-theme", theme);
        } catch (e) {
            // ignore storage errors
        }
        updateToggle(theme);
    };

    const applyTheme = (theme, animate = false, originX = null, originY = null) => {
        if (
            animate &&
            !reduceMotion &&
            typeof document.startViewTransition === "function"
        ) {
            if (originX !== null && originY !== null) {
                root.style.setProperty("--theme-origin-x", `${originX}px`);
                root.style.setProperty("--theme-origin-y", `${originY}px`);
            }
            document.startViewTransition(() => {
                persistTheme(theme);
            });
            return;
        }
        persistTheme(theme);
    };

    const toggle = document.querySelector("#themeToggle");
    if (toggle) {
        updateToggle(getCurrentTheme());
        toggle.addEventListener("click", () => {
            const nextTheme = getCurrentTheme() === "light" ? "dark" : "light";
            const rect = toggle.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            applyTheme(nextTheme, true, x, y);
        });

        const onMobileChange = () => updateToggle(getCurrentTheme());
        if (typeof mobileQuery.addEventListener === "function") {
            mobileQuery.addEventListener("change", onMobileChange);
        } else if (typeof mobileQuery.addListener === "function") {
            mobileQuery.addListener(onMobileChange);
        }
    }

    if (!body.classList.contains("page-loaded")) {
        requestAnimationFrame(() => body.classList.add("page-loaded"));
    }

    if (reduceMotion) {
        return;
    }

    document.addEventListener("click", (event) => {
        const anchor = event.target instanceof Element ? event.target.closest("a") : null;
        if (!anchor) return;
        if (event.defaultPrevented) return;
        if (anchor.target === "_blank" || anchor.hasAttribute("download")) return;

        const href = anchor.getAttribute("href") ?? "";
        if (
            href === "" ||
            href.startsWith("#") ||
            href.startsWith("mailto:") ||
            href.startsWith("tel:") ||
            href.startsWith("javascript:")
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
        body.classList.add("page-transition-out");
        window.setTimeout(() => {
            window.location.href = anchor.href;
        }, 260);
    });
})();
