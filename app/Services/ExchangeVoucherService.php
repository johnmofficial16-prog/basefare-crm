<?php

namespace App\Services;

use App\Models\ETicket;
use App\Models\TravelVoucher;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * ExchangeVoucherService — Future Travel Vouchers on Exchange (reissue) bookings.
 *
 * Client rule (4 Oct 2026): on an Exchange / Date Change, the amount charged
 * under Base Fare (fare line 1, whatever its label) is returned to the customer
 * as a Future Travel Voucher of the same amount. Agents, managers and admins
 * can change the amount on the acceptance; untouched, it is fare line 1.
 *
 * Lifecycle
 *   acceptance  extra_data.ftv = {amount, currency, valid_until}   normalize()
 *   transaction data.future_travel_voucher (copied from the acceptance)
 *   e-ticket    extra_data.ftv (copied by the e-ticket autofill)    forEticket()
 *   preview     travel_vouchers row issued + numbered               ensureIssued()
 *               agent's browser renders the standard voucher → PDF  savePdf()
 *   send        PDF attached to the e-ticket email
 *
 * Exchange only — every other type returns null everywhere.
 */
class ExchangeVoucherService
{
    const TYPE = 'exchange';

    const VALID_MONTHS = 12;   // agreed default: 1 year

    const REASON = 'EXCHANGE / REISSUE';

    const DEFAULT_TERMS = "1. Issued for the service fee on your ticket exchange; no cash value.\n"
        . "2. Valid for new flight bookings made through Base Fare only.\n"
        . "3. Must be redeemed before expiry; no extension allowed.\n"
        . "4. If the new booking exceeds the voucher value, the difference is payable by the passenger.\n"
        . "5. If the new booking is lower, the remaining balance is not refunded.\n"
        . "6. To redeem, call 888-608-4011 or email reservation@base-fare.com quoting the voucher number.";

    const MAX_PDF_BYTES = 5 * 1024 * 1024;

    // =========================================================================
    // ACCEPTANCE
    // =========================================================================

    /**
     * Clean the voucher block posted with an acceptance.
     *
     * @param array|null $raw         extra_data['ftv'] as posted
     * @param array      $fareLines   fare_breakdown [{label, amount}, ...]
     * @return array|null {amount: float, currency: string, valid_until: 'Y-m-d'} or null (no voucher)
     */
    public static function normalize(string $type, bool $isPreauth, ?array $raw, array $fareLines, string $currency): ?array
    {
        if ($type !== self::TYPE || $isPreauth) {
            return null;
        }

        $amount = isset($raw['amount']) && $raw['amount'] !== '' && is_numeric($raw['amount'])
            ? (float) $raw['amount']
            : (float) ($fareLines[0]['amount'] ?? 0);   // not edited → fare line 1
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return null;   // agent zeroed it: no voucher on this exchange
        }

        $valid = (string) ($raw['valid_until'] ?? '');
        $d = \DateTime::createFromFormat('Y-m-d', $valid);
        if (!$d || $d->format('Y-m-d') !== $valid || $valid <= date('Y-m-d')) {
            $valid = date('Y-m-d', strtotime('+' . self::VALID_MONTHS . ' months'));
        }

