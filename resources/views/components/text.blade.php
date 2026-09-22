@props([
    'as' => 'p',
    'size' => 'sm',
    'weight' => 'normal',
    'color' => 'gray-600',
    'class' => '',
])

@php
    $sizes = [
        'xs' => 'text-xs', 'sm' => 'text-sm', 'base' => 'text-base',
        'lg' => 'text-lg', 'xl' => 'text-xl', '2xl' => 'text-2xl', '3xl' => 'text-3xl',
    ];
    $weights = [
        'normal' => 'font-normal', 'medium' => 'font-medium', 'semibold' => 'font-semibold',
        'bold' => 'font-bold', 'extrabold' => 'font-extrabold',
    ];
@endphp

<{{ $as }} {{ $attributes->merge(['class' => ($sizes[$size] ?? 'text-sm') . ' ' . ($weights[$weight] ?? 'font-normal') . ' text-' . $color . ' ' . $class]) }}>
    {{ $slot }}
</{{ $as }}>
