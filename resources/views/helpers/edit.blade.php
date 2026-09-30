@extends('layouts.app')

@section('title', 'Edit Helper')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Edit Helper</x-slot>
        <x-slot name="subtitle">Perbarui aturan persentase billing bedah di bawah ini</x-slot>
        <x-slot name="actions">
            <a href="{{ route('helpers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <form action="{{ route('helpers.update', $helper) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input name="code" label="Kode" :value="$helper->code" :required="true" />

                <div>
                    <label for="status" class="block text-sm font-semibold text-sp-navy mb-1">Status <span class="text-red-500">*</span></label>
                    <select id="status" name="status" required class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary">
                        <option value="active" {{ old('status', $helper->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status', $helper->status) === 'inactive' ? 'selected' : '' }}>Non-Aktif</option>
                    </select>
                    @error('status')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <x-input name="name" label="Tipe" :value="$helper->name" :required="true" />
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-semibold text-sp-navy mb-1">Deskripsi</label>
                    <textarea id="description" name="description" rows="2"
                              class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white placeholder-gray-400 border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary">{{ old('description', $helper->description) }}</textarea>
                    @error('description')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <x-input name="operator_pct" label="Jasa Dokter Operator (%)" type="number" step="0.01" min="0" max="1000" :value="$helper->operator_pct" :required="true" />
                <x-input name="anesthesia_pct" label="Jasa Dokter Anastesi (%)" type="number" step="0.01" min="0" max="1000" :value="$helper->anesthesia_pct" :required="true" />
                <x-input name="room_pct" label="Sewa Ruang Operasi (%)" type="number" step="0.01" min="0" max="1000" :value="$helper->room_pct" :required="true" />
                <x-input name="child_pct" label="Jasa Dokter Anak (%)" type="number" step="0.01" min="0" max="1000" :value="$helper->child_pct" :required="true" />

                <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                    <a href="{{ route('helpers.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                        <i class="bi bi-check-lg"></i> Perbarui Helper
                    </button>
                </div>
            </div>
        </form>
    </x-card>
</div>
@endsection
