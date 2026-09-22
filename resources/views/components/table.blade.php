@props([
    'columns' => [],
    'pagination' => null,
    'empty' => 'Tidak ada data.',
    'perPage' => null,
    'perPageOptions' => [5, 10, 25, 50, 100],
])

@php
    $perPage = $perPage ?? (int) request('per_page', 10);
@endphp

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-max">
            <thead>
                <tr class="bg-gray-100">
                    @foreach($columns as $column)
                        <th class="px-4 py-2.5 text-left font-semibold text-gray-700 whitespace-nowrap">
                            {{ is_array($column) ? ($column['label'] ?? '') : $column }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @if(!$slot->isEmpty())
                    {{ $slot }}
                @else
                    <tr>
                        <td colspan="{{ count($columns) ?: 1 }}" class="px-4 py-8 text-center text-gray-500">{{ $empty }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if($pagination)
        <div class="flex flex-col sm:flex-row items-center justify-between gap-2 px-4 py-3 border-t border-gray-100">
            <div class="flex items-center gap-2">
                <label for="per-page-{{ Str::slug($pagination->path()) }}" class="text-xs text-gray-500">Tampilkan</label>
                <select
                    id="per-page-{{ Str::slug($pagination->path()) }}"
                    onchange="const p = new URLSearchParams(window.location.search); p.set('per_page', this.value); p.delete('page'); window.location = window.location.pathname + '?' + p.toString();"
                    class="px-2 py-1 text-xs border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary"
                >
                    @foreach($perPageOptions as $option)
                        <option value="{{ $option }}" {{ $perPage == $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
                <label for="per-page-{{ Str::slug($pagination->path()) }}" class="text-xs text-gray-500">per halaman</label>
            </div>
            <p class="text-xs text-gray-500">
                Menampilkan {{ $pagination->firstItem() ?? 0 }}–{{ $pagination->lastItem() ?? 0 }} dari {{ $pagination->total() }} data
            </p>
            <div class="text-sm">{{ $pagination->links() }}</div>
        </div>
    @endif
</div>
