<?php

declare(strict_types=1);

namespace ClubPortalUpdater;

/** The cPanel PHP handler block in public/.htaccess belongs to the host, not to the release: it is carried over. */
final class Htaccess
{
    private const BEGIN = '/^[ \t]*# php -- BEGIN cPanel-generated handler/';

    private const END = '/^[ \t]*# php -- END cPanel-generated handler/';

    /**
     * Returns the new file with the old file's cPanel block at its top. A block the new file already has is replaced by
     * the old one, so there is never more than one. Without a complete block in the old file the new file is returned as is.
     */
    public static function carryCpanelBlock(string $old, string $new): string
    {
        $block = self::block($old);
        if ($block === null) {
            return $new;
        }

        return $block.self::withoutBlock($new);
    }

    /** The first complete block (BEGIN line through END line, newline included), or null. */
    private static function block(string $text): ?string
    {
        $lines = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $start = null;
        foreach ($lines as $i => $line) {
            if ($start === null && preg_match(self::BEGIN, $line) === 1) {
                $start = $i;
            } elseif ($start !== null && preg_match(self::END, $line) === 1) {
                $block = implode('', array_slice($lines, $start, $i - $start + 1));

                return str_ends_with($block, "\n") ? $block : $block."\n";
            }
        }

        return null;
    }

    private static function withoutBlock(string $text): string
    {
        $lines = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $inside = false;
        foreach ($lines as $line) {
            if (! $inside && preg_match(self::BEGIN, $line) === 1) {
                $inside = true;
            }
            if (! $inside) {
                $kept[] = $line;
            } elseif (preg_match(self::END, $line) === 1) {
                $inside = false;
            }
        }

        // An unterminated block in the new file is kept as written (nothing to cut safely).
        return $inside ? $text : implode('', $kept);
    }
}
