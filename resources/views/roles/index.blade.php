@extends('layouts.app')

@section('title', 'Manajemen Role')

@section('content')
@php
    $roleDataMap = collect($roles->items())->mapWithKeys(fn($role) => [
        $role->id => [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->pluck('id')->map(fn($id) => (string) $id)->values(),
        ],
    ])->all();
@endphp
<div class="space-y-5" x-data="roleEditModal({{ Js::from($roleDataMap) }})">

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <x-stats label="Total Role" :value="$totalRoles" icon="bi-shield-lock-fill" color="bg-blue-500" />
        <x-stats label="Role Aktif" :value="$activeRoles" icon="bi-shield-check" color="bg-sp-primary" />
        <x-stats label="Role Tidak Aktif" :value="$inactiveRoles" icon="bi-shield-x" color="bg-red-500" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

        {{-- ===================== TAMBAH ROLE ===================== --}}
        <div class="lg:col-span-4">
            <x-card title="Tambah Role" subtitle="Buat role baru">
                <form action="{{ route('roles.store') }}" method="POST">
                    @csrf

                    <x-input name="name" label="Nama Role" placeholder="Contoh: admin" :required="true" />

                    <x-input name="display_name" label="Display Name" placeholder="Contoh: Administrator" :required="true" />

                    <div class="mb-3">
                        <label for="description" class="block text-sm font-semibold text-sp-navy mb-1">Deskripsi</label>
                        <textarea name="description" id="description" rows="3" placeholder="Deskripsi role"
                            class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">{{ old('description') }}</textarea>
                        @error('description')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="is_active" class="block text-sm font-semibold text-sp-navy mb-1">Status</label>
                        <select name="is_active" id="is_active"
                            class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                            <option value="1" {{ old('is_active', 1) == 1 ? 'selected' : '' }}>Aktif</option>
                            <option value="0" {{ old('is_active', 1) == 0 ? 'selected' : '' }}>Tidak Aktif</option>
                        </select>
                    </div>

                    <div class="mb-4" x-data="{ showPerms: false }">
                        <button type="button" @click="showPerms = !showPerms"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-sp-primary hover:text-sp-primary-dark transition-colors">
                            <i class="bi" :class="showPerms ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                            Atur Permissions
                        </button>
                        <div x-show="showPerms" x-transition class="mt-2 grid grid-cols-2 gap-2 max-h-44 overflow-y-auto border border-gray-200 rounded-md p-2">
                            @foreach($permissions as $permission)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                    @checked(in_array($permission->id, old('permissions', [])))
                                    class="rounded border-gray-300 text-sp-primary focus:ring-sp-primary">
                                {{ $permission->display_name }}
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-floppy"></i> Simpan Role
                    </button>
                </form>
            </x-card>
        </div>

        {{-- ===================== DATA ROLE ===================== --}}
        <div class="lg:col-span-8">
            <x-card padding="false">
                <x-slot name="title">Data Role</x-slot>
                <x-slot name="subtitle">Daftar role dalam sistem</x-slot>
                <x-slot name="actions">
                    <form method="GET" class="relative">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari role..."
                            class="pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-full bg-gray-50 focus:bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary outline-none transition-colors w-44">
                    </form>
                </x-slot>

                <x-table :columns="['No', 'Nama Role', 'Deskripsi', 'Total User', 'Status', 'Aksi']" :pagination="$roles" class="border-0 rounded-none shadow-none">
                    @foreach($roles as $role)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $loop->iteration }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="text-sm font-semibold text-gray-900">{{ $role->display_name }}</div>
                            <div class="text-xs text-gray-500">{{ $role->name }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 max-w-[14rem]">
                            <span class="line-clamp-2">{{ $role->description ?? '-' }}</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $role->users_count > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                {{ $role->users_count }} user
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full {{ $role->is_active ? 'bg-sp-primary/10 text-sp-primary' : 'bg-gray-100 text-gray-600' }}">
                                <i class="bi {{ $role->is_active ? 'bi-check-circle' : 'bi-x-circle' }}"></i>
                                {{ $role->is_active ? 'Aktif' : 'Tidak Aktif' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-actions>
                                <x-actions-item icon="bi-pencil" label="Edit" @click="openEdit({{ $role->id }})" />
                                <x-actions-form
                                    action="{{ route('roles.destroy', $role) }}"
                                    method="DELETE"
                                    icon="bi-trash"
                                    label="Hapus"
                                    color="text-gray-700 hover:bg-red-50 hover:text-red-600"
                                    confirm="Yakin ingin menghapus role ini?"
                                />
                            </x-actions>
                        </td>
                    </tr>
                    @endforeach
                </x-table>
            </x-card>
        </div>
    </div>

    {{-- ===================== MODAL EDIT ROLE ===================== --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @keydown.escape.window="close()"
         class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-black/50" @click="close()"></div>

        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-sp-primary text-white rounded-t-lg">
                <h5 class="font-bold"><i class="bi bi-pencil-square mr-2"></i>Edit Role</h5>
                <button type="button" @click="close()" class="text-white/80 hover:text-white transition-colors"><i class="bi bi-x-lg"></i></button>
            </div>

            <form :action="action" method="POST" class="p-4">
                <input type="hidden" name="_method" value="PUT">
                @csrf

                <x-input x-model="name" id="edit-name" name="name" label="Nama Role" :required="true" />
                <x-input x-model="displayName" id="edit-display-name" name="display_name" label="Display Name" :required="true" />

                <div class="mb-3">
                    <label for="edit_description" class="block text-sm font-semibold text-sp-navy mb-1">Deskripsi</label>
                    <textarea name="description" id="edit_description" rows="3" x-model="description"
                        class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors"></textarea>
                </div>

                <div class="mb-3">
                    <label for="edit_is_active" class="block text-sm font-semibold text-sp-navy mb-1">Status</label>
                    <select name="is_active" id="edit_is_active" x-model="isActive"
                        class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                        <option :value="1">Aktif</option>
                        <option :value="0">Tidak Aktif</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="block text-sm font-semibold text-sp-navy mb-1">Permissions</label>
                    <div class="grid grid-cols-2 gap-2 max-h-44 overflow-y-auto border border-gray-200 rounded-md p-2">
                        @foreach($permissions as $permission)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                x-model="permissions" :value="'{{ $permission->id }}'"
                                class="rounded border-gray-300 text-sp-primary focus:ring-sp-primary">
                            {{ $permission->display_name }}
                        </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" @click="close()"
                        class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        Batal
                    </button>
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('roleEditModal', (initialRoles = {}) => ({
        roles: initialRoles,
        open: false,
        action: '',
        name: '',
        displayName: '',
        description: '',
        isActive: 1,
        permissions: [],

        openEdit(id) {
            const role = this.roles[id];
            if (!role) return;

            this.action = '{{ url('roles') }}/' + role.id;
            this.name = role.name;
            this.displayName = role.display_name;
            this.description = role.description || '';
            this.isActive = role.is_active ? 1 : 0;
            this.permissions = role.permissions.map(String);
            this.open = true;
        },

        close() {
            this.open = false;
        }
    }));
});
</script>
@endpush
