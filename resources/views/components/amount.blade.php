@props(['tx'])
@php
    [$sign, $color] = match ($tx->type) {
        \App\Enums\TransactionType::Income => ['+', 'text-income'],
        \App\Enums\TransactionType::Expense => ['−', 'text-expense'],
        \App\Enums\TransactionType::Transfer => ['', 'text-transfer'],
    };
@endphp
<span {{ $attributes->class(['num', $color]) }}>{{ $sign }}{{ money($tx->amount, $tx->wallet->currency) }}</span>
