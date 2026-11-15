<div>
    <x-page-header title="Cockpit" :subtitle="$scopeLabel.' · '.$today->format('l, F j')">
        <a href="{{ route('transactions') }}" wire:navigate class="btn"><x-ico name="arrow-left-right" /> Ledger</a>
        <button type="button" class="btn btn-primary" @click="$dispatch('quick-entry')"><x-ico name="plus" /> Add <span class="kbd !border-black/20 !bg-black/10 !text-black/70">N</span></button>
    </x-page-header>

    {{-- ── KPI bento ───────────────────────────────────────────────── --}}
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key figures">
        {{-- Safe to spend: the hero --}}
        <article class="card glow relative overflow-hidden border-brand/25 p-4" x-data="{ open: false }">
            <div class="flex items-center justify-between">
                <p class="eyebrow flex items-center gap-1.5 !text-brand"><x-ico name="shield-check" class="size-3.5" /> Safe to spend today</p>
                <button type="button" class="cursor-pointer text-ink-3 hover:text-ink" @click="open = !open" :aria-expanded="open" aria-label="Show how this is calculated">
                    <x-ico name="info" class="size-3.5" />
                </button>
            </div>
            <p class="num mt-2 text-[28px] leading-8 font-semibold {{ $safe['daily'] < 0 ? 'text-expense' : '' }}">{{ money($safe['daily']) }}</p>
            <p class="mt-1 text-xs text-ink-3"><span class="num text-ink-2">{{ money($safe['pool']) }}</span> free over <span class="num text-ink-2">{{ $safe['days_remaining'] }}</span> days left</p>

            <dl x-show="open" x-cloak x-collapse class="num mt-3 space-y-1 border-t border-line pt-3 text-xs">
                @foreach ([
                    ['Liquid cash', $safe['liquid'], ''],
                    ['Expected income', $safe['expected_income'], '+'],
                    ['Unpaid fixed bills', $safe['unpaid_bills'], '−'],
                    ['Remaining budgets', $safe['budgets_remaining'], '−'],
                    ['Sinking fund contributions', $safe['sinking_contributions'], '−'],
                ] as [$label, $value, $sign])
                    <div class="flex justify-between gap-3">
                        <dt class="font-sans text-ink-3">{{ $label }}</dt>
                        <dd class="text-ink-2">{{ $sign }}{{ money($value) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-3 border-t border-line pt-1">
                    <dt class="font-sans text-ink-3">÷ days remaining</dt>
                    <dd class="text-ink-2">{{ $safe['days_remaining'] }}</dd>
                </div>
            </dl>
        </article>

        <article class="card p-4">
            <p class="eyebrow flex items-center gap-1.5"><x-ico name="scale" class="size-3.5" /> Net worth</p>
            <p class="num mt-2 text-[28px] leading-8 font-semibold">{{ money($netWorth) }}</p>
            <p class="mt-1 text-xs text-ink-3"><span class="num text-ink-2">{{ money($liquid) }}</span> liquid</p>
        </article>

        <article class="card p-4">
            <div class="flex items-center justify-between">
                <p class="eyebrow flex items-center gap-1.5"><x-ico name="arrow-left-right" class="size-3.5" /> Cash flow · {{ $today->format('M') }}</p>
                @if ($flow['savings_rate'] !== null)
                    <span class="badge num {{ $flow['savings_rate'] >= 0 ? 'bg-income/10 text-income' : 'bg-expense/10 text-expense' }}" title="Savings rate">
                        {{ $flow['savings_rate'] }}% saved
                    </span>
                @endif
            </div>
            <p class="num mt-2 text-[28px] leading-8 font-semibold">{{ money($flow['net'], null, true) }}</p>
            <div class="num mt-1 flex gap-3 text-xs">
                <span class="flex items-center gap-1 text-ink-2"><x-ico name="arrow-down-left" class="size-3 text-income" /> In {{ money($flow['income']) }}</span>
                <span class="flex items-center gap-1 text-ink-2"><x-ico name="arrow-up-right" class="size-3 text-expense" /> Out {{ money($flow['expense']) }}</span>
            </div>
        </article>

        <article class="card p-4">
            <div class="flex items-center justify-between">
                <p class="eyebrow flex items-center gap-1.5"><x-ico name="flame" class="size-3.5" /> Burn rate</p>
                @if ($burn['change'] !== null)
                    <span class="badge num {{ $burn['change'] <= 0 ? 'bg-income/10 text-income' : 'bg-expense/10 text-expense' }}" title="vs previous 30 days">
                        <x-ico :name="$burn['change'] <= 0 ? 'trending-down' : 'trending-up'" class="size-3" /> {{ abs($burn['change']) }}%
                    </span>
                @endif
            </div>
            <p class="num mt-2 text-[28px] leading-8 font-semibold">{{ money($burn['daily']) }}<span class="text-sm font-normal text-ink-3">/day</span></p>
            <p class="mt-1 text-xs text-ink-3">
                <span class="num text-ink-2">{{ money($burn['monthly']) }}</span> in 30 days
                @if ($burn['runway_days'] !== null) · <span class="num text-ink-2">{{ $burn['runway_days'] }}</span> days runway @endif
            </p>
        </article>
    </section>

    {{-- ── Charts ──────────────────────────────────────────────────── --}}
    <section class="mt-3 grid grid-cols-1 gap-3 xl:grid-cols-3">
        <article class="card p-4 xl:col-span-2">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[13px] font-semibold">Cash flow <span class="font-normal text-ink-3">· last 30 days, {{ base_currency()->code }}</span></h2>
            </div>
            <div wire:key="cashflow-{{ md5(json_encode($series)) }}" x-data="cashflowChart(@js($series))">
                <div x-ref="canvas" wire:ignore class="-mx-2 min-h-[380px]"></div>
            </div>
        </article>

        <article class="card p-4">
            <h2 class="text-[13px] font-semibold">Spending by category <span class="font-normal text-ink-3">· {{ $today->format('F') }}</span></h2>
            @if ($breakdown->isEmpty())
                <p class="py-16 text-center text-xs text-ink-3">No spending recorded this month yet.</p>
            @else
                <div wire:key="donut-{{ md5(json_encode($donut)) }}" x-data="donutChart(@js($donut))">
                    <div x-ref="canvas" wire:ignore class="min-h-[210px]"></div>
                </div>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($breakdown as $row)
                        <li>
                            <button type="button" @disabled(! $row['id'])
                                    @if ($row['id']) wire:click="$dispatch('open-category', { id: {{ $row['id'] }} })" @endif
                                    class="flex h-7 w-full items-center gap-2 rounded-md px-1.5 text-xs {{ $row['id'] ? 'cursor-pointer hover:bg-white/5' : '' }}">
                                <span class="size-2 rounded-full" style="background-color: {{ $row['color'] }}"></span>
                                <span class="flex-1 truncate text-left text-ink-2">{{ $row['name'] }}</span>
                                <span class="num text-ink-3">{{ $row['share'] }}%</span>
                                <span class="num w-24 text-right">{{ money($row['amount']) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>
    </section>

    {{-- ── Wallet carousel ─────────────────────────────────────────── --}}
    <section class="mt-3" aria-label="Wallets">
        <div class="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2">
            @foreach ($wallets as $wallet)
                @php $line = $sparklines[$wallet->id] ?? []; $up = count($line) > 1 && end($line) >= $line[0]; @endphp
                <a href="{{ route('transactions', ['wallet' => $wallet->id, 'period' => 'all']) }}" wire:navigate wire:key="wallet-{{ $wallet->id }}"
                   class="card w-56 shrink-0 snap-start p-3.5 transition-colors hover:border-line-strong">
                    <div class="flex items-center gap-2">
                        <span class="flex size-7 items-center justify-center rounded-lg" style="color: {{ $wallet->color_hex }}; background-color: {{ $wallet->color_hex }}1f;">
                            <x-ico :name="$wallet->type->icon()" class="size-3.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] leading-4 font-medium">{{ $wallet->name }}</span>
                            <span class="block text-[11px] leading-4 text-ink-3">{{ $wallet->type->label() }}</span>
                        </span>
                        <span class="badge num bg-white/5 text-ink-2">{{ $wallet->currency->code }}</span>
                    </div>
                    <p class="num mt-3 text-lg font-semibold {{ $wallet->balance < 0 ? 'text-expense' : '' }}">{{ $wallet->formattedBalance() }}</p>
                    <x-sparkline :points="$line" :color="$up ? '#22C55E' : '#F43F5E'" class="mt-1 h-8 w-full" />
                    <p class="mt-1 flex items-center justify-between text-[11px] text-ink-3">
                        <span>14 days</span>
                        @unless ($wallet->is_included_in_net_worth) <span>excluded from net worth</span> @endunless
                    </p>
                </a>
            @endforeach
        </div>
    </section>

    {{-- ── Feed + health ───────────────────────────────────────────── --}}
    <section class="mt-1 grid grid-cols-1 gap-3 xl:grid-cols-5">
        <article class="card xl:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                <h2 class="text-[13px] font-semibold">Recent transactions</h2>
                <div class="flex gap-1.5">
                    @foreach (['all' => 'All', 'expense' => 'Expenses', 'income' => 'Income', 'transfer' => 'Transfers'] as $value => $label)
                        <button type="button" wire:click="$set('feed', '{{ $value }}')" @class(['pill', 'pill-active' => $feed === $value])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto border-t border-line">
                <table class="data-table">
                    <tbody>
                        @forelse ($recent as $tx)
                            <tr wire:key="recent-{{ $tx->id }}" wire:click="$dispatch('open-transaction', { id: {{ $tx->id }} })">
                                <td class="num w-16 text-ink-3">{{ $tx->occurred_on->format('M j') }}</td>
                                <td class="max-w-56 truncate font-medium">{{ $tx->description ?: '—' }}</td>
                                <td>
                                    @if ($tx->type->value === 'transfer')
                                        <span class="badge bg-transfer/10 text-transfer"><x-ico name="repeat" class="size-3" /> Transfer</span>
                                    @else
                                        <x-category-badge :category="$tx->category" />
                                    @endif
                                </td>
                                <td class="text-xs text-ink-3">
                                    {{ $tx->wallet->name }}@if ($tx->destinationWallet) <span class="text-ink-3">→</span> {{ $tx->destinationWallet->name }}@endif
                                </td>
                                <td class="text-right"><x-amount :tx="$tx" /></td>
                            </tr>
                        @empty
                            <tr><td class="!h-24 text-center text-ink-3">Nothing here yet — press <span class="kbd">N</span> to add your first transaction.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <a href="{{ route('transactions') }}" wire:navigate class="flex h-9 items-center justify-center gap-1 text-xs text-ink-3 hover:text-ink">
                Open full ledger <x-ico name="arrow-right" class="size-3" />
            </a>
        </article>

        <div class="space-y-3 xl:col-span-2">
            <article class="card p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-[13px] font-semibold">Budget health</h2>
                    <a href="{{ route('budgets') }}" wire:navigate class="text-xs text-ink-3 hover:text-ink">All budgets</a>
                </div>
                <div class="space-y-3">
                    @forelse ($budgets as $row)
                        <button type="button" class="block w-full cursor-pointer text-left" wire:key="bh-{{ $row['budget']->id }}"
                                wire:click="$dispatch('open-category', { id: {{ $row['budget']->category_id }} })">
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="flex items-center gap-1.5 font-medium">
                                    <x-ico :name="$row['budget']->category->icon" class="size-3.5 text-ink-3" /> {{ $row['budget']->category->name }}
                                </span>
                                <span class="num text-ink-2">{{ money($row['spent']) }} <span class="text-ink-3">/ {{ \App\Support\Money::decimal($row['cap'], base_currency()) }}</span></span>
                            </div>
                            <div class="progress" role="progressbar" aria-valuenow="{{ min(100, $row['percent']) }}" aria-valuemin="0" aria-valuemax="100">
                                <span class="{{ ['income' => 'bg-income', 'warn' => 'bg-warn', 'expense' => 'bg-expense'][$row['status']] }}" style="width: {{ min(100, $row['percent']) }}%"></span>
                            </div>
                        </button>
                    @empty
                        <p class="text-xs text-ink-3">No budgets yet. <a href="{{ route('budgets') }}" wire:navigate class="text-brand">Create an envelope</a>.</p>
                    @endforelse
                </div>
            </article>

            <article class="card p-4">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="flex items-center gap-1.5 text-[13px] font-semibold"><x-ico name="calendar-clock" class="size-3.5 text-ink-3" /> Upcoming · next 14 days</h2>
                    <a href="{{ route('debts') }}" wire:navigate class="text-xs text-ink-3 hover:text-ink">Calendar</a>
                </div>
                @forelse ($bills as $rule)
                    @php $days = (int) $today->diffInDays($rule->next_due_date, false); $isIncome = $rule->type->value === 'income'; @endphp
                    <div class="flex h-10 items-center gap-2.5 border-b border-line last:border-0" wire:key="bill-{{ $rule->id }}">
                        <span @class(['num w-14 shrink-0 text-xs', 'text-expense' => $days < 0, 'text-warn' => $days >= 0 && $days <= 2, 'text-ink-3' => $days > 2])>
                            {{ $days < 0 ? abs($days).'d late' : ($days === 0 ? 'today' : 'in '.$days.'d') }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] leading-4 font-medium">{{ $rule->name }}</span>
                            <span class="block truncate text-[11px] leading-4 text-ink-3">{{ $rule->wallet->name }}@if ($rule->auto_post) · auto-post @endif</span>
                        </span>
                        <span class="num text-xs {{ $isIncome ? 'text-income' : '' }}">{{ $isIncome ? '+' : '' }}{{ money($rule->amount, $rule->wallet->currency) }}</span>
                        <button type="button" class="btn btn-sm" wire:click="markPaid({{ $rule->id }})" wire:loading.attr="disabled" wire:target="markPaid({{ $rule->id }})">
                            <x-ico name="check" class="size-3" /> {{ $isIncome ? 'Received' : 'Paid' }}
                        </button>
                    </div>
                @empty
                    <p class="py-2 text-xs text-ink-3">Nothing due in the next two weeks.</p>
                @endforelse
            </article>
        </div>
    </section>
</div>
