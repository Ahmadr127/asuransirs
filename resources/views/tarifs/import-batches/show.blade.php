@extends('layouts.app')

@section('title', 'Scan Import Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4" data-bpm-track="{{ $batch->id }}" data-bpm-title="{{ $batch->filename }}">
    <x-card>
        <x-slot name="title">Scanning Excel</x-slot>
        <x-slot name="subtitle">{{ $batch->filename }} &bull; {{ $batch->jenisTarif->name ?? '-' }} &bull; scan berjalan di background, halaman boleh ditinggal</x-slot>
        <x-slot name="actions">
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
            <p id="scan-error" class="hidden text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"></p>
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
    const fmt = n => Number(n || 0).toLocaleString('id-ID');

    // Notifikasi persisten ditangani Floating Process Manager.
    async function poll() {
        let res;
        try {
            res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
        } catch (e) { return schedule(); }
        if (!res.ok) { return schedule(); }
        const data = await res.json();
        const b = (data.batches || []).find(x => String(x.id) === String(batchId));
        if (!b) { return schedule(); }

        bar.style.width = b.scan_percent + '%';
        text.textContent = `${fmt(b.scan_processed_rows)} / ${fmt(b.scan_total_rows)} rows (${b.scan_percent}%)`;

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
        schedule();
    }

    function schedule() { setTimeout(poll, 1500); }
    schedule();
})();
</script>
@endpush
