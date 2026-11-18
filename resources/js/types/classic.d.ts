// Globals the classic Blade pages read (resources/js/classic.ts, classic-charts.ts). Bootstrap 5 ships no types.
declare module "bootstrap";

interface Window {
  bootstrap?: unknown;
  Chart?: unknown;
}
