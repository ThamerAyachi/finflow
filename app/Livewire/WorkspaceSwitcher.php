<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\WorkspaceScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkspaceSwitcher extends Component
{
    public function switch(string $value, WorkspaceScope $scope): void
    {
        $scope->set($value);

        // Reload the current page so every widget re-queries under the new scope.
        $this->redirect(request()->header('Referer') ?: route('dashboard'), navigate: true);
    }

    /** Re-render after workspaces are added, renamed or removed in Settings. */
    #[On('workspaces-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        $scope = app(WorkspaceScope::class);

        return view('livewire.workspace-switcher', [
            'workspaces' => $scope->workspaces(),
            'current' => $scope->current(),
        ]);
    }
}
