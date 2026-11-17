<?php

namespace App\Support\Audit;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Audit log descriptions as people read them, at display time only (stored rows never change):
 * "Payment #42" becomes the fee's transfer reference (Payment::transferReference(): "YURAN-42", or the
 * group's "YURAN-G40" for a fee sent or paid with a group slip) when that fee still exists, and ISO dates
 * ("2026-11-15") become "15 Nov 2026".
 */
final class FriendlyDescription
{
    private const PAYMENT = '/\bPayment #(\d+)\b/';

    private const ISO_DATE = '/\b(\d{4})-(\d{2})-(\d{2})\b/';

    /** @param  array<int, string>  $payments  fee id => reference, for fees that exist (see forPage()) */
    private function __construct(private array $payments) {}

    /**
     * One lookup for a page of audit rows: which of the fees they mention still exist.
     *
     * @param  iterable<\App\Models\AuditLog>  $logs
     */
    public static function forPage(iterable $logs): self
    {
        $ids = Collection::make($logs)
            ->flatMap(fn ($log) => preg_match_all(self::PAYMENT, (string) $log->description, $m) ? $m[1] : [])
            ->map(fn ($id) => (int) $id)->unique()->values();

        // One query, with the group slip's reference joined in as Payment::transferReference() would find it.
        $known = $ids->isEmpty() ? [] : Payment::query()
            ->leftJoin('payment_bills as tg', function ($j) {
                $j->on('tg.id', '=', 'payments.transfer_group_id')->whereIn('payments.status', ['pending_verification', 'paid']);
            })
            ->whereIn('payments.id', $ids)
            ->get(['payments.id', 'tg.reference as group_reference'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->group_reference ?: 'YURAN-'.$p->id])->all();

        return new self($known);
    }

    /** Plain text (e.g. a data attribute). */
    public function format(?string $text): string
    {
        return $this->replace((string) $text, fn (string $s) => $s);
    }

    /** For the table cell: escaped, with each reference and date kept on one line. */
    public function html(?string $text): HtmlString
    {
        return new HtmlString($this->replace(e((string) $text), fn (string $s) => '<span class="text-nowrap">'.$s.'</span>'));
    }

    private function replace(string $text, \Closure $wrap): string
    {
        $text = preg_replace_callback(self::PAYMENT, fn ($m) => isset($this->payments[(int) $m[1]]) ? $wrap($this->payments[(int) $m[1]]) : $m[0], $text);

        return preg_replace_callback(self::ISO_DATE, function ($m) use ($wrap) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $m[0];
            }

            return $wrap(Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->format('j M Y'));
        }, $text);
    }
}
