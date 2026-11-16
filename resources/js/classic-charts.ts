// Chart.js for the classic admin dashboard and the statistics page, bundled from npm instead of the
// jsDelivr CDN and loaded only on those two pages. Their inline scripts start from window.cpWhenReady("chart", ...).
import Chart from "chart.js/auto";

window.Chart = Chart;
// Inline scripts waiting in window.cpWhenReady (layouts/app.blade.php) run now.
document.dispatchEvent(new Event("cp:chart-ready"));
