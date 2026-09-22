@extends('layouts.app')

@section('title', 'Tambah User')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Tambah User Baru</x-slot>
        <x-slot name="subtitle">Lengkapi data pengguna di bawah ini</x-slot>
        <x-slot name="actions">
            <a href="{{ route('users.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <form action="{{ route('users.store') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input name="name" label="Nama Lengkap" :required="true" />

                <x-input name="nik" label="NIK" />

                <x-input name="username" label="Username" :required="true" />

                <x-input name="email" label="Email" type="email" :required="true" />

                <x-input name="password" label="Password" type="password" :required="true" />

                <x-input name="password_confirmation" label="Konfirmasi Password" type="password" :required="true" />

                <div class="md:col-span-2">
                    <x-searchable-dropdown
                        name="role_id"
                        label="Role"
                        :options="$roles"
                        label-field="display_name"
                        :selected="old('role_id')"
                        placeholder="Pilih Role..."
                        :required="true"
                    />
                </div>

                <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                    <a href="{{ route('users.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-check-lg"></i> Simpan User
                    </button>
                </div>
            </div>
        </form>
    </x-card>
</div>
@endsection
