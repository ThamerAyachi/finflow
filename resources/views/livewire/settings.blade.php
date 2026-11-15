<div>
    <x-page-header title="Settings" subtitle="Wallets, currencies, workspaces, categories and your account.">
        @if ($tab === 'wallets')
            <button type="button" class="btn btn-primary" wire:click="newWallet"><x-ico name="plus" /> New wallet</button>
        @elseif ($tab === 'currencies')
            <button type="button" class="btn btn-primary" wire:click="newCurrency"><x-ico name="plus" /> New currency</button>
        @elseif ($tab === 'workspaces')
            <button type="button" class="btn btn-primary" wire:click="newWorkspace"><x-ico name="plus" /> New workspace</button>
        @elseif ($tab === 'categories')
            <button type="button" class="btn btn-primary" wire:click="newCategory"><x-ico name="plus" /> New category</button>
        @endif
    </x-page-header>

    {{-- Tabs --}}
    <nav class="-mx-1 mb-5 flex gap-1 overflow-x-auto border-b border-line px-1" role="tablist" aria-label="Settings sections">
        @foreach ($tabs as $key => [$label, $icon])
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        '-mb-px flex h-9 cursor-pointer items-center gap-1.5 border-b-2 px-3 text-[13px] font-medium whitespace-nowrap transition-colors',
                        'border-brand text-ink' => $tab === $key,
                        'border-transparent text-ink-3 hover:text-ink-2' => $tab !== $key,
                    ])>
                <x-ico :name="$icon" class="size-3.5 {{ $tab === $key ? 'text-brand' : '' }}" /> {{ $label }}
            </button>
        @endforeach
    </nav>

    {{-- ── Wallets ─────────────────────────────────────────────── --}}
    @if ($tab === 'wallets')
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Wallet</th>
                            <th>Workspace</th>
                            <th>Currency</th>
                            <th class="text-right">Balance</th>
                            <th class="text-right">In {{ $base->code }}</th>
                            <th class="text-center">In net worth</th>
                            <th class="w-px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($wallets as $wallet)
                            <tr wire:key="sw-{{ $wallet->id }}" wire:click="editWallet({{ $wallet->id }})" @class(['opacity-50' => $wallet->is_archived])>
                                <td>
                                    <span class="flex items-center gap-2.5">
                                        <span class="flex size-6 items-center justify-center rounded-md" style="color: {{ $wallet->color_hex }}; background-color: {{ $wallet->color_hex }}1f;">
                                            <x-ico :name="$wallet->type->icon()" class="size-3.5" />
                                        </span>
                                        <span class="font-medium">{{ $wallet->name }}</span>
                                        <span class="text-xs text-ink-3">{{ $wallet->type->label() }}</span>
                                        @if ($wallet->is_archived) <span class="badge bg-white/5 text-ink-3">archived</span> @endif
                                    </span>
                                </td>
                                <td class="text-xs text-ink-2">{{ $wallet->workspace->name }}</td>
                                <td><span class="badge num bg-white/5 text-ink-2">{{ $wallet->currency->code }}</span></td>
                                <td class="num text-right {{ $wallet->balance < 0 ? 'text-expense' : '' }}">{{ $wallet->formattedBalance() }}</td>
                                <td class="num text-right text-ink-3">{{ $wallet->currency->code === $base->code ? '—' : money($wallet->baseBalance()) }}</td>
                                <td class="text-center" @click.stop>
                                    <button type="button" role="switch" aria-checked="{{ $wallet->is_included_in_net_worth ? 'true' : 'false' }}" aria-label="Include {{ $wallet->name }} in net worth"
                                            wire:click="toggleNetWorth({{ $wallet->id }})"
                                            @class(['relative inline-flex h-4.5 w-8 cursor-pointer rounded-full transition-colors', 'bg-brand' => $wallet->is_included_in_net_worth, 'bg-white/15' => ! $wallet->is_included_in_net_worth])>
                                        <span @class(['absolute top-0.5 size-3.5 rounded-full bg-white transition-all', 'left-4' => $wallet->is_included_in_net_worth, 'left-0.5' => ! $wallet->is_included_in_net_worth])></span>
                                    </button>
                                </td>
                                <td @click.stop>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleArchive({{ $wallet->id }})">{{ $wallet->is_archived ? 'Restore' : 'Archive' }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr class="!cursor-default hover:!bg-transparent">
                                <td colspan="7" class="!h-40 text-center text-ink-3">
                                    No wallets yet. <button type="button" class="cursor-pointer text-brand" wire:click="newWallet">Add your first wallet</button>
                                    — a cash wallet, a bank account or a card.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <p class="mt-2 text-[11px] text-ink-3">Foreign-currency wallets are valued with the rates on the <button type="button" class="cursor-pointer text-ink-2 underline decoration-line-strong underline-offset-2 hover:text-ink" wire:click="$set('tab', 'currencies')">Currencies</button> tab.</p>
    @endif

    {{-- ── Currencies ──────────────────────────────────────────── --}}
    @if ($tab === 'currencies')
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Currency</th>
                            <th>Symbol</th>
                            <th class="text-right">Decimals</th>
                            <th class="text-right">1 unit = ? {{ $base->code }}</th>
                            <th class="text-right">Example</th>
                            <th class="text-right">Wallets</th>
                            <th class="w-px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($currencies as $currency)
                            <tr wire:key="cur-{{ $currency->code }}" wire:click="editCurrency('{{ $currency->code }}')">
                                <td>
                                    <span class="flex items-center gap-2.5">
                                        <span class="badge num bg-white/[0.07] text-ink">{{ $currency->code }}</span>
                                        <span class="font-medium">{{ $currency->name }}</span>
                                        @if ($currency->is_base) <span class="badge bg-brand/10 text-brand">base</span> @endif
                                    </span>
                                </td>
                                <td class="num text-ink-2">{{ $currency->symbol }}</td>
                                <td class="num text-right text-ink-2">{{ $currency->decimals }}</td>
                                <td class="num text-right">{{ $currency->is_base ? '1' : rtrim(rtrim(number_format($currency->exchange_rate, 6, '.', ','), '0'), '.') }}</td>
                                <td class="num text-right text-ink-3">{{ money(1234 * $currency->minorPerMajor() + intdiv($currency->minorPerMajor(), 2), $currency) }}</td>
                                <td class="num text-right text-ink-2">{{ $currency->wallets_count }}</td>
                                <td @click.stop>
                                    @unless ($currency->is_base)
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="makeBase('{{ $currency->code }}')" @disabled($baseLocked)
                                                title="{{ $baseLocked ? 'Locked: money has already been recorded in '.$base->code : 'Use as base currency' }}"
                                                wire:confirm="Make {{ $currency->code }} the base currency? All reports, budgets and debts will be in {{ $currency->code }}.">
                                            Make base
                                        </button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <p class="flex items-start gap-2 rounded-lg border border-line px-3 py-2.5 text-xs text-ink-2">
                <x-ico name="info" class="mt-0.5 size-3.5 shrink-0 text-ink-3" />
                <span>The <span class="text-ink">base currency ({{ $base->code }})</span> is what net worth, budgets, debts and reports are shown in.
                    Rates convert other currencies into it; updating a rate re-values every wallet in that currency. Transactions already recorded keep the rate they were saved with.</span>
            </p>
            @if ($baseLocked)
                <p class="flex items-start gap-2 rounded-lg border border-warn/25 bg-warn/10 px-3 py-2.5 text-xs text-warn">
                    <x-ico name="triangle-alert" class="mt-0.5 size-3.5 shrink-0" />
                    <span>The base currency is locked because transactions, budgets, debts or funds are already stored in {{ $base->code }}.</span>
                </p>
            @endif
        </div>
    @endif

    {{-- ── Workspaces ──────────────────────────────────────────── --}}
    @if ($tab === 'workspaces')
        <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($workspaces as $workspace)
                <button type="button" wire:key="ws-{{ $workspace->id }}" wire:click="editWorkspace({{ $workspace->id }})"
                        class="card flex cursor-pointer items-center gap-3 p-4 text-left transition-colors hover:border-line-strong">
                    <span class="flex size-9 items-center justify-center rounded-lg" style="color: {{ $workspace->color_hex }}; background-color: {{ $workspace->color_hex }}1f;">
                        <x-ico :name="$workspace->type->icon()" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ $workspace->name }}</span>
                        <span class="block text-xs text-ink-3">{{ ucfirst($workspace->type->value) }} · <span class="num">{{ $workspace->wallets_count }}</span> {{ Str::plural('wallet', $workspace->wallets_count) }}</span>
                    </span>
                    <x-ico name="pencil" class="size-3.5 text-ink-3" />
                </button>
            @empty
                <div class="card col-span-full p-8 text-center text-ink-3">
                    No workspaces yet. <button type="button" class="cursor-pointer text-brand" wire:click="newWorkspace">Create one</button> — e.g. Personal and Business.
                </div>
            @endforelse
        </section>
        <p class="mt-3 text-xs text-ink-3">Workspaces keep business money out of personal metrics. Switch between them with the selector at the top of the sidebar.</p>
    @endif

    {{-- ── Categories & rules ──────────────────────────────────── --}}
    @if ($tab === 'categories')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <section class="card">
                <div class="flex items-start justify-between gap-3 px-4 py-3">
                    <div>
                        <h2 class="text-[13px] font-semibold">Categories</h2>
                        <p class="mt-0.5 text-xs text-ink-3">Click one to edit it. Sub-categories roll up into their parent in reports and budgets.</p>
                    </div>
                    @if ($categories->isNotEmpty())
                        <button type="button" class="btn btn-sm shrink-0" wire:click="installStarterCategories" title="Adds any missing categories from the starter set">
                            <x-ico name="sparkles" class="size-3" /> Starter set
                        </button>
                    @endif
                </div>
                <div class="space-y-3 border-t border-line px-4 py-3">
                    @forelse ($categories->groupBy(fn ($c) => $c->type->value) as $type => $group)
                        <div>
                            <p class="eyebrow mb-1.5">{{ $type }}</p>
                            <div class="space-y-1.5">
                                @foreach ($group as $parent)
                                    <div class="flex flex-wrap items-center gap-1.5" wire:key="cat-{{ $parent->id }}">
                                        <button type="button" wire:click="editCategory({{ $parent->id }})" class="badge h-6 cursor-pointer px-2 text-xs transition hover:brightness-125"
                                                style="color: {{ $parent->color_hex }}; background-color: {{ $parent->color_hex }}1f;">
                                            <x-ico :name="$parent->icon" class="size-3" /> {{ $parent->name }}
                                        </button>
                                        @foreach ($parent->children as $child)
                                            <button type="button" wire:click="editCategory({{ $child->id }})" wire:key="cat-{{ $child->id }}"
                                                    class="badge h-6 cursor-pointer border border-line px-2 text-xs text-ink-2 transition hover:border-line-strong hover:text-ink">
                                                <x-ico :name="$child->icon" class="size-3" /> {{ $child->name }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="py-6 text-center">
                            <p class="text-xs text-ink-3">No categories yet.</p>
                            <button type="button" class="btn btn-primary mt-3" wire:click="installStarterCategories"><x-ico name="sparkles" /> Add starter categories</button>
                            <p class="mt-2 text-[11px] text-ink-3">Food, Transport, Housing, Salary, Business Ops and more. You can edit them afterwards.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="px-4 py-3">
                    <h2 class="flex items-center gap-1.5 text-[13px] font-semibold"><x-ico name="wand-sparkles" class="size-3.5 text-brand" /> Auto-categorisation rules</h2>
                    <p class="mt-0.5 text-xs text-ink-3">When a description contains the keyword, quick entry and CSV import fill in the category and wallet.</p>
                </div>

                <form wire:submit="addRule" class="grid grid-cols-1 gap-2 border-t border-line px-4 py-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <input type="text" class="input h-8" wire:model="keyword" placeholder="Keyword, e.g. STEG" aria-label="Keyword">
                    <select class="input h-8" wire:model="ruleCategoryId" aria-label="Category">
                        <option value="">Category…</option>
                        @foreach ($categoryOptions as $option) <option value="{{ $option['id'] }}">{{ $option['label'] }}</option> @endforeach
                    </select>
                    <select class="input h-8" wire:model="ruleWalletId" aria-label="Wallet">
                        <option value="">Any wallet</option>
                        @foreach ($wallets->where('is_archived', false) as $wallet) <option value="{{ $wallet->id }}">{{ $wallet->name }}</option> @endforeach
                    </select>
                    <button type="submit" class="btn"><x-ico name="plus" /> Add</button>
                    @error('keyword') <p class="field-error sm:col-span-4">{{ $message }}</p> @enderror
                    @error('ruleCategoryId') <p class="field-error sm:col-span-4">{{ $message }}</p> @enderror
                </form>

                <div class="border-t border-line">
                    @forelse ($rules as $rule)
                        <div class="flex h-9 items-center gap-2.5 border-b border-line px-4 last:border-0 {{ $rule->is_active ? '' : 'opacity-50' }}" wire:key="trule-{{ $rule->id }}">
                            <span class="text-xs text-ink-3">contains</span>
                            <span class="num rounded bg-white/[0.07] px-1.5 text-xs">{{ $rule->keyword }}</span>
                            <x-ico name="arrow-right" class="size-3 text-ink-3" />
                            <x-category-badge :category="$rule->category" :clickable="false" />
                            @if ($rule->wallet) <span class="truncate text-xs text-ink-3">· {{ $rule->wallet->name }}</span> @endif
                            <span class="ml-auto flex gap-1">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleRule({{ $rule->id }})">{{ $rule->is_active ? 'Pause' : 'Enable' }}</button>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="deleteRule({{ $rule->id }})" aria-label="Delete rule"><x-ico name="trash-2" class="size-3" /></button>
                            </span>
                        </div>
                    @empty
                        <p class="px-4 py-5 text-center text-xs text-ink-3">No rules yet.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    {{-- ── Account ─────────────────────────────────────────────── --}}
    @if ($tab === 'account')
        <section id="account" class="card max-w-xl">
            <div class="px-4 py-3">
                <h2 class="text-[13px] font-semibold">Password</h2>
                <p class="mt-0.5 text-xs text-ink-3">Signed in as <span class="text-ink-2">{{ auth()->user()?->email }}</span>.</p>
            </div>
            <form wire:submit="updatePassword" class="space-y-3 border-t border-line px-4 py-4">
                <div>
                    <label class="label" for="a-current">Current password</label>
                    <input id="a-current" type="password" class="input" wire:model="currentPassword" autocomplete="current-password">
                    @error('currentPassword') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="a-new">New password</label>
                        <input id="a-new" type="password" class="input" wire:model="newPassword" autocomplete="new-password">
                        @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="a-confirm">Confirm new password</label>
                        <input id="a-confirm" type="password" class="input" wire:model="newPasswordConfirmation" autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn">Update password</button>
            </form>
        </section>
    @endif

    {{-- ── Forms ───────────────────────────────────────────────── --}}
    @if ($form === 'wallet')
        @php $selectedCurrency = $currencies->firstWhere('code', $currency); @endphp
        <x-modal :title="$editingId ? 'Edit wallet' : 'New wallet'" submit="saveWallet">
            <div>
                <label class="label" for="w-name">Name</label>
                <input id="w-name" type="text" class="input" wire:model="walletName" placeholder="BIAT Checking">
                @error('walletName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="w-type">Type</label>
                    <select id="w-type" class="input" wire:model="walletType">
                        @foreach ($walletTypes as $type) <option value="{{ $type->value }}">{{ $type->label() }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="w-workspace">Workspace</label>
                    <select id="w-workspace" class="input" wire:model="workspaceId" @disabled($editingId)>
                        @foreach ($workspaces as $workspace) <option value="{{ $workspace->id }}">{{ $workspace->name }}</option> @endforeach
                    </select>
                    @error('workspaceId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="w-currency">Currency</label>
                <select id="w-currency" class="input" wire:model.live="currency" @disabled($editingId)>
                    @foreach ($currencies as $option)
                        <option value="{{ $option->code }}">{{ $option->code }} — {{ $option->name }}</option>
                    @endforeach
                </select>
                @error('currency') <p class="field-error">{{ $message }}</p> @enderror
                @if ($selectedCurrency && ! $selectedCurrency->is_base)
                    <p class="num mt-1 text-[11px] text-ink-3">1 {{ $selectedCurrency->code }} = {{ rtrim(rtrim(number_format($selectedCurrency->exchange_rate, 6, '.', ''), '0'), '.') }} {{ $base->code }}
                        <span class="font-sans">· change it on the Currencies tab</span></p>
                @endif
                @unless ($editingId)
                    <p class="mt-1 text-[11px] text-ink-3">Missing a currency? <button type="button" class="cursor-pointer text-ink-2 underline decoration-line-strong underline-offset-2 hover:text-ink" wire:click="newCurrency">Add it</button>, then create the wallet.</p>
                @endunless
            </div>
            @unless ($editingId)
                <div>
                    <label class="label" for="w-opening">Opening balance <span class="text-ink-3">(negative for a credit card you owe on)</span></label>
                    <input id="w-opening" type="text" inputmode="decimal" class="input num" wire:model="openingBalance">
                    @error('openingBalance') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endunless
            <div class="flex items-center justify-between gap-3">
                <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                    <input type="checkbox" class="accent-brand" wire:model="inNetWorth"> Include in net worth
                </label>
                <label class="flex items-center gap-2 text-xs text-ink-3">
                    Colour <input type="color" class="h-8 w-12 cursor-pointer rounded-lg border border-line bg-canvas p-1" wire:model="walletColor" aria-label="Wallet colour">
                </label>
            </div>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save wallet</button>
            </x-slot:footer>
        </x-modal>
    @elseif ($form === 'currency')
        @php $locked = $editingCurrency && $editingCurrency->wallets_count > 0; $isBase = (bool) $editingCurrency?->is_base; @endphp
        <x-modal :title="$editingCode ? 'Edit '.$editingCode : 'New currency'" submit="saveCurrency">
            <div class="grid grid-cols-[7rem_1fr] gap-3">
                <div>
                    <label class="label" for="c-code">Code</label>
                    <input id="c-code" type="text" class="input num uppercase" wire:model="currencyCode" maxlength="3" placeholder="GBP" @disabled($editingCode)>
                </div>
                <div>
                    <label class="label" for="c-name">Name</label>
                    <input id="c-name" type="text" class="input" wire:model="currencyName" placeholder="British Pound">
                </div>
            </div>
            @error('currencyCode') <p class="field-error -mt-2">{{ $message }}</p> @enderror
            @error('currencyName') <p class="field-error -mt-2">{{ $message }}</p> @enderror

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="c-symbol">Symbol</label>
                    <input id="c-symbol" type="text" class="input num" wire:model="currencySymbol" maxlength="8" placeholder="£">
                    @error('currencySymbol') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="c-decimals">Decimals</label>
                    <select id="c-decimals" class="input num" wire:model="currencyDecimals" @disabled($locked)>
                        @foreach ([0 => '0 (e.g. JPY)', 1 => '1', 2 => '2 (e.g. USD, EUR)', 3 => '3 (e.g. TND)', 4 => '4'] as $d => $label) <option value="{{ $d }}">{{ $label }}</option> @endforeach
                    </select>
                    @error('currencyDecimals') <p class="field-error">{{ $message }}</p> @enderror
                    @if ($locked) <p class="mt-1 text-[11px] text-ink-3">Fixed: wallets already use it.</p> @endif
                </div>
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                <input type="checkbox" class="accent-brand" wire:model="currencySymbolFirst"> Put the symbol before the amount <span class="num text-ink-3">($12.00 vs 12.000 TND)</span>
            </label>

            <div>
                <label class="label" for="c-rate">Exchange rate</label>
                @if ($isBase)
                    <p class="rounded-lg border border-line px-3 py-2 text-xs text-ink-3">This is the base currency, so its rate is always 1.</p>
                @else
                    <div class="flex items-center gap-2">
                        <span class="num shrink-0 text-xs text-ink-3">1 {{ $currencyCode ?: '…' }} =</span>
                        <input id="c-rate" type="text" inputmode="decimal" class="input num" wire:model="currencyRate" placeholder="3.120000">
                        <span class="num shrink-0 text-xs text-ink-3">{{ $base->code }}</span>
                    </div>
                    @error('currencyRate') <p class="field-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <x-slot:footer>
                @if ($editingCode && ! $isBase && ! $locked)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteCurrency" wire:confirm="Remove {{ $editingCode }}?"><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save currency</button>
            </x-slot:footer>
        </x-modal>
    @elseif ($form === 'workspace')
        <x-modal :title="$editingId ? 'Edit workspace' : 'New workspace'" submit="saveWorkspace">
            <div>
                <label class="label" for="ws-name">Name</label>
                <input id="ws-name" type="text" class="input" wire:model="workspaceName" placeholder="Side business">
                @error('workspaceName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-[1fr_auto] gap-3">
                <div>
                    <label class="label" for="ws-type">Type</label>
                    <select id="ws-type" class="input" wire:model="workspaceType">
                        @foreach ($workspaceTypes as $type) <option value="{{ $type->value }}">{{ ucfirst($type->value) }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="ws-color">Colour</label>
                    <input id="ws-color" type="color" class="h-9 w-14 cursor-pointer rounded-lg border border-line bg-canvas p-1" wire:model="workspaceColor">
                </div>
            </div>

            <x-slot:footer>
                @if ($editingId)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteWorkspace" wire:confirm="Remove this workspace?"><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save workspace</button>
            </x-slot:footer>
        </x-modal>
    @elseif ($form === 'category')
        <x-modal :title="$editingId ? 'Edit category' : 'New category'" submit="saveCategory">
            <div>
                <label class="label" for="cat-name">Name</label>
                <input id="cat-name" type="text" class="input" wire:model="categoryName" placeholder="Pets">
                @error('categoryName') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="cat-type">Type</label>
                    <select id="cat-type" class="input" wire:model.live="categoryType">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="cat-parent">Parent</label>
                    <select id="cat-parent" class="input" wire:model="parentId">
                        <option value="">None (top level)</option>
                        @foreach ($parents->filter(fn ($p) => $p->type->value === $categoryType && $p->id !== $editingId) as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    @error('parentId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-[1fr_auto] gap-3">
                <div>
                    <label class="label" for="cat-icon">Icon</label>
                    <select id="cat-icon" class="input" wire:model="categoryIcon">
                        @foreach (collect($icons)->push($categoryIcon)->unique() as $option) <option value="{{ $option }}">{{ str_replace('-', ' ', $option) }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="cat-color">Colour</label>
                    <input id="cat-color" type="color" class="h-9 w-14 cursor-pointer rounded-lg border border-line bg-canvas p-1" wire:model="categoryColor">
                </div>
            </div>

            <x-slot:footer>
                @if ($editingId)
                    <button type="button" class="btn btn-danger mr-auto" wire:click="deleteCategory"
                            wire:confirm="Remove this category? Its sub-categories are removed too and their transactions become uncategorised."><x-ico name="trash-2" /> Remove</button>
                @endif
                <button type="button" class="btn btn-ghost" wire:click="closeForm">Cancel</button>
                <button type="submit" class="btn btn-primary">Save category</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
