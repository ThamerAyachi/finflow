<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\ParsesMoney;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\RecurringService;
use App\Services\ReportService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use ParsesMoney;

    /** Inline filter for the recent-transactions feed: all | expense | income | transfer. */
    public string $feed = 'all';

    #[On('transaction-saved')]
    public function refresh(): void {}

    public function markPaid(int $ruleId, RecurringService $recurring, WorkspaceScope $scope): void
    {
        $rule = RecurringRule::query()->forWorkspaces($scope->ids())->find($ruleId);

        if ($rule) {
            $recurring->markPaid($rule);
            $this->toast("{$rule->name} marked as paid");
            $this->dispatch('transaction-saved')->to(CategoryDrawer::class);
        }
    }

    public function render(): View
    {
        $report = app(ReportService::class);
        $scope = app(WorkspaceScope::class);
        $today = CarbonImmutable::today();

        $wallets = $report->wallets();
        $breakdown = $report->categoryBreakdown($today->startOfMonth(), $today->endOfMonth());

        return view('livewire.dashboard', [
            'today' => $today,
            'scopeLabel' => $scope->label(),
            'netWorth' => $report->netWorth(),
            'liquid' => $report->liquidCash(),
            'safe' => $report->safeToSpend($today),
            'flow' => $report->flow($today->startOfMonth(), $today->endOfMonth()),
            'burn' => $report->burnRate($today),
            'series' => $report->dailySeries(30, $today),
            'breakdown' => $breakdown,
            'donut' => [
                'series' => $breakdown->map(fn (array $row) => Money::toFloat($row['amount']))->all(),
                'labels' => $breakdown->pluck('name')->all(),
                'colors' => $breakdown->pluck('color')->all(),
            ],
            'wallets' => $wallets,
            'sparklines' => $report->sparklines($wallets, 14, $today),
            'budgets' => $report->budgets($today)->take(5),
            'bills' => $report->upcomingBills(14, $today),
            'recent' => Transaction::query()->forWorkspaces($scope->ids())
                ->when($this->feed !== 'all', fn ($q) => $q->where('type', $this->feed))
                ->with(['wallet', 'destinationWallet', 'category'])
                ->orderByDesc('occurred_on')->orderByDesc('id')->limit(12)->get(),
        ]);
    }
}
