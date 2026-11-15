<div>
    <x-page-header title="Transactions" subtitle="Every movement across your wallets. Click a row to edit, split or refund.">
        <button type="button" class="btn" wire:click="openImport"><x-ico name="upload" /> Import CSV</button>
        <button type="button" class="btn" wire:click="export"><x-ico name="download" /> Export</button>
        <button type="button" class="btn btn-primary" @click="$dispatch('quick-entry')"><x-ico name="plus" /> Add</button>
    </x-page-header>

    {{-- Filters: one row above the table --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="relative w-full sm:w-64">
            <x-ico name="search" class="pointer-events-none absolute top-2.5 left-2.5 size-3.5 text-ink-3" />
            <input type="search" wire:model.live.debounce.250ms="search" class="input h-8 pl-8" placeholder="Search description or notes…" aria-label="Search transactions">
        </div>

        <div class="flex gap-1.5" role="group" aria-label="Period">
            @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', '30d' => '30 days', 'all' => 'All time'] as $value => $label)
                <button type="button" wire:click="$set('period', '{{ $value }}')" @class(['pill', 'pill-active' => $period === $value])>{{ $label }}</button>
            @endforeach
        </div>

        <span class="hidden h-5 w-px bg-line sm:block"></span>

        <select wire:model.live="type" aria-label="Type" @class(['pill appearance-none bg-canvas pr-2', 'pill-active' => $type !== ''])>
            <option value="">Type: any</option>
            <option value="expense">Expenses</option>
            <option value="income">Income</option>
            <option value="transfer">Transfers</option>
        </select>
        <select wire:model.live="walletId" aria-label="Wallet" @class(['pill max-w-44 appearance-none bg-canvas pr-2', 'pill-active' => $walletId !== ''])>
            <option value="">Wallet: any</option>
            @foreach ($wallets as $wallet)
                <option value="{{ $wallet->id }}">{{ $wallet->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="categoryId" aria-label="Category" @class(['pill max-w-48 appearance-none bg-canvas pr-2', 'pill-active' => $categoryId !== ''])>
            <option value="">Category: any</option>
            @foreach ($categories as $option)
                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>

        @if ($hasFilters)
            <button type="button" class="btn btn-ghost btn-sm" wire:click="clearFilters"><x-ico name="x" class="size-3" /> Clear</button>
        @endif

        <span class="ml-auto text-xs text-ink-3" wire:loading.delay>Updating…</span>
    </div>

    <div class="card overflow-hidden">
        {{-- Bulk bar --}}
        @if (count($selected) > 0)
            <div class="flex flex-wrap items-center gap-2 border-b border-brand/25 bg-brand/[0.07] px-3 py-2">
                <span class="num text-xs font-medium text-brand">{{ count($selected) }} selected</span>
                <select wire:model="bulkCategoryId" class="input h-7 w-52 text-xs" aria-label="Category to apply">
                    <option value="">Move to category…</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }} ({{ $option['type'] }})</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-sm" wire:click="bulkCategorize"><x-ico name="tag" class="size-3" /> Apply</button>
                <button type="button" class="btn btn-danger btn-sm" wire:click="bulkDelete"
                        wire:confirm="Delete {{ count($selected) }} transactions? Wallet balances will be restored."><x-ico name="trash-2" class="size-3" /> Delete</button>
                <button type="button" class="btn btn-ghost btn-sm ml-auto" wire:click="$set('selected', [])">Clear selection</button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-9">
                            <input type="checkbox" class="accent-brand" aria-label="Select all on this page"
                                   wire:click="toggleAll(@js($transactions->pluck('id')->all()))"
                                   @checked($transactions->isNotEmpty() && count($selected) >= $transactions->count())>
                        </th>
                        <th class="w-24">Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Wallet</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        <tr wire:key="tx-{{ $tx->id }}" wire:click="$dispatch('open-transaction', { id: {{ $tx->id }} })"
                            @class(['bg-brand/[0.04]' => in_array((string) $tx->id, $selected, true)])>
                            <td @click.stop>
                                <input type="checkbox" class="accent-brand" wire:model.live="selected" value="{{ $tx->id }}" aria-label="Select transaction">
                            </td>
                            <td class="num text-ink-3">{{ $tx->occurred_on->format('M j, y') }}</td>
                            <td class="max-w-72">
                                <span class="flex items-center gap-2">
                                    <span class="truncate font-medium">{{ $tx->description ?: '—' }}</span>
                                    @if ($tx->parent_transaction_id)
                                        <span class="badge bg-income/10 text-income"><x-ico name="undo-2" class="size-3" /> refund</span>
                                    @elseif ($tx->reimbursements_count > 0)
                                        <span class="badge bg-white/5 text-ink-2"><x-ico name="split" class="size-3" /> split ×{{ $tx->reimbursements_count }}</span>
                                    @endif
                                    @unless ($tx->affects_reports)
                                        <span class="badge bg-warn/10 text-warn">debt</span>
                                    @endunless
                                </span>
                            </td>
                            <td>
                                @if ($tx->type->value === 'transfer')
                                    <span class="badge bg-transfer/10 text-transfer"><x-ico name="repeat" class="size-3" /> Transfer</span>
                                @else
                                    <x-category-badge :category="$tx->category" />
                                @endif
                            </td>
                            <td class="text-xs text-ink-2">
                                {{ $tx->wallet->name }}@if ($tx->destinationWallet) <span class="text-ink-3">→</span> {{ $tx->destinationWallet->name }}@endif
                            </td>
                            <td class="text-right"><x-amount :tx="$tx" /></td>
                        </tr>
                    @empty
                        <tr class="!cursor-default hover:!bg-transparent">
                            <td colspan="6" class="!h-40 text-center text-ink-3">
                                No transactions match these filters.
                                @if ($hasFilters) <button type="button" class="cursor-pointer text-brand" wire:click="clearFilters">Clear filters</button> @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Summary footer for the current filter --}}
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-line bg-canvas/40 px-4 py-2.5">
            <div class="flex items-center gap-2 text-xs text-ink-3">
                <span class="num">{{ $transactions->total() }}</span> transactions
                @if ($transactions->hasPages())
                    <span class="text-ink-3">·</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="previousPage" @disabled($transactions->onFirstPage()) aria-label="Previous page"><x-ico name="chevron-left" class="size-3.5" /></button>
                    <span class="num">{{ $transactions->currentPage() }} / {{ $transactions->lastPage() }}</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="nextPage" @disabled(! $transactions->hasMorePages()) aria-label="Next page"><x-ico name="chevron-right" class="size-3.5" /></button>
                @endif
            </div>
            <dl class="num flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
                <div class="flex items-center gap-2"><dt class="font-sans text-ink-3">Total in</dt><dd class="text-income">+{{ money($totalIn) }}</dd></div>
                <div class="flex items-center gap-2"><dt class="font-sans text-ink-3">Total out</dt>{{-- Negative when the filter only contains refunds (e.g. Type: income). --}}
                    @if ($totalOut < 0)
                        <dd class="text-income">+{{ money(abs($totalOut)) }} <span class="font-sans text-ink-3">refunded</span></dd>
                    @else
                        <dd class="text-expense">−{{ money($totalOut) }}</dd>
                    @endif</div>
                <div class="flex items-center gap-2"><dt class="font-sans text-ink-3">Net</dt><dd class="text-sm font-semibold">{{ money($totalIn - $totalOut, null, true) }}</dd></div>
            </dl>
        </div>
    </div>
    <p class="mt-2 text-[11px] text-ink-3">Totals are in {{ base_currency()->code }} and exclude transfers and debt movements; refunds reduce “Total out”.</p>

    @if ($showImport)
        <x-modal title="Import transactions from CSV" submit="import">
            <p class="text-xs text-ink-2">
                Header row required. Columns: <span class="num text-ink">date, description, amount</span> and optionally
                <span class="num text-ink">type, wallet, category</span>. Negative amounts are expenses. Rows without a category go through your auto-categorisation rules.
            </p>
            <div>
                <label class="label" for="import-file">CSV file</label>
                <input id="import-file" type="file" accept=".csv,text/csv,text/plain" wire:model="csv"
                       class="block w-full cursor-pointer rounded-lg border border-dashed border-line-strong bg-canvas p-3 text-xs text-ink-2 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-raised file:px-2.5 file:py-1.5 file:text-xs file:text-ink">
                <p class="mt-1 text-xs text-ink-3" wire:loading wire:target="csv">Uploading…</p>
                @error('csv') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="import-wallet">Default wallet <span class="text-ink-3">(when a row names none)</span></label>
                <select id="import-wallet" class="input" wire:model="importWalletId">
                    @foreach ($wallets as $wallet)
                        <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option>
                    @endforeach
                </select>
                @error('importWalletId') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            @if ($importSummary)
                <p class="flex items-center gap-2 rounded-lg border border-brand/25 bg-brand/10 px-3 py-2 text-xs text-brand">
                    <x-ico name="check-check" class="size-3.5" /> {{ $importSummary }}
                </p>
            @endif

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Close</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="import, csv"><x-ico name="upload" /> Import</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
