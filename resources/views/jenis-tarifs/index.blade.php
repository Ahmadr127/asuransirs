@extends('layouts.app')

@section('title', 'Kelola Jenis Tarif')

@section('content')
<div class="w-full mx-auto">
    <x-card padding="false">
        <x-slot name="title">Kelola Jenis Tarif</x-slot>
        <x-slot name="subtitle">Master data jenis tarif (tarif, obat, alkes, bhp, makanan, ...)</x-slot>
        <x-slot name="actions">
            <a href="{{ route('jenis-tarifs.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                <i class="bi bi-plus-lg"></i> Tambah Jenis
            </a>
        </x-slot>

        <div class="px-4 py-3 border-b border-gray-100 bg-white">
            <form method="GET" class="flex flex-col lg:flex-row gap-3 items-end">
                <div class="flex-1">
                    <label for="search" class="block text-xs font-semibold text-gray-600 mb-1">Pencarian</label>
                    <div class="relative">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="search" name="search" value="{{ request('search') }}"
                               placeholder="Cari kode atau nama jenis..."
                               class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-full bg-gray-50 placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                    </div>
                </div>
                <div class="lg:w-40">
                    <label for="status" class="block text-xs font-semibold text-gray-600 mb-1">Status</label>
                    <select id="status" name="status" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                        <option value="">Semua</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Non-Aktif</option>
                    </select>
                </div>
                <div class="lg:w-40">
                    <label for="sort" class="block text-xs font-semibold text-gray-600 mb-1">Urutkan</label>
                    <select id="sort" name="sort" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                        <option value="name" {{ request('sort', 'name') === 'name' ? 'selected' : '' }}>Nama</option>
                        <option value="code" {{ request('sort') === 'code' ? 'selected' : '' }}>Kode</option>
                        <option value="created_at" {{ request('sort') === 'created_at' ? 'selected' : '' }}>Terbaru</option>
                    </select>
                </div>
                <div class="lg:w-32">
                    <label for="direction" class="block text-xs font-semibold text-gray-600 mb-1">Arah</label>
                    <select id="direction" name="direction" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                        <option value="asc" {{ request('direction', 'asc') === 'asc' ? 'selected' : '' }}>A–Z</option>
                        <option value="desc" {{ request('direction') === 'desc' ? 'selected' : '' }}>Z–A</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('jenis-tarifs.index') }}" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        <i class="bi bi-x-lg mr-1.5 text-xs"></i> Reset
                    </a>
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-funnel mr-1.5 text-xs"></i> Filter
                    </button>
                </div>
            </form>
        </div>

        <x-table :columns="['Kode', 'Nama', 'Status', 'Tanggal Dibuat', 'Aksi']" :pagination="$jenisTarifs" empty="Tidak ada data jenis tarif." class="border-0 rounded-none shadow-none">
            @foreach($jenisTarifs as $jenis)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold font-mono rounded bg-gray-100 text-gray-800">{{ $jenis->code }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $jenis->name }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($jenis->status === 'active')
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">Non-Aktif</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $jenis->created_at->format('d/m/Y H:i') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-actions>
                        <x-actions-item href="{{ route('jenis-tarifs.show', $jenis) }}" icon="bi-eye" label="Detail" />
                        <x-actions-item href="{{ route('jenis-tarifs.edit', $jenis) }}" icon="bi-pencil" label="Edit" />
                        <x-actions-form
                            action="{{ route('jenis-tarifs.destroy', $jenis) }}"
                            method="DELETE"
                            icon="bi-trash"
                            label="Hapus"
                            confirm="Yakin ingin menghapus jenis tarif ini?"
                        />
                    </x-actions>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
