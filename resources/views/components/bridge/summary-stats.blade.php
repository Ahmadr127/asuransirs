@props(['summary'])

<div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-3">
    <x-stats label="Total" :value="$summary['total'] ?? 0" icon="bi-list-ol" color="bg-gray-500" />
    <x-stats label="Matched" :value="$summary['matched'] ?? 0" icon="bi-check-circle" color="bg-green-600" />
    <x-stats label="Ambiguous" :value="$summary['ambiguous'] ?? 0" icon="bi-question-circle" color="bg-yellow-500" />
    <x-stats label="Not Found" :value="$summary['not_found'] ?? 0" icon="bi-x-circle" color="bg-red-600" />
    <x-stats label="Invalid" :value="$summary['invalid'] ?? 0" icon="bi-exclamation-triangle" color="bg-orange-500" />
</div>
