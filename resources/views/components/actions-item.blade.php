@props([
    'href' => null,
    'icon' => null,
    'label' => null,
    'color' => 'text-gray-700 hover:bg-gray-50 hover:text-sp-primary',
])

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex items-center gap-2 px-3 py-2 text-sm font-medium ' . $color]) }}>
        @if($icon)<i class="bi {{ $icon }} w-4 text-center"></i>@endif
        {{ $label ?? $slot }}
    </a>
@else
    <button type="button" {{ $attributes->merge(['class' => 'w-full flex items-center gap-2 px-3 py-2 text-sm font-medium ' . $color]) }}>
        @if($icon)<i class="bi {{ $icon }} w-4 text-center"></i>@endif
        {{ $label ?? $slot }}
    </button>
@endif
