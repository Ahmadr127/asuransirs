@extends('layouts.app')

@section('title', 'Edit Tarif')

@section('content')
<div class="max-w-3xl mx-auto">
    <x-card>
        <x-slot name="title">Edit Tarif</x-slot>
        <x-slot name="subtitle">Perbarui data tarif di bawah ini</x-slot>
        <x-slot name="actions">
            <a href="{{ route('tarifs.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </x-slot>

        <form action="{{ route('tarifs.update', $tarif) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-searchable-dropdown
                    name="jenis_tarif_id"
                    label="Jenis Tarif"
                    :options="$jenisTarifs->map(fn($j) => (object)['id' => $j->id, 'label' => $j->code . ' — ' . $j->name])"
                    label-field="label"
                    :selected="$tarif->jenis_tarif_id"
                    placeholder="Pilih Jenis Tarif..."
                    :required="true"
                />

                <div>
                    <label for="surgery_type" class="block text-sm font-semibold text-sp-navy mb-1">Surgery Type <span class="text-red-500">*</span></label>
                    <select id="surgery_type" name="surgery_type" required class="w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary">
                        <option value="SURGERY" {{ old('surgery_type', $tarif->surgery_type) === 'SURGERY' ? 'selected' : '' }}>SURGERY</option>
                        <option value="NON SURGERY" {{ old('surgery_type', $tarif->surgery_type) === 'NON SURGERY' ? 'selected' : '' }}>NON SURGERY</option>
                    </select>
                    @error('surgery_type')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <x-searchable-dropdown
                    name="provider_id"
                    label="Provider"
                    :options="$providers->map(fn($p) => (object)['id' => $p->id, 'label' => $p->code . ' — ' . $p->name])"
                    label-field="label"
                    :selected="$tarif->provider_id"
                    placeholder="Pilih Provider..."
                    :required="true"
                />

                <x-searchable-dropdown
                    name="service_id"
                    label="Service"
                    :options="$services->map(fn($s) => (object)['id' => $s->id, 'label' => $s->code . ' — ' . $s->name])"
                    label-field="label"
                    :selected="$tarif->service_id"
                    placeholder="Pilih Service..."
                    :required="true"
                />

                <x-searchable-dropdown
                    name="class_id"
                    label="Kelas"
                    :options="$classes->map(fn($c) => (object)['id' => $c->id, 'label' => $c->code . ' — ' . $c->name])"
                    label-field="label"
                    :selected="$tarif->class_id"
                    placeholder="Pilih Kelas..."
                    :required="true"
                />

                <x-input name="helper" label="Helper" :value="$tarif->helper" placeholder="Contoh: Dr. Andi (opsional)" />

                <x-input name="tariff" label="Tarif (Rp)" type="number" step="0.01" min="0" :value="$tarif->tariff" :required="true" />

                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input name="valid_date_from" label="Berlaku Dari" type="date" :value="$tarif->valid_date_from->format('Y-m-d')" :required="true" />

                    <x-input name="end_date_to" label="Berlaku Sampai" type="date" :value="$tarif->end_date_to->format('Y-m-d')" :required="true" />
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <a href="{{ route('tarifs.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors">
                    <i class="bi bi-check-lg"></i> Perbarui Tarif
                </button>
            </div>
        </form>
    </x-card>
</div>
@endsection
