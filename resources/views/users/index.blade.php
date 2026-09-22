@extends('layouts.app')

@section('title', 'Kelola Users')

@section('content')
<div class="w-full mx-auto" x-data="{
    ...tableFilter({
        search: '{{ request('search') }}',
        dateFrom: '{{ request('date_from') }}',
        dateTo: '{{ request('date_to') }}'
    })
}">
    <x-card padding="false">
        <x-slot name="title">Kelola Users</x-slot>
        <x-slot name="subtitle">Manajemen data pengguna sistem</x-slot>
        <x-slot name="actions">
            <a href="{{ route('users.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                <i class="bi bi-person-plus"></i> Tambah User
            </a>
        </x-slot>

        <x-table-filter search-placeholder="Cari nama, username, atau email..." />

        <x-table :columns="['Nama', 'NIK', 'Username', 'Email', 'Role', 'Tanggal Dibuat', 'Aksi']" :pagination="$users" class="border-0 rounded-none shadow-none">
            @foreach($users as $user)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center flex-shrink-0">
                            <span class="text-xs font-medium text-gray-600">{{ strtoupper(substr($user->name, 0, 2)) }}</span>
                        </div>
                        <div class="text-sm font-medium text-gray-900">{{ $user->name }}</div>
                    </div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $user->nik ?? '-' }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $user->username }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $user->email }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($user->role)
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full
                        @if($user->role->name === 'admin') bg-red-100 text-red-800
                        @elseif($user->role->name === 'librarian') bg-blue-100 text-blue-800
                        @else bg-green-100 text-green-800 @endif">
                        {{ $user->role->display_name }}
                    </span>
                    @else
                    <span class="text-sm text-gray-500">Tidak ada role</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $user->created_at->format('d/m/Y H:i') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-actions>
                        <x-actions-item href="{{ route('users.edit', $user) }}" icon="bi-pencil" label="Edit" />
                        @if($user->id !== auth()->id())
                        <x-actions-form
                            action="{{ route('users.destroy', $user) }}"
                            method="DELETE"
                            icon="bi-trash"
                            label="Hapus"
                            color="text-gray-700 hover:bg-red-50 hover:text-red-600"
                            confirm="Yakin ingin menghapus user ini?"
                        />
                        @else
                        <div class="px-3 py-2 text-sm text-gray-400">(Akun Anda)</div>
                        @endif
                    </x-actions>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
