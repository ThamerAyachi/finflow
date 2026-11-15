<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategoryType;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Budget;
use App\Models\Category;
use App\Models\SinkingFund;
use App\Services\ReportService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Budgets & Funds')]
class Budgets extends Component
{
    use ParsesMoney;

    /** '' | 'budget' | 'fund' */
    public string $form = '';

    public ?int $editingId = null;

    public string $workspaceId = '';

    // Budget form
    public string $categoryId = '';

    public string $amount = '';

    public bool $rollover = false;

    // Sinking fund form
    public string $name = '';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $icon = 'piggy-bank';

    public string $color = '#3B82F6';

    // Inline contribution
    public ?int $contributingId = null;

    public string $contribution = '';

    #[On('transaction-saved')]
    public function refresh(): void {}

    private function scope(): WorkspaceScope
    {
        return app(WorkspaceScope::class);
    }

    public function closeForm(): void
    {
        $this->form = '';
        $this->editingId = null;
        $this->resetErrorBag();
    }

    // ── Budgets ─────────────────────────────────────────────────────

    public function newBudget(): void
    {
        $this->reset('categoryId', 'amount', 'rollover', 'editingId');
        $this->workspaceId = (string) $this->scope()->default()->id;
        $this->form = 'budget';
    }

    public function editBudget(int $id): void
    {
        $budget = Budget::query()->whereIn('workspace_id', $this->scope()->ids())->findOrFail($id);

        $this->editingId = $budget->id;
        $this->workspaceId = (string) $budget->workspace_id;
        $this->categoryId = (string) $budget->category_id;
        $this->amount = Money::decimal($budget->amount, base_currency(), thousands: false);
        $this->rollover = $budget->rollover;
        $this->form = 'budget';
    }

    public function saveBudget(): void
    {
        $this->validate(['categoryId' => 'required|exists:categories,id', 'workspaceId' => 'required|exists:workspaces,id'],
            attributes: ['categoryId' => 'category', 'workspaceId' => 'workspace']);

        if (($amount = $this->parseAmount('amount')) === null) {
            return;
        }

        $duplicate = Budget::query()->where('workspace_id', (int) $this->workspaceId)->where('category_id', (int) $this->categoryId)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();

        if ($duplicate) {
            $this->addError('categoryId', 'This category already has a budget in that workspace.');

            return;
        }

        Budget::query()->updateOrCreate(['id' => $this->editingId], [
            'workspace_id' => (int) $this->workspaceId,
            'category_id' => (int) $this->categoryId,
            'amount' => $amount,
            'rollover' => $this->rollover,
        ]);

        $this->toast('Budget saved');
        $this->closeForm();
    }

    public function deleteBudget(): void
    {
        Budget::query()->whereIn('workspace_id', $this->scope()->ids())->whereKey($this->editingId)->delete();
        $this->toast('Budget removed');
        $this->closeForm();
    }

    // ── Sinking funds ───────────────────────────────────────────────

    public function newFund(): void
    {
        $this->reset('name', 'targetAmount', 'icon', 'color', 'editingId');
        $this->targetDate = today()->addMonths(6)->toDateString();
        $this->workspaceId = (string) $this->scope()->default()->id;
        $this->form = 'fund';
    }

    public function editFund(int $id): void
    {
        $fund = SinkingFund::query()->whereIn('workspace_id', $this->scope()->ids())->findOrFail($id);

        $this->editingId = $fund->id;
        $this->workspaceId = (string) $fund->workspace_id;
        $this->name = $fund->name;
        $this->targetAmount = Money::decimal($fund->target_amount, base_currency(), thousands: false);
        $this->targetDate = $fund->target_date->toDateString();
        $this->icon = $fund->icon;
        $this->color = $fund->color_hex;
        $this->form = 'fund';
    }

    public function saveFund(): void
    {
        $this->validate([
            'name' => 'required|string|max:80',
            'targetDate' => 'required|date',
            'workspaceId' => 'required|exists:workspaces,id',
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        if (($target = $this->parseAmount('targetAmount')) === null) {
            return;
        }

        SinkingFund::query()->updateOrCreate(['id' => $this->editingId], [
            'workspace_id' => (int) $this->workspaceId,
            'name' => $this->name,
            'target_amount' => $target,
            'target_date' => $this->targetDate,
            'icon' => $this->icon,
            'color_hex' => $this->color,
        ]);

        $this->toast('Sinking fund saved');
        $this->closeForm();
    }

    public function deleteFund(): void
    {
        SinkingFund::query()->whereIn('workspace_id', $this->scope()->ids())->whereKey($this->editingId)->delete();
        $this->toast('Sinking fund removed');
        $this->closeForm();
    }

    public function startContribution(int $id): void
    {
        $fund = SinkingFund::query()->whereIn('workspace_id', $this->scope()->ids())->with('contributions')->findOrFail($id);

        $this->resetErrorBag();
        $this->contributingId = $fund->id;
        $this->contribution = Money::decimal($fund->dueThisMonth() ?: $fund->monthlyAllocation(), base_currency(), thousands: false);
    }

    /** Set money aside in a fund (virtual: no wallet movement). Negative amounts withdraw. */
    public function contribute(): void
    {
        if (($amount = $this->parseAmount('contribution', allowNegative: true)) === null || $amount === 0) {
            return;
        }

        DB::transaction(function () use ($amount): void {
            /** @var SinkingFund $fund */
            $fund = SinkingFund::query()->whereIn('workspace_id', $this->scope()->ids())->lockForUpdate()->findOrFail($this->contributingId);

            if ($fund->saved_amount + $amount < 0) {
                $this->addError('contribution', 'Cannot withdraw more than what is saved.');

                return;
            }

            $fund->contributions()->create(['amount' => $amount, 'contributed_on' => today()]);
            $fund->increment('saved_amount', $amount);
        });

        if ($this->getErrorBag()->isEmpty()) {
            $this->toast('Contribution recorded');
            $this->contributingId = null;
        }
    }

    public function render(): View
    {
        $report = app(ReportService::class);
        $today = CarbonImmutable::today();
        $budgets = $report->budgets($today);
        $funds = SinkingFund::query()->whereIn('workspace_id', $this->scope()->ids())->with(['contributions', 'workspace'])->orderBy('target_date')->get();

        return view('livewire.budgets', [
            'today' => $today,
            'budgets' => $budgets,
            'funds' => $funds,
            'totalCap' => (int) $budgets->sum('cap'),
            'totalSpent' => (int) $budgets->sum('spent'),
            'fundsDue' => (int) $funds->sum(fn (SinkingFund $f) => $f->dueThisMonth($today)),
            'fundsSaved' => (int) $funds->sum('saved_amount'),
            'showWorkspace' => $this->scope()->isAll(),
            'workspaces' => $this->scope()->workspaces(),
            'categories' => Category::options(CategoryType::Expense),
            'icons' => ['piggy-bank', 'wrench', 'shield', 'laptop', 'car', 'house', 'plane', 'camera', 'graduation-cap', 'gift', 'heart-pulse', 'target'],
        ]);
    }
}
