document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('returnBtn');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const firstLogin = btn.dataset.firstLogin === '1';
        const redirectUrl = btn.dataset.redirect;

        if (firstLogin) {
            Swal.fire({
                icon: 'warning',
                title: 'Action Required',
                text: 'You must change your password before accessing the dashboard.',
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'OK'
            });
            return;
        }

        window.location.href = redirectUrl;
    });
});