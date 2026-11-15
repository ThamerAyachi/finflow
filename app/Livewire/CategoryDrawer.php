<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Right-hand slide-over: drill into a category — trend, sub-categories, latest activity. */
class CategoryDrawer extends Component
{
    public ?int $categoryId = null;

    #[On('open-category')]
    public function open(int $id): void
    {
        $this->categoryId = $id;
    }

    public function close(): void
    {
        $this->categoryId = null;
    }

    #[On('transaction-saved')]
    public function refresh(): void {}

    public function render(): View
    {
        $category = $this->categoryId ? Category::query()->with(['children', 'parent'])->find($this->categoryId) : null;

        if (! $category) {
            return view('livewire.category-drawer', ['category' => null]);
        }

        $ids = app(WorkspaceScope::class)->ids();
        $family = [$category->id, ...$category->children->pluck('id')->all()];
        $today = CarbonImmutable::today();
        $isIncome = $category->type->value === 'income';
        $sumSql = $isIncome ? Transaction::INCOME_SQL : Transaction::EXPENSE_SQL;

        $total = fn (array $categoryIds, CarbonImmutable $from, CarbonImmutable $to): int => (int) Transaction::query()
            ->forWorkspaces($ids)->reportable()->whereIn('category_id', $categoryIds)->between($from, $to)
            ->selectRaw("COALESCE(SUM({$sumSql}), 0) AS total")->value('total');

        $trend = collect(range(5, 0))->map(function (int $back) use ($today, $total, $family): array {
            $month = $today->startOfMonth()->subMonthsNoOverflow($back);

            return ['label' => $month->format('M'), 'amount' => max(0, $total($family, $month, $month->endOfMonth()))];
        });

        return view('livewire.category-drawer', [
            'category' => $category,
            'isIncome' => $isIncome,
            'thisMonth' => $trend->last()['amount'],
            'lastMonth' => $trend->get(4)['amount'],
            'trend' => $trend,
            'trendMax' => max(1, $trend->max('amount')),
            'children' => $category->children->map(fn (Category $child) => [
                'category' => $child,
                'amount' => max(0, $total([$child->id], $today->startOfMonth(), $today->endOfMonth())),
            ])->sortByDesc('amount')->values(),
            'transactions' => Transaction::query()->forWorkspaces($ids)->whereIn('category_id', $family)
                ->with(['wallet', 'category'])->orderByDesc('occurred_on')->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
