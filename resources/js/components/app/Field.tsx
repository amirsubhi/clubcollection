import type { ReactNode } from "react";
import { FieldError, FieldHint, Label } from "@/components/ui/input";
import { cn } from "@/lib/utils";

export function Field({
  id,
  label,
  required,
  hint,
  error,
  children,
  className,
}: {
  id?: string;
  label: string;
  required?: boolean;
  hint?: ReactNode;
  error?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex min-w-0 flex-col gap-1.5", className)}>
      <Label htmlFor={id}>
        {label}
        {required && <span className="text-danger"> *</span>}
      </Label>
      {children}
      {error ? <FieldError>{error}</FieldError> : hint ? <FieldHint>{hint}</FieldHint> : null}
    </div>
  );
}
