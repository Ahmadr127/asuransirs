@props([
    'align' => 'right',
    'triggerIcon' => 'bi-three-dots-vertical',
    'menuClass' => 'w-40',
    'count' => 0,
])

{{--
    Kolom aksi. Jika jumlah aksi hanya 1-2, tampilkan sebagai icon button biasa
    (tanpa titik tiga); jika 3 atau lebih, gunakan dropdown titik tiga.

    Untuk mode dropdown, state memakai nama unik "menuOpen" agar tidak
    bertabrakan (scope isolation) dengan variabel "open" milik modal halaman.
--}}
@if($count > 0 && $count <= 2)
    <div class="flex items-center justify-start gap-1.5">
        {{ $slot }}
    </div>
@else
<div
    x-data="dropdownMenu({ align: '{{ $align }}' })"
    class="relative inline-block"
    @click.outside="menuOpen = false"
    @keydown.escape.window="menuOpen = false"
>
    <button
        type="button"
        x-ref="trigger"
        @click="toggle()"
        class="inline-flex items-center justify-center w-7 h-7 rounded-md text-sp-navy border border-gray-200 bg-white hover:bg-sp-primary/10 hover:border-sp-primary/30 hover:text-sp-primary transition-colors"
        title="Aksi"
    >
        <i class="bi {{ $triggerIcon }}"></i>
    </button>

    {{-- Dropdown menu: fixed positioning agar tidak terpotong overflow table --}}
    <div
        x-show="menuOpen"
        x-ref="menu"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click="menuOpen = false"
        :style="`top: ${position.top}px; left: ${position.left}px;`"
        class="fixed z-30 mt-1 {{ $menuClass }} bg-white border border-gray-200 rounded-lg shadow-lg py-1"
    >
        {{ $slot }}
    </div>
</div>

@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dropdownMenu', (config = {}) => ({
        menuOpen: false,
        align: config.align || 'right',
        position: { top: 0, left: 0 },

        toggle() {
            this.menuOpen = !this.menuOpen;
            if (this.menuOpen) {
                this.$nextTick(() => this.updatePosition());
            }
        },

        updatePosition() {
            const trigger = this.$refs.trigger.getBoundingClientRect();
            const menu = this.$refs.menu;
            const menuWidth = menu.offsetWidth || 160;

            let left = this.align === 'left' ? trigger.left : trigger.right - menuWidth;
            left = Math.min(Math.max(left, 8), window.innerWidth - menuWidth - 8);

            this.position = {
                top: trigger.bottom + 4,
                left: left,
            };
        },
    }));
});
</script>
@endpush
@endonce
@endif
