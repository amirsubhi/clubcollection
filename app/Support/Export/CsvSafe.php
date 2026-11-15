<?php

namespace App\Support\Export;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * CSV export hygiene (S1). A spreadsheet treats a cell starting with = + - @
 * (or a tab / carriage return) as a formula, so a member name such as
 * `=HYPERLINK(...)` could run when the treasurer opens the file. Such cells
 * get a leading apostrophe. Every CSV export writes its rows through row().
 */
final class CsvSafe
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(mixed $value): mixed
    {
        // Plain numbers ("-12.50", "+60123456789") stay numbers in the sheet.
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::TRIGGERS, true) ? "'".$value : $value;
    }

    /** @param  array<int, mixed>  $row */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }

    /** fputcsv() with every cell made safe. */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, self::row($row));
    }

    /**
     * A Content-Disposition header for a download: an ASCII slug of the name
     * (so a club name can't inject quotes or header text) plus the extension.
     */
    public static function disposition(string $name, string $extension): string
    {
        $file = (Str::slug($name) ?: 'export').'.'.$extension;

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file, $file);
    }
}
