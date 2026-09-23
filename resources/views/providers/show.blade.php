@extends('layouts.app')

@section('title', 'Detail Provider')

@section('content')
<div class="space-y-6">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 bg-white border-b border-gray-200">
            <div class="flex justify-between items-start">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <span class="inline-flex px-2 py-1 text-xs font-mono bg-gray-200 text-gray-700 rounded">{{ $provider->code }}</span>
                        @if($provider->status === 'active')
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                        @else
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Non-Aktif</span>
                        @endif
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900">{{ $provider->name }}</h2>
                    <p class="text-sm text-gray-500 mt-1">Dibuat {{ $provider->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('providers.edit', $provider) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                        Edit
                    </a>
                    <a href="{{ route('providers.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>

    <x-card padding="false">
        <x-slot name="title">Tarif Provider Ini ({{ $provider->tarifs->count() }})</x-slot>
        <x-table :columns="['Jenis', 'Service', 'Kelas', 'Tarif', 'Periode', 'Status']" empty="Belum ada tarif untuk provider ini." class="border-0 rounded-none shadow-none">
            @foreach($provider->tarifs as $tarif)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{ $tarif->jenisTarif->name ?? '-' }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $tarif->service->code ?? '-' }} — {{ $tarif->service->name ?? '-' }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $tarif->serviceClass->code ?? '-' }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">Rp {{ number_format($tarif->tariff, 2, ',', '.') }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $tarif->valid_date_from->format('d/m/Y') }} – {{ $tarif->end_date_to->format('d/m/Y') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ \App\Models\Tarif::badgeClass($tarif->status) }}">{{ $tarif->status }}</span>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
