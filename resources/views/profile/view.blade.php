@extends('layouts.app')

@section('title', 'Profil User')

@section('content')
<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 bg-white border-b border-gray-200">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900">Profil Saya</h2>
        </div>

        <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-12">
                
                <!-- Nama Lengkap -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Nama Lengkap</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">{{ $user->name }}</span>
                </div>

                <!-- NIK -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">NIK</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">{{ $user->nik ?? '-' }}</span>
                </div>

                <!-- Username -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Username</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">{{ $user->username }}</span>
                </div>

                <!-- Email -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Email</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">{{ $user->email }}</span>
                </div>

                <!-- Role -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Role</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">
                        @if($user->role)
                            <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full 
                                @if($user->role->name === 'admin') bg-red-100 text-red-800
                                @elseif($user->role->name === 'librarian') bg-blue-100 text-blue-800
                                @else bg-green-100 text-green-800 @endif">
                                {{ $user->role->display_name }}
                            </span>
                        @else
                            <span class="text-gray-500 italic">Tidak ada role</span>
                        @endif
                    </span>
                </div>

                <!-- Organization Unit -->
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Unit Organisasi</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">
                        {{ $user->organizationUnit ? $user->organizationUnit->name : '-' }}
                    </span>
                </div>

                <!-- Tanggal Bergabung -->
                <div class="flex flex-col md:col-span-2">
                    <span class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Bergabung Sejak</span>
                    <span class="mt-1 text-lg font-medium text-gray-900">{{ $user->created_at->format('d F Y') }}</span>
                </div>

            </div>
        </div>
        
        <div class="mt-8">
            <p class="text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1"></i> Untuk melakukan perubahan data profil, silakan minta admin untuk melakukan update melalui menu <strong>Pengguna & Akses > Users</strong>.
            </p>
        </div>
    </div>
</div>
@endsection
