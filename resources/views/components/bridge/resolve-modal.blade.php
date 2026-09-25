@props(['token', 'groups' => []])
@php
    $ambiguousGroups = array_filter($groups, fn ($g) => ! ($g['manual'] ?? false));
    $notFoundGroups = array_filter($groups, fn ($g) => ($g['manual'] ?? false));
    $totalGroups = count($ambiguousGroups) + count($notFoundGroups);
    $rowIndex = 0;
@endphp

@if($totalGroups > 0)
<div data-rm-scope>
<div class="px-4 pb-4">
    <button type="button" data-rm-open
        class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-blue-600 hover:bg-blue-700 transition-colors">
        <i class="bi bi-pencil-square"></i> Petakan Manual ({{ $totalGroups }} grup)
    </button>
    <p class="mt-1 text-xs text-gray-500">Satu grup berlaku untuk seluruh row dengan description + kelas sama.</p>
</div>

<div data-rm-modal class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-100 bg-opacity-80" data-rm-close></div>
    <div class="relative w-full max-w-3xl max-h-[85vh] flex flex-col bg-white rounded-lg shadow-xl">
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h3 class="text-base font-semibold text-sp-navy">Petakan Manual</h3>
            <button type="button" data-rm-close class="text-gray-400 hover:text-gray-600 text-xl leading-none px-1">&times;</button>
        </div>
        <form action="{{ route('bridge.resolve-batch') }}" method="POST" class="flex flex-col min-h-0">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="flex-1 overflow-y-auto px-4 py-3 flex flex-col gap-4 min-h-0">
                @if(!empty($ambiguousGroups))
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-2">Ambiguous — pilih satu kandidat per grup:</p>
                        <div class="flex flex-col gap-3">
                            @foreach($ambiguousGroups as $key => $group)
                                <div class="border border-yellow-200 bg-yellow-50 rounded-md p-3" data-rm-root>
                                    <p class="text-sm"><span class="font-semibold">Description:</span> {{ $group['description'] }} &bull; <span class="font-semibold">Kelas:</span> {{ $group['kelas'] }} &bull; <span class="text-gray-500">{{ count($group['rows']) }} row</span></p>
                                    <input type="hidden" name="rows[{{ $rowIndex }}][key]" value="{{ $key }}">
                                    <select name="rows[{{ $rowIndex }}][candidate]"
                                        class="mt-2 w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                                        <option value="">— belum dipilih —</option>
                                        @foreach($group['candidates'] as $candidate)
                                            <option value="{{ $candidate['service_code'] }}|{{ $candidate['class_code'] }}"
                                                @selected(!empty($group['resolved']) && $group['resolved']['service_code'] === $candidate['service_code'] && $group['resolved']['class_code'] === $candidate['class_code'])>{{ $candidate['service_code'] }} | {{ $candidate['service_name'] ?? $candidate['service_code'] }} | {{ $candidate['class_code'] }}@if(!empty($candidate['tariff'])) | Rp {{ number_format((float) $candidate['tariff'], 0, ',', '.') }}@endif</option>
                                        @endforeach
                                    </select>
                                </div>
                                @php $rowIndex++; @endphp
                            @endforeach
                        </div>
                    </div>
                @endif
                @if(!empty($notFoundGroups))
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-2">Not Found — cari service master per grup <span class="font-normal text-gray-500">(kelas otomatis dari master)</span>:</p>
                        <div class="flex flex-col gap-3">
                            @foreach($notFoundGroups as $key => $group)
                                @php
                                    $resolvedCode = $group['resolved']['service_code'] ?? '';
                                @endphp
                                <div class="border border-red-200 bg-red-50 rounded-md p-3" data-rm-root data-rm-desc="{{ $group['description'] }}">
                                    <p class="text-sm"><span class="font-semibold">Description:</span> {{ $group['description'] !== '' ? $group['description'] : '-' }} &bull; <span class="font-semibold">Kelas:</span> {{ $group['kelas'] !== '' ? $group['kelas'] : '-' }} &bull; <span class="text-gray-500">{{ count($group['rows']) }} row</span></p>
                                    <input type="hidden" name="rows[{{ $rowIndex }}][key]" value="{{ $key }}">
                                    <input type="hidden" name="rows[{{ $rowIndex }}][candidate]" value="{{ $resolvedCode !== '' ? $resolvedCode.'|' : '' }}" data-rm-candidate>
                                    <div class="mt-2 relative">
                                        <input type="text" autocomplete="off" spellcheck="false" placeholder="Klik untuk lihat yang paling mirip, atau ketik untuk menyaring…"
                                            value="{{ $resolvedCode !== '' ? $resolvedCode.' — dipetakan' : '' }}"
                                            data-rm-input
                                            class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
                                        <div data-rm-list class="hidden absolute z-10 mt-1 w-full max-h-56 overflow-auto bg-white border border-gray-300 rounded-md shadow-lg"></div>
                                    </div>
                                </div>
                                @php $rowIndex++; @endphp
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <div class="flex items-center justify-end gap-2 px-4 py-3 border-t bg-gray-50 rounded-b-lg">
                <button type="button" data-rm-close class="px-4 py-2 text-sm font-semibold text-gray-700 rounded-md border border-gray-300 hover:bg-gray-100 transition-colors">Batal</button>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white rounded-md bg-green-600 hover:bg-green-700 transition-colors">
                    <i class="bi bi-check-lg"></i> Terapkan Semua
                </button>
            </div>
        </form>
    </div>
