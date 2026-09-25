@props(['rows' => []])

@php
    // Store JSON untuk modal analisa per baris: hanya row AMBIGUOUS /
    // NOT_FOUND agar ukuran HTML tetap kecil. Kandidat dipangkas ke
    // field yang dipakai modal (termasuk metadata skor _*).
    $analysisStore = [];
    foreach ($rows as $row) {
        if (! in_array($row['status'] ?? '', ['AMBIGUOUS', 'NOT_FOUND'], true)) {
            continue;
        }
        $candidates = [];
        foreach (array_slice($row['candidates'] ?? [], 0, 20) as $c) {
            $candidates[] = [
                'service_code' => $c['service_code'] ?? '-',
                'service_name' => $c['service_name'] ?? null,
                'class_code' => $c['class_code'] ?? '-',
                'class_name' => $c['class_name'] ?? null,
                'tariff' => isset($c['tariff']) && is_numeric($c['tariff']) ? (float) $c['tariff'] : null,
                'score' => isset($c['_score']) ? round((float) $c['_score'], 3) : null,
                'class_match' => (int) ($c['_class_match'] ?? 0),
                'tariff_diff' => isset($c['_tariff_diff']) && is_numeric($c['_tariff_diff']) ? (float) $c['_tariff_diff'] : null,
            ];
        }
        $suggested = $row['suggested'] ?? null;
        $analysisStore[$row['excel_row']] = [
            'excel_row' => $row['excel_row'],
            'status' => $row['status'],
            'service_code' => $row['service_code'] ?? '',
            'service_description' => $row['service_description'] ?? '',
            'service_class_code' => $row['service_class_code'] ?? '',
            'class_name' => $row['class_name'] ?? '',
            'tariff' => isset($row['tariff']) && is_numeric($row['tariff']) ? (float) $row['tariff'] : null,
            'tariff_raw' => trim((string) ($row['tariff_raw'] ?? '')),
            'mapping_key' => $row['mapping_key'] ?? '',
            'new_class_code' => $row['new_class_code'] ?? null,
            'analysis' => $row['analysis'] ?? null,
            'suggested' => $suggested ? [
                'service_code' => $suggested['service_code'] ?? '-',
                'class_code' => $suggested['class_code'] ?? '-',
            ] : null,
            'candidates' => $candidates,
        ];
    }
@endphp

