@extends('layouts.app')

@section('title', 'Edit Provider')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Edit Provider</x-slot>
        <x-slot name="subtitle">Perbarui data provider di bawah ini</x-slot>
        <x-slot name="actions">
            <a href="{{ route('providers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <form action="{{ route('providers.update', $provider) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input name="code" label="Kode" :value="$provider->code" :required="true" />

                <div>
                    <label for="status" class="block text-sm font-semibold text-sp-navy mb-1">Status <span class="text-red-500">*</span></label>
                    <select id="status" name="status" required class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary">
                        <option value="active" {{ old('status', $provider->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status', $provider->status) === 'inactive' ? 'selected' : '' }}>Non-Aktif</option>
                    </select>
                    @error('status')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <x-input name="name" label="Nama" :value="$provider->name" :required="true" />
                </div>

                <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                    <a href="{{ route('providers.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-check-lg"></i> Perbarui Provider
                    </button>
                </div>
            </div>
        </form>
    </x-card>
</div>
@endsection
