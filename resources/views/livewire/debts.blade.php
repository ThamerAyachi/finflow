<div>
    <x-page-header title="Debts & Recurring" subtitle="Who owes whom, and every fixed bill on the calendar.">
        <label class="flex items-center gap-2 text-xs text-ink-3">
            Settle via
            <select wire:model.live="settleWalletId" class="input h-8 w-44" aria-label="Wallet used for settlements">
                @foreach ($wallets as $wallet)
                    <option value="{{ $wallet->id }}">{{ $wallet->name }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" class="btn" wire:click="newRule"><x-ico name="repeat" /> New recurring</button>
        <button type="button" class="btn btn-primary" wire:click="newDebt"><x-ico name="plus" /> New debt</button>
    </x-page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        {{-- ── Debts ─────────────────────────────────────────────── --}}
        <section class="space-y-5">
            <div class="grid grid-cols-2 gap-3">
                <div class="card p-3.5">
                    <p class="eyebrow flex items-center gap-1.5"><x-ico name="arrow-down-left" class="size-3.5 text-income" /> Owed to you</p>
                    <p class="num mt-1 text-lg font-semibold">{{ money($owedToYou) }}</p>
                </div>
                <div class="card p-3.5">
                    <p class="eyebrow flex items-center gap-1.5"><x-ico name="arrow-up-right" class="size-3.5 text-expense" /> You owe</p>
                    <p class="num mt-1 text-lg font-semibold">{{ money($youOwe) }}</p>
                </div>
            </div>

            @foreach ([['Owed to you', $receivables, 'receivable', 'Collect', '#22C55E'], ['You owe', $payables, 'payable', 'Pay', '#F43F5E']] as [$heading, $list, $direction, $verb, $color])
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-[13px] font-semibold">{{ $heading }} <span class="num font-normal text-ink-3">{{ $list->count() }}</span></h2>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="newDebt('{{ $direction }}')"><x-ico name="plus" class="size-3" /> Add</button>
                    </div>

                    <div class="space-y-2">
                        @forelse ($list as $debt)
                            <article class="card p-3.5 {{ $debt->isSettled() ? 'opacity-60' : '' }}" wire:key="debt-{{ $debt->id }}" x-data="{ history: false }">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white/[0.07] text-xs font-semibold uppercase">{{ mb_substr($debt->contact_name, 0, 2) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-[13px] font-semibold">{{ $debt->contact_name }}</h3>
                                        <p class="truncate text-[11px] text-ink-3">
                                            @if ($showWorkspace) {{ $debt->workspace->name }} · @endif
                                            {{ $debt->notes ?: 'No note' }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="num text-sm font-semibold">{{ money($debt->remaining()) }}</p>
                                        <p class="num text-[11px] text-ink-3">of {{ money($debt->principal) }}</p>
                                    </div>
                                </div>

                                <div class="progress mt-3" role="progressbar" aria-valuenow="{{ round($debt->progress()) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ round($debt->progress()) }}% repaid">
                                    <span style="width: {{ $debt->progress() }}%; background-color: {{ $color }};"></span>
                                </div>

                                <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                                    @if ($debt->isSettled())
                                        <span class="badge bg-income/10 text-income"><x-ico name="check-check" class="size-3" /> Settled {{ $debt->settled_at->format('M j') }}</span>
                                    @elseif ($debt->due_date)
                                        <span @class(['num flex items-center gap-1', 'text-expense' => $debt->isOverdue(), 'text-ink-3' => ! $debt->isOverdue()])>
                                            <x-ico :name="$debt->isOverdue() ? 'triangle-alert' : 'calendar'" class="size-3" />
                                            {{ $debt->isOverdue() ? 'Overdue since' : 'Due' }} {{ $debt->due_date->format('M j, Y') }}
                                        </span>
                                    @endif

                                    <button type="button" class="cursor-pointer text-ink-3 hover:text-ink" @click="history = !history" :aria-expanded="history">
                                        <span class="num">{{ $debt->payments->count() }}</span> {{ Str::plural('payment', $debt->payments->count()) }}
                                    </button>

                                    <div class="ml-auto flex gap-1.5">
                                        @unless ($debt->isSettled())
                                            <button type="button" class="btn btn-sm" wire:click="startPayment({{ $debt->id }})">Partial</button>
                                            <button type="button" class="btn btn-sm" wire:click="settle({{ $debt->id }})"
                                                    wire:confirm="{{ $verb }} the remaining {{ money($debt->remaining()) }} via the selected wallet?">
                                                <x-ico name="check" class="size-3" /> Settle
                                            </button>
                                        @endunless
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="deleteDebt({{ $debt->id }})" aria-label="Delete debt"
                                                wire:confirm="Remove this debt? Wallet transactions already posted stay in the ledger."><x-ico name="trash-2" class="size-3" /></button>
                                    </div>
                                </div>

                                @if ($payingId === $debt->id)
                                    <form wire:submit="pay" class="mt-3 flex items-start gap-2 border-t border-line pt-3">
                                        <div class="flex-1">
                                            <input type="text" inputmode="decimal" class="input num h-8" wire:model="payAmount" placeholder="Amount ({{ base_currency()->code }})" aria-label="Payment amount" autofocus>
                                            @error('payAmount') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <button type="submit" class="btn btn-primary">{{ $verb }}</button>
                                        <button type="button" class="btn btn-ghost" wire:click="$set('payingId', null)">Cancel</button>
                                    </form>
                                @endif

                                <div x-show="history" x-cloak x-collapse class="mt-3 border-t border-line pt-2">
                                    @forelse ($debt->payments as $payment)
                                        <button type="button" class="flex h-7 w-full cursor-pointer items-center justify-between text-xs hover:text-ink"
                                                @if ($payment->transaction_id) wire:click="$dispatch('open-transaction', { id: {{ $payment->transaction_id }} })" @endif>
                                            <span class="num text-ink-3">{{ $payment->paid_on->format('M j, Y') }}</span>
                                            <span class="num text-ink-2">{{ money($payment->amount) }}</span>
                                        </button>
                                    @empty
                                        <p class="py-1 text-xs text-ink-3">No payments yet.</p>
                                    @endforelse
                                </div>
                            </article>
                        @empty
                            <p class="card p-5 text-center text-xs text-ink-3">Nothing outstanding.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach

            <label class="flex cursor-pointer items-center gap-2 text-xs text-ink-3">
                <input type="checkbox" class="accent-brand" wire:model.live="showSettled"> Show settled debts
            </label>
        </section>

        {{-- ── Recurring calendar ────────────────────────────────── --}}
        <section class="space-y-3">
            <article class="card p-4">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <h2 class="text-[13px] font-semibold">{{ $monthDate->format('F Y') }}</h2>
                        <p class="text-[11px] text-ink-3"><span class="num text-ink-2">{{ money($calendar['dueTotal']) }}</span> in bills still due this month</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" class="btn btn-sm" wire:click="shiftMonth(-1)" aria-label="Previous month"><x-ico name="chevron-left" class="size-3.5" /></button>
                        <button type="button" class="btn btn-sm" wire:click="$set('month', '{{ $today->format('Y-m') }}')">Today</button>
                        <button type="button" class="btn btn-sm" wire:click="shiftMonth(1)" aria-label="Next month"><x-ico name="chevron-right" class="size-3.5" /></button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-line bg-line">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="eyebrow bg-surface py-1.5 text-center !text-[10px]">{{ $weekday }}</div>
                    @endforeach
                    @foreach ($calendar['weeks'] as $week)
                        @foreach ($week as $day)
                            <div @class(['min-h-[68px] bg-surface p-1', 'opacity-35' => ! $day['inMonth']])>
                                <span @class(['num inline-flex size-5 items-center justify-center rounded-full text-[11px]', 'bg-brand font-semibold text-black' => $day['isToday'], 'text-ink-3' => ! $day['isToday']])>{{ $day['date']->day }}</span>
                                @foreach (array_slice($day['events'], 0, 2) as $event)
                                    <span title="{{ $event['name'] }}{{ $event['paid'] ? ' — paid' : ($event['overdue'] ? ' — overdue' : ' — due') }}"
                                          @class([
                                              'mt-0.5 flex items-center gap-1 truncate rounded px-1 text-[10px] leading-4',
                                              'bg-white/5 text-ink-3 line-through' => $event['paid'],
                                              'bg-expense/15 text-expense' => ! $event['paid'] && $event['overdue'],
                                              'bg-income/10 text-income' => ! $event['paid'] && ! $event['overdue'] && $event['income'],
                                              'bg-transfer/10 text-transfer' => ! $event['paid'] && ! $event['overdue'] && ! $event['income'],
                                          ])>
                                        <span class="truncate">{{ $event['name'] }}</span>
                                    </span>
                                @endforeach
                                @if (count($day['events']) > 2)
                                    <span class="num mt-0.5 block px-1 text-[10px] text-ink-3">+{{ count($day['events']) - 2 }} more</span>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>

                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-ink-3">
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-transfer"></span> Bill due</li>
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-income"></span> Income expected</li>
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-expense"></span> Overdue</li>
                    <li class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-white/20"></span> <span class="line-through">Posted</span></li>
                </ul>
            </article>

            <article class="card">
                <h2 class="px-4 py-3 text-[13px] font-semibold">Recurring rules <span class="num font-normal text-ink-3">{{ $rules->count() }}</span></h2>
                <div class="overflow-x-auto border-t border-line">
                    <table class="data-table">
                        <tbody>
                            @forelse ($rules as $rule)
                                @php $days = (int) $today->diffInDays($rule->next_due_date, false); $isIncome = $rule->type->value === 'income'; @endphp
                                <tr wire:key="rule-{{ $rule->id }}" wire:click="editRule({{ $rule->id }})">
                                    <td @class(['num w-20 text-xs', 'text-expense' => $days < 0, 'text-warn' => $days >= 0 && $days <= 2, 'text-ink-3' => $days > 2])>
                                        {{ $rule->next_due_date->format('M j') }}
                                    </td>
                                    <td>
                                        <span class="block max-w-44 truncate leading-4 font-medium">{{ $rule->name }}</span>
                                        <span class="block text-[11px] leading-4 text-ink-3">{{ $rule->frequency->label() }} · {{ $rule->wallet->name }}@if ($rule->auto_post) · auto @endif</span>
                                    </td>
                                    <td class="num text-right {{ $isIncome ? 'text-income' : '' }}">{{ $isIncome ? '+' : '' }}{{ money($rule->amount, $rule->wallet->currency) }}</td>
                                    <td class="w-px" @click.stop>
                                        <div class="flex justify-end gap-1.5">
                                            <button type="button" class="btn btn-sm" wire:click="markPaid({{ $rule->id }})"><x-ico name="check" class="size-3" /> {{ $isIncome ? 'Received' : 'Paid' }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="skip({{ $rule->id }})" title="Skip this occurrence">Skip</button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr class="!cursor-default"><td class="!h-20 text-center text-ink-3">No recurring rules yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </div>

    {{-- Forms --}}
    @if ($form === 'debt')
        <x-modal title="New debt" submit="saveDebt">
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-canvas p-0.5">
                @foreach (['receivable' => 'I lent money', 'payable' => 'I borrowed money'] as $value => $label)
                    <button type="button" wire:click="$set('direction', '{{ $value }}')"
                            @class(['h-8 cursor-pointer rounded-md text-xs font-medium transition-colors', 'bg-raised text-ink' => $direction === $value, 'text-ink-3 hover:text-ink-2' => $direction !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($showWorkspace)
                <div>
                    <label class="label" for="d-workspace">Workspace</label>
                    <select id="d-workspace" class="input" wire:model="workspaceId">
                        @foreach ($workspaces as $workspace) <option value="{{ $workspace->id }}">{{ $workspace->name }}</option> @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="label" for="d-contact">{{ $direction === 'receivable' ? 'Who owes you?' : 'Who do you owe?' }}</label>
                <input id="d-contact" type="text" class="input" wire:model="contactName" placeholder="Contact name">
                @error('contactName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="d-amount">Amount ({{ base_currency()->code }})</label>
                    <input id="d-amount" type="text" inputmode="decimal" class="input num" wire:model="principal" placeholder="0.000">
                    @error('principal') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="d-due">Due date</label>
                    <input id="d-due" type="date" class="input num" wire:model="dueDate">
                    @error('dueDate') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="d-wallet">{{ $direction === 'receivable' ? 'Paid out from' : 'Received into' }}</label>
                <select id="d-wallet" class="input" wire:model="debtWalletId">
                    <option value="">No wallet movement (already happened / on credit)</option>
                    @foreach ($wallets as $wallet) <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option> @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="d-notes">Note</label>
                <input id="d-notes" type="text" class="input" wire:model="debtNotes" placeholder="Optional">
            </div>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save debt</button>
            </x-slot:footer>
        </x-modal>
    @elseif ($form === 'rule')
        <x-modal :title="$editingId ? 'Edit recurring rule' : 'New recurring rule'" submit="saveRule">
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-canvas p-0.5">
                @foreach (['expense' => 'Bill / outflow', 'income' => 'Income / inflow'] as $value => $label)
                    <button type="button" wire:click="$set('ruleType', '{{ $value }}')"
                            @class(['h-8 cursor-pointer rounded-md text-xs font-medium transition-colors', 'bg-raised text-ink' => $ruleType === $value, 'text-ink-3 hover:text-ink-2' => $ruleType !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <div>
                <label class="label" for="r-name">Name</label>
                <input id="r-name" type="text" class="input" wire:model="ruleName" placeholder="Netflix, Rent, Salary…">
                @error('ruleName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="r-amount">Amount</label>
                    <input id="r-amount" type="text" inputmode="decimal" class="input num" wire:model="ruleAmount" placeholder="0.00">
                    @error('ruleAmount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="r-wallet">Wallet</label>
                    <select id="r-wallet" class="input" wire:model="ruleWalletId">
                        @foreach ($wallets as $wallet) <option value="{{ $wallet->id }}">{{ $wallet->name }} · {{ $wallet->currency->code }}</option> @endforeach
                    </select>
                    @error('ruleWalletId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="r-frequency">Repeats</label>
                    <select id="r-frequency" class="input" wire:model="ruleFrequency">
                        @foreach ($frequencies as $frequency) <option value="{{ $frequency->value }}">{{ $frequency->label() }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="r-next">Next due</label>
                    <input id="r-next" type="date" class="input num" wire:model="ruleNextDue">
                    @error('ruleNextDue') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="r-category">Category</label>
                <select id="r-category" class="input" wire:model="ruleCategoryId">
                    <option value="">Uncategorised</option>
                    @foreach ($categories->where('type', $ruleType) as $option) <option value="{{ $option['id'] }}">{{ $option['label'] }}</option> @endforeach
                </select>
            </div>
            <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                <input type="checkbox" class="accent-brand" wire:model="ruleAutoPost"> Post automatically on the due date
            </label>

            <x-slot:footer>
                @if ($editingId)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteRule" wire:confirm="Remove this recurring rule?"><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save rule</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
