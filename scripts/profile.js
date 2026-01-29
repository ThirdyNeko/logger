document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('returnBtn');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const firstLogin = btn.dataset.firstLogin === '1';
        const redirectUrl = btn.dataset.redirect;

        if (firstLogin) {
            alert("You must change your password before accessing the dashboard.");
            return;
        }

        window.location.href = redirectUrl;
    });
});
