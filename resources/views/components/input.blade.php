@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'disabled' => false,
    'error' => null,
])

@php
    $id = $id ?? $name;
    $error = $error ?? ($name ? $errors->first($name) : null);
    $hasError = !empty($error);
@endphp

<div {{ $attributes->whereStartsWith('class')->merge(['class' => 'mb-3']) }}>
    @if($label)
        <label for="{{ $id }}" class="block text-sm font-semibold text-sp-navy mb-1">
            {{ $label }}@if($required)<span class="text-red-500"> *</span>@endif
        </label>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        placeholder="{{ $placeholder }}"
        {{ $required ? 'required' : '' }}
        {{ $disabled ? 'disabled' : '' }}
        {{ $attributes->except(['class']) }}
        @class([
            'w-full text-sm px-3 py-2 border rounded-md outline-none transition-colors bg-white placeholder-gray-400',
            'border-red-400 focus:ring-2 focus:ring-red-100 focus:border-red-400' => $hasError,
            'border-gray-300 focus:ring-2 focus:ring-sp-primary/20 focus:border-sp-primary' => !$hasError,
            'bg-gray-100 cursor-not-allowed' => $disabled,
        ])
    >

    @if($hasError)
        <p class="mt-1 text-xs text-red-500">{{ $error }}</p>
    @endif
</div>
