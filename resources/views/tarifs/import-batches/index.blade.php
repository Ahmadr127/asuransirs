@extends('layouts.app')

@section('title', 'Riwayat Import Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4">
    <x-card padding="false">
        <x-slot name="title">Riwayat Import Tarif</x-slot>
        <x-slot name="subtitle">Scan & import berjalan di background via Queue — browser tidak perlu menunggu. Wajib worker: <code class="font-mono text-xs bg-gray-100 px-1 rounded">php artisan queue:work --timeout=1800 --tries=1 --sleep=3</code> (jangan queue:listen).</x-slot>
        <x-slot name="actions">
            <a href="{{ route('tarif-import.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <x-table :columns="['File', 'Jenis', 'Status', 'Progress', 'Hasil', 'Aksi']" :pagination="$batches" empty="Belum ada import. Upload Excel dari halaman Import untuk memulai scan.">
            @foreach($batches as $batch)
            <tr class="hover:bg-gray-50 transition-colors" data-batch-row="{{ $batch->id }}" data-batch-status="{{ $batch->status }}" data-bpm-track="{{ $batch->id }}" data-bpm-title="{{ $batch->filename }}">
                <td class="px-4 py-3">
                    <div class="text-sm font-medium text-gray-900">{{ $batch->filename }}</div>
                    <div class="text-xs text-gray-500">#{{ $batch->id }} &bull; {{ $batch->created_at?->format('d/m/Y H:i') }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ $batch->jenisTarif->name ?? '-' }}</td>
                <td class="px-4 py-3 whitespace-nowrap" data-batch-badge>
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ \App\Models\ImportBatch::badgeClass($batch->status) }}">{{ \App\Models\ImportBatch::statusLabel($batch->status) }}</span>
                </td>
                <td class="px-4 py-3 min-w-52" data-batch-progress>
                    @if($batch->isScanActive())
                        <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                            <div class="bg-sp-primary h-2.5 rounded-full transition-all duration-500" style="width: {{ $batch->scanPercent() }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-600">Scan: {{ number_format($batch->scan_processed_rows) }} / {{ number_format($batch->scan_total_rows) }} rows ({{ $batch->scanPercent() }}%)</p>
                    @elseif($batch->isImportActive())
                        <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                            <div class="bg-blue-600 h-2.5 rounded-full transition-all duration-500" style="width: {{ $batch->progressPercent() }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-600">{{ number_format($batch->processed_rows) }} / {{ number_format($batch->total_rows) }} rows ({{ $batch->progressPercent() }}%)</p>
                    @else
                        <p class="text-xs text-gray-500">{{ number_format($batch->processed_rows) }} / {{ number_format($batch->total_rows) }} rows</p>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-gray-600 max-w-xs" data-batch-result>
                    @if($batch->status === \App\Models\ImportBatch::STATUS_SCAN_COMPLETED)
                        <a href="{{ route('tarif-import.batches.show', $batch) }}" class="font-semibold text-sp-primary hover:underline">Lihat preview & import &rarr;</a>
                    @elseif($batch->status === \App\Models\ImportBatch::STATUS_SCAN_FAILED)
                        <div class="font-medium text-red-700">Scan gagal</div>
                        <div>Progress terakhir: {{ number_format($batch->scan_processed_rows) }} / {{ number_format($batch->scan_total_rows) }} rows</div>
                        @if($batch->scan_error_message)
                            <div class="mt-1 p-1.5 bg-red-50 border border-red-200 rounded text-red-800 break-words">{{ \Illuminate\Support\Str::limit($batch->scan_error_message, 300) }}</div>
                        @endif
                    @elseif($batch->status === \App\Models\ImportBatch::STATUS_COMPLETED)
                        <div>Masuk: <span class="font-semibold text-green-700">{{ number_format($batch->inserted) }}</span> &bull; Duplikat: {{ number_format($batch->skipped_duplicate) }} &bull; Error: {{ number_format($batch->skipped_error) }}</div>
                        <div class="text-gray-500">Provider +{{ $batch->providers_created }}, Service +{{ $batch->services_created }}, Kelas +{{ $batch->classes_created }}</div>
                    @elseif($batch->status === \App\Models\ImportBatch::STATUS_FAILED)
                        <div class="font-medium text-red-700">Import gagal</div>
                        <div>Progress terakhir: {{ number_format($batch->processed_rows) }} / {{ number_format($batch->total_rows) }} rows</div>
                        @if($batch->error_message)
                            <div class="mt-1 p-1.5 bg-red-50 border border-red-200 rounded text-red-800 break-words">{{ \Illuminate\Support\Str::limit($batch->error_message, 300) }}</div>
                        @endif
                    @else
                        <span class="text-gray-400">Menunggu worker...</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap" data-batch-actions>
                    <div class="flex items-center gap-2">
                        @if(in_array($batch->status, [\App\Models\ImportBatch::STATUS_SCAN_COMPLETED, \App\Models\ImportBatch::STATUS_SCAN_FAILED], true))
                            <a href="{{ route('tarif-import.batches.show', $batch) }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                                <i class="bi bi-eye"></i> {{ $batch->status === \App\Models\ImportBatch::STATUS_SCAN_COMPLETED ? 'Preview' : 'Detail' }}
                            </a>
                        @endif
                        @if($batch->status === \App\Models\ImportBatch::STATUS_SCAN_FAILED)
                            <form action="{{ route('tarif-import.batches.retry-scan', $batch) }}" method="POST">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white rounded-md bg-blue-600 hover:bg-blue-700 transition-colors">
                                    <i class="bi bi-arrow-repeat"></i> Retry Scan
                                </button>
                            </form>
                        @endif
                        @if($batch->status === \App\Models\ImportBatch::STATUS_FAILED)
                            <form action="{{ route('tarif-import.batches.retry', $batch) }}" method="POST">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white rounded-md bg-blue-600 hover:bg-blue-700 transition-colors">
                                    <i class="bi bi-arrow-repeat"></i> Retry
                                </button>
                            </form>
                        @endif
                        @if(!in_array($batch->status, [\App\Models\ImportBatch::STATUS_PROCESSING_SCAN, \App\Models\ImportBatch::STATUS_PROCESSING_IMPORT], true))
                            <form action="{{ route('tarif-import.batches.destroy', $batch) }}" method="POST" onsubmit="return confirm('Hapus import ini? Data history import dan file terkait akan dihapus. Tindakan ini tidak dapat dibatalkan.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-red-600 border border-red-300 rounded-md bg-white hover:bg-red-50 transition-colors">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        @else
                            <span class="inline-flex items-center px-3 py-1.5 text-xs text-gray-400" title="Tidak dapat dihapus selama diproses">Diproses...</span>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const statusUrl = @json(route('tarif-import.batches.status'));
    const rows = document.querySelectorAll('[data-batch-row]');
    if (rows.length === 0) return;

    const prev = {};
    rows.forEach(r => { prev[r.dataset.batchRow] = r.dataset.batchStatus; });

    const badgeClass = {
        completed: 'bg-green-100 text-green-800',
        scan_completed: 'bg-green-100 text-green-800',
        processing_import: 'bg-blue-100 text-blue-800',
        processing_scan: 'bg-blue-100 text-blue-800',
        failed: 'bg-red-100 text-red-800',
        scan_failed: 'bg-red-100 text-red-800',
        pending_scan: 'bg-yellow-100 text-yellow-800',
        pending_import: 'bg-yellow-100 text-yellow-800',
    };
    const fmt = n => Number(n || 0).toLocaleString('id-ID');
    const active = s => ['pending_scan', 'processing_scan', 'pending_import', 'processing_import'].includes(s);

    function hasActive() {
        return Object.values(prev).some(active);
    }

    async function poll() {
        let res;
        try {
            res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
        } catch (e) { schedule(); return; }
        if (!res.ok) { schedule(); return; }
        const data = await res.json();

        (data.batches || []).forEach(b => {
            const row = document.querySelector(`[data-batch-row="${b.id}"]`);
            if (!row) return;
            const old = prev[b.id];

            // Progress bar: processed/total*100 (scan & import), bukan fixed maximum.
            const prog = row.querySelector('[data-batch-progress]');
            if (prog && (b.is_scan_active || b.is_import_active)) {
                const isScan = b.is_scan_active;
                const done = isScan ? b.scan_processed_rows : b.processed_rows;
                const total = isScan ? b.scan_total_rows : b.total_rows;
                const label = isScan ? 'Scan' : '';
                prog.innerHTML =
                    '<div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">' +
                    `<div class="${isScan ? 'bg-sp-primary' : 'bg-blue-600'} h-2.5 rounded-full transition-all duration-500" style="width: ${isScan ? b.scan_percent : b.percent}%"></div></div>` +
                    `<p class="mt-1 text-xs text-gray-600">${label ? label + ': ' : ''}${fmt(done)} / ${fmt(total)} rows (${isScan ? b.scan_percent : b.percent}%)</p>`;
            }

            // Transisi terminal/entry-baru: reload agar aksi & badge akurat.
            // Notifikasi persisten ditangani Floating Process Manager.
            if (old !== b.status && ['scan_completed', 'scan_failed', 'completed', 'failed'].includes(b.status)) {
                prev[b.id] = b.status;
                setTimeout(() => window.location.reload(), 1500);
                return;
            }
            prev[b.id] = b.status;

            const badge = row.querySelector('[data-batch-badge] span');
            if (badge) {
                badge.textContent = b.status_label || String(b.status).toUpperCase();
                badge.className = `inline-flex px-2 py-0.5 text-xs font-semibold rounded-full ${badgeClass[b.status] || badgeClass.pending_import}`;
            }
        });

        if (hasActive()) schedule();
    }

    function schedule() { setTimeout(poll, 2000); }

    if (hasActive()) schedule();
})();
</script>
@endpush
