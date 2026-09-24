@props(['token', 'mappingKey', 'group'])
@php $uid = 'nf_'.md5((string) $mappingKey); @endphp

<div class="border border-red-200 bg-red-50 rounded-md p-3" data-nf-root>
    <p class="text-sm"><span class="font-semibold">Description:</span> {{ $group['description'] !== '' ? $group['description'] : '-' }} &bull; <span class="font-semibold">Kelas:</span> {{ $group['kelas'] !== '' ? $group['kelas'] : '-' }} &bull; <span class="text-gray-500">{{ count($group['rows']) }} row</span>
        @if(!empty($group['resolved']))
            <span class="ml-1 inline-flex items-center px-1.5 py-0.5 text-xs font-semibold text-green-700 bg-green-100 rounded">sudah dipetakan → {{ $group['resolved']['service_code'] }} (kelas bawaan Excel)</span>
        @endif
    </p>
    <form action="{{ route('bridge.resolve') }}" method="POST" class="mt-2 flex flex-col md:flex-row gap-2 items-start md:items-end">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="mapping_key" value="{{ $mappingKey }}">
        <input type="hidden" name="candidate" value="" data-nf-candidate>
        <div class="flex-1 relative">
            <label for="{{ $uid }}_q" class="block text-xs font-semibold text-gray-600 mb-1">Service master <span class="font-normal text-gray-500">(kelas ikut bawaan Excel)</span></label>
            <input id="{{ $uid }}_q" type="text" autocomplete="off" spellcheck="false" placeholder="Ketik min. 2 huruf — kode / nama / deskripsi…"
                data-nf-input
                class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors">
            <div data-nf-list class="hidden absolute z-10 mt-1 w-full max-h-56 overflow-auto bg-white border border-gray-300 rounded-md shadow-lg"></div>
        </div>
        <button type="submit" data-nf-submit disabled
            class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-blue-600 hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="bi bi-check-lg mr-1.5 text-xs"></i> Terapkan
        </button>
    </form>
</div>

@once
@push('scripts')
<script>
(function () {
    const searchUrl = @json(route('bridge.search-services'));
    const timers = new WeakMap();

    function closeLists(except) {
        document.querySelectorAll('[data-nf-list]').forEach(l => { if (l !== except) l.classList.add('hidden'); });
    }

    function renderList(root, items) {
        const list = root.querySelector('[data-nf-list]');
        list.innerHTML = '';
        if (items.length === 0) {
            const d = document.createElement('div');
            d.className = 'px-3 py-2 text-sm text-gray-500';
            d.textContent = 'Tidak ditemukan di master.';
            list.appendChild(d);
        } else {
            items.forEach(it => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'block w-full text-left px-3 py-1.5 text-sm hover:bg-blue-50 focus:bg-blue-50 focus:outline-none';
                b.dataset.code = it.service_code;
                b.dataset.name = it.service_name || '';
                const desc = it.service_description ? ' — ' + it.service_description : '';
                b.textContent = it.service_code + ' | ' + (it.service_name || it.service_code) + desc;
                b.setAttribute('data-nf-item', '');
                list.appendChild(b);
            });
        }
        list.classList.remove('hidden');
    }

    document.addEventListener('input', e => {
        const input = e.target.closest('[data-nf-input]');
        if (!input) return;
        const root = input.closest('[data-nf-root]');
        clearTimeout(timers.get(root));
        root.querySelector('[data-nf-candidate]').value = '';
        root.querySelector('[data-nf-submit]').disabled = true;
        const q = input.value.trim();
        if (q.length < 2) {
            root.querySelector('[data-nf-list]').classList.add('hidden');
            return;
        }
        timers.set(root, setTimeout(async () => {
            const list = root.querySelector('[data-nf-list]');
            list.innerHTML = '';
            const d = document.createElement('div');
            d.className = 'px-3 py-2 text-sm text-gray-500';
            d.textContent = 'Mencari…';
            list.appendChild(d);
            list.classList.remove('hidden');
            try {
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw 0;
                const json = await res.json();
                renderList(root, json.data || []);
            } catch (err) {
                list.innerHTML = '';
                const f = document.createElement('div');
                f.className = 'px-3 py-2 text-sm text-red-500';
                f.textContent = 'Pencarian gagal.';
                list.appendChild(f);
            }
        }, 300));
    });

    document.addEventListener('click', e => {
        const item = e.target.closest('[data-nf-item]');
        if (item) {
            const root = item.closest('[data-nf-root]');
            root.querySelector('[data-nf-input]').value = item.dataset.code + ' — ' + (item.dataset.name || item.dataset.code);
            root.querySelector('[data-nf-candidate]').value = item.dataset.code + '|';
            root.querySelector('[data-nf-submit]').disabled = false;
            root.querySelector('[data-nf-list]').classList.add('hidden');
            return;
        }
        if (!e.target.closest('[data-nf-root]')) closeLists();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeLists();
    });
})();
</script>
@endpush
@endonce
