<x-card padding="false">
    <x-slot name="title">Mapping Tarif</x-slot>
    <x-slot name="subtitle">Upload Excel lama, mapping otomatis ke master Tarif (SERVICECODE DESCRIPTION + KELAS), lalu generate Excel baru</x-slot>

    <form id="bridge-scan-form" action="{{ route('bridge.scan') }}" method="POST" enctype="multipart/form-data" class="p-4 flex flex-col md:flex-row gap-4 items-end">
        @csrf
        <div class="flex-1">
            <label for="bridge-file" class="block text-sm font-semibold text-sp-navy mb-1">File Excel <span class="text-red-500">*</span></label>
            <input id="bridge-file" name="file" type="file" accept=".xlsx,.xls,.csv,.html,.htm" required
                class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary file:mr-3 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:border file:border-gray-300 file:rounded-md file:bg-gray-50 hover:file:bg-gray-100">
            <p class="mt-1 text-xs text-gray-500">Format .xlsx / .xls / .csv (termasuk .xls hasil export lama), maks 20 MB. Kolom yang dibaca: SERVICECODE, SERVICECODE DESCRIPTION, SERVICECODE KELAS, KELAS. <span id="bridge-file-info" class="font-medium text-gray-700"></span></p>
            @error('file')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <button id="bridge-scan-btn" type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors disabled:opacity-70 disabled:cursor-wait">
                <i class="bi bi-search"></i> <span>Scan Excel</span>
            </button>
        </div>
    </form>
</x-card>

{{-- Overlay loading scan: backdrop blur + kartu animasi (Animate.css) + efek scan beam CSS --}}
<div id="bridge-loading" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-sp-navy/60 backdrop-blur-sm">
    <div class="animate__animated animate__fadeInUp animate__faster w-full max-w-sm bg-white rounded-2xl shadow-2xl px-6 py-6 text-center">
        {{-- Ikon dokumen + beam scan beranimasi --}}
        <div class="relative mx-auto w-24 h-24 rounded-2xl bg-sp-primary/10 flex items-center justify-center overflow-hidden">
            <i class="bi bi-file-earmark-spreadsheet text-5xl text-sp-primary animate__animated animate__pulse animate__infinite"></i>
            <div class="bridge-beam"></div>
        </div>

        <h3 class="mt-4 text-base font-bold text-sp-navy">Memproses Excel…</h3>
        <p id="bridge-loading-file" class="mt-1 text-xs text-gray-500 truncate"></p>
        <p class="mt-2 text-sm font-semibold text-sp-primary">
            <span id="bridge-loading-status">Mengupload file ke server…</span><span class="bridge-dots"><span>.</span><span>.</span><span>.</span></span>
        </p>

        {{-- Progress bar shimmer (indeterminate) --}}
        <div class="mt-4 h-2.5 w-full rounded-full bg-gray-100 overflow-hidden">
            <div class="bridge-shimmer h-full w-full rounded-full"></div>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            <i class="bi bi-clock"></i> <span id="bridge-loading-timer">0 dtk</span> &bull; jangan tutup / refresh halaman
        </p>
    </div>
</div>

<style>
    /* Beam scan: garis cahaya menyapu ikon dokumen dari atas ke bawah */
    .bridge-beam {
        position: absolute;
        left: 0;
        right: 0;
        top: -30%;
        height: 30%;
        background: linear-gradient(to bottom, transparent, rgba(0, 119, 116, 0.45), transparent);
        animation: bridge-beam-sweep 1.6s ease-in-out infinite;
        pointer-events: none;
    }
    @keyframes bridge-beam-sweep {
        0% { top: -30%; }
        50% { top: 100%; }
        100% { top: -30%; }
    }
    /* Titik-titik status memantul berurutan */
    .bridge-dots span {
        display: inline-block;
        animation: bridge-dot-bounce 1.2s infinite;
    }
    .bridge-dots span:nth-child(2) { animation-delay: 0.15s; }
    .bridge-dots span:nth-child(3) { animation-delay: 0.3s; }
    @keyframes bridge-dot-bounce {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30% { transform: translateY(-4px); opacity: 1; }
    }
    /* Shimmer geser untuk progress bar indeterminate */
    .bridge-shimmer {
        background: linear-gradient(90deg, #007774 0%, #4fd1c5 25%, #007774 50%, #4fd1c5 75%, #007774 100%);
        background-size: 200% 100%;
        animation: bridge-shimmer-slide 1.4s linear infinite;
    }
    @keyframes bridge-shimmer-slide {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
</style>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('bridge-scan-form');
    if (!form || form.dataset.bridgeLoading === 'on') return;
    form.dataset.bridgeLoading = 'on';

    const overlay = document.getElementById('bridge-loading');
    const btn = document.getElementById('bridge-scan-btn');
    const input = document.getElementById('bridge-file');
    const info = document.getElementById('bridge-file-info');
    const statusEl = document.getElementById('bridge-loading-status');
    const timerEl = document.getElementById('bridge-loading-timer');
    const fileEl = document.getElementById('bridge-loading-file');

    const phases = [
        'Mengupload file ke server…',
        'Membaca header & baris Excel…',
        'Normalisasi data…',
        'Mapping ke master Tarif…',
        'Menyiapkan preview hasil…',
    ];

    if (input && info) {
        input.addEventListener('change', () => {
            const f = input.files && input.files[0];
            info.textContent = f ? ('Dipilih: ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)') : '';
        });
    }

    form.addEventListener('submit', (e) => {
        if (!form.checkValidity()) return; // biarkan validasi browser tampil
        const f = input && input.files && input.files[0];
        if (fileEl) fileEl.textContent = f ? f.name : '';
        if (btn) {
            btn.disabled = true;
            btn.querySelector('span').textContent = 'Memproses…';
        }
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        let i = 0;
        const started = Date.now();
        const phaseTimer = setInterval(() => {
            i = (i + 1) % phases.length;
            if (statusEl) statusEl.textContent = phases[i];
        }, 2600);
        const clockTimer = setInterval(() => {
            if (timerEl) timerEl.textContent = Math.floor((Date.now() - started) / 1000) + ' dtk';
        }, 1000);
        // Navigasi pindah halaman → interval ikut hilang; tidak perlu clear manual.
        void phaseTimer; void clockTimer; void e;
    });
})();
</script>
@endpush
