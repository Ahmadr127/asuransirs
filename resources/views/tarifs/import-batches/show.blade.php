@extends('layouts.app')

@section('title', 'Scan Import Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4" data-bpm-track="{{ $batch->id }}" data-bpm-title="{{ $batch->filename }}">
    <x-card>
        <x-slot name="title">Scanning Excel</x-slot>
        <x-slot name="subtitle">{{ $batch->filename }} &bull; {{ $batch->jenisTarif->name ?? '-' }} &bull; scan berjalan di background, halaman boleh ditinggal</x-slot>
        <x-slot name="actions">
            <form action="{{ route('tarif-import.batches.kill', $batch) }}" method="POST" onsubmit="return confirm('HENTIKAN proses scan ini? Job antrean akan dihapus dan status menjadi DIBATALKAN.');">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-orange-600 hover:bg-orange-700 transition-colors">
                    <i class="bi bi-stop-circle"></i> Kill
                </button>
            </form>
            <form action="{{ route('tarif-import.batches.destroy', $batch) }}" method="POST" onsubmit="return confirm('Hapus import ini? Data history import dan file terkait akan dihapus. Tindakan ini tidak dapat dibatalkan.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-red-600 border border-red-300 rounded-md bg-white hover:bg-red-50 transition-colors">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </form>
            <a href="{{ route('tarif-import.batches') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-clock-history"></i> Riwayat
            </a>
        </x-slot>
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ \App\Models\ImportBatch::badgeClass($batch->status) }}">{{ \App\Models\ImportBatch::statusLabel($batch->status) }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div id="scan-bar" class="bg-sp-primary h-3 rounded-full transition-all duration-500" style="width: {{ $batch->scanPercent() }}%"></div>
            </div>
            <p id="scan-text" class="text-sm text-gray-600">{{ number_format($batch->scan_processed_rows) }} / {{ number_format($batch->scan_total_rows) }} rows ({{ $batch->scanPercent() }}%)</p>
            <p class="text-xs text-gray-500">Batch #{{ $batch->id }} &bull; diupload {{ $batch->created_at?->format('d/m/Y H:i:s') ?? '-' }} &bull; file tersimpan, menunggu giliran worker.</p>
            <p id="scan-error" class="hidden text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"></p>
            <div id="scan-stuck" class="hidden text-sm text-yellow-800 bg-yellow-50 border border-yellow-200 rounded-md px-3 py-2">
                <p class="font-semibold">File berhasil diupload, tapi scan belum berjalan <span id="scan-stuck-elapsed"></span>.</p>
                <p class="mt-1">Penyebab paling umum: <span class="font-medium">queue worker tidak berjalan</span> di server. Jalankan <code class="font-mono text-xs bg-yellow-100 px-1 rounded">php artisan queue:work --timeout=3600 --memory=1024 --tries=1 --sleep=3</code>, lalu pantau di <a href="{{ route('tarif-import.batches') }}" class="font-semibold underline">Riwayat Import</a>. Batch otomatis ditandai SCAN FAILED bila worker mati &gt; 10 menit.</p>
            </div>
        </div>
    </x-card>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const batchId = @json($batch->id);
    const statusUrl = @json(route('tarif-import.batches.status'));
    const showUrl = @json(route('tarif-import.batches.show', $batch));
    const bar = document.getElementById('scan-bar');
    const text = document.getElementById('scan-text');
    const errBox = document.getElementById('scan-error');
    const stuckBox = document.getElementById('scan-stuck');
    const stuckElapsed = document.getElementById('scan-stuck-elapsed');
    const fmt = n => Number(n || 0).toLocaleString('id-ID');

    // Notifikasi persisten ditangani Floating Process Manager.
    // Hentikan polling bila batch tak ditemukan berulang (mis. dihapus).
    let misses = 0;
    let lastProcessed = @json((int) $batch->scan_processed_rows);
    let lastChange = Date.now();
    const STUCK_AFTER_MS = 90000; // 90 detik tanpa progress → kemungkinan worker mati
    async function poll() {
        let res;
        try {
            res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
        } catch (e) { return schedule(); }
        if (!res.ok) { return schedule(); }
        const data = await res.json();
        const b = (data.batches || []).find(x => String(x.id) === String(batchId));
        if (!b) {
            misses++;
            if (misses >= 20) {
                errBox.textContent = 'Batch tidak ditemukan (mungkin sudah dihapus). Polling dihentikan.';
                errBox.classList.remove('hidden');
                text.textContent = 'Terhenti.';
                return;
            }
            return schedule();
        }
        misses = 0;

        bar.style.width = b.scan_percent + '%';
        text.textContent = `${fmt(b.scan_processed_rows)} / ${fmt(b.scan_total_rows)} rows (${b.scan_percent}%)`;

        // Deteksi antrean macet: upload OK tapi worker tidak memproses.
        if (['pending_scan', 'processing_scan'].includes(b.status)) {
            if (Number(b.scan_processed_rows) !== Number(lastProcessed)) {
                lastProcessed = Number(b.scan_processed_rows);
                lastChange = Date.now();
                if (stuckBox) stuckBox.classList.add('hidden');
            } else if (Date.now() - lastChange > STUCK_AFTER_MS) {
                if (stuckBox) stuckBox.classList.remove('hidden');
                if (stuckElapsed) {
                    const mins = Math.max(1, Math.round((Date.now() - lastChange) / 60000));
                    stuckElapsed.textContent = `(tidak ada progress ${mins} mnt)`;
                }
            }
        }

        if (b.status === 'scan_completed') {
            text.textContent = 'Scan selesai. Menampilkan preview...';
            setTimeout(() => { window.location.href = showUrl; }, 800);
            return;
        }
        if (b.status === 'scan_failed') {
            errBox.textContent = b.scan_error_message || 'Scan gagal.';
            errBox.classList.remove('hidden');
            text.textContent = 'Terhenti.';
            setTimeout(() => { window.location.href = showUrl; }, 1500);
            return;
        }
        if (b.status === 'cancelled') {
            errBox.textContent = 'Proses dihentikan oleh user.';
            errBox.classList.remove('hidden');
            text.textContent = 'Dibatalkan.';
            return;
        }
        schedule();
    }

    function schedule() { setTimeout(poll, 1500); }
    schedule();
})();
</script>
@endpush
