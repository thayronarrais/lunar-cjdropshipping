<x-filament-panels::page>
    @if ($loadError)
        <x-filament::section>
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
        </x-filament::section>
    @else
        <form wire:submit="listNow" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="button" color="gray" icon="heroicon-o-truck" wire:click="quoteShipping">
                    {{ __('lunar-cjdropshipping::admin.listing.actions.quote') }}
                </x-filament::button>

                <x-filament::button type="button" icon="heroicon-o-sparkles" wire:click="recommendPrices">
                    {{ __('lunar-cjdropshipping::admin.listing.actions.recommend') }}
                </x-filament::button>

                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="bulkMode">
                        <option value="percent">{{ __('lunar-cjdropshipping::admin.listing.bulk.percent') }}</option>
                        <option value="amount">{{ __('lunar-cjdropshipping::admin.listing.bulk.amount') }}</option>
                        <option value="set">{{ __('lunar-cjdropshipping::admin.listing.bulk.set') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input type="number" step="0.01" wire:model="bulkValue" />
                </x-filament::input.wrapper>

                <x-filament::button type="button" color="gray" wire:click="applyBulk">
                    {{ __('lunar-cjdropshipping::admin.listing.bulk.apply') }}
                </x-filament::button>
            </div>

            <div style="overflow-x:auto" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            @foreach (['selected', 'image', 'sku', 'variant', 'cost', 'shipping', 'total', 'rrp', 'price', 'margin'] as $column)
                                <th class="px-3 py-2 text-start font-medium">{{ __('lunar-cjdropshipping::admin.listing.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variants as $index => $row)
                            @php
                                $shipping = $this->shippingFor($index);
                                $total = $this->totalFor($index);
                                $margin = $this->marginFor($index);
                                $belowMinimum = $margin !== null && bccomp($margin, $this->minimumMargin(), 2) < 0;
                            @endphp

                            <tr wire:key="variant-{{ $row['vid'] }}" class="border-t border-gray-200 dark:border-white/5">
                                <td class="px-3 py-2">
                                    <x-filament::input.checkbox wire:model.live="variants.{{ $index }}.selected" />
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row['image'])
                                        <img src="{{ $row['image'] }}" alt="" loading="lazy" style="width:3rem;height:3rem;object-fit:cover;border-radius:0.375rem">
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $row['sku'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['label'] }}</td>
                                <td class="px-3 py-2">{{ $row['cost_usd'] !== null ? 'US$ '.$row['cost_usd'] : '—' }}</td>
                                <td class="px-3 py-2">{{ $shipping !== null ? 'US$ '.$shipping : '—' }}</td>
                                <td class="px-3 py-2">{{ $total !== null ? 'US$ '.$total : '—' }}</td>
                                <td class="px-3 py-2">{{ $this->rrpFor($index) ?? '—' }}</td>
                                <td class="px-3 py-2" style="min-width:7rem">
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="number" step="0.01" min="0" wire:model.live.debounce.500ms="variants.{{ $index }}.price" />
                                    </x-filament::input.wrapper>
                                </td>
                                <td class="px-3 py-2 font-medium {{ $belowMinimum ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                    {{ $margin !== null ? $margin.'%' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <x-filament::input.checkbox wire:model="acceptNegativeMargin" />
                {{ __('lunar-cjdropshipping::admin.listing.accept_negative_margin') }}
            </label>

            <x-filament::button type="submit" icon="heroicon-o-check">
                {{ __('lunar-cjdropshipping::admin.listing.actions.list_now') }}
            </x-filament::button>
        </form>
    @endif
</x-filament-panels::page>
