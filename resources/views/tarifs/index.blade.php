@extends('layouts.app')

@section('title', 'Buku Tarif')

@section('content')
<div class="w-full mx-auto">
    <x-card padding="false">
        <x-slot name="title">Buku Tarif</x-slot>
        <x-slot name="subtitle">Satu tabel dinamis untuk tarif, obat, alkes, bhp, dan makanan</x-slot>
        <x-slot name="actions">
            <a href="{{ route('tarif-import.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-upload"></i> Import Excel
            </a>
            <a href="{{ route('tarif-import.export-xlsx', request()->query()) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export XLSX
            </a>
            <a href="{{ route('tarifs.export', request()->query()) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-download"></i> Export CSV
            </a>
            <a href="{{ route('tarifs.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                <i class="bi bi-plus-lg"></i> Tambah Tarif
            </a>
        </x-slot>

        <div class="px-4 py-3 border-b border-gray-100 bg-white">
            <form method="GET" class="flex flex-col gap-3" onsubmit="return cleanTarifFilter(this)">
                <div class="flex flex-col lg:flex-row gap-3 items-end">
                    <div class="lg:w-44">
                        <label for="jenis_tarif_id" class="block text-xs font-semibold text-gray-600 mb-1">Jenis Tarif</label>
                        <select id="jenis_tarif_id" name="jenis_tarif_id" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua Jenis</option>
                            @foreach($jenisTarifs as $jenis)
                                <option value="{{ $jenis->id }}" {{ (string) request('jenis_tarif_id') === (string) $jenis->id ? 'selected' : '' }}>{{ $jenis->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1">
                        <label for="search" class="block text-xs font-semibold text-gray-600 mb-1">Pencarian</label>
                        <div class="relative">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                            <input type="text" id="search" name="search" value="{{ request('search') }}"
                                   placeholder="Cari service, provider, kelas, atau helper..."
                                   class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-full bg-gray-50 placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                        </div>
                    </div>
                    <div class="lg:w-44">
                        <label for="provider_id" class="block text-xs font-semibold text-gray-600 mb-1">Provider</label>
                        <select id="provider_id" name="provider_id" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua Provider</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" {{ (string) request('provider_id') === (string) $provider->id ? 'selected' : '' }}>{{ $provider->code }} — {{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:w-44">
                        <label for="service_id" class="block text-xs font-semibold text-gray-600 mb-1">Service</label>
                        <select id="service_id" name="service_id" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua Service</option>
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" {{ (string) request('service_id') === (string) $service->id ? 'selected' : '' }}>{{ $service->code }} — {{ $service->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:w-40">
                        <label for="class_id" class="block text-xs font-semibold text-gray-600 mb-1">Kelas</label>
                        <select id="class_id" name="class_id" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua Kelas</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ (string) request('class_id') === (string) $class->id ? 'selected' : '' }}>{{ $class->code }} — {{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex flex-col lg:flex-row gap-3 items-end">
                    <div class="lg:w-40">
                        <label for="surgery_type" class="block text-xs font-semibold text-gray-600 mb-1">Surgery Type</label>
                        <select id="surgery_type" name="surgery_type" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua</option>
                            <option value="SURGERY" {{ request('surgery_type') === 'SURGERY' ? 'selected' : '' }}>SURGERY</option>
                            <option value="NON SURGERY" {{ request('surgery_type') === 'NON SURGERY' ? 'selected' : '' }}>NON SURGERY</option>
                        </select>
                    </div>
                    <div class="lg:w-40">
                        <label for="status" class="block text-xs font-semibold text-gray-600 mb-1">Status</label>
                        <select id="status" name="status" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="">Semua</option>
                            <option value="ACTIVE" {{ request('status') === 'ACTIVE' ? 'selected' : '' }}>ACTIVE</option>
                            <option value="UPCOMING" {{ request('status') === 'UPCOMING' ? 'selected' : '' }}>UPCOMING</option>
                            <option value="EXPIRED" {{ request('status') === 'EXPIRED' ? 'selected' : '' }}>EXPIRED</option>
                        </select>
                    </div>
                    <div class="lg:w-44">
                        <label for="date_from" class="block text-xs font-semibold text-gray-600 mb-1">Periode Dari</label>
                        <input type="date" id="date_from" name="date_from" value="{{ request('date_from') }}"
                               class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                    </div>
                    <div class="lg:w-44">
                        <label for="date_to" class="block text-xs font-semibold text-gray-600 mb-1">Periode Sampai</label>
                        <input type="date" id="date_to" name="date_to" value="{{ request('date_to') }}"
                               class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                    </div>
                    <div class="lg:w-44">
                        <label for="sort" class="block text-xs font-semibold text-gray-600 mb-1">Urutkan</label>
                        <select id="sort" name="sort" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="created_at" {{ request('sort', 'created_at') === 'created_at' ? 'selected' : '' }}>Terbaru</option>
                            <option value="tariff" {{ request('sort') === 'tariff' ? 'selected' : '' }}>Nominal Tarif</option>
                            <option value="valid_date_from" {{ request('sort') === 'valid_date_from' ? 'selected' : '' }}>Awal Berlaku</option>
                            <option value="end_date_to" {{ request('sort') === 'end_date_to' ? 'selected' : '' }}>Akhir Berlaku</option>
                        </select>
                    </div>
                    <div class="lg:w-36">
                        <label for="direction" class="block text-xs font-semibold text-gray-600 mb-1">Arah</label>
                        <select id="direction" name="direction" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="desc" {{ request('direction', 'desc') === 'desc' ? 'selected' : '' }}>Menurun</option>
                            <option value="asc" {{ request('direction') === 'asc' ? 'selected' : '' }}>Menaik</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('tarifs.index') }}" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                            <i class="bi bi-x-lg mr-1.5 text-xs"></i> Reset
                        </a>
                        <button type="submit" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                            <i class="bi bi-funnel mr-1.5 text-xs"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <x-table :columns="['Service Code', 'Service Description', 'Provider', 'Kode Kelas', 'Kelas', 'Surgery Type', 'Tarif', 'Periode Berlaku', 'Status', 'Aksi']" :pagination="$tarifs" :showNumber="true" empty="Tidak ada data tarif." class="border-0 rounded-none shadow-none">
            @foreach($tarifs as $tarif)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap text-center text-gray-500">{{ ($tarifs->firstItem() ?? 1) + $loop->index }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold font-mono rounded bg-gray-100 text-gray-800">{{ $tarif->service->code ?? '-' }}</span>
                </td>
                <td class="px-4 py-3">
                    <div class="text-sm font-medium text-gray-900">{{ $tarif->service->name ?? '-' }}</div>
                    <div class="text-xs text-gray-500 max-w-xs truncate">{{ $tarif->service->description ?? '-' }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="text-sm text-gray-900">{{ $tarif->provider->code ?? '-' }}</div>
                    <div class="text-xs text-gray-500">{{ $tarif->provider->name ?? '-' }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold font-mono rounded bg-gray-100 text-gray-800">{{ $tarif->serviceClass->code ?? '-' }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $tarif->serviceClass->name ?? '-' }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $tarif->surgery_type === 'SURGERY' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">{{ $tarif->surgery_type ?? '-' }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">Rp {{ number_format($tarif->tariff, 2, ',', '.') }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $tarif->valid_date_from->format('d/m/Y') }} – {{ $tarif->end_date_to->format('d/m/Y') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ \App\Models\Tarif::badgeClass($tarif->status) }}">{{ $tarif->status }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-actions>
                        <x-actions-item href="{{ route('tarifs.show', $tarif) }}" icon="bi-eye" label="Detail" />
                        <x-actions-item href="{{ route('tarifs.edit', $tarif) }}" icon="bi-pencil" label="Edit" />
                        <x-actions-form
                            action="{{ route('tarifs.destroy', $tarif) }}"
                            method="DELETE"
                            icon="bi-trash"
                            label="Hapus"
                            confirm="Yakin ingin menghapus tarif ini?"
                        />
                    </x-actions>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection

@push('scripts')
<script>
// URL filter tetap pendek: field kosong / nilai default tidak ikut disubmit
// (input disabled tidak dikirim browser). Backend memperlakukan parameter
// yang hilang sama dengan "Semua"/default.
function cleanTarifFilter(form) {
    form.querySelectorAll('select, input').forEach(function (el) {
        if (!el.name) return;
        if (el.value === '') { el.disabled = true; return; }
        if ((el.name === 'sort' && el.value === 'created_at') ||
            (el.name === 'direction' && el.value === 'desc')) {
            el.disabled = true;
        }
    });
    return true;
}
</script>
@endpush
