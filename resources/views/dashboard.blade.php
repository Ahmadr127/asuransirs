@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4">
    @if(auth()->user()->hasPermission('manage_tarifs'))
        <x-card padding="false">
            <x-slot name="title">Jalan Pintas</x-slot>
            <div class="p-4 flex flex-wrap gap-2">
                <a href="{{ route('tarifs.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                    <i class="bi bi-book"></i> Buku Tarif
                </a>
                <a href="{{ route('bridge.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-sp-navy rounded-md border border-gray-300 bg-white hover:bg-gray-50 transition-colors">
                    <i class="bi bi-arrow-left-right"></i> Bridge Tarif
                </a>
            </div>
        </x-card>

        <x-bridge.upload-form />
    @else
        <x-card padding="false">
            <x-slot name="title">Selamat Datang, {{ auth()->user()->name }}!</x-slot>
            <div class="p-4 text-sm text-gray-500">Gunakan menu di samping untuk navigasi.</div>
        </x-card>
    @endif
</div>
@endsection
