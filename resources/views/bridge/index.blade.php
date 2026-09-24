@extends('layouts.app')

@section('title', 'Bridge Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4">
    <x-card padding="false">
        <x-slot name="title">Bridge Tarif</x-slot>
        <x-slot name="subtitle">Upload Excel lama, mapping otomatis ke master Tarif (SERVICECODE DESCRIPTION + KELAS), lalu generate Excel baru</x-slot>

        <form action="{{ route('bridge.scan') }}" method="POST" enctype="multipart/form-data" class="p-4 flex flex-col md:flex-row gap-4 items-end">
            @csrf
            <div class="flex-1">
                <label for="file" class="block text-sm font-semibold text-sp-navy mb-1">File Excel <span class="text-red-500">*</span></label>
                <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv,.html,.htm" required
                    class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary file:mr-3 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:border file:border-gray-300 file:rounded-md file:bg-gray-50 hover:file:bg-gray-100">
                <p class="mt-1 text-xs text-gray-500">Format .xlsx / .xls / .csv (termasuk .xls hasil export lama), maks 20 MB. Kolom yang dibaca: SERVICECODE, SERVICECODE DESCRIPTION, SERVICECODE KELAS, KELAS.</p>
                @error('file')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                    <i class="bi bi-search"></i> Scan Excel
                </button>
            </div>
        </form>
    </x-card>
</div>
@endsection
