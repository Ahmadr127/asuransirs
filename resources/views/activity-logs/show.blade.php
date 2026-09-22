@extends('layouts.app')

@section('title', 'Detail Log #'.$activityLog->id)

@section('content')
<div class="w-full mx-auto space-y-4 max-w-5xl">
    <div class="flex items-center gap-2">
        <a href="{{ route('activity-logs.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
        <span class="text-sm text-gray-500">Detail Audit Log</span>
    </div>

    <x-card>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div><span class="font-semibold">Waktu:</span> {{ $activityLog->created_at->format('d/m/Y H:i:s') }} ({{ $activityLog->created_at->diffForHumans() }})</div>
            <div><span class="font-semibold">Event:</span> <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $activityLog->event==='updated' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800' }}">{{ strtoupper($activityLog->event) }}</span></div>
            <div><span class="font-semibold">Model:</span> {{ class_basename($activityLog->subject_type) }} #{{ $activityLog->subject_id }} ({{ $activityLog->log_name }})</div>
            <div><span class="font-semibold">Deskripsi:</span> {{ $activityLog->description }}</div>
            <div><span class="font-semibold">By User:</span> {{ $activityLog->causer?->name ?? 'System' }} @if($activityLog->causer) <span class="text-gray-500">({{ $activityLog->causer->email }}) ID: {{ $activityLog->causer_id }}</span> @endif</div>
            <div><span class="font-semibold">IP / User Agent:</span> {{ $activityLog->ip_address ?? '-' }} <div class="text-xs text-gray-500 truncate">{{ $activityLog->user_agent }}</div></div>
        </div>
    </x-card>

    @php
        $props = $activityLog->properties ?? [];
        $old = $props['old'] ?? [];
        $new = $props['new'] ?? $props['attributes'] ?? [];
        $diff = $props['diff'] ?? [];
    @endphp

    @if($activityLog->event === 'updated')
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <x-card padding="false">
                <x-slot name="title">Data Sebelumnya (OLD)</x-slot>
                <div class="p-4">
                    <pre class="text-xs bg-gray-50 p-3 rounded border overflow-auto max-h-[400px]">{{ json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                <div class="px-4 pb-3">
                    <table class="w-full text-xs border">
                        <thead class="bg-gray-100"><tr><th class="px-2 py-1 text-left">Field</th><th class="px-2 py-1 text-left">Value</th></tr></thead>
                        <tbody>
                            @foreach($old as $k=>$v)
                            <tr class="border-t"><td class="px-2 py-1 font-mono font-semibold">{{ $k }}</td><td class="px-2 py-1 break-all">{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? '-') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            <x-card padding="false">
                <x-slot name="title">Data Sesudah (NEW)</x-slot>
                <div class="p-4">
                    <pre class="text-xs bg-blue-50 p-3 rounded border overflow-auto max-h-[400px]">{{ json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                <div class="px-4 pb-3">
                    <table class="w-full text-xs border">
                        <thead class="bg-gray-100"><tr><th class="px-2 py-1 text-left">Field</th><th class="px-2 py-1 text-left">Value</th></tr></thead>
                        <tbody>
                            @foreach($new as $k=>$v)
                            <tr class="border-t"><td class="px-2 py-1 font-mono font-semibold">{{ $k }}</td><td class="px-2 py-1 break-all">{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? '-') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <x-card padding="false">
            <x-slot name="title">Perubahan (DIFF) - Field yang berubah</x-slot>
            <x-slot name="subtitle">Hanya field yang berbeda antara OLD vs NEW</x-slot>
            @if(empty($diff))
                <div class="p-4 text-sm text-gray-500 text-center">Tidak ada perbedaan terdeteksi (mungkin hanya updated_at)</div>
            @else
                <div class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100"><tr><th class="px-4 py-2 text-left">Field</th><th class="px-4 py-2 text-left">Sebelum</th><th class="px-4 py-2 text-left">Sesudah</th></tr></thead>
                        <tbody class="divide-y">
                            @foreach($diff as $field=>$change)
                            <tr>
                                <td class="px-4 py-2 font-mono font-semibold">{{ $field }}</td>
                                <td class="px-4 py-2 bg-red-50">{{ is_array($change['old'] ?? null) ? json_encode($change['old'], JSON_UNESCAPED_UNICODE) : ($change['old'] ?? '-') }}</td>
                                <td class="px-4 py-2 bg-green-50">{{ is_array($change['new'] ?? null) ? json_encode($change['new'], JSON_UNESCAPED_UNICODE) : ($change['new'] ?? '-') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @elseif($activityLog->event === 'deleted')
        <x-card padding="false">
            <x-slot name="title">Snapshot Data Sebelum Dihapus (OLD)</x-slot>
            <x-slot name="subtitle">Data lengkap saat sebelum dihapus oleh {{ $activityLog->causer?->name ?? 'System' }} pada {{ $activityLog->created_at->format('d/m/Y H:i:s') }}</x-slot>
            <div class="p-4">
                <pre class="text-xs bg-red-50 p-3 rounded border overflow-auto max-h-[500px]">{{ json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            <div class="px-4 pb-4">
                <table class="w-full text-xs border">
                    <thead class="bg-gray-100"><tr><th class="px-2 py-1 text-left">Field</th><th class="px-2 py-1 text-left">Value</th></tr></thead>
                    <tbody>
                        @foreach($old as $k=>$v)
                        <tr class="border-t"><td class="px-2 py-1 font-mono font-semibold">{{ $k }}</td><td class="px-2 py-1 break-all">{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? '-') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-card>
        <x-slot name="title">Raw Properties (JSON)</x-slot>
        <pre class="text-xs bg-gray-900 text-green-400 p-4 rounded overflow-auto max-h-[400px]">{{ json_encode($activityLog->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </x-card>
</div>
@endsection
