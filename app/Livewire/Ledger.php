<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TransactionType;
use App\Livewire\Concerns\ParsesMoney;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\RuleEngine;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\WorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[Title('Transactions')]
class Ledger extends Component
{
    use ParsesMoney, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $type = '';

    #[Url(as: 'wallet')]
    public string $walletId = '';

    #[Url(as: 'category')]
    public string $categoryId = '';

    /** @var list<string> */
    public array $selected = [];

    public string $bulkCategoryId = '';

    public bool $showImport = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $csv = null;

    public string $importWalletId = '';

    public ?string $importSummary = null;

    #[On('transaction-saved')]
    public function refresh(): void {}

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'period', 'type', 'walletId', 'categoryId'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'walletId', 'categoryId');
        $this->period = 'all';
        $this->resetPage();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function range(): ?array
    {
        $today = CarbonImmutable::today();

        return match ($this->period) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            '30d' => [$today->subDays(29), $today],
            default => null,
        };
    }

    private function query(): Builder
    {
        $range = $this->range();
        $walletId = (int) $this->walletId;
        $search = trim($this->search);

        return Transaction::query()
            ->forWorkspaces(app(WorkspaceScope::class)->ids())
            ->when($range, fn (Builder $q) => $q->between($range[0], $range[1]))
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($walletId, fn (Builder $q) => $q->where(
                fn (Builder $q) => $q->where('wallet_id', $walletId)->orWhere('destination_wallet_id', $walletId)
            ))
            ->when((int) $this->categoryId, function (Builder $q): void {
                $category = Category::query()->find((int) $this->categoryId);
                $q->whereIn('category_id', $category ? $category->familyIds() : [0]);
            })
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => $q->where('description', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")
            ));
    }

    public function toggleAll(array $pageIds): void
    {
        $pageIds = array_map('strval', $pageIds);
        $this->selected = array_diff($pageIds, $this->selected) === [] ? [] : $pageIds;
    }

    public function bulkCategorize(TransactionService $service): void
    {
        $categoryId = (int) $this->bulkCategoryId;

        if (! $categoryId || $this->selected === []) {
            return;
        }

        $count = 0;
        $category = Category::query()->findOrFail($categoryId);

        DB::transaction(function () use ($service, $category, &$count): void {
            $rows = Transaction::query()->forWorkspaces(app(WorkspaceScope::class)->ids())
                ->whereIn('id', $this->selected)
                ->where('type', $category->type->value)   // an expense category only applies to expenses
                ->whereNull('parent_transaction_id')
                ->get();

            foreach ($rows as $transaction) {
                $service->update($transaction, ['category_id' => $category->id]);
                $count++;
            }
        });

        $skipped = count($this->selected) - $count;
        $this->selected = [];
        $this->bulkCategoryId = '';
        $this->toast("{$count} moved to {$category->name}".($skipped ? " · {$skipped} skipped" : ''));
    }

    public function bulkDelete(TransactionService $service): void
    {
        $rows = Transaction::query()->forWorkspaces(app(WorkspaceScope::class)->ids())->whereIn('id', $this->selected)->get();

        DB::transaction(fn () => $rows->each(fn (Transaction $t) => $service->delete($t)));

        $this->selected = [];
        $this->toast($rows->count().' deleted');
    }

    public function export(): StreamedResponse
    {
        $query = $this->query()->with(['wallet', 'destinationWallet', 'category'])->orderBy('occurred_on')->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'type', 'description', 'category', 'wallet', 'to_wallet', 'amount', 'currency', 'amount_base', 'notes']);

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $t) {
                    $sign = $t->type === TransactionType::Expense ? '-' : '';
                    fputcsv($out, [
                        $t->occurred_on->toDateString(), $t->type->value, $t->description, $t->category?->name,
                        $t->wallet->name, $t->destinationWallet?->name,
                        $sign.Money::decimal($t->amount, $t->wallet->currency, thousands: false), $t->wallet->currency->code,
                        $sign.Money::decimal($t->base_amount, base_currency(), thousands: false), $t->notes,
                    ]);
                }
            });

            fclose($out);
        }, 'transactions-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function openImport(): void
    {
        $this->reset('csv', 'importSummary');
        $this->resetErrorBag();
        $this->importWalletId = $this->importWalletId ?: (string) Wallet::query()->forWorkspaces(app(WorkspaceScope::class)->ids())->active()->value('id');
        $this->showImport = true;
    }

    public function closeForm(): void
    {
        $this->showImport = false;
    }

    /**
     * Import a CSV with a header row. Recognised columns: date, description,
     * amount (negative = expense), and optionally type, wallet, category.
     * Rows without a category / wallet are run through the rule engine.
     */
    public function import(TransactionService $service, RuleEngine $rules): void
    {
        $this->validate([
            'csv' => 'required|file|mimes:csv,txt|max:2048',
            'importWalletId' => 'required',
        ], attributes: ['csv' => 'CSV file', 'importWalletId' => 'default wallet']);

        $wallets = Wallet::query()->active()->get();
        $fallback = $wallets->firstWhere('id', (int) $this->importWalletId);
        $categories = Category::query()->get();

        if (! $fallback) {
            $this->addError('importWalletId', 'Choose a default wallet.');

            return;
        }

        $handle = fopen($this->csv->getRealPath(), 'r');
        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), (array) fgetcsv($handle, null, $delimiter));

        if (! in_array('date', $header, true) || ! in_array('amount', $header, true)) {
            fclose($handle);
            $this->addError('csv', 'The file needs at least "date" and "amount" columns in its header row.');

            return;
        }

        $imported = 0;
        $skipped = 0;
        $ruled = 0;

        DB::transaction(function () use ($handle, $delimiter, $header, $wallets, $fallback, $categories, $service, $rules, &$imported, &$skipped, &$ruled): void {
            while (($line = fgetcsv($handle, null, $delimiter)) !== false) {
                if ($line === [null] || count($line) < count($header)) {
                    continue;
                }

                $row = array_combine($header, array_slice($line, 0, count($header)));

                try {
                    $description = trim((string) ($row['description'] ?? ''));
                    $rule = $rules->match($description);

                    $wallet = $wallets->first(fn (Wallet $w) => strcasecmp($w->name, trim((string) ($row['wallet'] ?? ''))) === 0)
                        ?? ($rule?->wallet_id ? $wallets->firstWhere('id', $rule->wallet_id) : null)
                        ?? $fallback;

                    $raw = preg_replace('/[^\d.,\-]/', '', (string) $row['amount']);
                    $minor = Money::parse($raw, $wallet->currency);
                    $type = TransactionType::tryFrom(strtolower(trim((string) ($row['type'] ?? ''))))
                        ?? ($minor < 0 ? TransactionType::Expense : TransactionType::Income);

                    if ($type === TransactionType::Transfer || $minor === 0) {
                        $skipped++;

                        continue;
                    }

                    $category = $categories->first(fn (Category $c) => strcasecmp($c->name, trim((string) ($row['category'] ?? ''))) === 0);
                    if (! $category && $rule?->category_id) {
                        $category = $categories->firstWhere('id', $rule->category_id);
                        $ruled++;
                    }

                    $service->record([
                        'wallet_id' => $wallet->id,
                        'type' => $type,
                        'amount' => abs($minor),
                        'category_id' => $category && $category->type->value === $type->value ? $category->id : null,
                        'description' => $description,
                        'occurred_on' => CarbonImmutable::parse(trim((string) $row['date']))->toDateString(),
                    ]);
                    $imported++;
                } catch (Throwable) {
                    $skipped++;
                }
            }
        });

        fclose($handle);

        $this->reset('csv');
        $this->importSummary = "{$imported} imported · {$ruled} auto-categorised by rules · {$skipped} skipped";
        $this->toast("{$imported} transactions imported");
    }

    public function render(): View
    {
        $scopeIds = app(WorkspaceScope::class)->ids();

        $totals = $this->query()->reportable()
            ->selectRaw('COALESCE(SUM('.Transaction::INCOME_SQL.'), 0) AS income, COALESCE(SUM('.Transaction::EXPENSE_SQL.'), 0) AS expense')
            ->toBase()->first();

        return view('livewire.ledger', [
            'transactions' => $this->query()
                ->with(['wallet', 'destinationWallet', 'category'])->withCount('reimbursements')
                ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(50),
            'totalIn' => (int) $totals->income,
            'totalOut' => (int) $totals->expense,
            'wallets' => Wallet::query()->forWorkspaces($scopeIds)->active()->orderBy('id')->get(),
            'categories' => Category::options(),
            'hasFilters' => $this->search !== '' || $this->type !== '' || $this->walletId !== '' || $this->categoryId !== '' || $this->period !== 'month',
        ]);
    }
}
