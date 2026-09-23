@extends('layouts.app')

@section('title', 'Import Excel Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4">
    <x-card padding="false">
        <x-slot name="title">Import Excel Tarif</x-slot>
        <x-slot name="subtitle">Pilih jenis tarif, upload Excel (11 kolom), scan, periksa preview, lalu import</x-slot>
        <x-slot name="actions">
            <a href="{{ route('tarif-import.template') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-file-earmark-spreadsheet"></i> Template
            </a>
            <a href="{{ route('tarifs.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Data Tarif
            </a>
        </x-slot>

        <form action="{{ route('tarif-import.scan') }}" method="POST" enctype="multipart/form-data" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            @csrf
            <x-searchable-dropdown
                name="jenis_tarif_id"
                label="Jenis Tarif"
                :options="$jenisTarifs->map(fn($j) => (object)['id' => $j->id, 'label' => $j->code . ' — ' . $j->name])"
                label-field="label"
                :selected="old('jenis_tarif_id', $result['jenis_tarif_id'] ?? null)"
                placeholder="Pilih Jenis Tarif..."
                :required="true"
            />
            <div>
                <label for="file" class="block text-sm font-semibold text-sp-navy mb-1">File Excel <span class="text-red-500">*</span></label>
                <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                    class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary file:mr-3 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:border file:border-gray-300 file:rounded-md file:bg-gray-50 hover:file:bg-gray-100">
                <p class="mt-1 text-xs text-gray-500">Format .xlsx / .xls / .csv, maks 20 MB. Header 11 kolom sesuai template.</p>
                @error('file')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                    <i class="bi bi-search"></i> Scan Excel
                </button>
            </div>
        </form>
    </x-card>

    @if(isset($pending))
        <x-card>
            <x-slot name="title">Memindai {{ $pending['filename'] }}</x-slot>
            <x-slot name="subtitle">{{ $pending['jenis_tarif_name'] }} &bull; file besar diproses bertahap agar tidak timeout</x-slot>
            <div class="flex flex-col gap-2">
                <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                    <div id="scan-bar" class="bg-sp-primary h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p id="scan-text" class="text-sm text-gray-600">Menyiapkan...</p>
                <p id="scan-error" class="hidden text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2"></p>
            </div>
        </x-card>
        <script>
        (function () {
            const token = @json($pending['token']);
            const total = Math.max(@json($pending['total']), 1);
            const bar = document.getElementById('scan-bar');
            const text = document.getElementById('scan-text');
            const errBox = document.getElementById('scan-error');
            const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            async function poll() {
                let res;
                try {
                    res = await fetch(@json(route('tarif-import.scan-chunk')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ token: token }),
                    });
                } catch (e) {
                    return fail('Jaringan terputus. Muat ulang halaman untuk melanjutkan (progres tersimpan).');
                }
                if (!res.ok) {
                    let msg = 'Scan gagal (HTTP ' + res.status + ').';
                    try { const j = await res.json(); if (j.message) msg = j.message; } catch (e) {}
                    return fail(msg);
                }
                const data = await res.json();
                const pct = Math.min(100, Math.round((data.processed / total) * 100));
                bar.style.width = pct + '%';
                text.textContent = 'Memproses ' + data.processed.toLocaleString('id-ID') + ' dari ~' + total.toLocaleString('id-ID') + ' baris (' + pct + '%)...';
                if (data.done) {
                    text.textContent = 'Selesai. Menampilkan hasil...';
                    window.location = @json(route('tarif-import.result')) + '?token=' + encodeURIComponent(token);
                } else {
                    poll();
                }
            }
            function fail(msg) {
                errBox.textContent = msg;
                errBox.classList.remove('hidden');
                text.textContent = 'Terhenti.';
            }
            poll();
        })();
        </script>
    @endif

    @if(isset($result))
        @if(isset($result['fatal']))
            <x-card>
                <x-slot name="title">Hasil Scan: {{ $result['filename'] }}</x-slot>
                <div class="px-4 py-3 text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-md">
                    {{ $result['fatal'] }}
                </div>
                @if(!empty($result['header']['missing']))
                    <p class="mt-2 text-xs text-gray-600">Kolom wajib hilang: {{ implode(', ', $result['header']['missing']) }}</p>
                @endif
            </x-card>
        @else
            <x-card padding="false">
                <x-slot name="title">Hasil Scan</x-slot>
                <x-slot name="subtitle">{{ $result['filename'] }} &bull; {{ $result['jenis_tarif_name'] }}</x-slot>
                <x-slot name="actions">
                    @if(($result['valid_rows'] ?? 0) + ($result['warning_rows'] ?? 0) > 0)
                        <form action="{{ route('tarif-import.commit') }}" method="POST" onsubmit="return confirm('Import {{ ($result['valid_rows'] ?? 0) + ($result['warning_rows'] ?? 0) }} baris valid ke database? Baris error/duplikat dilewati.');">
                            @csrf
                            <input type="hidden" name="token" value="{{ $result['token'] }}">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold text-white rounded-md bg-green-600 hover:bg-green-700 transition-colors">
                                <i class="bi bi-upload"></i> Import Data
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('tarif-import.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        Batal
                    </a>
                </x-slot>

                <div class="p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
                    <x-stats label="Total" :value="$result['total_rows']" icon="bi-list-ol" color="bg-gray-500" />
                    <x-stats label="Valid" :value="$result['valid_rows']" icon="bi-check-circle" color="bg-green-600" />
                    <x-stats label="Warning" :value="$result['warning_rows']" icon="bi-exclamation-triangle" color="bg-yellow-500" />
                    <x-stats label="Error" :value="$result['error_rows']" icon="bi-x-circle" color="bg-red-600" />
                    <x-stats label="Duplikat" :value="$result['duplicate_rows']" icon="bi-copy" color="bg-orange-500" />
                    <x-stats label="Prov Miss" :value="$result['provider_missing']" icon="bi-building" color="bg-purple-500" />
                    <x-stats label="Svc Miss" :value="$result['service_missing']" icon="bi-gear" color="bg-purple-500" />
                    <x-stats label="Cls Miss" :value="$result['class_missing']" icon="bi-layers" color="bg-purple-500" />
                </div>

                <div class="px-4 pb-2 text-xs text-gray-600">
                    Provider ditemukan {{ $result['provider_found'] }}, Service ditemukan {{ $result['service_found'] }}, Kelas ditemukan {{ $result['class_found'] }}.
                    Duplikat dalam file {{ $result['duplicate_in_file'] }}, duplikat dengan database {{ $result['duplicate_in_db'] }}.
                    Master baru (unik, bukan per row): Provider {{ $result['new_provider_total'] ?? 0 }}, Service {{ $result['new_service_total'] ?? 0 }}, Kelas {{ $result['new_class_total'] ?? 0 }}
                    <span class="font-semibold">(otomatis dibuat sekali saat Import, tanpa duplikat — scan tidak menulis database)</span>.
                    Kolom Bedah & Helper boleh kosong (isi "-" dianggap kosong).
                </div>

                @if((($result['new_provider_total'] ?? 0) + ($result['new_service_total'] ?? 0) + ($result['new_class_total'] ?? 0)) > 0)
                <div class="px-4 pb-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                    <div class="border border-yellow-200 bg-yellow-50 rounded-md p-3">
                        <p class="font-semibold text-yellow-800 mb-1">Provider baru: {{ $result['new_provider_total'] ?? 0 }}</p>
                        @forelse(($result['new_providers'] ?? []) as $p)
                            <div class="font-mono">{{ $p['code'] }} <span class="font-sans text-gray-600">— {{ $p['name'] }}</span></div>
                        @empty
                            <p class="text-gray-500">-</p>
                        @endforelse
                        @if(($result['new_provider_total'] ?? 0) > count($result['new_providers'] ?? []))
                            <p class="text-gray-500 mt-1">... dan {{ ($result['new_provider_total'] ?? 0) - count($result['new_providers'] ?? []) }} lainnya.</p>
                        @endif
                    </div>
                    <div class="border border-yellow-200 bg-yellow-50 rounded-md p-3">
                        <p class="font-semibold text-yellow-800 mb-1">Service baru: {{ $result['new_service_total'] ?? 0 }}</p>
                        @forelse(($result['new_services'] ?? []) as $s)
                            <div class="font-mono">{{ $s['code'] }} <span class="font-sans text-gray-600">— {{ $s['name'] }}</span></div>
                        @empty
                            <p class="text-gray-500">-</p>
                        @endforelse
                        @if(($result['new_service_total'] ?? 0) > count($result['new_services'] ?? []))
                            <p class="text-gray-500 mt-1">... dan {{ ($result['new_service_total'] ?? 0) - count($result['new_services'] ?? []) }} lainnya.</p>
                        @endif
                    </div>
                    <div class="border border-yellow-200 bg-yellow-50 rounded-md p-3">
                        <p class="font-semibold text-yellow-800 mb-1">Kelas baru: {{ $result['new_class_total'] ?? 0 }}</p>
                        @forelse(($result['new_classes'] ?? []) as $c)
                            <div class="font-mono">{{ $c['code'] }} <span class="font-sans text-gray-600">— {{ $c['name'] }}</span></div>
                        @empty
                            <p class="text-gray-500">-</p>
                        @endforelse
                        @if(($result['new_class_total'] ?? 0) > count($result['new_classes'] ?? []))
                            <p class="text-gray-500 mt-1">... dan {{ ($result['new_class_total'] ?? 0) - count($result['new_classes'] ?? []) }} lainnya.</p>
                        @endif
                    </div>
                </div>
                @endif
            </x-card>

            <x-card padding="false">
                <x-slot name="title">Preview Data @if($result['truncated_preview']) ({{ count($result['preview']) }} dari {{ $result['total_rows'] }} baris) @endif</x-slot>
                <x-table :columns="['No', 'Status', 'PROVID', 'Provider', 'Service', 'Deskripsi', 'Kelas', 'Bedah', 'Helper', 'Tarif', 'Berlaku', 'Pesan']" empty="Tidak ada baris.">
                    @foreach($result['preview'] as $row)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">{{ $row['row'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full
                                {{ $row['status'] === 'VALID' ? 'bg-green-100 text-green-800' : '' }}
                                {{ $row['status'] === 'WARNING' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                {{ $row['status'] === 'ERROR' ? 'bg-red-100 text-red-800' : '' }}
                                {{ $row['status'] === 'DUPLICATE' ? 'bg-orange-100 text-orange-800' : '' }}">{{ $row['status'] }}</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs">{{ $row['provider_code'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['provider_name'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs">{{ $row['service_code'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs max-w-xs truncate">{{ $row['service_description'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['service_class_code'] ?? '-' }} / {{ $row['class_name'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['surgery_type'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['helper'] ?? '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs text-right">{{ $row['tariff'] !== null ? number_format($row['tariff'], 2, ',', '.') : '-' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['valid_date_from'] ?? '-' }} &ndash; {{ $row['end_date_to'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600 max-w-xs">
                            @foreach($row['messages'] as $msg)
                                <div>&bull; {{ $msg }}</div>
                            @endforeach
                        </td>
                    </tr>
                    @endforeach
                </x-table>
            </x-card>

            @if(count($result['errors']) > 0)
            <x-card padding="false">
                <x-slot name="title">Error per Row @if($result['truncated_errors']) ({{ count($result['errors']) }} pertama) @endif</x-slot>
                <x-table :columns="['Row', 'Field', 'Value', 'Message']" empty="Tidak ada error.">
                    @foreach($result['errors'] as $err)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-2 whitespace-nowrap text-xs font-semibold">{{ $err['row'] }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-xs font-mono">{{ $err['field'] }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-xs">{{ $err['value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-xs text-red-700">{{ $err['message'] }}</td>
                    </tr>
                    @endforeach
                </x-table>
            </x-card>
            @endif
        @endif
    @endif
</div>
@endsection
