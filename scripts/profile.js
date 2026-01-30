document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('returnBtn');
    if (!btn) return;

    const modalEl = document.getElementById('firstLoginModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

    btn.addEventListener('click', () => {
        const firstLogin = btn.dataset.firstLogin === '1';
        const redirectUrl = btn.dataset.redirect;

        if (firstLogin) {
            modal?.show();
            return;
        }

        window.location.href = redirectUrl;
    });
});
