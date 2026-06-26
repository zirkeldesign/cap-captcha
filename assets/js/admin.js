/*
 * Cap CAPTCHA — admin glue.
 *
 * "Test connection" button: posts to admin-ajax with the cap_captcha_test_connection
 * action, renders ✅/❌ inline next to the button. Reads config from the running
 * plugin (which respects wp-config constants), not from the form fields — so
 * the test reflects what the plugin will actually send when verifying tokens.
 */

(function () {
    const cfg = window.capCaptchaAdmin || {};

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(() => {
        const btn = document.querySelector('[data-cap-captcha-test]');
        if (!btn) return;

        const out = document.querySelector('[data-cap-captcha-test-result]');

        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            btn.disabled = true;
            if (out) {
                out.className = 'cap-captcha-test-connection__result';
                out.textContent = cfg.i18n?.testing ?? 'Testing…';
            }

            try {
                const body = new URLSearchParams({
                    action: 'cap_captcha_test_connection',
                    _ajax_nonce: cfg.nonce ?? '',
                });
                const res = await fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                const data = await res.json();
                if (out) {
                    if (data?.success) {
                        out.classList.add('cap-captcha-test-connection__result--ok');
                        out.textContent = data.data?.message ?? (cfg.i18n?.ok ?? 'OK');
                    } else {
                        out.classList.add('cap-captcha-test-connection__result--err');
                        out.textContent = data?.data?.message ?? (cfg.i18n?.error ?? 'Error');
                    }
                }
            } catch (err) {
                if (out) {
                    out.classList.add('cap-captcha-test-connection__result--err');
                    out.textContent = (cfg.i18n?.error ?? 'Error') + ': ' + (err?.message ?? err);
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Quick-link from an integration card to its options disclosure: open it and
    // smooth-scroll. Falls back to a native anchor jump without JS.
    ready(() => {
        document.querySelectorAll('.cap-captcha-integration__options-link').forEach((link) => {
            link.addEventListener('click', (e) => {
                const id = (link.getAttribute('href') || '').replace(/^#/, '');
                const details = id && document.getElementById(id);
                if (!details) return;
                e.preventDefault();
                details.open = true;
                details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const summary = details.querySelector('summary');
                if (summary) summary.focus();

                // Flash the disclosure so it's clear where the link landed.
                details.classList.remove('cap-captcha-integration-options--flash');
                void details.offsetWidth; // reflow so the animation restarts on repeat clicks
                details.classList.add('cap-captcha-integration-options--flash');
            });
        });
    });
})();
