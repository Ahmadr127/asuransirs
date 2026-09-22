@props([
    'title' => null,
    'subtitle' => null,
    'actions' => null,
    'footer' => null,
    'padding' => true,
])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden']) }}>
    @if($title || !empty($actions))
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
            <div class="min-w-0">
                @if($title)
                    <h5 class="font-bold text-sp-navy">{{ $title }}</h5>
                @endif
                @if($subtitle)
                    <p class="text-xs text-gray-500 mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
            @if(!empty($actions))
                <div class="flex items-center gap-2 flex-shrink-0">{{ $actions }}</div>
            @endif
        </div>
    @endif

    <div @if($padding) class="p-4" @endif>
        {{ $slot }}
    </div>

    @if(!empty($footer))
        <div class="px-4 py-3 border-t border-gray-100">{{ $footer }}</div>
    @endif
</div>
