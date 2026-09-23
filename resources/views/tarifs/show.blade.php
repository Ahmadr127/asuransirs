@extends('layouts.app')

@section('title', 'Detail Tarif')

@section('content')
<div class="space-y-6">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 bg-white border-b border-gray-200">
            <div class="flex justify-between items-start">
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">{{ $tarif->jenisTarif->name ?? '-' }}</span>
                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full {{ $tarif->surgery_type === 'SURGERY' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">{{ $tarif->surgery_type ?? '-' }}</span>
                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full {{ \App\Models\Tarif::badgeClass($tarif->status) }}">{{ $tarif->status }}</span>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900">{{ $tarif->service->name ?? '-' }}</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ $tarif->service->code ?? '-' }} • {{ $tarif->service->description ?? '-' }}</p>
                    <p class="text-xl font-bold text-sp-primary mt-2">Rp {{ number_format($tarif->tariff, 2, ',', '.') }}</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('tarifs.edit', $tarif) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                        Edit
                    </a>
                    <a href="{{ route('tarifs.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-card>
            <x-slot name="title">Master Terkait</x-slot>
            <div class="divide-y divide-gray-100 text-sm">
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Provider</span>
                    <span class="font-medium text-gray-900 text-right">{{ $tarif->provider->code ?? '-' }} — {{ $tarif->provider->name ?? '-' }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Service</span>
                    <span class="font-medium text-gray-900 text-right">{{ $tarif->service->code ?? '-' }} — {{ $tarif->service->name ?? '-' }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Kelas</span>
                    <span class="font-medium text-gray-900 text-right">{{ $tarif->serviceClass->code ?? '-' }} — {{ $tarif->serviceClass->name ?? '-' }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Helper</span>
                    <span class="font-medium text-gray-900 text-right">{{ $tarif->helper ?? '-' }}</span>
                </div>
            </div>
        </x-card>

        <x-card>
            <x-slot name="title">Periode & Status</x-slot>
            <div class="divide-y divide-gray-100 text-sm">
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Berlaku Dari</span>
                    <span class="font-medium text-gray-900">{{ $tarif->valid_date_from->format('d/m/Y') }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Berlaku Sampai</span>
                    <span class="font-medium text-gray-900">{{ $tarif->end_date_to->format('d/m/Y') }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Status (otomatis)</span>
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ \App\Models\Tarif::badgeClass($tarif->status) }}">{{ $tarif->status }}</span>
                </div>
                <div class="flex justify-between gap-4 py-2.5">
                    <span class="text-gray-500">Dibuat</span>
                    <span class="font-medium text-gray-900">{{ $tarif->created_at->format('d/m/Y H:i') }}</span>
                </div>
            </div>
        </x-card>
    </div>
</div>
@endsection
