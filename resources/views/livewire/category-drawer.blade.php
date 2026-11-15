<div>
    @if ($category)
        <x-slide-over :title="$category->name" :subtitle="($category->parent ? $category->parent->name.' › ' : '').($isIncome ? 'Income category' : 'Spending category')">
            <div class="space-y-5">
                <div class="card flex items-center gap-3 px-4 py-3">
                    <span class="flex size-9 items-center justify-center rounded-lg" style="color: {{ $category->color_hex }}; background-color: {{ $category->color_hex }}1f;">
                        <x-ico :name="$category->icon" class="size-4.5" />
                    </span>
                    <div class="flex-1">
                        <p class="eyebrow">{{ $isIncome ? 'Earned' : 'Net spent' }} this month</p>
                        <p class="num text-2xl font-semibold">{{ money($thisMonth) }}</p>
                    </div>
                    @if ($lastMonth > 0)
                        @php $delta = round(($thisMonth - $lastMonth) / $lastMonth * 100); @endphp
                        <div class="text-right">
                            <p class="eyebrow">vs last month</p>
                            <p class="num text-sm {{ ($delta > 0) === $isIncome ? 'text-income' : 'text-expense' }}">{{ $delta > 0 ? '+' : '' }}{{ $delta }}%</p>
                        </div>
                    @endif
                </div>

                {{-- 6-month trend: single series, so no legend; values on hover + in the label row --}}
                <section>
                    <h3 class="eyebrow mb-2">Last 6 months</h3>
                    <div class="flex h-28 items-end gap-2">
                        @foreach ($trend as $i => $point)
                            <div class="group flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $point['label'] }}: {{ money($point['amount']) }}">
                                <span class="num text-[10px] text-ink-3 opacity-0 transition-opacity group-hover:opacity-100">{{ \App\Support\Money::compact($point['amount']) }}</span>
                                <div class="w-full rounded-t-[4px] transition-opacity {{ $loop->last ? '' : 'opacity-45 group-hover:opacity-80' }}"
                                     style="height: {{ max(2, round($point['amount'] / $trendMax * 80)) }}%; background-color: {{ $category->color_hex }};"></div>
                                <span class="num text-[10px] {{ $loop->last ? 'text-ink' : 'text-ink-3' }}">{{ $point['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                @if ($children->isNotEmpty())
                    <section>
                        <h3 class="eyebrow mb-2">Sub-categories · this month</h3>
                        <div class="space-y-2.5">
                            @foreach ($children as $row)
                                <button type="button" wire:click="open({{ $row['category']->id }})" class="block w-full cursor-pointer text-left">
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="flex items-center gap-1.5 text-ink-2"><x-ico :name="$row['category']->icon" class="size-3" /> {{ $row['category']->name }}</span>
                                        <span class="num">{{ money($row['amount']) }}</span>
                                    </div>
                                    <div class="progress"><span style="width: {{ $thisMonth > 0 ? round($row['amount'] / $thisMonth * 100) : 0 }}%; background-color: {{ $category->color_hex }};"></span></div>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section>
                    <h3 class="eyebrow mb-1">Latest activity</h3>
                    @forelse ($transactions as $tx)
                        <button type="button" wire:key="cat-tx-{{ $tx->id }}" wire:click="$dispatch('open-transaction', { id: {{ $tx->id }} })"
                                class="flex h-9 w-full cursor-pointer items-center gap-2 border-b border-line text-left text-xs hover:bg-white/[0.035]">
                            <span class="num w-12 text-ink-3">{{ $tx->occurred_on->format('M j') }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $tx->description ?: '—' }}</span>
                            @if ($tx->isReimbursement())
                                <span class="badge bg-income/10 text-income">refund</span>
                            @endif
                            <x-amount :tx="$tx" />
                        </button>
                    @empty
                        <p class="py-3 text-xs text-ink-3">No transactions in this category yet.</p>
                    @endforelse
                </section>
            </div>
        </x-slide-over>
    @endif
</div>