<p class="px-4 pt-3 text-xs text-gray-500"><i class="bi bi-cursor-click"></i> Klik baris berstatus <span class="font-semibold text-yellow-700">AMBIGUOUS</span> / <span class="font-semibold text-red-700">NOT_FOUND</span> untuk melihat detail analisa.</p>
<x-table :columns="['Row', 'Status', 'Old Code', 'Description', 'Old Class Code', 'Kelas', 'New Code', 'New Class Code']" empty="Tidak ada baris.">
    @foreach($rows as $row)
    @php $clickable = in_array($row['status'] ?? '', ['AMBIGUOUS', 'NOT_FOUND'], true); @endphp
    <tr @if($clickable) data-ba-open="{{ $row['excel_row'] }}" title="Klik untuk lihat detail analisa" @endif
        class="hover:bg-gray-50 transition-colors @if($clickable) cursor-pointer hover:bg-blue-50/60 @endif">
        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">{{ $row['excel_row'] }}</td>
        <td class="px-4 py-3 whitespace-nowrap">
            <x-bridge.status-badge :status="$row['status']" />
            @if(($row['status'] ?? '') === 'AMBIGUOUS' && !empty($row['suggested']['service_code']))
                <span class="ml-1 inline-flex px-1.5 py-0 text-[10px] font-bold rounded-full bg-green-600 text-white align-middle">ada saran</span>
            @endif
        </td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs bg-gray-50">{{ $row['service_code'] !== '' ? $row['service_code'] : '-' }}</td>
        <td class="px-4 py-3 text-xs max-w-xs truncate">{{ $row['service_description'] !== '' ? $row['service_description'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs bg-gray-50">{{ $row['service_class_code'] !== '' ? $row['service_class_code'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['class_name'] !== '' ? $row['class_name'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs font-semibold bg-blue-50">{{ $row['new_service_code'] ?? '-' }}@if(($row['status'] ?? '') === 'AMBIGUOUS' && !empty($row['suggested_applied'])) <span class="inline-flex px-1.5 py-0 text-[10px] font-bold rounded-full bg-teal-600 text-white font-sans">saran</span>@endif</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs font-semibold bg-blue-50">{{ $row['new_class_code'] ?? '-' }}</td>
    </tr>
    @endforeach
</x-table>

@if(!empty($analysisStore))
<div data-ba-modal class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900 bg-opacity-50" data-ba-close></div>
    <div class="relative w-full max-w-3xl max-h-[85vh] flex flex-col bg-white rounded-lg shadow-xl">
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h3 class="text-base font-semibold text-sp-navy" data-ba-title>Detail Analisa</h3>
            <button type="button" data-ba-close class="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto px-4 py-3 min-h-0" data-ba-body></div>
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-t bg-gray-50 rounded-b-lg">
            <p class="text-xs text-gray-500">Pilihan final tetap lewat tombol “Petakan Manual” di atas tabel.</p>
            <button type="button" data-ba-close class="px-4 py-2 text-sm font-semibold text-gray-700 rounded-md border border-gray-300 hover:bg-gray-100 transition-colors">Tutup</button>
        </div>
    </div>
</div>

<script type="application/json" data-ba-store>{!! json_encode($analysisStore, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>

@once
@push('scripts')
<script>
(function () {
    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    function rupiah(v) {
        if (v === null || v === undefined || v === '') return '-';
        try { return 'Rp ' + new Intl.NumberFormat('id-ID').format(v); }
        catch (e) { return String(v); }
    }
    function pct(diff) {
        if (diff === null || diff === undefined) return '<span class="text-gray-400">tanpa sinyal</span>';
        return (diff * 100).toFixed(2).replace('.', ',') + '%';
    }
    function store() {
        var el = document.querySelector('[data-ba-store]');
        if (!el) return {};
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
    }
    function renderCompares(candidates, suggested) {
        var sugCode = suggested ? suggested.service_code + '|' + suggested.class_code : null;
        var html = '<div class="overflow-x-auto border border-gray-200 rounded-md"><table class="w-full text-xs min-w-max">'
            + '<thead><tr class="bg-gray-100">'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Kandidat</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Kelas Master</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Tarif Master</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Selisih Tarif</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Kelas Cocok</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700">Skor</th>'
            + '<th class="px-3 py-2 text-left font-semibold text-gray-700"></th>'
            + '</tr></thead><tbody class="divide-y divide-gray-100">';
        candidates.forEach(function (c, i) {
            var key = c.service_code + '|' + c.class_code;
            var isSug = sugCode && key === sugCode;
            html += '<tr class="' + (isSug ? 'bg-green-50 font-semibold' : (i === 0 ? 'bg-yellow-50' : '')) + '">'
                + '<td class="px-3 py-2 font-mono">' + esc(c.service_code) + '<span class="block font-sans text-gray-500">' + esc(c.service_name || '') + '</span></td>'
                + '<td class="px-3 py-2 font-mono">' + esc(c.class_code) + '<span class="block font-sans text-gray-500">' + esc(c.class_name || '') + '</span></td>'
                + '<td class="px-3 py-2 whitespace-nowrap">' + esc(rupiah(c.tariff)) + '</td>'
                + '<td class="px-3 py-2 whitespace-nowrap">' + pct(c.tariff_diff) + '</td>'
                + '<td class="px-3 py-2">' + (c.class_match ? '<span class="inline-flex px-2 py-0.5 text-[11px] font-bold rounded-full bg-green-100 text-green-800">Ya</span>' : '<span class="text-gray-400">Tidak</span>') + '</td>'
                + '<td class="px-3 py-2 font-mono">' + esc(c.score === null ? '-' : c.score) + '</td>'
                + '<td class="px-3 py-2">' + (isSug ? '<span class="inline-flex px-2 py-0.5 text-[11px] font-bold rounded-full bg-green-600 text-white">Saran</span>' : (i === 0 ? '<span class="inline-flex px-2 py-0.5 text-[11px] font-bold rounded-full bg-yellow-100 text-yellow-800">Teratas</span>' : '')) + '</td>'
                + '</tr>';
        });
        return html + '</tbody></table></div>'
            + '<p class="mt-1 text-[11px] text-gray-500">Skor = kecocokan kode kelas × 1,0 + kedekatan tarif × 2,0. “Saran” muncul bila satu kandidat cocok nyaris persis (≤ 1%) dengan gap jelas: cocok persis 0% cukup gap ≥ 1pp, yang hanya dekat butuh gap ≥ 5pp — tetap perlu dipilih manual.</p>';
    }
    function excelTariffLabel(d) {
        if (d.tariff !== null && d.tariff !== undefined) return rupiah(d.tariff);
        if (d.tariff_raw) return esc(d.tariff_raw) + ' (tidak terbaca)';
        return 'kosong';
    }
    function renderBody(d) {
        var excelTariff = excelTariffLabel(d);
        var html = '<div class="flex flex-col gap-3 text-sm">'
            + '<div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">'
            + '<div class="border rounded-md p-2"><p class="font-semibold text-gray-500">Old Code</p><p class="font-mono">' + esc(d.service_code || '-') + '</p></div>'
            + '<div class="border rounded-md p-2"><p class="font-semibold text-gray-500">Description</p><p>' + esc(d.service_description || '-') + '</p></div>'
            + '<div class="border rounded-md p-2"><p class="font-semibold text-gray-500">Old Class Code</p><p class="font-mono">' + esc(d.service_class_code || '-') + '</p></div>'
            + '<div class="border rounded-md p-2"><p class="font-semibold text-gray-500">Kelas</p><p>' + esc(d.class_name || '-') + '</p></div>'
            + '<div class="border rounded-md p-2 border-teal-300 bg-teal-50"><p class="font-semibold text-teal-700">Tarif Excel</p><p class="font-bold text-teal-900">' + excelTariff + '</p></div>'
            + '<div class="border rounded-md p-2"><p class="font-semibold text-gray-500">Mapping Key</p><p class="font-mono break-all">' + esc(d.mapping_key || '-') + '</p></div>'
            + '</div>'
            + '<div><p class="text-xs font-semibold text-gray-600 mb-1">Penjelasan Analisa</p>'
            + '<p class="text-xs leading-relaxed text-gray-700 bg-gray-50 border border-gray-200 rounded-md p-2.5">' + esc(d.analysis || 'Tidak ada penjelasan.') + '</p></div>';
        if (d.status === 'AMBIGUOUS') {
            if (d.suggested) {
                html += '<div class="text-xs bg-green-50 border border-green-200 rounded-md p-2.5">Rekomendasi (belum diterapkan): <span class="font-mono font-bold">' + esc(d.suggested.service_code) + ' | ' + esc(d.suggested.class_code) + '</span> — pilih di “Petakan Manual” bila setuju.</div>';
            } else {
                html += '<div class="text-xs bg-yellow-50 border border-yellow-200 rounded-md p-2.5">Belum ada rekomendasi yang cukup yakin — bandingkan tabel lalu pilih manual.</div>';
            }
            html += '<div><p class="text-xs font-semibold text-gray-600 mb-1">Perbandingan Kandidat (' + d.candidates.length + ')</p>'
                + '<div class="mb-1.5 text-xs bg-teal-50 border border-teal-300 rounded-md px-2.5 py-1.5">Tarif Excel (pembanding): <span class="font-bold font-mono">' + excelTariff + '</span></div>'
                + renderCompares(d.candidates, d.suggested) + '</div>';
        } else if (d.status === 'NOT_FOUND') {
            html += '<div class="text-xs bg-red-50 border border-red-200 rounded-md p-2.5">Kode kelas master untuk baris ini: <span class="font-mono font-bold">' + esc(d.new_class_code || '(tidak dikenali — ikut bawaan Excel)') + '</span>. Cari service yang benar lewat “Petakan Manual” (ketik ≥ 2 huruf untuk menyaring, klik untuk saran paling mirip description).</div>';
        }
        return html + '</div>';
    }

    document.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-ba-open]');
        if (openBtn) {
            var modal = document.querySelector('[data-ba-modal]');
            var d = store()[openBtn.getAttribute('data-ba-open')];
            if (!modal || !d) return;
            modal.querySelector('[data-ba-title]').textContent = 'Analisa Row ' + d.excel_row + ' — ' + d.status;
            modal.querySelector('[data-ba-body]').innerHTML = renderBody(d);
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            return;
        }
        if (e.target.closest('[data-ba-close]')) {
            var modal = e.target.closest('[data-ba-modal]');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('[data-ba-modal]').forEach(function (m) { m.classList.add('hidden'); });
            document.body.classList.remove('overflow-hidden');
        }
    });
})();
</script>
@endpush
@endonce
@endif
