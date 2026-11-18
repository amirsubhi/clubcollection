{{-- One audit entry. Below xl (.table-stack) it becomes a card: date and chip first, then who, the description and the changes button. --}}
@php($chip = $log->action_badge_class)
<tr role="row">
    <td role="cell" class="ps-xl-3 text-muted">
        <span class="text-nowrap">{{ $log->created_at->format('d M Y') }}</span><span class="d-none d-xl-inline"><br></span><span class="d-xl-none"> · </span><span class="small">{{ $log->created_at->format('H:i:s') }}</span>
        <span class="d-xl-none ms-1 badge bg-{{ $chip }}-subtle text-{{ $chip }}-emphasis border border-{{ $chip }}-subtle">{{ $log->action_label }}</span>
    </td>
    <td role="cell" data-label="User">
        <span class="fw-semibold">{{ $log->user_name }}</span>
        <span class="badge bg-light text-secondary border">{{ str_replace('_',' ', $log->user_role) }}</span>
    </td>
    @if($showClub)
    <td role="cell" class="text-muted" data-label="Club">{{ $log->club?->name ?? '—' }}</td>
    @endif
    <td role="cell" class="d-none d-xl-table-cell">
        <span class="badge bg-{{ $chip }}-subtle text-{{ $chip }}-emphasis border border-{{ $chip }}-subtle" data-testid="audit-chip">{{ $log->action_label }}</span>
    </td>
    {{-- Full text, wrapped; friendly references, kinds and dates (FriendlyDescription, display only). --}}
    <td role="cell" class="audit-desc" data-testid="audit-desc">{{ $friendly->html($log->description, $log->club_id) }}</td>
    <td role="cell" class="text-xl-center stack-actions">
        @if($log->old_values || $log->new_values)
        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                data-bs-toggle="modal" data-bs-target="#diffModal"
                data-old="{{ json_encode($log->old_values, JSON_PRETTY_PRINT) }}"
                data-new="{{ json_encode($log->new_values, JSON_PRETTY_PRINT) }}"
                data-desc="{{ $friendly->format($log->description, $log->club_id) }}">
            <i class="bi bi-code-square me-1" aria-hidden="true"></i>View changes<span class="visually-hidden"> ({{ $log->action_label }}, {{ $log->created_at->format('d M Y H:i') }})</span>
        </button>
        @else
        <span class="text-muted d-none d-xl-inline" aria-label="No changes recorded">—</span>
        @endif
    </td>
</tr>
