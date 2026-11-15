<div>
    @php $tone = ['income' => ['bg-income', 'text-income'], 'warn' => ['bg-warn', 'text-warn'], 'expense' => ['bg-expense', 'text-expense']]; @endphp

    <x-page-header title="Budgets & Sinking Funds" :subtitle="$today->format('F Y').' · day '.$today->day.' of '.$today->daysInMonth">
        <button type="button" class="btn" wire:click="newFund"><x-ico name="target" /> New fund</button>
        <button type="button" class="btn btn-primary" wire:click="newBudget"><x-ico name="plus" /> New budget</button>
    </x-page-header>

    {{-- Summary strip --}}
    <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @php $overall = $totalCap > 0 ? round($totalSpent / $totalCap * 100) : 0; @endphp
        <div class="card p-3.5">
            <p class="eyebrow">Budgeted</p>
            <p class="num mt-1 text-lg font-semibold">{{ money($totalCap) }}</p>
        </div>
        <div class="card p-3.5">
            <p class="eyebrow">Spent so far</p>
            <p class="num mt-1 text-lg font-semibold">{{ money($totalSpent) }} <span class="text-xs font-normal text-ink-3">{{ $overall }}%</span></p>
        </div>
        <div class="card p-3.5">
            <p class="eyebrow">Left to spend</p>
            <p class="num mt-1 text-lg font-semibold {{ $totalCap - $totalSpent < 0 ? 'text-expense' : 'text-income' }}">{{ money($totalCap - $totalSpent) }}</p>
        </div>
        <div class="card p-3.5">
            <p class="eyebrow">Funds · still to set aside</p>
            <p class="num mt-1 text-lg font-semibold">{{ money($fundsDue) }} <span class="text-xs font-normal text-ink-3">this month</span></p>
        </div>
    </section>

    {{-- Envelopes --}}
    <h2 class="mb-2 flex items-center gap-2 text-[13px] font-semibold">Monthly envelopes <span class="num font-normal text-ink-3">{{ $budgets->count() }}</span></h2>
    <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($budgets as $row)
            @php $budget = $row['budget']; [$bar] = $tone[$row['status']]; @endphp
            <article class="card p-4" wire:key="budget-{{ $budget->id }}">
                <div class="flex items-start gap-3">
                    <button type="button" wire:click="$dispatch('open-category', { id: {{ $budget->category_id }} })" aria-label="Open {{ $budget->category->name }}"
                            class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg transition hover:brightness-125"
                            style="color: {{ $budget->category->color_hex }}; background-color: {{ $budget->category->color_hex }}1f;">
                        <x-ico :name="$budget->category->icon" class="size-4" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-sm font-semibold">{{ $budget->category->name }}</h3>
                        <p class="truncate text-[11px] text-ink-3">
                            @if ($showWorkspace) {{ $budget->workspace->name }} · @endif
                            @if ($row['carry'] > 0) <span class="num">+{{ money($row['carry']) }}</span> rolled over @elseif ($budget->rollover) rollover on @else resets monthly @endif
                        </p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="editBudget({{ $budget->id }})" aria-label="Edit budget"><x-ico name="pencil" class="size-3.5" /></button>
                </div>

                <div class="mt-4 flex items-baseline justify-between">
                    <p class="num text-xl font-semibold">{{ money($row['spent']) }}</p>
                    <p class="num text-xs text-ink-3">of {{ money($row['cap']) }}</p>
                </div>

                {{-- Progress with a tick marking where spending "should" be today --}}
                <div class="relative mt-2">
                    <div class="progress !h-2" role="progressbar" aria-valuenow="{{ min(100, $row['percent']) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['percent'] }}% of budget used">
                        <span class="{{ $bar }}" style="width: {{ min(100, $row['percent']) }}%"></span>
                    </div>
                    <span class="absolute -top-1 h-4 w-0.5 rounded bg-ink/70" style="left: {{ $row['pace_marker'] }}%" title="Today — {{ $row['pace_marker'] }}% of the month"></span>
                </div>

                <div class="mt-2.5 flex items-center justify-between gap-2 text-xs">
                    {{-- Pace tone is independent of the bar: under 75% used can still be ahead of schedule. --}}
                    @php $over = $row['spent'] > $row['cap']; $behind = $row['days_ahead'] < 0; @endphp
                    <span @class(['flex items-center gap-1.5', 'text-expense' => $over, 'text-warn' => ! $over && $behind, 'text-income' => ! $over && ! $behind])>
                        <x-ico :name="$over ? 'flame' : ($behind ? 'trending-up' : 'trending-down')" class="size-3.5" />
                        {{ $row['pace_label'] }}
                    </span>
                    <span class="num text-ink-2">{{ $row['percent'] }}%</span>
                </div>
                <p class="num mt-1 text-[11px] text-ink-3">
                    @if ($row['remaining'] > 0)
                        {{ money($row['remaining']) }} left · {{ money((int) floor($row['remaining'] / max(1, $today->daysInMonth - $today->day + 1))) }}/day
                    @else
                        Envelope is empty
                    @endif
                </p>
            </article>
        @empty
            <div class="card col-span-full p-8 text-center text-ink-3">
                No budgets yet. <button type="button" class="cursor-pointer text-brand" wire:click="newBudget">Create your first envelope</button>.
            </div>
        @endforelse
    </section>

    {{-- Sinking funds --}}
    <h2 class="mt-7 mb-2 flex items-center gap-2 text-[13px] font-semibold">
        Sinking funds <span class="num font-normal text-ink-3">{{ money($fundsSaved) }} saved</span>
    </h2>
    <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($funds as $fund)
            @php
                $due = $fund->dueThisMonth($today);
                $monthly = $fund->monthlyAllocation($today);
                $done = $fund->remaining() === 0;
            @endphp
            <article class="card p-4" wire:key="fund-{{ $fund->id }}">
                <div class="flex items-start gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg" style="color: {{ $fund->color_hex }}; background-color: {{ $fund->color_hex }}1f;">
                        <x-ico :name="$fund->icon" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-sm font-semibold">{{ $fund->name }}</h3>
                        <p class="num truncate text-[11px] text-ink-3">
                            @if ($showWorkspace) <span class="font-sans">{{ $fund->workspace->name }} ·</span> @endif
                            by {{ $fund->target_date->format('M Y') }} · {{ $fund->monthsRemaining($today) }} mo left
                        </p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="editFund({{ $fund->id }})" aria-label="Edit fund"><x-ico name="pencil" class="size-3.5" /></button>
                </div>

                <div class="mt-4 flex items-baseline justify-between">
                    <p class="num text-xl font-semibold">{{ money($fund->saved_amount) }}</p>
                    <p class="num text-xs text-ink-3">of {{ money($fund->target_amount) }}</p>
                </div>
                <div class="progress mt-2 !h-2" role="progressbar" aria-valuenow="{{ round($fund->progress()) }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ $fund->progress() }}%; background-color: {{ $fund->color_hex }};"></span>
                </div>

                <div class="mt-2.5 flex items-center justify-between text-xs">
                    @if ($done)
                        <span class="flex items-center gap-1.5 text-income"><x-ico name="check-check" class="size-3.5" /> Fully funded</span>
                    @elseif ($due === 0)
                        <span class="flex items-center gap-1.5 text-income"><x-ico name="check" class="size-3.5" /> This month covered</span>
                    @else
                        <span class="text-ink-2">Set aside <span class="num text-ink">{{ money($due) }}</span> this month</span>
                    @endif
                    <span class="num text-ink-3">{{ round($fund->progress()) }}%</span>
                </div>
                <p class="num mt-1 text-[11px] text-ink-3">Auto-allocation {{ money($monthly) }}/month</p>

                @if ($contributingId === $fund->id)
                    <form wire:submit="contribute" class="mt-3 flex items-start gap-2 border-t border-line pt-3">
                        <div class="flex-1">
                            <input type="text" inputmode="decimal" class="input num h-8" wire:model="contribution" aria-label="Contribution amount" autofocus>
                            @error('contribution') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">Add</button>
                        <button type="button" class="btn btn-ghost" wire:click="$set('contributingId', null)">Cancel</button>
                    </form>
                @elseif (! $done)
                    <button type="button" class="btn mt-3 w-full" wire:click="startContribution({{ $fund->id }})"><x-ico name="plus" /> Contribute</button>
                @endif
            </article>
        @empty
            <div class="card col-span-full p-8 text-center text-ink-3">
                No sinking funds yet. <button type="button" class="cursor-pointer text-brand" wire:click="newFund">Start saving for a goal</button>.
            </div>
        @endforelse
    </section>

    {{-- Forms --}}
    @if ($form === 'budget')
        <x-modal :title="$editingId ? 'Edit budget' : 'New monthly budget'" submit="saveBudget">
            @if ($showWorkspace)
                <div>
                    <label class="label" for="b-workspace">Workspace</label>
                    <select id="b-workspace" class="input" wire:model="workspaceId">
                        @foreach ($workspaces as $workspace) <option value="{{ $workspace->id }}">{{ $workspace->name }}</option> @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="label" for="b-category">Category</label>
                <select id="b-category" class="input" wire:model="categoryId">
                    <option value="">Select a category…</option>
                    @foreach ($categories as $option) <option value="{{ $option['id'] }}">{{ $option['label'] }}</option> @endforeach
                </select>
                <p class="mt-1 text-[11px] text-ink-3">A budget on a parent category also covers its sub-categories.</p>
                @error('categoryId') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="b-amount">Monthly cap ({{ base_currency()->code }})</label>
                <input id="b-amount" type="text" inputmode="decimal" class="input num" wire:model="amount" placeholder="0.000">
                @error('amount') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                <input type="checkbox" class="accent-brand" wire:model="rollover"> Roll unspent budget over to the next month
            </label>

            <x-slot:footer>
                @if ($editingId)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteBudget" wire:confirm="Remove this budget?"><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save budget</button>
            </x-slot:footer>
        </x-modal>
    @elseif ($form === 'fund')
        <x-modal :title="$editingId ? 'Edit sinking fund' : 'New sinking fund'" submit="saveFund">
            @if ($showWorkspace)
                <div>
                    <label class="label" for="f-workspace">Workspace</label>
                    <select id="f-workspace" class="input" wire:model="workspaceId">
                        @foreach ($workspaces as $workspace) <option value="{{ $workspace->id }}">{{ $workspace->name }}</option> @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="label" for="f-name">Goal</label>
                <input id="f-name" type="text" class="input" wire:model="name" placeholder="Annual car maintenance">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="f-target">Target ({{ base_currency()->code }})</label>
                    <input id="f-target" type="text" inputmode="decimal" class="input num" wire:model="targetAmount" placeholder="0.000">
                    @error('targetAmount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="f-date">Target date</label>
                    <input id="f-date" type="date" class="input num" wire:model="targetDate">
                    @error('targetDate') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-[1fr_auto] gap-3">
                <div>
                    <label class="label" for="f-icon">Icon</label>
                    <select id="f-icon" class="input" wire:model="icon">
                        @foreach ($icons as $option) <option value="{{ $option }}">{{ str_replace('-', ' ', $option) }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="f-color">Colour</label>
                    <input id="f-color" type="color" class="h-9 w-14 cursor-pointer rounded-lg border border-line bg-canvas p-1" wire:model="color">
                </div>
            </div>

            <x-slot:footer>
                @if ($editingId)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteFund" wire:confirm="Remove this fund and its contribution history?"><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save fund</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
