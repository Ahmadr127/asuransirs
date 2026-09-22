@props([
    'id' => null,
    'type' => 'line',
    'labels' => [],
    'datasets' => [],
    'height' => 280,
    'options' => [],
])

@php
    $id = $id ?? 'chart-' . Str::random(8);
@endphp

<div {{ $attributes->merge(['class' => 'relative w-full']) }} style="height: {{ $height }}px;">
    <canvas
        id="{{ $id }}"
        x-data="chartComponent({{ Js::from([
            'id' => $id,
            'type' => $type,
            'labels' => $labels,
            'datasets' => $datasets,
            'options' => $options,
        ]) }})"
    ></canvas>
</div>
