{{-- Chart.js for the classic dashboard and statistics pages: bundled (resources/js/classic-charts.ts), or the pinned jsDelivr copy when public/build predates it. Page scripts start from window.cpWhenReady('chart', ...). --}}
@if (\App\Support\FrontendAssets::classicBundled())
    @vite(['resources/js/classic-charts.ts'])
@else
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.6.1/dist/chart.umd.min.js" integrity="sha384-Emf5ZVIUiarAdhkgtqK+5/Z5qIvM6QoVrS+qAVtfyZVuD5lIHrac0me485+Antp0" crossorigin="anonymous"></script>
@endif
