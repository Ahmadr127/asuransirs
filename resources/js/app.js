import './bootstrap';

document.addEventListener('alpine:init', () => {
    Alpine.data('chartComponent', (config) => ({
        chart: null,

        init() {
            if (typeof Chart === 'undefined') return;

            const defaults = {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false },
                plugins: {
                    legend: {
                        labels: {
                            font: { family: 'Nunito', size: 12 },
                            boxWidth: 12,
                            boxHeight: 8,
                            padding: 12,
                        },
                    },
                    tooltip: {
                        bodyFont: { family: 'Nunito', size: 12 },
                        titleFont: { family: 'Nunito', size: 13, weight: 'bold' },
                        padding: 10,
                        boxPadding: 4,
                    },
                },
            };

            this.chart = new Chart(this.$el, {
                type: config.type,
                data: { labels: config.labels, datasets: config.datasets },
                options: Object.assign(defaults, config.options || {}),
            });
        },
    }));

    Alpine.data('searchableTable', (config) => ({
        columns: config.columns || [],
        rows: config.rows || [],
        perPage: config.perPage || 10,
        perPageOptions: config.perPageOptions || [5, 10, 25, 50, 100],
        empty: config.empty || 'Tidak ada data.',
        searchPlaceholder: config.searchPlaceholder || 'Cari...',
        page: 1,
        filters: {},

        init() {
            this.columns.forEach(col => {
                this.filters[col.key] = '';
            });
        },

        get filteredRows() {
            return this.rows.filter(row => {
                return this.columns.every(col => {
                    const q = (this.filters[col.key] || '').toLowerCase().trim();
                    if (!q) return true;
                    return String(row[col.key] ?? '').toLowerCase().includes(q);
                });
            });
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredRows.length / this.perPage));
        },

        get pagedRows() {
            const start = (this.page - 1) * this.perPage;
            return this.filteredRows.slice(start, start + this.perPage);
        },

        get start() {
            return this.filteredRows.length === 0 ? 0 : (this.page - 1) * this.perPage + 1;
        },

        get end() {
            return Math.min(this.page * this.perPage, this.filteredRows.length);
        },

        get pageNumbers() {
            const total = this.totalPages;
            const current = this.page;
            const pages = [];
            const start = Math.max(1, current - 2);
            const end = Math.min(total, current + 2);
            for (let p = start; p <= end; p++) pages.push(p);
            return pages;
        },

        go(page) {
            this.page = Math.min(Math.max(1, page), this.totalPages);
        },

        prev() {
            this.go(this.page - 1);
        },

        next() {
            this.go(this.page + 1);
        },
    }));
});
