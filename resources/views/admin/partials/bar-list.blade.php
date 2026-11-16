{{--
    Labelled horizontal bars (RESEARCH.md §3: no pies or donuts): one row per item, the label and the figure in
    text, the bar only shows the size. Colours are the --chart-* tokens (bootstrap-theme.css .bar-list), so
    they follow light / dark like the React charts.
    $rows: list of ['label' => string, 'value' => int|float, 'display' => string]
    $caption: what the bars show (screen readers get the list as text)
    $testid: optional data-testid
--}}
@php
    $max = max(1, ...array_map(fn ($r) => (float) $r['value'], $rows ?: [['value' => 0]]));
@endphp
<ul class="bar-list" aria-label="{{ $caption }}" @isset($testid) data-testid="{{ $testid }}" @endisset>
    @forelse ($rows as $r)
        @php $pct = $r['value'] > 0 ? max(1.5, round($r['value'] / $max * 100, 1)) : 0; @endphp
        <li>
            <span class="bar-label">{{ $r['label'] }}</span>
            <span class="bar-value money">{{ $r['display'] }}</span>
            <span class="bar-track" aria-hidden="true"><span class="bar-fill" style="width: {{ $pct }}%"></span></span>
        </li>
    @empty
        <li class="text-muted">Nothing to show yet.</li>
    @endforelse
</ul>
