@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-5">
    <!-- Welcome + Quick Actions -->
    <x-card>
        <h2 class="text-2xl font-bold text-gray-900">Selamat Datang, {{ $user->name }}!</h2>
        <p class="text-gray-500 mb-4">Sistem Manajemen Terintegrasi</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @if($user->hasPermission('manage_users'))
            <a href="{{ route('users.index') }}" class="flex items-center p-3 bg-sp-primary/5 hover:bg-sp-primary/10 rounded-lg transition-colors group">
                <div class="w-9 h-9 bg-sp-primary rounded-lg flex items-center justify-center mr-3 text-white flex-shrink-0 shadow-sm"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="font-semibold text-sp-navy">Kelola Pengguna</div>
                    <div class="text-xs text-sp-primary">Manajemen data pengguna</div>
                </div>
            </a>
            @endif

            @if($user->hasPermission('manage_roles'))
            <a href="{{ route('roles.index') }}" class="flex items-center p-3 bg-blue-500/5 hover:bg-blue-500/10 rounded-lg transition-colors group">
                <div class="w-9 h-9 bg-blue-500 rounded-lg flex items-center justify-center mr-3 text-white flex-shrink-0 shadow-sm"><i class="bi bi-person-fill-check"></i></div>
                <div>
                    <div class="font-semibold text-sp-navy">Kelola Role</div>
                    <div class="text-xs text-blue-500">Manajemen role pengguna</div>
                </div>
            </a>
            @endif

            @if($user->hasPermission('manage_permissions'))
            <a href="{{ route('permissions.index') }}" class="flex items-center p-3 bg-purple-500/5 hover:bg-purple-500/10 rounded-lg transition-colors group">
                <div class="w-9 h-9 bg-purple-500 rounded-lg flex items-center justify-center mr-3 text-white flex-shrink-0 shadow-sm"><i class="bi bi-key-fill"></i></div>
                <div>
                    <div class="font-semibold text-sp-navy">Kelola Permission</div>
                    <div class="text-xs text-purple-500">Manajemen izin akses</div>
                </div>
            </a>
            @endif
        </div>
    </x-card>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach($stats as $stat)
        <x-stats :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" :color="$stat['color']" />
        @endforeach
    </div>

    <!-- Chart -->
    <x-card title="Tren Pengguna" subtitle="Pengguna baru dalam 6 bulan terakhir">
        <x-chart
            type="line"
            :labels="$chartLabels"
            :datasets="[[
                'label' => 'Pengguna Baru',
                'data' => $chartData,
                'borderColor' => '#007774',
                'backgroundColor' => 'rgba(0, 119, 116, 0.15)',
                'fill' => true,
                'tension' => 0.3,
                'pointRadius' => 4,
                'pointBackgroundColor' => '#007774',
            ]]"
            :height="260"
        />
    </x-card>

    <!-- Searchable Table (pencarian per kolom di baris pertama) -->
    <x-card title="Data Pengguna" subtitle="Ketik di kolom pencarian untuk memfilter data">
        <x-searchable-table
            :columns="[
                ['key' => 'name', 'label' => 'Nama'],
                ['key' => 'nik', 'label' => 'NIK'],
                ['key' => 'username', 'label' => 'Username'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'created_at', 'label' => 'Dibuat'],
            ]"
            :rows="$tableRows"
            :per-page="8"
        />
    </x-card>
</div>
@endsection
