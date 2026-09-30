@extends('layouts.app')

@section('title', 'Detail Helper')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Detail Helper</x-slot>
        <x-slot name="subtitle">{{ $helper->name }}</x-slot>
        <x-slot name="actions">
            <a href="{{ route('helpers.edit', $helper) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <a href="{{ route('helpers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Kode</dt>
                <dd class="mt-1 font-mono font-semibold text-gray-900">{{ $helper->code }}</dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Status</dt>
                <dd class="mt-1">
                    @if($helper->status === 'active')
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">Non-Aktif</span>
                    @endif
                </dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3 md:col-span-2">
                <dt class="font-semibold text-gray-500">Tipe</dt>
                <dd class="mt-1 font-medium text-gray-900">{{ $helper->name }}</dd>
            </div>
            @if($helper->description)
            <div class="border border-gray-200 rounded-md p-3 md:col-span-2">
                <dt class="font-semibold text-gray-500">Deskripsi</dt>
                <dd class="mt-1 text-gray-900">{{ $helper->description }}</dd>
            </div>
            @endif
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Jasa Dokter Operator</dt>
                <dd class="mt-1 font-mono font-semibold text-gray-900">{{ rtrim(rtrim(number_format((float) $helper->operator_pct, 2, ',', '.'), '0'), ',') }}%</dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Jasa Dokter Anastesi</dt>
                <dd class="mt-1 font-mono font-semibold text-gray-900">{{ rtrim(rtrim(number_format((float) $helper->anesthesia_pct, 2, ',', '.'), '0'), ',') }}%</dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Sewa Ruang Operasi</dt>
                <dd class="mt-1 font-mono font-semibold text-gray-900">{{ rtrim(rtrim(number_format((float) $helper->room_pct, 2, ',', '.'), '0'), ',') }}%</dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3">
                <dt class="font-semibold text-gray-500">Jasa Dokter Anak</dt>
                <dd class="mt-1 font-mono font-semibold text-gray-900">{{ rtrim(rtrim(number_format((float) $helper->child_pct, 2, ',', '.'), '0'), ',') }}%</dd>
            </div>
            <div class="border border-gray-200 rounded-md p-3 md:col-span-2">
                <dt class="font-semibold text-gray-500">Dibuat</dt>
                <dd class="mt-1 text-gray-900">{{ $helper->created_at->format('d/m/Y H:i') }}</dd>
            </div>
        </dl>
    </x-card>
</div>
@endsection
