@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-5">
    <!-- Welcome + Quick Actions -->
    <x-card>
        <h2 class="text-2xl font-bold text-gray-900">Selamat Datang, {{ $user->name }}!</h2>
        <p class="text-gray-500 mb-4">Sistem Manajemen Terintegrasi</p>
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
