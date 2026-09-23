@extends('layouts.app')

@section('title', 'Kelola Kelas')

@section('content')
<div class="w-full mx-auto">
    <x-card padding="false">
        <x-slot name="title">Kelola Kelas</x-slot>
        <x-slot name="subtitle">Master data kelas untuk management tarif</x-slot>
        <x-slot name="actions">
            <a href="{{ route('classes.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                <i class="bi bi-plus-lg"></i> Tambah Kelas
            </a>
        </x-slot>

        <div class="px-4 py-3 border-b border-gray-100 bg-white">
            <form method="GET" class="flex flex-col lg:flex-row gap-3 items-end">
                <div class="flex-1">
                    <label for="search" class="block text-xs font-semibold text-gray-600 mb-1">Pencarian</label>
                    <div class="relative">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="search" name="search" value="{{ request('search') }}"
                               placeholder="Cari kode atau nama kelas..."
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
                    <a href="{{ route('classes.index') }}" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        <i class="bi bi-x-lg mr-1.5 text-xs"></i> Reset
                    </a>
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-funnel mr-1.5 text-xs"></i> Filter
                    </button>
                </div>
            </form>
        </div>

        <x-table :columns="['Kode', 'Nama', 'Status', 'Tanggal Dibuat', 'Aksi']" :pagination="$classes" empty="Tidak ada data kelas." class="border-0 rounded-none shadow-none">
            @foreach($classes as $class)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold font-mono rounded bg-gray-100 text-gray-800">{{ $class->code }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $class->name }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($class->status === 'active')
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">Non-Aktif</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $class->created_at->format('d/m/Y H:i') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-actions>
                        <x-actions-item href="{{ route('classes.show', $class) }}" icon="bi-eye" label="Detail" />
                        <x-actions-item href="{{ route('classes.edit', $class) }}" icon="bi-pencil" label="Edit" />
                        <x-actions-form
                            action="{{ route('classes.destroy', $class) }}"
                            method="DELETE"
                            icon="bi-trash"
                            label="Hapus"
                            confirm="Yakin ingin menghapus kelas ini?"
                        />
                    </x-actions>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
