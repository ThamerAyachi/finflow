<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\DebtDirection;
use App\Enums\Frequency;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Category;
use App\Models\Debt;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\DebtService;
use App\Services\RecurringService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Debts & Recurring')]
class Debts extends Component
{
    use ParsesMoney;

    /** '' | 'debt' | 'rule' */
    public string $form = '';

    public ?int $editingId = null;

    /** Wallet used by 1-click settle and as the default for payments. */
    public string $settleWalletId = '';

    public bool $showSettled = false;

    // Debt form
    public string $workspaceId = '';

    public string $contactName = '';

    public string $direction = 'receivable';

    public string $principal = '';

    public string $dueDate = '';

    public string $debtNotes = '';

    public string $debtWalletId = '';

    // Partial payment
    public ?int $payingId = null;

    public string $payAmount = '';

    // Recurring rule form
    public string $ruleName = '';

    public string $ruleType = 'expense';

    public string $ruleAmount = '';

    public string $ruleWalletId = '';

    public string $ruleCategoryId = '';

    public string $ruleFrequency = 'monthly';

    public string $ruleNextDue = '';

    public bool $ruleAutoPost = false;

    /** Calendar month, "Y-m". */
    public string $month = '';

    public function mount(): void
    {
        $this->month = today()->format('Y-m');
        $this->settleWalletId = (string) $this->wallets()->first()?->id;
    }

    #[On('transaction-saved')]
    public function refresh(): void {}

    private function scope(): WorkspaceScope
    {
        return app(WorkspaceScope::class);
    }

    private function wallets()
    {
        return Wallet::query()->forWorkspaces($this->scope()->ids())->active()->orderBy('id')->get();
    }

    private function debt(int $id): Debt
    {
        return Debt::query()->forWorkspaces($this->scope()->ids())->findOrFail($id);
    }

    private function rule(int $id): RecurringRule
    {
        return RecurringRule::query()->forWorkspaces($this->scope()->ids())->findOrFail($id);
    }

    public function closeForm(): void
    {
        $this->form = '';
        $this->editingId = null;
        $this->resetErrorBag();
    }

    // ── Debts ───────────────────────────────────────────────────────

    public function newDebt(string $direction = 'receivable'): void
    {
        $this->reset('contactName', 'principal', 'debtNotes', 'debtWalletId', 'editingId');
        $this->direction = $direction;
        $this->dueDate = today()->addMonth()->toDateString();
        $this->workspaceId = (string) $this->scope()->default()->id;
        $this->form = 'debt';
    }

    public function saveDebt(DebtService $debts): void
    {
        $this->validate([
            'contactName' => 'required|string|max:80',
            'direction' => 'required|in:receivable,payable',
            'dueDate' => 'nullable|date',
            'debtNotes' => 'max:255',
            'workspaceId' => 'required|exists:workspaces,id',
        ], attributes: ['contactName' => 'contact name']);

        if (($principal = $this->parseAmount('principal')) === null) {
            return;
        }

        $debts->create([
            'workspace_id' => (int) $this->workspaceId,
            'contact_name' => $this->contactName,
            'direction' => $this->direction,
            'principal' => $principal,
            'due_date' => $this->dueDate ?: null,
            'notes' => $this->debtNotes ?: null,
        ], ((int) $this->debtWalletId) ?: null);

        $this->dispatch('transaction-saved');
        $this->toast('Debt recorded');
        $this->closeForm();
    }

    public function startPayment(int $id): void
    {
        $this->resetErrorBag();
        $this->payingId = $id;
        $this->payAmount = '';
    }