</div>
</div>

@once
@push('scripts')
<script>
(function () {
    const searchUrl = @json(route('bridge.search-services'));
    const timers = new WeakMap();

    document.addEventListener('click', e => {
        const openBtn = e.target.closest('[data-rm-open]');
        if (openBtn) {
            const scope = openBtn.closest('[data-rm-scope]');
            const modal = scope ? scope.querySelector('[data-rm-modal]') : document.querySelector('[data-rm-modal]');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }
            return;
        }
        if (e.target.closest('[data-rm-close]')) {
            const modal = e.target.closest('[data-rm-modal]');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                modal.querySelectorAll('[data-rm-list]').forEach(l => l.classList.add('hidden'));
            }
            return;
        }
        const item = e.target.closest('[data-rm-item]');
        if (item) {
            const root = item.closest('[data-rm-root]');
            root.querySelector('[data-rm-input]').value = item.dataset.code + ' — ' + (item.dataset.name || item.dataset.code);
            root.querySelector('[data-rm-candidate]').value = item.dataset.code + '|';
            root.querySelector('[data-rm-list]').classList.add('hidden');
            return;
        }
        if (!e.target.closest('[data-rm-root]')) {
            document.querySelectorAll('[data-rm-list]').forEach(l => l.classList.add('hidden'));
        }
    });

    async function fetchSimilar(root, q) {
        const desc = root.dataset.rmDesc || '';
        const params = new URLSearchParams({ description: desc });
        if (q && q.length >= 2) params.set('q', q);
        const list = root.querySelector('[data-rm-list]');
        list.innerHTML = '';
        const d = document.createElement('div');
        d.className = 'px-3 py-2 text-sm text-gray-500';
        d.textContent = 'Mencari…';
        list.appendChild(d);
        list.classList.remove('hidden');
        try {
            const res = await fetch(searchUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw 0;
            const json = await res.json();
            list.innerHTML = '';
                if (!json.data || json.data.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'px-3 py-2 text-sm text-gray-500';
                    empty.textContent = 'Tidak ada service yang cukup mirip dengan description.';
                    list.appendChild(empty);
            } else {
                json.data.forEach(it => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'block w-full text-left px-3 py-1.5 text-sm hover:bg-blue-50 focus:bg-blue-50 focus:outline-none';
                    b.dataset.code = it.service_code;
                    b.dataset.name = it.service_name || '';
                    const descText = it.service_description ? ' — ' + it.service_description : '';
                    b.textContent = it.service_code + ' | ' + (it.service_name || it.service_code) + descText;
                    b.setAttribute('data-rm-item', '');
                    list.appendChild(b);
                });
            }
        } catch (err) {
            list.innerHTML = '';
            const f = document.createElement('div');
            f.className = 'px-3 py-2 text-sm text-red-500';
            f.textContent = 'Pencarian gagal.';
            list.appendChild(f);
        }
    }

    // Klik/fokus: langsung tampilkan kandidat paling mirip description.
    document.addEventListener('focusin', e => {
        const input = e.target.closest('[data-rm-input]');
        if (!input) return;
        const root = input.closest('[data-rm-root]');
        if (root.querySelector('[data-rm-candidate]').value !== '') return;
        if (!root.querySelector('[data-rm-list]').classList.contains('hidden')) return;
        fetchSimilar(root, input.value.trim());
    });

    document.addEventListener('input', e => {
        const input = e.target.closest('[data-rm-input]');
        if (!input) return;
        const root = input.closest('[data-rm-root]');
        clearTimeout(timers.get(root));
        root.querySelector('[data-rm-candidate]').value = '';
        const q = input.value.trim();
        // Ketikan < 2 huruf: kembali ke daftar mirip description.
        timers.set(root, setTimeout(() => fetchSimilar(root, q.length >= 2 ? q : ''), q.length >= 2 ? 300 : 0));
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('[data-rm-modal]').forEach(m => m.classList.add('hidden'));
            document.body.classList.remove('overflow-hidden');
        }
    });
})();
</script>
@endpush
@endonce
@endif
