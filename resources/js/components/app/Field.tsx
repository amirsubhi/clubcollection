import type { ReactNode } from "react";
import { FieldContext } from "@/components/ui/field-context";
import { DateHint, FieldError, FieldHint, Label } from "@/components/ui/input";
import { cn } from "@/lib/utils";

export function Field({
  id,
  label,
  required,
  hint,
  error,
  children,
  className,
  group = false,
  dateValue,
}: {
  id?: string;
  label: string;
  required?: boolean;
  hint?: ReactNode;
  error?: string;
  children: ReactNode;
  className?: string;
  /** The control is a group (e.g. a radiogroup), not a single input: the label is a plain element the group points at with aria-labelledby={`${id}-label`}. */
  group?: boolean;
  /** A date field's value (YYYY-MM-DD): written out ("16 Nov 2026") under the field. */
  dateValue?: string;
}) {
  const noteId = id && (error || hint) ? `${id}-${error ? "error" : "hint"}` : undefined;
  const mark = required && (
    <span className="text-danger" aria-hidden>
      {" "}
      *
    </span>
  );
  return (
    <FieldContext.Provider value={{ id, describedBy: noteId, invalid: !!error, required }}>
      <div className={cn("flex min-w-0 flex-col gap-1.5", className)}>
        {group ? (
          <span id={id ? `${id}-label` : undefined} className="text-sm font-medium leading-none">
            {label}
            {mark}
          </span>
        ) : (
          <Label htmlFor={id} id={id ? `${id}-label` : undefined}>
            {label}
            {mark}
          </Label>
        )}
        {children}
        {dateValue && <DateHint value={dateValue} className="-mt-0.5" />}
        {error ? <FieldError id={noteId}>{error}</FieldError> : hint ? <FieldHint id={noteId}>{hint}</FieldHint> : null}
      </div>
    </FieldContext.Provider>
  );
}
