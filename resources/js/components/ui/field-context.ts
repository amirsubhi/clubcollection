import { createContext, useContext } from "react";

/** What a Field tells the control inside it: its id, the ids of its hint/error text, and whether it is required or invalid. */
export interface FieldState {
  id?: string;
  describedBy?: string;
  invalid?: boolean;
  required?: boolean;
}

export const FieldContext = createContext<FieldState>({});

/** Read by Input, Textarea and SelectTrigger so a control inside <Field> is linked to its label, hint and error without repeating ids. */
export function useFieldState(): FieldState {
  return useContext(FieldContext);
}

/** Merge an explicit aria-describedby with the Field's note id. */
export function joinIds(...ids: (string | undefined | false)[]): string | undefined {
  const s = ids.filter(Boolean).join(" ");
  return s || undefined;
}
