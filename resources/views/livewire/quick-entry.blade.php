<div>
    {{-- State is owned by Alpine; wire:ignore keeps Livewire's DOM morphing away from it. --}}
    <div wire:ignore x-data="quickEntry(@js($config))" @quick-entry.window="show($event.detail?.type)" @keydown.escape.window="hide()">
        <div x-show="open" x-cloak class="fixed inset-0 z-[55] flex items-start justify-center p-4 pt-[13vh]" role="dialog" aria-modal="true" aria-label="New transaction">
            <div x-show="open" x-transition.opacity.duration.150ms class="fixed inset-0 bg-black/70 backdrop-blur-sm" @click="hide()"></div>

            <form x-show="open" x-trap.noscroll="open"
                  x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="translate-y-2 scale-[0.98] opacity-0"
                  @submit.prevent="submit()"
                  class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-line-strong bg-surface shadow-[0_24px_80px_rgba(0,0,0,0.6)]">

                {{-- Type switch --}}
                <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                    <div class="flex gap-1 rounded-lg bg-canvas p-0.5" role="tablist">
                        @foreach ([['expense', 'Expense', 'E', 'text-expense'], ['income', 'Income', 'I', 'text-income'], ['transfer', 'Transfer', 'T', 'text-transfer']] as [$value, $label, $key, $tone])
                            <button type="button" tabindex="-1" role="tab" @click="type = '{{ $value }}'; $refs.amount.focus()"
                                    :aria-selected="type === '{{ $value }}'"
                                    :class="type === '{{ $value }}' ? 'bg-raised {{ $tone }} shadow-sm' : 'text-ink-3 hover:text-ink-2'"
                                    class="flex h-7 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors">
                                {{ $label }} <span class="kbd">{{ $key }}</span>
                            </button>
                        @endforeach
                    </div>
                    <span class="kbd">Esc</span>
                </div>

                {{-- Calculator amount --}}
                <div class="glow px-5 pt-5 pb-4">
                    <div class="flex items-baseline gap-2">
                        <span class="num text-3xl font-semibold"
                              :class="{ 'text-expense': type === 'expense', 'text-income': type === 'income', 'text-transfer': type === 'transfer' }"
                              x-text="type === 'expense' ? '−' : (type === 'income' ? '+' : '→')"></span>
                        <input type="text" x-ref="amount" x-model="expr" @keydown="amountKey($event)"
                               inputmode="decimal" autocomplete="off" spellcheck="false" placeholder="0" aria-label="Amount"
                               class="num w-full bg-transparent text-4xl font-semibold tracking-tight text-ink placeholder:text-ink-3/50 focus:outline-none">
                        <span class="num text-sm text-ink-3" x-text="currency.code"></span>
                    </div>
                    <p class="num mt-1 h-5 text-xs text-ink-3">
                        <template x-if="isExpression && preview"><span>= <span class="text-ink" x-text="preview"></span></span></template>
                        <template x-if="!(isExpression && preview)"><span>Type an amount or a sum like <span class="text-ink-2">12.5+4*2</span></span></template>
                    </p>
                </div>

                <div class="space-y-3 border-t border-line px-5 py-4">
                    <div>
                        <input type="text" x-model="description" @input.debounce.250ms="suggest()" class="input" maxlength="255" autocomplete="off"
                               placeholder="Description — e.g. STEG, Meta Ads, Carrefour" aria-label="Description">
                        <p x-show="hint" x-cloak class="mt-1 flex items-center gap-1 text-xs text-brand">
                            <x-ico name="wand-sparkles" class="size-3" /> <span x-text="hint"></span>
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="label" for="qe-wallet" x-text="type === 'transfer' ? 'From' : 'Wallet'">Wallet</label>
                            <select id="qe-wallet" class="input" x-model.number="walletId">
                                @foreach ($wallets->groupBy('workspace.name') as $workspace => $group)
                                    <optgroup label="{{ $workspace }}">
                                        @foreach ($group as $wallet)
                                            <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="type === 'expense'">
                            <label class="label" for="qe-expense-category">Category</label>
                            <select id="qe-expense-category" class="input" x-model="expenseCategoryId">
                                <option value="">Uncategorised</option>
                                @foreach ($expenseCategories as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="type === 'income'" x-cloak>
                            <label class="label" for="qe-income-category">Category</label>
                            <select id="qe-income-category" class="input" x-model="incomeCategoryId">
                                <option value="">Uncategorised</option>
                                @foreach ($incomeCategories as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="type === 'transfer'" x-cloak>
                            <label class="label" for="qe-destination">To</label>
                            <select id="qe-destination" class="input" x-model="destinationWalletId">
                                <option value="">Select wallet…</option>
                                @foreach ($wallets->groupBy('workspace.name') as $workspace => $group)
                                    <optgroup label="{{ $workspace }}">
                                        @foreach ($group as $wallet)
                                            <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="label" for="qe-date">Date</label>
                            <input id="qe-date" type="date" class="input num" x-model="date">
                        </div>
                    </div>

                    <p x-show="error" x-cloak x-text="error" class="field-error" role="alert"></p>
                </div>

                <div class="flex items-center justify-between border-t border-line bg-canvas/40 px-5 py-3">
                    <p class="hidden items-center gap-3 text-[11px] text-ink-3 sm:flex">
                        <span class="flex items-center gap-1"><span class="kbd">Tab</span> next field</span>
                        <span class="flex items-center gap-1"><span class="kbd">Enter</span> save</span>
                    </p>
                    <button type="submit" class="btn btn-primary ml-auto" :disabled="saving">
                        <span x-text="saving ? 'Saving…' : 'Save transaction'">Save transaction</span>
                        <x-ico name="corner-down-left" class="size-3.5" />
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
