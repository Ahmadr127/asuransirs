@props([
    'filters' => [],
    'searchPlaceholder' => 'Cari...',
    'showDateRange' => true
])

<div class="px-4 py-3 border-b border-gray-100 bg-white">
    <div class="flex flex-col lg:flex-row gap-3 items-end">
        <!-- Search Input -->
        <div class="flex-1">
            <label for="search" class="block text-xs font-semibold text-gray-600 mb-1">Pencarian</label>
            <div class="relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="{{ $searchPlaceholder }}"
                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-full bg-gray-50 placeholder-gray-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors"
                    x-model="filters.search"
                    @input.debounce.300ms="applyFilters()"
                >
            </div>
        </div>

        <!-- Date Range Filter -->
        @if($showDateRange)
        <div class="lg:w-44">
            <label for="date_from" class="block text-xs font-semibold text-gray-600 mb-1">Dari Tanggal</label>
            <input
                type="date"
                id="date_from"
                name="date_from"
                value="{{ request('date_from') }}"
                x-model="filters.dateFrom"
                @change="applyFilters()"
                class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors"
            >
        </div>

        <div class="lg:w-44">
            <label for="date_to" class="block text-xs font-semibold text-gray-600 mb-1">Sampai Tanggal</label>
            <input
                type="date"
                id="date_to"
                name="date_to"
                value="{{ request('date_to') }}"
                x-model="filters.dateTo"
                @change="applyFilters()"
                class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary transition-colors"
            >
        </div>
        @endif

        <!-- Action Buttons -->
        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="clearFilters()"
                class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-gray-600 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition-colors"
            >
                <i class="bi bi-x-lg mr-1.5 text-xs"></i>
                Reset
            </button>

            <button
                type="button"
                @click="applyFilters()"
                class="inline-flex items-center px-3 py-1.5 text-sm font-semibold text-white rounded-md bg-sp-primary hover:bg-sp-primary-dark transition-colors"
            >
                <i class="bi bi-funnel mr-1.5 text-xs"></i>
                Filter
            </button>
        </div>
    </div>

    <!-- Active Filters Display -->
    <div x-show="hasActiveFilters()" class="mt-3 pt-3 border-t border-gray-100">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-xs font-semibold text-gray-600">Filter Aktif:</span>
            <template x-for="(value, key) in getActiveFilters()" :key="key">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-sp-primary/10 text-sp-primary">
                    <span x-text="getFilterLabel(key, value)"></span>
                    <button
                        @click="removeFilter(key)"
                        class="ml-1.5 inline-flex items-center justify-center w-4 h-4 rounded-full text-sp-primary/60 hover:bg-sp-primary/20 hover:text-sp-primary focus:outline-none"
                    >
                        <i class="bi bi-x text-xs"></i>
                    </button>
                </span>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('tableFilter', (initialFilters = {}) => ({
        filters: {
            search: initialFilters.search || '',
            dateFrom: initialFilters.dateFrom || '',
            dateTo: initialFilters.dateTo || '',
            ...initialFilters
        },

        init() {
            this.filters = {
                search: new URLSearchParams(window.location.search).get('search') || '',
                dateFrom: new URLSearchParams(window.location.search).get('date_from') || '',
                dateTo: new URLSearchParams(window.location.search).get('date_to') || '',
                ...initialFilters
            };
        },

        applyFilters() {
            const params = new URLSearchParams();

            if (this.filters.search) params.set('search', this.filters.search);
            if (this.filters.dateFrom) params.set('date_from', this.filters.dateFrom);
            if (this.filters.dateTo) params.set('date_to', this.filters.dateTo);

            const currentPage = new URLSearchParams(window.location.search).get('page');
            if (currentPage) params.set('page', currentPage);

            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.location.href = newUrl;
        },

        clearFilters() {
            this.filters = {
                search: '',
                dateFrom: '',
                dateTo: '',
                ...initialFilters
            };
            window.location.href = window.location.pathname;
        },

        removeFilter(key) {
            this.filters[key] = '';
            this.applyFilters();
        },

        hasActiveFilters() {
            return Object.values(this.filters).some(value => value !== '' && value !== null);
        },

        getActiveFilters() {
            const active = {};
            Object.entries(this.filters).forEach(([key, value]) => {
                if (value !== '' && value !== null) {
                    active[key] = value;
                }
            });
            return active;
        },

        getFilterLabel(key, value) {
            const labels = {
                search: `Pencarian: "${value}"`,
                dateFrom: `Dari: ${this.formatDate(value)}`,
                dateTo: `Sampai: ${this.formatDate(value)}`
            };
            return labels[key] || `${key}: ${value}`;
        },

        formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }
    }));
});
</script>
@endpush
