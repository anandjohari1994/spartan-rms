<x-filament-panels::page>
    <form wire:submit="preview" class="space-y-6">
        {{ $this->form }}
        <div class="flex gap-3"><x-filament::button type="submit">Apply filters</x-filament::button><x-filament::button wire:click="export" color="gray">Export CSV</x-filament::button></div>
    </form>
    <x-filament::section heading="Report preview" description="First 50 matching rows. CSV export includes every matching row. Dates apply to registration, call time, interview schedule or follow-up schedule according to report type. HR filters use current candidate ownership.">
        <div class="overflow-x-auto"><table class="w-full text-sm text-left">
            @if(count($rows))<thead><tr>@foreach(array_keys($rows[0]) as $header)<th class="p-3 font-semibold">{{ $header }}</th>@endforeach</tr></thead>@endif
            <tbody>@forelse($rows as $row)<tr class="border-t border-gray-200 dark:border-gray-700">@foreach($row as $value)<td class="p-3">{{ $value }}</td>@endforeach</tr>@empty<tr><td class="p-4">No records match these filters.</td></tr>@endforelse</tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>
