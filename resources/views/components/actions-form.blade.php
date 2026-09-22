@props([
    'action' => null,
    'method' => 'DELETE',
    'icon' => null,
    'label' => null,
    'color' => 'text-gray-700 hover:bg-gray-50 hover:text-red-600',
    'confirm' => null,
])

<form action="{{ $action }}" method="POST" class="block">
    @csrf
    @if(strtoupper($method) !== 'POST')
        @method($method)
    @endif
    <button
        type="submit"
        data-confirm="{{ $confirm }}"
        onclick="return !this.dataset.confirm || confirm(this.dataset.confirm)"
        {{ $attributes->merge(['class' => 'w-full flex items-center gap-2 px-3 py-2 text-sm font-medium ' . $color]) }}
    >
        @if($icon)<i class="bi {{ $icon }} w-4 text-center"></i>@endif
        {{ $label ?? $slot }}
    </button>
</form>
