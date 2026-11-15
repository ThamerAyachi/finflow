<div>
    @if ($t)
        @php
            $isTransfer = $t->type === \App\Enums\TransactionType::Transfer;
            $isExpense = $t->type === \App\Enums\TransactionType::Expense;
            $locked = $t->debtPayment !== null;
            $reimbursed = (int) $t->reimbursements->sum('base_amount');
        @endphp

        <x-slide-over :title="$t->description ?: $t->type->label()" :subtitle="$t->type->label().' · '.$t->occurred_on->format('D, M j Y')">
            <form wire:submit="save" id="tx-form" class="space-y-4">
                <div class="card glow flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="eyebrow">{{ $isExpense && $reimbursed > 0 ? 'Paid' : 'Amount' }}</p>
                        <x-amount :tx="$t" class="text-2xl font-semibold" />
                    </div>
                    @if ($isExpense && $reimbursed > 0)
                        <div class="text-right">
                            <p class="eyebrow">Net cost</p>
                            <p class="num text-2xl font-semibold">{{ money($t->base_amount - $reimbursed) }}</p>
                        </div>
                    @endif
                </div>

                @if ($t->parent)
                    <button type="button" wire:click="open({{ $t->parent->id }})"
                            class="flex w-full cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 text-left text-xs text-ink-2 hover:border-line-strong">
                        <x-ico name="undo-2" class="size-3.5 text-income" />
                        <span class="flex-1 truncate">Reimbursement of “{{ $t->parent->description }}”</span>
                        <x-ico name="arrow-right" class="size-3.5" />
                    </button>
                @endif

                @if ($locked)
                    <p class="flex items-start gap-2 rounded-lg border border-warn/25 bg-warn/10 px-3 py-2 text-xs text-warn">
                        <x-ico name="info" class="mt-0.5 size-3.5" />
                        <span>Linked to the debt with {{ $t->debtPayment->debt->contact_name }}. Amount and wallet are fixed; delete it to reverse the payment.</span>
                    </p>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="tx-amount">Amount</label>
                        <input id="tx-amount" type="text" inputmode="decimal" class="input num" wire:model="amount" @disabled($locked)>
                        @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="tx-date">Date</label>
                        <input id="tx-date" type="date" class="input num" wire:model="occurredOn">
                        @error('occurredOn') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="label" for="tx-description">Description</label>
                    <input id="tx-description" type="text" class="input" wire:model="description" maxlength="255">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="tx-wallet">{{ $isTransfer ? 'From wallet' : 'Wallet' }}</label>
                        <select id="tx-wallet" class="input" wire:model="walletId" @disabled($locked)>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($isTransfer)
                        <div>
                            <label class="label" for="tx-destination">To wallet</label>
                            <select id="tx-destination" class="input" wire:model="destinationWalletId">
                                @foreach ($wallets as $wallet)
                                    <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <label class="label" for="tx-category">Category</label>
                            <select id="tx-category" class="input" wire:model="categoryId" @disabled($locked || $t->parent)>
                                <option value="">Uncategorised</option>
                                @foreach ($categories as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                @if ($isTransfer && $t->destinationWallet && $t->destinationWallet->currency->code !== $t->wallet->currency->code)
                    <p class="num text-xs text-ink-3">Arrived as {{ money((int) $t->destination_amount, $t->destinationWallet->currency) }} in {{ $t->destinationWallet->name }}</p>
                @endif

                <div>
                    <label class="label" for="tx-notes">Notes</label>
                    <textarea id="tx-notes" rows="2" class="input" wire:model="notes" placeholder="Optional"></textarea>
                </div>
            </form>

            {{-- Split bill / refund history --}}
            @if ($isExpense && ! $t->parent && ! $locked)
                <section class="mt-6 border-t border-line pt-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold"><x-ico name="split" class="size-3.5 text-ink-3" /> Splits & reimbursements</h3>
                        @if ($reimbursed > 0)
                            <span class="num text-xs text-income">{{ money($reimbursed) }} back</span>
                        @endif
                    </div>

                    @forelse ($t->reimbursements as $child)
                        <div class="flex h-9 items-center gap-2 border-b border-line text-xs" wire:key="reimb-{{ $child->id }}">
                            <span class="num w-14 text-ink-3">{{ $child->occurred_on->format('M j') }}</span>
                            <span class="min-w-0 flex-1 truncate text-ink-2">{{ $child->description }}</span>
                            <span class="num text-income">+{{ money($child->amount, $child->wallet->currency) }}</span>
                            <button type="button" class="cursor-pointer text-ink-3 hover:text-expense" wire:click="removeReimbursement({{ $child->id }})" aria-label="Remove reimbursement">
                                <x-ico name="x" class="size-3.5" />
                            </button>
                        </div>
                    @empty
                        <p class="text-xs text-ink-3">Paid for others? Link what they pay back so this category only shows your share.</p>
                    @endforelse

                    <div class="mt-3 grid grid-cols-[1fr_1.2fr_auto] items-start gap-2">
                        <div>
                            <input type="text" inputmode="decimal" class="input num" wire:model="refundAmount" placeholder="Amount back" aria-label="Reimbursement amount"
                                   wire:keydown.enter.prevent="addReimbursement">
                        </div>
                        <select class="input" wire:model="refundWalletId" aria-label="Received into wallet">
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">→ {{ $wallet->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn h-9" wire:click="addReimbursement"><x-ico name="plus" /> Link</button>
                    </div>
                    @error('refundAmount') <p class="field-error">{{ $message }}</p> @enderror
                </section>
            @endif

            <x-slot:footer>
                <button type="button" class="btn btn-danger" wire:click="delete"
                        wire:confirm="Delete this transaction? Wallet balances will be restored.">
                    <x-ico name="trash-2" /> Delete
                </button>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-ghost" wire:click="close">Cancel</button>
                    <button type="submit" form="tx-form" class="btn btn-primary">Save changes</button>
                </div>
            </x-slot:footer>
        </x-slide-over>
    @endif
</div>