        return [
            'amount'      => $amount,
            'currency'    => strtoupper($currency ?: 'USD'),
            'valid_until' => $valid,
        ];
    }

    /** "CAD 150.00" */
    public static function money(array $ftv): string
    {
        return strtoupper($ftv['currency'] ?? 'USD') . ' ' . number_format((float) ($ftv['amount'] ?? 0), 2);
    }

    /** "04 Oct 2027" */
    public static function date(string $ymd): string
    {
        $t = strtotime($ymd);
        return $t ? date('d M Y', $t) : $ymd;
    }

    // =========================================================================
    // E-TICKET
    // =========================================================================

    /** The voucher an e-ticket carries, or null (not an exchange / none promised). */
    public static function forEticket(ETicket $et): ?array
    {
        $extra = is_array($et->extra_data) ? $et->extra_data : (json_decode((string) $et->extra_data, true) ?: []);
        $ftv = $extra['ftv'] ?? null;
        if (!is_array($ftv) || (float) ($ftv['amount'] ?? 0) <= 0) {
            return null;
        }
        // Type comes from the linked transaction or acceptance — exchange only
        $type = $et->transaction?->type ?? $et->acceptance?->type ?? null;
        return $type === self::TYPE ? $ftv : null;
    }

    public static function columnsReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = DB::schema()->hasColumn('travel_vouchers', 'eticket_id');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    public static function voucherFor(ETicket $et): ?TravelVoucher
    {
        if (!self::columnsReady()) return null;
        return TravelVoucher::where('eticket_id', $et->id)->where('status', '!=', 'void')->orderByDesc('id')->first();
    }

    /**
     * The voucher record for this e-ticket, issuing (and numbering) it on first
     * call. Amount/expiry follow the e-ticket's ftv until the PDF has been sent.
     */
    public static function ensureIssued(ETicket $et, array $ftv, int $userId): TravelVoucher
    {
        if (!self::columnsReady()) {
            throw new \RuntimeException('Vouchers are not switched on yet — run the 2026_10_04 migration.');
        }

        $firstTicket = '';
        foreach ((array) $et->ticket_data as $pax) {
            if (!empty($pax['ticket_number'])) { $firstTicket = (string) $pax['ticket_number']; break; }
        }

        $attrs = [
            'customer_name' => mb_strtoupper(trim((string) $et->customer_name)),
            'pnr'           => (string) $et->pnr,
            'ticket_number' => $firstTicket,
            'amount'        => (float) $ftv['amount'],
            'currency'      => strtoupper($ftv['currency'] ?? $et->currency ?? 'USD'),
            'expiry_date'   => $ftv['valid_until'] ?? date('Y-m-d', strtotime('+' . self::VALID_MONTHS . ' months')),
        ];

        $v = self::voucherFor($et);
        if ($v) {
            if ($v->pdf_path && $et->status !== ETicket::STATUS_DRAFT) {
                return $v;   // already sent to the customer — never change it silently
            }
            $changed = false;
            foreach ($attrs as $k => $val) {
                $cur = $v->$k instanceof \DateTimeInterface ? $v->$k->format('Y-m-d') : $v->$k;
                if ((string) $cur !== (string) $val) { $v->$k = $val; $changed = true; }
            }
            if ($changed) {
                $v->pdf_path = null;   // details changed → PDF must be re-rendered
                $v->save();
            }
            return $v;
        }

        return TravelVoucher::create($attrs + [
            'voucher_no'     => self::newNumber(),
            'issue_date'     => date('Y-m-d'),
            'reason'         => self::REASON,
            'terms'          => self::DEFAULT_TERMS,
            'status'         => 'active',
            'created_by'     => $userId,
            'source'         => 'exchange',
            'eticket_id'     => $et->id,
            'transaction_id' => $et->transaction_id,
            'acceptance_id'  => $et->acceptance_id,
        ]);
    }

    /** VCH-XXXXXX, unique (server-side; the maker's client-side numbers could collide). */
    private static function newNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I — read out over the phone
        for ($i = 0; $i < 20; $i++) {
            $no = 'VCH-';
            for ($j = 0; $j < 6; $j++) $no .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if (!TravelVoucher::where('voucher_no', $no)->exists()) return $no;
        }
        throw new \RuntimeException('Could not allocate a voucher number.');
    }

    // =========================================================================
    // PDF
    // =========================================================================

    /** Store the browser-rendered PDF. Returns an error string, or null on success. */
    public static function savePdf(TravelVoucher $v, ?UploadedFileInterface $file): ?string
    {
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return 'The voucher PDF did not upload. Please try again.';
        }
        if ($file->getSize() <= 0 || $file->getSize() > self::MAX_PDF_BYTES) {
            return 'The voucher PDF is empty or too large.';
        }

        $dir = dirname(__DIR__, 2) . '/storage/vouchers';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'Could not save the voucher PDF on the server.';
        }
        $rel = 'storage/vouchers/' . preg_replace('/[^A-Z0-9-]/', '', $v->voucher_no) . '_' . bin2hex(random_bytes(6)) . '.pdf';
        $abs = dirname(__DIR__, 2) . '/' . $rel;

        try {
            $file->moveTo($abs);
        } catch (\Throwable $e) {
            error_log('[ExchangeVoucher] moveTo failed: ' . $e->getMessage());
            return 'Could not save the voucher PDF on the server.';
        }

        $fh = @fopen($abs, 'rb');
        $magic = $fh ? fread($fh, 5) : '';
        if ($fh) fclose($fh);
        if ($magic !== '%PDF-') {
            @unlink($abs);
            return 'The uploaded file is not a PDF.';
        }

        $old = $v->pdf_path ? self::pdfAbsPath($v) : null;
        $v->pdf_path = $rel;
        $v->save();
        if ($old && $old !== realpath($abs)) @unlink($old);

        return null;
    }

    /** Absolute path of the stored PDF, or null if missing / outside storage/vouchers. */
    public static function pdfAbsPath(TravelVoucher $v): ?string
    {
        $rel = (string) $v->pdf_path;
        if (preg_match('#^storage/vouchers/[A-Z0-9-]+_[a-f0-9]{12}\.pdf$#', $rel) !== 1) return null;
        $base = realpath(dirname(__DIR__, 2) . '/storage/vouchers');
        $real = realpath(dirname(__DIR__, 2) . '/' . $rel);
        if ($base === false || $real === false) return null;
        return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }

    public static function pdfFilename(TravelVoucher $v): string
    {
        return 'Future_Travel_Voucher_' . $v->voucher_no . '.pdf';
    }
}
