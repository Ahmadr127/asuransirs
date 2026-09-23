@props(['status'])

@php
$color = match ($status) {
    'MATCHED' => 'bg-green-100 text-green-800',
    'AMBIGUOUS' => 'bg-yellow-100 text-yellow-800',
    'NOT_FOUND' => 'bg-red-100 text-red-800',
    'INVALID' => 'bg-orange-100 text-orange-800',
    default => 'bg-gray-100 text-gray-800',
};
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex px-2 py-0.5 text-xs font-semibold rounded-full '.$color]) }}>{{ $status }}</span>
