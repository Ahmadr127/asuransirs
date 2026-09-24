@extends('layouts.app')

@section('title', 'Hasil Mapping Bridge Tarif')

@section('content')
<div class="w-full mx-auto flex flex-col gap-4">
    <div>
        <a href="{{ route('bridge.index') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-sp-primary hover:text-sp-primary-dark transition-colors">
            <i class="bi bi-arrow-left"></i> Kembali / Upload file lain
        </a>
    </div>

    <x-card padding="false">
        <x-slot name="title">Hasil Mapping</x-slot>
        <x-slot name="subtitle">{{ $result['filename'] ?? '' }}</x-slot>
        <x-slot name="actions">
            @if(($result['summary']['matched'] ?? 0) > 0)
                <form action="{{ route('bridge.generate') }}" method="POST" onsubmit="return confirm('Generate Excel baru? SERVICECODE berubah untuk row MATCHED; SERVICECODE KELAS mengikuti master bila ditemukan.');">
                    @csrf
                    <input type="hidden" name="token" value="{{ $result['token'] }}">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold text-white rounded-md bg-green-600 hover:bg-green-700 transition-colors">
                        <i class="bi bi-file-earmark-arrow-down"></i> Generate Excel
                    </button>
                </form>
            @endif
        </x-slot>

        <x-bridge.summary-stats :summary="$result['summary']" />

        @if(!empty($result['groups']))
            <x-bridge.resolve-modal :token="$result['token']" :groups="$result['groups']" />
        @endif

        <x-bridge.preview-table :rows="$result['preview']" />
    </x-card>
</div>
@endsection
