<x-filament-panels::page>
    <form wire:submit="search" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
            {{ __('lunar-cjdropshipping::admin.catalog.search') }}
        </x-filament::button>
    </form>

    @if ($error)
        <x-filament::section>
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
        </x-filament::section>
    @elseif ($products === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('lunar-cjdropshipping::admin.catalog.empty') }}</p>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:1rem">
            @foreach ($products as $product)
                @php($status = $statuses[$product->id] ?? null)

                <x-filament::section wire:key="cj-product-{{ $product->id }}">
                    @if ($product->image)
                        <img src="{{ $product->image }}" alt="" loading="lazy" style="aspect-ratio:1/1;width:100%;object-fit:cover;border-radius:0.5rem">
                    @endif

                    <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden" title="{{ $product->name }}">
                        {{ $product->name ?? $product->sku ?? $product->id }}
                    </p>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        US$ {{ $product->sellPrice ?? '—' }}
                        · {{ __('lunar-cjdropshipping::admin.catalog.stock') }}: {{ $product->warehouseInventory ?? 0 }}
                    </p>

                    <div class="mt-3">
                        @if ($status === \Thayron\LunarCjDropshipping\Enums\CandidateStatus::Imported)
                            <x-filament::badge color="success">{{ __('lunar-cjdropshipping::admin.catalog.imported') }}</x-filament::badge>
                        @elseif ($status !== null)
                            <x-filament::badge color="gray">{{ __('lunar-cjdropshipping::admin.catalog.in_list') }}</x-filament::badge>
                        @else
                            <x-filament::button size="sm" icon="heroicon-o-plus" wire:click="addToList(@js($product->id))">
                                {{ __('lunar-cjdropshipping::admin.catalog.add') }}
                            </x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="flex items-center justify-between gap-3">
            <x-filament::button color="gray" wire:click="previousPage" :disabled="$this->page <= 1">
                {{ __('lunar-cjdropshipping::admin.catalog.previous') }}
            </x-filament::button>

            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->page }}</span>

            <x-filament::button color="gray" wire:click="nextPage" :disabled="! $hasMore">
                {{ __('lunar-cjdropshipping::admin.catalog.next') }}
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
