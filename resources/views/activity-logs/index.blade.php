@extends('layouts.app')

@section('title', isset($subject) ? 'Log: '.class_basename($subject).' #'.$subject->id : 'Audit Log')

@section('content')
<div class="w-full mx-auto space-y-4" x-data="{
    ...tableFilter({
        search: '{{ request('search') }}',
        dateFrom: '{{ request('date_from') }}',
        dateTo: '{{ request('date_to') }}'
    })
}">
    <x-card padding="false">
        <x-slot name="title">
            @if(isset($subject))
                Log {{ class_basename($subject) }}: {{ $subject->name ?? $subject->title ?? '#'.$subject->id }}
            @else
                Audit Log - Update & Delete
            @endif
        </x-slot>
        <x-slot name="subtitle">Riwayat lengkap perubahan data: data lama, waktu, dan pelaku (by user)</x-slot>

        {{-- Filter --}}
        <x-table-filter search-placeholder="Cari deskripsi, log_name, event..." />

        @if(!isset($subject))
        <div class="px-4 pb-3 flex flex-wrap gap-2 items-center">
            <form method="GET" class="flex flex-wrap gap-2 w-full">
                <input type="hidden" name="search" value="{{ request('search') }}">
                <input type="hidden" name="date_from" value="{{ request('date_from') }}">
                <input type="hidden" name="date_to" value="{{ request('date_to') }}">
                <select name="log_name" onchange="this.form.submit()" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md">
                    <option value="">Semua Model</option>
                    @foreach($logNames ?? [] as $ln)
                        <option value="{{ $ln }}" {{ request('log_name')==$ln?'selected':'' }}>{{ $ln }}</option>
                    @endforeach
                </select>
                <select name="event" onchange="this.form.submit()" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md">
                    <option value="">Semua Event</option>
                    @foreach($events ?? [] as $ev)
                        <option value="{{ $ev }}" {{ request('event')==$ev?'selected':'' }}>{{ $ev }}</option>
                    @endforeach
                </select>
                <select name="causer_id" onchange="this.form.submit()" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md">
                    <option value="">Semua User (Pelaku)</option>
                    @foreach($users ?? [] as $u)
                        <option value="{{ $u->id }}" {{ request('causer_id')==$u->id?'selected':'' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
                @if(request()->hasAny(['search','log_name','event','causer_id','date_from','date_to']))
                    <a href="{{ request()->url() }}" class="px-3 py-1.5 text-sm bg-gray-100 rounded-md hover:bg-gray-200">Reset</a>
                @endif
            </form>
        </div>
        @endif

        <x-table :columns="['Waktu', 'Event', 'Model', 'Deskripsi', 'By User', 'IP', 'Aksi']" :pagination="$logs">
            @foreach($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap text-sm">
                    <div class="font-medium text-gray-900">{{ $log->created_at->format('d/m/Y H:i:s') }}</div>
                    <div class="text-xs text-gray-500">{{ $log->created_at->diffForHumans() }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($log->event==='updated')
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">UPDATE</span>
                    @elseif($log->event==='deleted')
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">DELETE</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">{{ strtoupper($log->event) }}</span>
                    @endif
                    <div class="text-xs text-gray-500 mt-1">{{ $log->log_name }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm">
                    <div class="font-medium">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                    @if($log->subject)
                        <div class="text-xs text-gray-500">{{ $log->subject->name ?? $log->subject->title ?? '-' }}</div>
                    @else
                        <div class="text-xs text-red-500">(data terhapus)</div>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-900 max-w-xs truncate">{{ $log->description }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm">
                    @if($log->causer)
                        <div class="font-medium">{{ $log->causer->name }}</div>
                        <div class="text-xs text-gray-500">{{ $log->causer->email ?? '' }}</div>
                    @else
                        <span class="text-gray-400">System</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                    {{ $log->ip_address ?? '-' }}
                    <div class="truncate max-w-[120px]" title="{{ $log->user_agent }}">{{ Str::limit($log->user_agent ?? '', 30) }}</div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <a href="{{ route('activity-logs.show', $log) }}" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-md bg-white border border-gray-300 hover:bg-gray-50">
                        <i class="bi bi-eye"></i> Detail
                    </a>
                </td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
