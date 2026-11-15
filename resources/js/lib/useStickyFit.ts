import { useLayoutEffect, useRef, useState } from "react";

/**
 * Sticky bars only while they are small next to the screen. With large text (or a short screen) a
 * sticky header or action bar would cover most of the page and hide focused fields (WCAG 2.4.11),
 * so it falls back into the normal flow instead.
 *
 * It also publishes its height as --sticky-top / --sticky-bottom on <html>, which app.css uses as
 * scroll-padding, so a focused field scrolls clear of whatever is still pinned.
 *
 * keepPinned: the element stays fixed whatever its size (the bottom nav); only the padding is set.
 */
export function useStickyFit<T extends HTMLElement>(edge: "top" | "bottom", { maxFraction = 0.2, minViewport = 0, keepPinned = false } = {}) {
  const ref = useRef<T>(null);
  const [pinned, setPinned] = useState(true);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const root = document.documentElement;
    const name = edge === "top" ? "--sticky-top" : "--sticky-bottom";
    const update = () => {
      const h = el.getBoundingClientRect().height;
      const vh = window.innerHeight;
      const fits = keepPinned || (vh >= minViewport && h <= vh * maxFraction);
      setPinned(fits);
      root.style.setProperty(name, fits ? `${Math.ceil(h) + 8}px` : "0px");
    };
    update();
    const ro = typeof ResizeObserver === "undefined" ? null : new ResizeObserver(update);
    ro?.observe(el);
    window.addEventListener("resize", update);
    return () => {
      ro?.disconnect();
      window.removeEventListener("resize", update);
      root.style.removeProperty(name);
    };
  }, [edge, maxFraction, minViewport, keepPinned]);
  return [ref, pinned] as const;
}
