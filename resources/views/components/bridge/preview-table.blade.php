@props(['rows' => []])

<x-table :columns="['Row', 'Status', 'Old Code', 'Description', 'Old Class Code', 'Kelas', 'New Code', 'New Class Code']" empty="Tidak ada baris.">
    @foreach($rows as $row)
    <tr class="hover:bg-gray-50 transition-colors">
        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">{{ $row['excel_row'] }}</td>
        <td class="px-4 py-3 whitespace-nowrap">
            <x-bridge.status-badge :status="$row['status']" />
        </td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs">{{ $row['service_code'] !== '' ? $row['service_code'] : '-' }}</td>
        <td class="px-4 py-3 text-xs max-w-xs truncate">{{ $row['service_description'] !== '' ? $row['service_description'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs">{{ $row['service_class_code'] !== '' ? $row['service_class_code'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $row['class_name'] !== '' ? $row['class_name'] : '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs font-semibold">{{ $row['new_service_code'] ?? '-' }}</td>
        <td class="px-4 py-3 whitespace-nowrap font-mono text-xs font-semibold">{{ $row['new_class_code'] ?? '-' }}</td>
    </tr>
    @endforeach
</x-table>
