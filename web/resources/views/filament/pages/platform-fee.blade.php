{{-- Admin → ZoneSpot fee: every place, a day / week / month, by sport --}}
@php($amd = fn ($n) => number_format($n, 0, '.', ' ').' ֏')
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <x-filament::tabs>
            @foreach (['day', 'week', 'month'] as $p)
                <x-filament::tabs.item :active="$period === $p" wire:click="setPeriod('{{ $p }}')">{{ __('admin.fee.'.$p) }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div style="display:flex;align-items:center;gap:6px">
            <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="move(-1)" :label="__('admin.fee.prev')" />
            <b style="min-width:150px;text-align:center">{{ $label }}</b>
            <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="move(1)" :label="__('admin.fee.next')" />
        </div>
        <x-filament::input.wrapper style="margin-left:auto">
            <x-filament::input.select wire:model.live="sport">
                <option value="">{{ __('admin.fee.all_sports') }}</option>
                @foreach ($sportOptions as $k => $v)
                    <option value="{{ $k }}">{{ $v }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <x-filament::section>
            <div style="font-size:13px;opacity:.7">{{ __('admin.fee.total_fee') }}</div>
            <div style="font-size:28px;font-weight:800;color:rgb(var(--primary-500, 132 204 22))">{{ $amd($fee) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div style="font-size:13px;opacity:.7">{{ __('admin.fee.total_base') }}</div>
            <div style="font-size:28px;font-weight:800">{{ $amd($base) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead>
                    <tr style="text-align:left;opacity:.7;font-size:12px;text-transform:uppercase;letter-spacing:.04em">
                        <th style="padding:8px">{{ __('admin.venue.name') }}</th>
                        <th style="padding:8px">%</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.kind_booking') }}</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.kind_training') }}</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.kind_tournament') }}</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.kind_unpaid') }}</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.base') }}</th>
                        <th style="padding:8px;text-align:right">{{ __('admin.fee.fee') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr style="border-top:1px solid rgba(128,128,128,.2)">
                            <td style="padding:10px 8px"><a href="{{ \App\Filament\Resources\Venues\VenueResource::getUrl('edit', ['record' => $r['slug']]) }}" style="font-weight:700">{{ $sports[$r['id']] ?? '' }} {{ $r['name'] }}</a></td>
                            <td style="padding:10px 8px">{{ rtrim(rtrim(number_format($r['pct'], 2, '.', ''), '0'), '.') }}%</td>
                            @foreach (['booking', 'training', 'tournament', 'unpaid'] as $k)
                                <td style="padding:10px 8px;text-align:right;font-variant-numeric:tabular-nums;{{ $r['byKind'][$k] ? '' : 'opacity:.35' }}">{{ $amd($r['byKind'][$k]) }}</td>
                            @endforeach
                            <td style="padding:10px 8px;text-align:right;font-variant-numeric:tabular-nums">{{ $amd($r['base']) }}</td>
                            <td style="padding:10px 8px;text-align:right;font-weight:800;font-variant-numeric:tabular-nums">{{ $amd($r['fee']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:14px 8px;opacity:.6">{{ __('admin.fee.none') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid rgba(128,128,128,.4);font-weight:800">
                        <td style="padding:10px 8px" colspan="6">{{ __('admin.fee.total') }}</td>
                        <td style="padding:10px 8px;text-align:right">{{ $amd($base) }}</td>
                        <td style="padding:10px 8px;text-align:right">{{ $amd($fee) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p style="font-size:12.5px;opacity:.65;margin-top:10px">{{ __('admin.fee.note_counted') }}</p>
    </x-filament::section>
</x-filament-panels::page>
