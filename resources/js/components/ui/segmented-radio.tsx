import { useRef, type KeyboardEvent, type ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface SegmentedOption<T extends string> {
  value: T;
  /** Visible content (text and/or icon). */
  label: ReactNode;
  /** Accessible name when the visible label is icon-only. */
  ariaLabel?: string;
  title?: string;
}

/**
 * Radio-group keyboard pattern: one tab stop (the checked option, roving tabIndex),
 * arrow keys move and select, Home/End jump to the first/last option.
 * Styling is left to the caller through `className` and `itemClassName(on)`.
 */
export function SegmentedRadio<T extends string>({
  value,
  onChange,
  options,
  ariaLabel,
  labelledBy,
  className,
  itemClassName,
}: {
  value: T;
  onChange: (v: T) => void;
  options: SegmentedOption<T>[];
  ariaLabel?: string;
  labelledBy?: string;
  className?: string;
  itemClassName: (on: boolean) => string;
}) {
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  // If nothing is checked, the first option keeps the tab stop.
  const focusIdx = Math.max(0, options.findIndex((o) => o.value === value));
  const onKey = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const n = options.length;
    let next: number;
    if (e.key === "ArrowRight" || e.key === "ArrowDown") next = (i + 1) % n;
    else if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = (i - 1 + n) % n;
    else if (e.key === "Home") next = 0;
    else if (e.key === "End") next = n - 1;
    else return;
    e.preventDefault();
    onChange(options[next].value);
    refs.current[next]?.focus();
  };
  return (
    <div role="radiogroup" aria-label={ariaLabel} aria-labelledby={labelledBy} className={className}>
      {options.map((o, i) => {
        const on = value === o.value;
        return (
          <button
            key={o.value}
            ref={(el) => {
              refs.current[i] = el;
            }}
            type="button"
            role="radio"
            aria-checked={on}
            aria-label={o.ariaLabel}
            title={o.title}
            tabIndex={i === focusIdx ? 0 : -1}
            onClick={() => onChange(o.value)}
            onKeyDown={(e) => onKey(e, i)}
            className={cn("focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring", itemClassName(on))}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}
