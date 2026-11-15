<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TransactionRule;
use Illuminate\Support\Collection;

/** Keyword → category / wallet auto-categorisation used by quick entry and CSV import. */
final class RuleEngine
{
    /** @var Collection<int, TransactionRule>|null */
    private ?Collection $rules = null;

    public function match(string $description): ?TransactionRule
    {
        $haystack = mb_strtolower($description);

        if (trim($haystack) === '') {
            return null;
        }

        return $this->rules()->first(
            fn (TransactionRule $rule) => str_contains($haystack, mb_strtolower($rule->keyword))
        );
    }

    public function flush(): void
    {
        $this->rules = null;
    }

    /** @return Collection<int, TransactionRule> Highest priority first, then longest keyword. */
    private function rules(): Collection
    {
        return $this->rules ??= TransactionRule::query()
            ->where('is_active', true)
            ->get()
            ->sortBy([['priority', 'desc'], fn ($a, $b) => mb_strlen($b->keyword) <=> mb_strlen($a->keyword)])
            ->values();
    }
}
