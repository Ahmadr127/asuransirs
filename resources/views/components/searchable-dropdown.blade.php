{{--
    Searchable Dropdown Component

    Usage:
    <x-searchable-dropdown
        name="field_name"
        label="Label Text"
        :options="$collection"
        value-field="id"
        label-field="name"
        :selected="$selectedValue"
        placeholder="Select option..."
        :required="true"
    />

    With option groups:
    <x-searchable-dropdown
        name="field_name"
        label="Label Text"
        :options="$collection"
        value-field="id"
        label-field="name"
        group-field="category"
        :selected="$selectedValue"
    />
--}}

@props([
    'name',
    'label' => null,
    'options' => [],
    'valueField' => 'id',
    'labelField' => 'name',
    'groupField' => null,
    'selected' => null,
    'placeholder' => 'Pilih...',
    'required' => false,
    'disabled' => false,
    'emptyOption' => null,
    'error' => null
])

@php
    $inputId = 'dropdown-' . Str::random(8);
    $selectedValue = old($name, $selected);
@endphp

<div
    x-data="searchableDropdown({
        options: {{ Js::from($options->map(fn($opt) => [
            'value' => data_get($opt, $valueField),
            'label' => data_get($opt, $labelField),
            'group' => $groupField ? data_get($opt, $groupField) : null,
            'raw' => $opt
        ])) }},
        selected: {{ Js::from($selectedValue) }},
        placeholder: '{{ $placeholder }}',
        emptyOption: {{ Js::from($emptyOption) }}
    })"
    class="relative"
    @click.away="close()"
    style="z-index: 10;"
>
    @if($label)
    <label for="{{ $inputId }}" class="block text-sm font-semibold text-sp-navy mb-1">
        {{ $label }}
        @if($required)
            <span class="text-red-500">*</span>
        @endif
    </label>
    @endif

    {{-- Hidden input for form submission --}}
    <input type="hidden" name="{{ $name }}" x-model="selectedValue">

    {{-- Dropdown trigger --}}
    <button
        type="button"
        id="{{ $inputId }}"
        @click="toggle()"
        :disabled="{{ $disabled ? 'true' : 'false' }}"
        class="relative w-full bg-white border border-gray-300 rounded-md shadow-sm pl-3 pr-9 py-2 text-left cursor-pointer focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary sm:text-sm {{ $disabled ? 'bg-gray-100 cursor-not-allowed' : '' }}"
        :class="{ 'ring-2 ring-sp-primary/20 border-sp-primary': open }"
    >
        <span x-text="displayText" class="block truncate text-sm" :class="{ 'text-gray-400': !selectedValue }"></span>
        <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <i class="bi bi-chevron-down text-xs text-gray-400 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
        </span>
    </button>

    {{-- Dropdown panel - uses fixed positioning to escape overflow:hidden containers --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-ref="dropdown"
        class="fixed bg-white shadow-xl max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm"
        :style="dropdownStyle"
        style="z-index: 99999;"
        x-cloak
    >
        {{-- Search input --}}
        <div class="sticky top-0 z-10 bg-white px-2 py-2 border-b border-gray-100">
            <div class="relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                <input
                    type="text"
                    x-model="search"
                    x-ref="searchInput"
                    @keydown.escape="close()"
                    @keydown.enter.prevent="selectFirst()"
                    placeholder="Cari..."
                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary"
                >
            </div>
        </div>

        {{-- Options list --}}
        <ul class="py-1">
            {{-- Empty option --}}
            <template x-if="emptyOption !== null">
                <li
                    @click="select(null)"
                    class="cursor-pointer select-none relative py-1.5 pl-3 pr-9 hover:bg-sp-hover"
                    :class="{ 'bg-sp-primary/10 text-sp-primary': selectedValue === null }"
                >
                    <span class="block truncate text-sm text-gray-500" x-text="emptyOption || '-- Tidak Ada --'"></span>
                    <span x-show="selectedValue === null" class="absolute inset-y-0 right-0 flex items-center pr-4 text-sp-primary">
                        <i class="bi bi-check text-sm"></i>
                    </span>
                </li>
            </template>

            {{-- Filtered options --}}
            <template x-for="option in filteredOptions" :key="option.value">
                <li
                    @click="select(option.value)"
                    class="cursor-pointer select-none relative py-1.5 pl-3 pr-9 hover:bg-sp-hover"
                    :class="{ 'bg-sp-primary/10 text-sp-primary': selectedValue == option.value }"
                >
                    <span class="block truncate text-sm" x-text="option.label"></span>
                    <span x-show="option.group" class="text-xs text-gray-400 ml-1" x-text="'(' + option.group + ')'"></span>
                    <span x-show="selectedValue == option.value" class="absolute inset-y-0 right-0 flex items-center pr-4 text-sp-primary">
                        <i class="bi bi-check text-sm"></i>
                    </span>
                </li>
            </template>

            {{-- No results --}}
            <template x-if="filteredOptions.length === 0 && search">
                <li class="py-2 pl-3 pr-9 text-gray-500 text-sm">
                    Tidak ada hasil untuk "<span x-text="search"></span>"
                </li>
            </template>
        </ul>
    </div>

    {{-- Error message --}}
    @error($name)
        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
    @enderror
</div>

@once
@push('scripts')
<script>
function searchableDropdown(config) {
    return {
        open: false,
        search: '',
        dropUp: false,
        dropdownPosition: { top: 0, left: 0, width: 0 },
        options: config.options || [],
        selectedValue: config.selected,
        placeholder: config.placeholder || 'Pilih...',
        emptyOption: config.emptyOption,

        get filteredOptions() {
            if (!this.search) return this.options;
            const query = this.search.toLowerCase();
            return this.options.filter(opt =>
                (opt.label && opt.label.toLowerCase().includes(query)) ||
                (opt.group && opt.group.toLowerCase().includes(query))
            );
        },

        get displayText() {
            if (this.selectedValue === null || this.selectedValue === '') {
                return this.placeholder;
            }
            const found = this.options.find(opt => opt.value == this.selectedValue);
            return found ? found.label : this.placeholder;
        },

        get dropdownStyle() {
            if (this.dropUp) {
                return `bottom: ${window.innerHeight - this.dropdownPosition.top + 4}px; left: ${this.dropdownPosition.left}px; width: ${this.dropdownPosition.width}px;`;
            }
            return `top: ${this.dropdownPosition.top + this.dropdownPosition.height + 4}px; left: ${this.dropdownPosition.left}px; width: ${this.dropdownPosition.width}px;`;
        },

        updatePosition() {
            const rect = this.$el.getBoundingClientRect();
            this.dropdownPosition = {
                top: rect.top,
                left: rect.left,
                width: rect.width,
                height: rect.height
            };

            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;
            this.dropUp = spaceBelow < 250 && spaceAbove > spaceBelow;
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.updatePosition();
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                });
            }
        },

        close() {
            this.open = false;
            this.search = '';
        },

        select(value) {
            this.selectedValue = value;
            this.close();
        },

        selectFirst() {
            if (this.filteredOptions.length > 0) {
                this.select(this.filteredOptions[0].value);
            }
        }
    }
}
</script>
@endpush
@endonce
