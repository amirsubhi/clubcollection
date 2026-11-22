// Globals the Blade layouts define or the classic Blade pages read (resources/js/classic.ts, classic-charts.ts). Bootstrap 5 ships no types.
declare module "bootstrap";

interface Window {
  bootstrap?: unknown;
  Chart?: unknown;
  /** partials/pwa-head.blade.php: re-colours the theme-color metas after a theme change. */
  cpThemeColor?: () => void;
}
