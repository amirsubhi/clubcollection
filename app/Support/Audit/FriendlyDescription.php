<?php

namespace App\Support\Audit;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Audit log descriptions as people read them, at display time only (stored rows never change):
 * - "Payment #42" becomes the fee's transfer reference (Payment::transferReference(): "YURAN-42", or the group's
 *   "YURAN-G40" for a fee sent or paid with a group slip);
 * - "attached to Expense #12" (older attachment rows) becomes the Fund Usage record number ("FU-2026-0012");
 * - a raw kind right after the document's quoted name, '"x.pdf" (proof_of_payment) attached to', becomes
 *   "(Proof of payment)";
 * - ISO dates ("2026-11-15") become "15 Nov 2026".
 * References are rewritten only when the fee or record still exists AND belongs to the log row's own club, so a
 * number typed by someone (or from another club) never turns into another club's reference. Text inside double
 * quotes (file names, typed names) is left exactly as written. New attachment rows are already written readably
 * (AttachmentService).
 */
final class FriendlyDescription
{
    private const PAYMENT = '\bPayment #(?<pay>\d+)\b';

    /** Only where AttachmentService wrote the owner: "attached to Expense #N" / "from Expense #N". */
    private const EXPENSE = '(?<=attached to |from )Expense #(?<exp>\d+)\b';

    private const ISO_DATE = '\b(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})\b';

    /** A raw kind at the start of the text that follows a quoted file name. */
    private const KIND = '/^ \((invoice|receipt|bank_slip|acknowledgement|other|proof_of_payment)\)(?= attached to| removed)/';

    /**
     * @param  array<int, array{club: int|null, ref: string}>  $payments  fee id => owner club and reference
     * @param  array<int, array{club: int|null, ref: string}>  $expenses  Fund Usage id => owner club and FU- number
     */
    private function __construct(private array $payments, private array $expenses = []) {}

    /**
     * One lookup per kind for a page of audit rows: which of the fees and records they mention still exist.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public static function forPage(iterable $logs): self
    {
        $ids = fn (string $re, string $group) => Collection::make($logs)
            ->flatMap(fn ($log) => preg_match_all('/'.$re.'/', (string) $log->description, $m) ? $m[$group] : [])
            ->map(fn ($id) => (int) $id)->unique()->values();

        $payIds = $ids(self::PAYMENT, 'pay');
        // One query, with the group slip's reference joined in as Payment::transferReference() would find it.
        $payments = $payIds->isEmpty() ? [] : Payment::query()
            ->leftJoin('payment_bills as tg', function ($j) {
                $j->on('tg.id', '=', 'payments.transfer_group_id')->whereIn('payments.status', ['pending_verification', 'paid']);
            })
            ->whereIn('payments.id', $payIds)
            ->get(['payments.id', 'payments.club_id', 'tg.reference as group_reference'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => ['club' => $p->club_id === null ? null : (int) $p->club_id, 'ref' => $p->group_reference ?: 'YURAN-'.$p->id]])
            ->all();

        $expIds = $ids(self::EXPENSE, 'exp');
        $expenses = $expIds->isEmpty() ? [] : Expense::query()->whereIn('id', $expIds)->get(['id', 'club_id', 'created_at'])
            ->mapWithKeys(fn (Expense $e) => [(int) $e->id => ['club' => $e->club_id === null ? null : (int) $e->club_id, 'ref' => $e->reference()]])
            ->all();

        return new self($payments, $expenses);
    }

    /** Plain text (e.g. a data attribute). $clubId: the log row's club_id. */
    public function format(?string $text, ?int $clubId = null): string
    {
        return $this->replace((string) $text, $clubId, fn (string $s) => $s, fn (string $s) => $s);
    }

    /** For the table cell: escaped, with each reference and date kept on one line. $clubId: the log row's club_id. */
    public function html(?string $text, ?int $clubId = null): HtmlString
    {
        return new HtmlString($this->replace((string) $text, $clubId, fn (string $s) => e($s), fn (string $s) => '<span class="text-nowrap">'.e($s).'</span>'));
    }

    private function replace(string $text, ?int $clubId, \Closure $esc, \Closure $wrap): string
    {
        // Odd parts are the double-quoted spans (kept verbatim); even parts are the system's own wording.
        $parts = preg_split('/("[^"\n]*")/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= $esc($part);

                continue;
            }
            if ($i > 0 && preg_match(self::KIND, $part, $k)) {
                $out .= $esc(' ('.Attachment::kindLabel($k[1]).')');
                $part = substr($part, strlen($k[0]));
            }
            $out .= $this->replaceRefs($part, $clubId, $esc, $wrap);
        }

        return $out;
    }

    private function replaceRefs(string $text, ?int $clubId, \Closure $esc, \Closure $wrap): string
    {
        $re = '/'.self::PAYMENT.'|'.self::EXPENSE.'|'.self::ISO_DATE.'/';
        $out = '';
        $at = 0;
        preg_match_all($re, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $out .= $esc(substr($text, $at, $offset - $at));
            $at = $offset + strlen($whole);
            $out .= $this->friendly($m, $clubId, $esc, $wrap) ?? $esc($whole);
        }

        return $out.$esc(substr($text, $at));
    }

    /** The friendly form of one match, or null to keep it as written. */
    private function friendly(array $m, ?int $clubId, \Closure $esc, \Closure $wrap): ?string
    {
        $own = fn (?array $hit) => $hit !== null && $clubId !== null && $hit['club'] === $clubId ? $wrap($hit['ref']) : null;

        if (($m['pay'][0] ?? null) !== null) {
            return $own($this->payments[(int) $m['pay'][0]] ?? null);
        }
        if (($m['exp'][0] ?? null) !== null) {
            return $own($this->expenses[(int) $m['exp'][0]] ?? null);
        }
        [$y, $mo, $d] = [(int) $m['y'][0], (int) $m['m'][0], (int) $m['d'][0]];

        return checkdate($mo, $d, $y) ? $wrap(Carbon::create($y, $mo, $d)->format('j M Y')) : null;
    }
}
