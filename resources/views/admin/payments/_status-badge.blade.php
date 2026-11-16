{{-- One status word from App\Support\Payments\DisplayStatus, with an icon (never colour alone). --}}
<span class="badge {{ \App\Support\Payments\DisplayStatus::badgeClass($status) }}" data-status="{{ $status }}"><i class="bi {{ \App\Support\Payments\DisplayStatus::badgeIcon($status) }} me-1" aria-hidden="true"></i>{{ \App\Support\Payments\DisplayStatus::adminLabel($status) }}</span>
