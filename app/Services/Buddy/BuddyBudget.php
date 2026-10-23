<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use App\Services\ShiftService;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * BuddyBudget — the hard cap that replaced the tight per-agent message limits.
 *
 * John, 23 Oct 2026: "we don't wanna get billed in thousands". The usage report
 * showed the opposite problem — $1.19 for the whole company in 30 days — while
 * the old limits (6 messages a minute, 40 a day) cut real conversations off.
 * So the protection moved from "how much may one person talk" to "how much may
 * Aisha spend today, in total": every Gemini call and every spoken line is
 * recorded here against the shift day, and once the day's spend reaches the
 * cap Aisha switches to her basic built-in replies until the next 6 PM.
 *
 * Knobs (.env, no deploy):
 *   BUDDY_DAILY_BUDGET_USD   AI spend per shift day before basic mode. Default 2.50 (~₹210).
 *   BUDDY_TTS_DAILY_CHARS    characters of speech per shift day. Default 150000.
 *   BUDDY_PRICE_IN / _OUT    $ per million tokens for the thinking models (3.x). Default 1.50 / 9.00.
 *   BUDDY_PRICE_FAST_IN/_OUT $ per million tokens for 2.5 models. Default 0.30 / 2.50.
 *
 * Fail-open by design: if the ledger table is missing (migration not run) or
 * the database hiccups, Aisha keeps working and the miss is logged. A broken
 * meter must never take her offline.
 */
final class BuddyBudget
{
    private const DEFAULT_DAILY_USD   = 2.50;
    private const DEFAULT_TTS_CHARS   = 150000;

    /** Has today's AI money run out? */
    public static function exhausted(): bool
    {
        try {
            return self::spentToday() >= self::dailyCap();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function dailyCap(): float
    {
        $v = self::env('BUDDY_DAILY_BUDGET_USD');
        return is_numeric($v) && (float) $v > 0 ? (float) $v : self::DEFAULT_DAILY_USD;
    }

    /** Dollars of Gemini spent this shift day (speech excluded — it has its own cap). */
    public static function spentToday(): float
    {
        try {
            return (float) DB::table('buddy_ai_usage')
                ->where('bday', self::bday())
                ->where('model', '!=', 'tts')
                ->sum('cost_usd');
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /** May Aisha speak this many more characters today? */
    public static function ttsAllowed(int $chars): bool
    {
        try {
            $used = (int) DB::table('buddy_ai_usage')
                ->where('bday', self::bday())->where('model', 'tts')->value('tokens_out');
            $cap = self::env('BUDDY_TTS_DAILY_CHARS');
            $cap = is_numeric($cap) && (int) $cap > 0 ? (int) $cap : self::DEFAULT_TTS_CHARS;
            return $used + $chars <= $cap;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** Record one Gemini call. Never throws. */
    public static function record(string $model, int $tokensIn, int $tokensOut): void
    {
        [$pin, $pout] = self::price($model);
        self::add($model, $tokensIn, $tokensOut, $tokensIn / 1e6 * $pin + $tokensOut / 1e6 * $pout);
    }

    /** Record spoken characters (synthesis only — cache hits cost nothing). */
    public static function recordTts(int $chars): void
    {
        // Google's HD voices are billed per million characters; $30 is the
        // conservative end of the published range, so the cap errs safe.
        self::add('tts', 0, $chars, $chars / 1e6 * 30.0);
    }

    /** @return array{0: float, 1: float} $ per million tokens in / out */
    public static function price(string $model): array
    {
        if (str_starts_with($model, 'gemini-2.')) {
            return [self::num('BUDDY_PRICE_FAST_IN', 0.30), self::num('BUDDY_PRICE_FAST_OUT', 2.50)];
        }
        return [self::num('BUDDY_PRICE_IN', 1.50), self::num('BUDDY_PRICE_OUT', 9.00)];
    }

    /** Today's ledger, for the maintenance buddy and the usage report. */
    public static function today(): array
    {
        try {
            $rows = DB::table('buddy_ai_usage')->where('bday', self::bday())->get();
            $out = ['bday' => self::bday(), 'cap_usd' => self::dailyCap(), 'spent_usd' => 0.0, 'models' => []];
            foreach ($rows as $r) {
                $out['models'][$r->model] = ['calls' => (int) $r->calls, 'tokens_in' => (int) $r->tokens_in,
                    'tokens_out' => (int) $r->tokens_out, 'cost_usd' => round((float) $r->cost_usd, 4)];
                if ($r->model !== 'tts') {
                    $out['spent_usd'] += (float) $r->cost_usd;
                }
            }
            $out['spent_usd'] = round($out['spent_usd'], 4);
            return $out;
        } catch (Throwable $e) {
            return ['bday' => self::bday(), 'cap_usd' => self::dailyCap(), 'spent_usd' => 0.0, 'models' => [], 'error' => 'ledger unavailable'];
        }
    }

    private static function add(string $model, int $in, int $out, float $cost): void
    {
        try {
            $bday = self::bday();
            $now  = date('Y-m-d H:i:s');
            $hit = DB::table('buddy_ai_usage')->where('bday', $bday)->where('model', $model)->update([
                'calls'      => DB::raw('calls + 1'),
                'tokens_in'  => DB::raw('tokens_in + ' . max(0, $in)),
                'tokens_out' => DB::raw('tokens_out + ' . max(0, $out)),
                'cost_usd'   => DB::raw('cost_usd + ' . sprintf('%.6F', max(0.0, $cost))),
                'updated_at' => $now,
            ]);
            if ($hit === 0) {
                try {
                    DB::table('buddy_ai_usage')->insert([
                        'bday' => $bday, 'model' => mb_substr($model, 0, 40), 'calls' => 1,
                        'tokens_in' => max(0, $in), 'tokens_out' => max(0, $out),
                        'cost_usd' => round(max(0.0, $cost), 6), 'updated_at' => $now,
                    ]);
                } catch (Throwable $e) {
                    // two requests raced to create today's row — add to the winner's
                    DB::table('buddy_ai_usage')->where('bday', $bday)->where('model', $model)->update([
                        'calls'      => DB::raw('calls + 1'),
                        'tokens_in'  => DB::raw('tokens_in + ' . max(0, $in)),
                        'tokens_out' => DB::raw('tokens_out + ' . max(0, $out)),
                        'cost_usd'   => DB::raw('cost_usd + ' . sprintf('%.6F', max(0.0, $cost))),
                        'updated_at' => $now,
                    ]);
                }
            }
        } catch (Throwable $e) {
            static $warned = false;
            if (!$warned) {
                $warned = true;
                ErrorLogService::log('warning', '[buddy] AI usage ledger write failed (run the aisha_v2 migration?): ' . $e->getMessage());
            }
        }
    }

    private static function bday(): string
    {
        try {
            [$start] = ShiftService::businessDayBounds();
            return substr((string) $start, 0, 10);
        } catch (Throwable $e) {
            return date('Y-m-d', time() - 18 * 3600);
        }
    }

    private static function env(string $k): ?string
    {
        $v = $_ENV[$k] ?? getenv($k);
        return $v === false || $v === null ? null : (string) $v;
    }

    private static function num(string $k, float $default): float
    {
        $v = self::env($k);
        return is_numeric($v) && (float) $v >= 0 ? (float) $v : $default;
    }
}