    public function pay(DebtService $debts): void
    {
        if (($amount = $this->parseAmount('payAmount')) === null) {
            return;
        }

        try {
            $debts->recordPayment($this->debt((int) $this->payingId), (int) $this->settleWalletId, $amount);
        } catch (DomainException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $this->payingId = null;
        $this->dispatch('transaction-saved');
        $this->toast('Payment recorded');
    }

    /** 1-click settlement through the selected wallet. */
    public function settle(int $id, DebtService $debts): void
    {
        $debt = $this->debt($id);
        $debts->settle($debt, (int) $this->settleWalletId);

        $this->dispatch('transaction-saved');
        $this->toast("Settled with {$debt->contact_name}");
    }

    public function deleteDebt(int $id): void
    {
        // Wallet transactions already posted for this debt are kept as history.
        $this->debt($id)->delete();
        $this->toast('Debt removed');
    }

    // ── Recurring rules ─────────────────────────────────────────────

    public function newRule(): void
    {
        $this->reset('ruleName', 'ruleAmount', 'ruleCategoryId', 'ruleAutoPost', 'editingId');
        $this->ruleType = 'expense';
        $this->ruleFrequency = 'monthly';
        $this->ruleNextDue = today()->toDateString();
        $this->ruleWalletId = $this->settleWalletId;
        $this->form = 'rule';
    }

    public function editRule(int $id): void
    {
        $rule = $this->rule($id)->load('wallet');

        $this->editingId = $rule->id;
        $this->ruleName = $rule->name;
        $this->ruleType = $rule->type->value;
        $this->ruleAmount = Money::decimal($rule->amount, $rule->wallet->currency, thousands: false);
        $this->ruleWalletId = (string) $rule->wallet_id;
        $this->ruleCategoryId = (string) $rule->category_id;
        $this->ruleFrequency = $rule->frequency->value;
        $this->ruleNextDue = $rule->next_due_date->toDateString();
        $this->ruleAutoPost = $rule->auto_post;
        $this->form = 'rule';
    }

    public function saveRule(): void
    {
        $this->validate([
            'ruleName' => 'required|string|max:80',
            'ruleType' => 'required|in:expense,income',
            'ruleFrequency' => 'required|in:weekly,monthly,yearly',
            'ruleNextDue' => 'required|date',
            'ruleWalletId' => 'required|exists:wallets,id',
        ], attributes: ['ruleName' => 'name', 'ruleNextDue' => 'next due date', 'ruleWalletId' => 'wallet']);

        $wallet = Wallet::query()->findOrFail((int) $this->ruleWalletId);

        if (($amount = $this->parseAmount('ruleAmount', $wallet->currency)) === null) {
            return;
        }

        RecurringRule::query()->updateOrCreate(['id' => $this->editingId], [
            'workspace_id' => $wallet->workspace_id,
            'wallet_id' => $wallet->id,
            'category_id' => ((int) $this->ruleCategoryId) ?: null,
            'name' => $this->ruleName,
            'type' => $this->ruleType,
            'amount' => $amount,
            'frequency' => $this->ruleFrequency,
            'next_due_date' => $this->ruleNextDue,
            'auto_post' => $this->ruleAutoPost,
        ]);

        $this->toast('Recurring rule saved');
        $this->closeForm();
    }

    public function deleteRule(): void
    {
        $this->rule((int) $this->editingId)->delete();
        $this->toast('Recurring rule removed');
        $this->closeForm();
    }

    public function markPaid(int $id, RecurringService $recurring): void
    {
        $rule = $this->rule($id);
        $recurring->markPaid($rule);

        $this->dispatch('transaction-saved');
        $this->toast("{$rule->name} posted");
    }

    public function skip(int $id, RecurringService $recurring): void
    {
        $recurring->skip($this->rule($id));
    }

    public function shiftMonth(int $by): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonthsNoOverflow($by)->format('Y-m');
    }

    /**
     * Calendar cells for the visible month: unpaid occurrences + what was already posted.
     *
     * @return array{weeks: list<list<array<string, mixed>>>, dueTotal: int}
     */
    private function calendar(CarbonImmutable $month, $rules): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $events = [];
        $dueTotal = 0;

        foreach ($rules as $rule) {
            foreach ($rule->occurrencesUntil($end) as $date) {
                if ($date->gte($start)) {
                    $events[$date->toDateString()][] = ['name' => $rule->name, 'paid' => false, 'income' => $rule->type->value === 'income', 'overdue' => $date->lt(CarbonImmutable::today())];
                    $dueTotal += $rule->type->value === 'income' ? 0 : $rule->baseAmount();
                }
            }
        }

        Transaction::query()->forWorkspaces($this->scope()->ids())->whereNotNull('recurring_rule_id')->between($start, $end)
            ->get(['description', 'type', 'occurred_on'])
            ->each(function (Transaction $t) use (&$events): void {
                $events[$t->occurred_on->toDateString()][] = ['name' => $t->description, 'paid' => true, 'income' => $t->type->value === 'income', 'overdue' => false];
            });

        $weeks = [];
        $cursor = $start->startOfWeek();

        while ($cursor->lte($end)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->month === $month->month,
                    'isToday' => $cursor->isToday(),
                    'events' => $events[$cursor->toDateString()] ?? [],
                ];
                $cursor = $cursor->addDay();
            }
            $weeks[] = $week;
        }

        return ['weeks' => $weeks, 'dueTotal' => $dueTotal];
    }

    public function render(): View
    {
        $ids = $this->scope()->ids();
        $month = CarbonImmutable::parse($this->month.'-01');

        $debts = Debt::query()->forWorkspaces($ids)->with(['payments', 'workspace'])
            ->when(! $this->showSettled, fn ($q) => $q->whereNull('settled_at'))
            ->orderByRaw('settled_at IS NOT NULL')->orderBy('due_date')->get();

        $rules = RecurringRule::query()->forWorkspaces($ids)->active()->with(['wallet', 'category'])->orderBy('next_due_date')->get();

        return view('livewire.debts', [
            'today' => CarbonImmutable::today(),
            'receivables' => $debts->where('direction', DebtDirection::Receivable)->values(),
            'payables' => $debts->where('direction', DebtDirection::Payable)->values(),
            'owedToYou' => (int) $debts->where('direction', DebtDirection::Receivable)->sum(fn (Debt $d) => $d->remaining()),
            'youOwe' => (int) $debts->where('direction', DebtDirection::Payable)->sum(fn (Debt $d) => $d->remaining()),
            'rules' => $rules,
            'monthDate' => $month,
            'calendar' => $this->calendar($month, $rules),
            'wallets' => $this->wallets(),
            'showWorkspace' => $this->scope()->isAll(),
            'workspaces' => $this->scope()->workspaces(),
            'categories' => Category::options(),
            'frequencies' => Frequency::cases(),
        ]);
    }
}
