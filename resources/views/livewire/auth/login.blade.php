<div class="w-full max-w-sm">
    <div class="mb-6 flex flex-col items-center text-center">
        <x-app-icon class="mb-4 size-16" />
        <h1 class="text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
        <p class="mt-1 text-[13px] text-ink-3">Sign in to your financial cockpit.</p>
    </div>

    <form wire:submit="login" class="card glow space-y-4 p-6" novalidate>
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" type="email" class="input" wire:model="email" autocomplete="username" autofocus required>
            @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <input id="password" type="password" class="input" wire:model="password" autocomplete="current-password" required>
            @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
            <input type="checkbox" class="accent-brand" wire:model="remember"> Keep me signed in on this device
        </label>

        <button type="submit" class="btn btn-primary h-9 w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
