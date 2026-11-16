{{-- Errors and the status line for the sign-in forms. Fields with an error point at #auth-error (aria-describedby). --}}
@if ($errors->any())
    <div id="auth-error" role="alert" class="auth-alert auth-alert-danger mb-5">
        <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
        <div class="min-w-0">
            @foreach (array_unique($errors->all()) as $message)
                <p>{{ is_string($message) ? __($message) : '' }}</p>
            @endforeach
        </div>
    </div>
@endif
@if (session('status'))
    <div role="status" class="auth-alert auth-alert-success mb-5">
        <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
        <p class="min-w-0">{{ is_string(session('status')) ? __(session('status')) : '' }}</p>
    </div>
@endif
