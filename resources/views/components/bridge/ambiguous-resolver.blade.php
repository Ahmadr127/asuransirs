@props(['token', 'mappingKey', 'group'])

<div class="border border-yellow-200 bg-yellow-50 rounded-md p-3">
    <p class="text-sm"><span class="font-semibold">Description:</span> {{ $group['description'] }} &bull; <span class="font-semibold">Kelas:</span> {{ $group['kelas'] }} &bull; <span class="text-gray-500">{{ count($group['rows']) }} row</span></p>
    <form action="{{ route('bridge.resolve') }}" method="POST" class="mt-2 flex flex-col md:flex-row gap-2 items-start md:items-end">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="mapping_key" value="{{ $mappingKey }}">
        <div class="flex-1">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Kandidat</label>
            <p class="mt-1 text-xs text-gray-500">Terurut relevansi (kelas + tarif Excel).</p>
            <select name="candidate" class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors" required>
                @foreach($group['candidates'] as $candidate)
                    <option value="{{ $candidate['service_code'] }}|{{ $candidate['class_code'] }}">{{ $candidate['service_code'] }} | {{ $candidate['service_name'] ?? $candidate['service_code'] }} | {{ $candidate['class_code'] }}@if(!empty($candidate['tariff'])) | Rp {{ number_format((float) $candidate['tariff'], 0, ',', '.') }}@endif</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-blue-600 hover:bg-blue-700 transition-colors">
            <i class="bi bi-check-lg mr-1.5 text-xs"></i> Terapkan
        </button>
    </form>
</div>
