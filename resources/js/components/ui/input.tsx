import * as React from "react";
import { joinIds, useFieldState } from "@/components/ui/field-context";
import { fmtDate } from "@/lib/format";
import { cn } from "@/lib/utils";

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
}

/** Inside a <Field>, the id, aria-describedby (hint/error), required and invalid state come from the Field unless set here. */
export const Input = React.forwardRef<HTMLInputElement, InputProps>(({ className, invalid, id, required, "aria-describedby": describedBy, ...props }, ref) => {
  const field = useFieldState();
  const bad = invalid ?? field.invalid;
  const req = required ?? field.required;
  return (
    <input
      ref={ref}
      id={id ?? field.id}
      required={req || undefined}
      aria-required={req || undefined}
      aria-invalid={bad || undefined}
      aria-describedby={joinIds(describedBy, field.describedBy)}
      className={cn(
        "flex h-10 w-full min-w-0 rounded-md border border-input bg-card px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        bad && "border-danger focus-visible:ring-danger",
        className,
      )}
      {...props}
    />
  );
});
Input.displayName = "Input";

export function Label({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn("text-sm font-medium leading-none", className)} {...props} />;
}

export function FieldError({ children, id }: { children: React.ReactNode; id?: string }) {
  return (
    <p id={id} className="text-xs font-medium text-danger-foreground">
      {children}
    </p>
  );
}

export function FieldHint({ children, id }: { children: React.ReactNode; id?: string }) {
  return (
    <p id={id} className="text-xs text-muted-foreground">
      {children}
    </p>
  );
}

/**
 * The picked date written out ("16 Nov 2026") under a date field. The native picker shows dates in the
 * browser's own order (day/month or month/day), so this removes any doubt without contradicting it.
 */
export function DateHint({ value, className }: { value?: string | null; className?: string }) {
  const ok = !!value && /^\d{4}-\d{2}-\d{2}$/.test(value);
  return (
    <p className={cn("min-h-4 text-xs text-muted-foreground", className)} data-testid="date-echo">
      {ok ? fmtDate(value) : "\u00a0"}
    </p>
  );
}
