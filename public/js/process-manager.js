/**
 * Floating Process Manager — monitor global proses Queue (Scan & Import Tarif).
 *
 * - Fixed kanan-bawah, persisten selama proses berjalan (bukan toast sesaat).
 * - Multi-item per batch ID, progress real dari endpoint status existing.
 * - Minimize hanya mengubah tampilan; tidak menghentikan Queue/job.
 * - Close item hanya untuk status terminal; tidak cancel/delete apa pun.
 * - localStorage menyimpan ID terpantau + mode minimize (bukan hasil data).
 *
 * API:
 *   ProcessManager.track({ id, title })
 *   ProcessManager.update(id, patch)
 *   ProcessManager.remove(id)
 *   ProcessManager.minimize() / .expand() / .toggleMin()
 *   ProcessManager.clearCompleted()
 */
(function () {
    'use strict';

    var LS_TRACKED = 'bpm_tracked_v1';
    var LS_MIN = 'bpm_minimized_v1';
    var STATUS_URL = '/tarifs/import/batches/status';
    var POLL_MS = 2000;

    var SCAN_ACTIVE = ['pending_scan', 'processing_scan'];
    var IMPORT_ACTIVE = ['pending_import', 'processing_import'];
    var TERMINAL = ['scan_completed', 'scan_failed', 'completed', 'failed'];

    var items = new Map(); // id -> { id, title, data|null }
    var minimized = false;
    var timer = null;

    function loadLS() {
        try {
            minimized = localStorage.getItem(LS_MIN) === '1';
            var list = JSON.parse(localStorage.getItem(LS_TRACKED) || '[]');
            list.forEach(function (t) {
                if (t && t.id != null) items.set(String(t.id), { id: String(t.id), title: t.title || ('Batch #' + t.id), data: null });
            });
        } catch (e) { /* abaikan storage rusak */ }
    }

    function saveLS() {
        try {
            localStorage.setItem(LS_MIN, minimized ? '1' : '0');
            localStorage.setItem(LS_TRACKED, JSON.stringify(
                Array.from(items.values()).map(function (it) { return { id: it.id, title: it.title }; })
            ));
        } catch (e) { /* abaikan */ }
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString('id-ID');
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function isActive(it) {
        return it.data ? (SCAN_ACTIVE.indexOf(it.data.status) !== -1 || IMPORT_ACTIVE.indexOf(it.data.status) !== -1)
                       : true; // belum ada data -> anggap aktif agar dipolling
    }

    function activeCount() {
        var n = 0;
        items.forEach(function (it) { if (isActive(it)) n++; });
        return n;
    }

    // ---------- shell ----------

    function ensureCSS() {
        if (document.getElementById('bpm-style')) return;
        var st = document.createElement('style');
        st.id = 'bpm-style';
        st.textContent = [
            '#bpm-panel{position:fixed;right:24px;bottom:24px;z-index:9000;width:400px;max-width:calc(100vw - 48px);',
            'background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,.12);overflow:hidden;font-size:0.8rem;}',
            '#bpm-head{display:flex;align-items:center;gap:.5rem;padding:.6rem .8rem;background:#f8fafc;border-bottom:1px solid #e5e7eb;}',
            '#bpm-head .bpm-title{font-weight:700;color:#1e3a5f;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
            '#bpm-head button{border:0;background:transparent;color:#6b7280;cursor:pointer;font-size:1rem;line-height:1;padding:.1rem .3rem;border-radius:.375rem;}',
            '#bpm-head button:hover{background:#e5e7eb;color:#111827;}',
            '#bpm-body{max-height:min(60vh,430px);overflow-y:auto;display:flex;flex-direction:column;gap:.6rem;padding:.7rem .8rem;}',
            '.bpm-item{border:1px solid #e5e7eb;border-radius:.5rem;padding:.6rem .7rem;background:#fff;}',
            '.bpm-item-top{display:flex;align-items:center;gap:.45rem;}',
            '.bpm-item-top .bpm-t{font-weight:700;color:#111827;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
            '.bpm-badge{font-size:.65rem;font-weight:700;padding:.1rem .45rem;border-radius:9999px;white-space:nowrap;}',
            '.bpm-b-blue{background:#dbeafe;color:#1e40af;}.bpm-b-green{background:#dcfce7;color:#166534;}',
            '.bpm-b-red{background:#fee2e2;color:#991b1b;}.bpm-b-yellow{background:#fef9c3;color:#854d0e;}.bpm-b-gray{background:#f3f4f6;color:#4b5563;}',
            '.bpm-msg{color:#4b5563;margin:.35rem 0 .3rem;white-space:pre-line;word-break:break-word;}',
            '.bpm-bar{height:.5rem;background:#e5e7eb;border-radius:9999px;overflow:hidden;}',
            '.bpm-bar>div{height:100%;background:#2563eb;border-radius:9999px;transition:width .5s ease;}',
            '.bpm-bar.scan>div{background:#0d9488;}',
            '.bpm-meta{color:#6b7280;font-size:.72rem;margin-top:.3rem;}',
            '.bpm-err{margin-top:.35rem;padding:.4rem .5rem;background:#fef2f2;border:1px solid #fecaca;border-radius:.375rem;color:#991b1b;font-size:.72rem;word-break:break-word;}',
            '.bpm-actions{display:flex;gap:.4rem;margin-top:.45rem;}',
            '.bpm-actions a,.bpm-actions button{font-size:.72rem;font-weight:600;padding:.3rem .6rem;border-radius:.375rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;}',
            '.bpm-view{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}',
            '.bpm-view:hover{background:#e5e7eb;}',
            '.bpm-retry{background:#2563eb;color:#fff;border:1px solid #2563eb;}',
            '.bpm-retry:hover{background:#1d4ed8;}',
            '.bpm-x{border:0;background:transparent;color:#9ca3af;cursor:pointer;font-size:1rem;line-height:1;padding:0 .1rem;}',
            '.bpm-x:hover{color:#111827;}',
            '.bpm-spin{display:inline-block;width:.8rem;height:.8rem;border:2px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;animation:bpm-rot .8s linear infinite;vertical-align:-1px;}',
            '@keyframes bpm-rot{to{transform:rotate(360deg);}}',
            '#bpm-mini{position:fixed;right:24px;bottom:24px;z-index:9000;display:flex;align-items:center;gap:.5rem;',
            'background:#1e3a5f;color:#fff;font-size:.8rem;font-weight:600;padding:.6rem .9rem;border-radius:9999px;',
            'box-shadow:0 10px 15px -3px rgba(0,0,0,.2);cursor:pointer;border:0;}',
            '#bpm-mini:hover{background:#274b73;}',
            '@media (max-width:640px){#bpm-panel{right:12px;left:12px;bottom:12px;width:auto;max-width:none;}',
            '#bpm-mini{right:12px;bottom:12px;}}',
        ].join('\n');
        document.head.appendChild(st);
    }

    function shell() {
        var p = document.getElementById('bpm-panel');
        if (p) return p;
        ensureCSS();
        p = document.createElement('div');
        p.id = 'bpm-panel';
        p.innerHTML = '<div id="bpm-head"></div><div id="bpm-body"></div>';
        document.body.appendChild(p);

        var m = document.createElement('button');
        m.id = 'bpm-mini';
        m.type = 'button';
        m.addEventListener('click', function () { api.expand(); });
        document.body.appendChild(m);
        return p;
    }

    // ---------- render ----------

    function badge(status) {
        var cls = 'bpm-b-gray', label = String(status || 'unknown').toUpperCase();
        var labels = {
            pending_scan: 'PENDING SCAN', processing_scan: 'SCANNING',
            scan_completed: 'SCAN SELESAI', scan_failed: 'SCAN GAGAL',
            pending_import: 'PENDING IMPORT', processing_import: 'IMPORTING',
            completed: 'SELESAI', failed: 'GAGAL',
        };
        if (labels[status]) label = labels[status];
        if (status === 'completed' || status === 'scan_completed') cls = 'bpm-b-green';
        else if (status === 'processing_scan' || status === 'processing_import') cls = 'bpm-b-blue';
        else if (status === 'failed' || status === 'scan_failed') cls = 'bpm-b-red';
        else if (status === 'pending_scan' || status === 'pending_import') cls = 'bpm-b-yellow';
        return '<span class="bpm-badge ' + cls + '">' + esc(label) + '</span>';
    }

    function scanBlock(d) {
        var hasScan = (d.scan_total_rows > 0) || SCAN_ACTIVE.indexOf(d.status) !== -1 ||
                      d.status === 'scan_failed' || d.status === 'scan_completed';
        if (!hasScan) return '';
        var msg, icon = '<i class="bi bi-search"></i> Scan';
        if (d.status === 'pending_scan') { msg = 'Menunggu worker...'; icon = '<i class="bi bi-clock"></i> Scan'; }
        else if (d.status === 'processing_scan') {
            msg = d.scan_processed_rows > 0
                ? 'Membaca baris ' + fmt(d.scan_processed_rows) + ' / ' + fmt(d.scan_total_rows)
                : 'Menyiapkan file...';
            icon = '<span class="bpm-spin"></span> Scan';
        }
        else if (d.status === 'scan_completed') {
            msg = 'Scan selesai — ' + fmt(d.scan_total_rows) + ' baris diproses';
        }
        else if (d.status === 'scan_failed') { msg = 'Scan gagal'; }
        else { return ''; }

        var h = '<div style="font-weight:600;color:#0f766e;margin-bottom:.2rem;">' + icon + ' <span style="float:right;">' + d.scan_percent + '%</span></div>';
        h += '<div class="bpm-msg">' + esc(msg) + '</div>';
        h += '<div class="bpm-bar scan"><div style="width:' + d.scan_percent + '%"></div></div>';

        var s = d.scan_summary || {};
        if (d.status === 'scan_completed' && s && s.total_rows != null) {
            h += '<div class="bpm-meta">Valid: ' + fmt(s.valid_rows) + ' • Warning: ' + fmt(s.warning_rows) +
                 ' • Error: ' + fmt(s.error_rows) + ' • Duplikat: ' + fmt(s.duplicate_rows) + '<br>' +
                 'Provider baru: ' + fmt(s.new_provider_total) + ' • Service baru: ' + fmt(s.new_service_total) +
                 ' • Kelas baru: ' + fmt(s.new_class_total) + '</div>';
        }
        if (d.status === 'scan_failed' && d.scan_error_message) {
            h += '<div class="bpm-err">' + esc(d.scan_error_message) + '</div>';
        }
        return h;
    }

    function importBlock(d) {
        var hasImport = (d.total_rows > 0) || IMPORT_ACTIVE.indexOf(d.status) !== -1 ||
                        d.status === 'completed' || d.status === 'failed';
        if (!hasImport) return '';
        var msg, icon = '<i class="bi bi-download"></i> Import';
        if (d.status === 'pending_import') { msg = 'Menunggu worker...'; icon = '<i class="bi bi-clock"></i> Import'; }
        else if (d.status === 'processing_import') {
            msg = d.processed_rows > 0
                ? 'Memproses ' + fmt(d.processed_rows) + ' / ' + fmt(d.total_rows)
                : 'Menyiapkan import...';
            icon = '<span class="bpm-spin"></span> Import';
        }
        else if (d.status === 'completed') { msg = 'Import selesai — ' + fmt(d.total_rows) + ' baris diproses'; }
        else if (d.status === 'failed') { msg = 'Import gagal'; }
        else { return ''; }

        var h = '<div style="font-weight:600;color:#1e40af;margin-bottom:.2rem;margin-top:.4rem;">' + icon + ' <span style="float:right;">' + d.percent + '%</span></div>';
        h += '<div class="bpm-msg">' + esc(msg) + '</div>';
        h += '<div class="bpm-bar"><div style="width:' + d.percent + '%"></div></div>';

        if (d.status === 'completed') {
            h += '<div class="bpm-meta">Berhasil: ' + fmt(d.inserted) + ' • Duplikat: ' + fmt(d.skipped_duplicate) +
                 ' • Error: ' + fmt(d.skipped_error) + '<br>Provider baru: ' + fmt(d.providers_created) +
                 ' • Service baru: ' + fmt(d.services_created) + ' • Kelas baru: ' + fmt(d.classes_created) + '</div>';
        }
        if (d.status === 'failed') {
            h += '<div class="bpm-meta">Progress terakhir: ' + fmt(d.processed_rows) + ' / ' + fmt(d.total_rows) + '</div>';
            if (d.error_message) h += '<div class="bpm-err">' + esc(d.error_message) + '</div>';
        }
        return h;
    }

    function itemHTML(it) {
        var d = it.data;
        var canClose = !d || TERMINAL.indexOf(d.status) !== -1;
        var h = '<div class="bpm-item" data-bpm-item="' + esc(it.id) + '">';
        h += '<div class="bpm-item-top"><span class="bpm-t">' + esc(it.title) + '</span>' +
             (d ? badge(d.status) : '<span class="bpm-badge bpm-b-yellow">MENUNGGU</span>');
        if (canClose) h += '<button type="button" class="bpm-x" data-bpm-close="' + esc(it.id) + '" title="Tutup">&times;</button>';
        h += '</div>';
        if (!d) {
            h += '<div class="bpm-msg">Menunggu status dari worker...</div>';
        } else {
            h += scanBlock(d) + importBlock(d);
            h += '<div class="bpm-actions">';
            h += '<a class="bpm-view" href="/tarifs/import/batches/' + esc(it.id) + '"><i class="bi bi-eye"></i> Lihat</a>';
            if (d.status === 'scan_failed') {
                h += '<button type="button" class="bpm-retry" data-bpm-retry="scan:' + esc(it.id) + '"><i class="bi bi-arrow-repeat"></i> Coba Lagi</button>';
            } else if (d.status === 'failed') {
                h += '<button type="button" class="bpm-retry" data-bpm-retry="import:' + esc(it.id) + '"><i class="bi bi-arrow-repeat"></i> Coba Lagi</button>';
            }
            h += '</div>';
        }
        return h + '</div>';
    }

    function render() {
        var panel = shell();
        var mini = document.getElementById('bpm-mini');
        if (items.size === 0) {
            panel.style.display = 'none';
            mini.style.display = 'none';
            return;
        }
        var n = activeCount();
        var head = document.getElementById('bpm-head');
        head.innerHTML = '<i class="bi bi-gear" style="color:#1e3a5f;"></i>' +
            '<span class="bpm-title">Proses Berjalan' + (n > 0 ? ' (' + n + ')' : '') + '</span>' +
            '<button type="button" data-bpm-min title="Minimize">−</button>';
        head.querySelector('[data-bpm-min]').addEventListener('click', function () { api.minimize(); });

        var body = document.getElementById('bpm-body');
        var html = '';
        items.forEach(function (it) { html += itemHTML(it); });
        body.innerHTML = html || '<div class="bpm-msg">Tidak ada proses.</div>';

        body.querySelectorAll('[data-bpm-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { api.remove(btn.getAttribute('data-bpm-close')); });
        });
        body.querySelectorAll('[data-bpm-retry]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var parts = btn.getAttribute('data-bpm-retry').split(':');
                retryProcess(parts[1], parts[0], btn);
            });
        });

        if (minimized) {
            panel.style.display = 'none';
            mini.style.display = 'flex';
            mini.innerHTML = '<i class="bi bi-gear"></i> ' + (n > 0 ? n + ' proses berjalan' : 'Proses selesai') + ' <span style="font-size:1rem;line-height:1;">＋</span>';
        } else {
            panel.style.display = 'block';
            mini.style.display = 'none';
        }
    }

    function retryProcess(id, kind, btn) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        btn.disabled = true;
        fetch('/tarifs/import/batches/' + encodeURIComponent(id) + '/' + (kind === 'scan' ? 'retry-scan' : 'retry'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            poll();
        }).catch(function () {
            btn.disabled = false;
            if (window.Toast) Toast.error('Retry gagal dikirim. Coba lagi dari halaman Riwayat.', { title: 'Retry gagal' });
        });
    }

    // ---------- polling ----------

    function poll() {
        if (items.size === 0) return;
        fetch(STATUS_URL, { headers: { Accept: 'application/json' } })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (!data || !data.batches) return;
                var seen = {};
                data.batches.forEach(function (b) { seen[String(b.id)] = b; });
                items.forEach(function (it, id) {
                    if (seen[id]) it.data = seen[id];
                    else items.delete(id); // batch dihapus di server
                });
                saveLS();
                render();
                schedule();
            })
            .catch(function () { schedule(); });
    }

    function schedule() {
        if (timer) clearTimeout(timer);
        timer = null;
        var need = false;
        items.forEach(function (it) { if (isActive(it)) need = true; });
        if (need) timer = setTimeout(poll, POLL_MS);
    }

    // ---------- API ----------

    var api = {
        track: function (info) {
            if (!info || info.id == null) return;
            var id = String(info.id);
            if (!items.has(id)) {
                items.set(id, { id: id, title: info.title || ('Batch #' + id), data: null });
            } else if (info.title) {
                items.get(id).title = info.title;
            }
            saveLS();
            render();
            schedule();
        },
        update: function (id, patch) {
            id = String(id);
            if (!items.has(id)) return;
            var it = items.get(id);
            if (patch.title) it.title = patch.title;
            if (patch.data) it.data = patch.data;
            saveLS();
            render();
            schedule();
        },
        remove: function (id) {
            items.delete(String(id));
            saveLS();
            render();
            schedule();
        },
        minimize: function () { minimized = true; saveLS(); render(); },
        expand: function () { minimized = false; saveLS(); render(); schedule(); },
        toggleMin: function () { minimized ? api.expand() : api.minimize(); },
        clearCompleted: function () {
            items.forEach(function (it, id) {
                if (it.data && TERMINAL.indexOf(it.data.status) !== -1) items.delete(id);
            });
            saveLS();
            render();
            schedule();
        },
    };

    window.ProcessManager = api;

    document.addEventListener('DOMContentLoaded', function () {
        loadLS();
        // Auto-track dari markup halaman (riwayat / detail batch).
        document.querySelectorAll('[data-bpm-track]').forEach(function (el) {
            var id = el.getAttribute('data-bpm-track') || el.getAttribute('data-batch-row');
            if (id) api.track({ id: id, title: el.getAttribute('data-bpm-title') || undefined });
        });
        render();
        if (items.size > 0) poll();
    });
})();
