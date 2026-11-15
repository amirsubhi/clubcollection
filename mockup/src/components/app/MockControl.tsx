import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface MockOption<T extends string> {
  value: T;
  label: string;
}

/** A clearly-labelled switcher that only exists in the mockup (to show different states). */
export function MockControl<T extends string>({
  label,
  value,
  options,
  onChange,
  className,
  children,
}: {
  label: string;
  value: T;
  options: MockOption<T>[];
  onChange: (v: T) => void;
  className?: string;
  children?: ReactNode;
}) {
  return (
    <div className={cn("flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-mock-border bg-mock-soft p-2 text-xs text-mock", className)}>
      <span className="font-semibold uppercase tracking-wide">Mockup control · {label}</span>
      <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1">
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={value === o.value}
            onClick={() => onChange(o.value)}
            className={cn(
              "min-h-8 rounded-md border px-2 py-1 font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-mock",
              value === o.value ? "border-mock bg-mock text-mock-soft" : "border-mock-border bg-card hover:bg-mock-soft",
            )}
          >
            {o.label}
          </button>
        ))}
      </div>
      {children}
    </div>
  );
}
