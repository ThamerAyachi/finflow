<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * The global workspace filter chosen in the sidebar switcher.
 * "all" or a workspace id, persisted in the session.
 */
final class WorkspaceScope
{
    private const KEY = 'workspace_scope';

    /** @var Collection<int, Workspace>|null */
    private ?Collection $workspaces = null;

    /** @return Collection<int, Workspace> */
    public function workspaces(): Collection
    {
        return $this->workspaces ??= Workspace::query()->orderBy('id')->get();
    }

    public function current(): ?Workspace
    {
        $value = session(self::KEY, 'all');

        return $value === 'all' ? null : $this->workspaces()->firstWhere('id', (int) $value);
    }

    public function isAll(): bool
    {
        return $this->current() === null;
    }

    public function set(string|int $value): void
    {
        $valid = $value === 'all' || $this->workspaces()->contains('id', (int) $value);
        session([self::KEY => $valid ? (string) $value : 'all']);
    }

    /** @return list<int> Workspace ids every query should be limited to. */
    public function ids(): array
    {
        $current = $this->current();

        return $current ? [$current->id] : $this->workspaces()->pluck('id')->all();
    }

    /**
     * Workspace new records default to when the scope is "all".
     * A fresh install has none yet, so a "Personal" one is created on demand.
     */
    public function default(): Workspace
    {
        if ($current = $this->current()) {
            return $current;
        }

        if ($this->workspaces()->isEmpty()) {
            Workspace::create(['name' => 'Personal', 'type' => 'personal', 'color_hex' => '#22C55E']);
            $this->flush();
        }

        return $this->workspaces()->first();
    }

    /** Forget the cached list after workspaces are added, renamed or removed. */
    public function flush(): void
    {
        $this->workspaces = null;
    }

    public function label(): string
    {
        return $this->current()?->name ?? 'All Workspaces';
    }
}
