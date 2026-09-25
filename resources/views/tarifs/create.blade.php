@extends('layouts.app')

@section('title', 'Tambah Tarif')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Tambah Tarif Baru</x-slot>
        <x-slot name="subtitle">Lengkapi data tarif di bawah ini</x-slot>
        <x-slot name="actions">
            <a href="{{ route('tarifs.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <form action="{{ route('tarifs.store') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-searchable-dropdown
                    name="jenis_tarif_id"
                    label="Jenis Tarif"
                    :options="$jenisTarifs->map(fn($j) => (object)['id' => $j['id'], 'label' => $j['code'] . ' — ' . $j['name']])"
                    label-field="label"
                    :selected="old('jenis_tarif_id')"
                    placeholder="Pilih Jenis Tarif..."
                    :required="true"
                />

                <div>
                    <label for="surgery_type" class="block text-sm font-semibold text-sp-navy mb-1">Surgery Type <span class="text-gray-400 font-normal">(opsional)</span></label>
                    <select id="surgery_type" name="surgery_type" class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary">
                        <option value="">Pilih Tipe... (kosong = tanpa bedah)</option>
                        <option value="SURGERY" {{ old('surgery_type') === 'SURGERY' ? 'selected' : '' }}>SURGERY</option>
                        <option value="NON SURGERY" {{ old('surgery_type') === 'NON SURGERY' ? 'selected' : '' }}>NON SURGERY</option>
                    </select>
                    @error('surgery_type')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <x-searchable-dropdown
                    name="provider_id"
                    label="Provider"
                    :options="$providers->map(fn($p) => (object)['id' => $p['id'], 'label' => $p['code'] . ' — ' . $p['name']])"
                    label-field="label"
                    :selected="old('provider_id')"
                    placeholder="Pilih Provider..."
                    :required="true"
                />

                <x-searchable-dropdown
                    name="service_id"
                    label="Service"
                    :options="$services->map(fn($s) => (object)['id' => $s['id'], 'label' => $s['code'] . ' — ' . $s['name']])"
                    label-field="label"
                    :selected="old('service_id')"
                    placeholder="Pilih Service..."
                    :required="true"
                />

                <x-searchable-dropdown
                    name="class_id"
                    label="Kelas"
                    :options="$classes->map(fn($c) => (object)['id' => $c['id'], 'label' => $c['code'] . ' — ' . $c['name']])"
                    label-field="label"
                    :selected="old('class_id')"
                    placeholder="Pilih Kelas..."
                    :required="true"
                />

                <x-input name="helper" label="Helper" placeholder="Contoh: Dr. Andi (opsional)" />

                <x-input name="tariff" label="Tarif (Rp)" type="number" step="0.01" min="0" placeholder="Contoh: 150000" :required="true" />

                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input name="valid_date_from" label="Berlaku Dari" type="date" :required="true" />

                    <x-input name="end_date_to" label="Berlaku Sampai" type="date" :required="true" />
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <a href="{{ route('tarifs.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                    <i class="bi bi-check-lg"></i> Simpan Tarif
                </button>
            </div>
        </form>
    </x-card>
</div>
@endsection
