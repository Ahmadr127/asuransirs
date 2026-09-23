/**
 * Global Toast notification — non-blocking, stacked top-right.
 * Dipakai layout (session flash) dan halaman polling (status queue).
 *
 *   Toast.success('Import berhasil', { title: '...', duration: 5000 });
 *   Toast.error('Import gagal', { title: '...', duration: 8000 });
 *   Toast.info('Import sedang diproses', { duration: 6000 });
 *
 * Tidak memakai modal: user tetap bisa berpindah halaman / bekerja
 * sementara queue berjalan di background.
 */
(function () {
    'use strict';

    var COLORS = {
        success: { bar: '#16a34a', icon: 'bi-check-circle-fill', bg: '#f0fdf4', border: '#bbf7d0', text: '#166534' },
        error: { bar: '#dc2626', icon: 'bi-x-circle-fill', bg: '#fef2f2', border: '#fecaca', text: '#991b1b' },
        info: { bar: '#2563eb', icon: 'bi-info-circle-fill', bg: '#eff6ff', border: '#bfdbfe', text: '#1e40af' },
    };

    function ensureContainer() {
        var c = document.getElementById('toast-container');
        if (c) return c;
        c = document.createElement('div');
        c.id = 'toast-container';
        c.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:0.5rem;max-width:min(24rem,calc(100vw - 2rem));';
        document.body.appendChild(c);
        return c;
    }

    function show(type, message, opts) {
        opts = opts || {};
        var theme = COLORS[type] || COLORS.info;
        var title = opts.title || ({ success: 'Berhasil', error: 'Gagal', info: 'Info' }[type] || 'Info');
        var duration = typeof opts.duration === 'number' ? opts.duration : (type === 'error' ? 8000 : 5000);

        var el = document.createElement('div');
        el.style.cssText = 'background:' + theme.bg + ';border:1px solid ' + theme.border + ';border-radius:0.5rem;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);overflow:hidden;';
        el.innerHTML =
            '<div style="display:flex;gap:0.6rem;align-items:flex-start;padding:0.7rem 0.8rem;">' +
            '<i class="bi ' + theme.icon + '" style="color:' + theme.bar + ';font-size:1.1rem;line-height:1.4;"></i>' +
            '<div style="flex:1;min-width:0;">' +
            '<p style="margin:0;font-size:0.8rem;font-weight:700;color:' + theme.text + ';"></p>' +
            '<p style="margin:0.15rem 0 0;font-size:0.78rem;color:#374151;white-space:pre-line;word-break:break-word;"></p>' +
            '</div>' +
            '<button type="button" aria-label="Tutup" style="border:0;background:transparent;color:#9ca3af;cursor:pointer;font-size:1rem;line-height:1;">&times;</button>' +
            '</div>' +
            '<div style="height:3px;background:' + theme.border + ';"><div style="height:100%;width:100%;background:' + theme.bar + ';transition:width linear;"></div></div>';

        el.querySelector('p').textContent = title;
        el.querySelectorAll('p')[1].textContent = message;
        var bar = el.querySelectorAll('div')[3];
        el.querySelector('button').addEventListener('click', function () { dismiss(); });

        var timer = null;
        function dismiss() {
            if (timer) clearTimeout(timer);
            el.style.transition = 'opacity .25s ease, transform .25s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateX(1rem)';
            setTimeout(function () { el.remove(); }, 260);
        }
        if (duration > 0) {
            // Animasikan bar menyusut sesuai durasi.
            requestAnimationFrame(function () {
                bar.style.transitionDuration = duration + 'ms';
                bar.style.width = '0%';
            });
            timer = setTimeout(dismiss, duration);
        }

        ensureContainer().appendChild(el);
        return { dismiss: dismiss, element: el };
    }

    window.Toast = {
        success: function (message, opts) { return show('success', message, opts); },
        error: function (message, opts) { return show('error', message, opts); },
        info: function (message, opts) { return show('info', message, opts); },
    };
})();
