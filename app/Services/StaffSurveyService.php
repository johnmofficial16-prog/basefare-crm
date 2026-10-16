<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * Staff survey: experience, mindset, goals and feedback.
 *
 * Who: every non-admin (agent, CSA, supervisor, manager).
 * When: after each sign-in, and when they go idle. "Not now" works
 * MAX_SKIPS times (each one snoozes the idle pop-up for SNOOZE_MINUTES);
 * after that it has to be filled before carrying on.
 *
 * A new round of the survey = a new KEY. Answers are kept per KEY.
 */
final class StaffSurveyService
{
    public const KEY = '2026-10';
    public const MAX_SKIPS = 3;
    public const SNOOZE_MINUTES = 30;
    public const IDLE_MINUTES = 3;
    private const TEXT_MAX = 1500;

    /** Written answers in the "one more minute" card: asked at most this many times, then never again. */
    public const MORE_MAX_DECLINES = 2;
    private const MORE_SNOOZE_HOURS = 20;
    /** The card is for the quiet ones: people who wrote fewer than this many answers in their own words. */
    private const MORE_IF_WRITTEN_UNDER = 2;

    /**
     * The questions, in pages. Types: scale (1–5), nps (0–10), choice (one),
     * multi (up to `max`), text (free, optional unless `required`).
     *
     * `followup` (on a scale/nps/choice question): a short written question that
     * appears right under it when the answer is one of `when` — "you gave 2/5,
     * what's behind that?". Asking about the person's own answer gets far more
     * people writing than a general box at the end. Always optional.
     * `starters` (on text questions): tap-to-start phrases, so nobody faces an
     * empty box.
     */
    public const PAGES = [
        [
            'title' => 'Your experience here',
            'intro' => 'Tap the answer closest to how you feel. There are no right or wrong answers.',
            'questions' => [
                ['key' => 'happy', 'type' => 'scale', 'label' => 'Overall, how happy are you working here?', 'low' => 'Very unhappy', 'high' => 'Very happy',
                    'followup' => ['key' => 'happy_why', 'when' => [1, 2, 3], 'label' => 'What would make it a 5 for you?', 'placeholder' => 'The one thing that would make work better',
                        'starters' => ['It would be a 5 if…', 'The main thing is…', 'Right now…']]],
                ['key' => 'recommend', 'type' => 'nps', 'label' => 'How likely are you to recommend working here to a friend?', 'low' => 'Not at all likely', 'high' => 'Extremely likely',
                    'followup' => ['key' => 'recommend_why', 'when' => [0, 1, 2, 3, 4, 5, 6], 'label' => 'What stops you from recommending us?', 'placeholder' => 'What would a friend find hard here?',
                        'starters' => ['A friend would find…', 'I would recommend it if…']]],
                ['key' => 'valued', 'type' => 'scale', 'label' => 'Do you feel your work is noticed and valued?', 'low' => 'Not at all', 'high' => 'Very much',
                    'followup' => ['key' => 'valued_why', 'when' => [1, 2], 'label' => 'What would make you feel more valued?', 'placeholder' => 'For example: a thank-you, an award, being asked for ideas',
                        'starters' => ['I would feel valued if…', 'Nobody noticed when…']]],
                ['key' => 'support', 'type' => 'scale', 'label' => 'How well supported do you feel by the people you report to?', 'low' => 'Not supported', 'high' => 'Fully supported',
                    'followup' => ['key' => 'support_why', 'when' => [1, 2], 'label' => 'What support do you need that you are not getting?', 'placeholder' => 'From your team leader, supervisor or manager',
                        'starters' => ['I need help with…', 'It would help if my manager…']]],
                ['key' => 'tools', 'type' => 'scale', 'label' => 'Do you have the training and tools to do your job well?', 'low' => 'Not at all', 'high' => 'Completely',
                    'followup' => ['key' => 'tools_why', 'when' => [1, 2], 'label' => 'What training or tool is missing?', 'placeholder' => 'For example: GDS training, a better headset, faster PC',
                        'starters' => ['I need training on…', 'The tool that slows me down is…']]],
                ['key' => 'workload', 'type' => 'scale', 'label' => 'How manageable are your workload and shift timings?', 'low' => 'Too much', 'high' => 'Very manageable',
                    'followup' => ['key' => 'workload_why', 'when' => [1, 2], 'label' => 'What is the hardest part of the workload or timings?', 'placeholder' => 'Shift hours, targets, breaks, travel time…',
                        'starters' => ['The hardest part is…', 'The night shift…', 'Targets…']]],
            ],
        ],
        [
            'title' => 'How you feel',
            'intro' => 'Write the way you speak. English, Hindi, Punjabi or Hinglish are all fine. A line is enough.',
            'questions' => [
                ['key' => 'shift_end', 'type' => 'choice', 'label' => 'How do you usually feel at the end of a shift?',
                    'options' => ['Energised', 'Satisfied', 'Tired but fine', 'Drained', 'Stressed'],
                    'followup' => ['key' => 'shift_why', 'when' => ['Drained', 'Stressed'], 'label' => 'What drains you most during a shift?', 'placeholder' => 'Calls, targets, timings, people…',
                        'starters' => ['What drains me is…', 'By the end of the shift…']]],
                ['key' => 'stay', 'type' => 'choice', 'label' => 'Do you see yourself working here a year from now?',
                    'options' => ['Yes, definitely', 'Probably', 'Not sure', 'Probably not', 'No'],
                    'followup' => ['key' => 'stay_why', 'when' => ['Not sure', 'Probably not', 'No'], 'label' => 'What would make you stay?', 'placeholder' => 'Be honest. This is exactly what we need to hear.',
                        'starters' => ['I would stay if…', 'I am thinking of leaving because…']]],
                ['key' => 'best', 'type' => 'text', 'label' => 'What is the best thing about working here?', 'placeholder' => 'For example: my team, the incentives, learning every day',
                    'starters' => ['My team…', 'The incentives…', 'I am learning…', 'My manager…']],
                ['key' => 'frustration', 'type' => 'text', 'label' => 'What frustrates you most at work?', 'placeholder' => 'Anything that gets in the way of doing your best',
                    'starters' => ['Shift timings…', 'Leads and calls…', 'Targets…', 'The system…', 'Breaks…']],
            ],
        ],
        [
            'title' => 'Your goals',
            'intro' => 'Where you want to go, so we can help you get there.',
            'questions' => [
                ['key' => 'path', 'type' => 'choice', 'label' => 'Where would you like to be in 2 years?',
                    'options' => ['Leading a team', 'A top performer in sales', 'In another department (quality, training, operations or marketing)', 'Running my own business', 'Studying further', 'Not sure yet']],
                ['key' => 'goals', 'type' => 'multi', 'max' => 3, 'label' => 'What matters most to you in the next 12 months? Pick up to 3.',
                    'options' => ['Earning more (incentives)', 'A promotion', 'Learning new skills', 'Better work-life balance', 'Job security', 'Saving for something big', 'Further studies', 'Recognition for my work']],
                ['key' => 'skill', 'type' => 'text', 'label' => 'Which skill would you most like to learn?', 'placeholder' => 'For example: closing sales, GDS, English, leading people',
                    'starters' => ['Closing sales', 'GDS and ticketing', 'Speaking English confidently', 'Leading a team', 'Handling angry customers']],
            ],
        ],
        [
            'title' => 'How we can help',
            'intro' => 'Your ideas shape what the company does next. Write in any language you like.',
            'questions' => [
                ['key' => 'help', 'type' => 'multi', 'max' => 3, 'label' => 'What could the company do to help you reach your goals? Pick up to 3.',
                    'options' => ['More training sessions', 'A clear promotion path', 'A better incentive plan', 'A mentor', 'Flexible shift options', 'Recognition and awards', 'Health and wellness support', 'Better tools and systems', 'Transport support']],
                ['key' => 'boss', 'type' => 'text', 'label' => 'If you ran the company for one day, what one thing would you change?', 'placeholder' => 'One change that would make the biggest difference',
                    'starters' => ['I would change…', 'I would give everyone…', 'I would stop…']],
                ['key' => 'feedback', 'type' => 'text', 'label' => 'Anything else you would like to tell the management?', 'placeholder' => 'Your feedback, ideas or concerns',
                    'starters' => ['I wish…', 'Please…', 'Thank you for…']],
            ],
        ],
    ];

    /**
     * Every question by key, follow-ups included (as text questions with a `parent`).
     * Order: each follow-up comes straight after its question.
     */
    public static function questions(): array
    {
        $out = [];
        foreach (self::PAGES as $page) {
            foreach ($page['questions'] as $q) {
                $f = $q['followup'] ?? null;
                unset($q['followup']);
                $out[$q['key']] = $q;
                if ($f) {
                    $out[$f['key']] = ['type' => 'text', 'parent' => $q['key']] + $f;
                }
            }
        }
        return $out;
    }

    /** Does this answer to a question open its follow-up? */
    public static function followupOpen(array $followup, mixed $answer): bool
    {
        return $answer !== null && $answer !== '' && in_array($answer, $followup['when'], true);
    }

    /**
     * The written questions this person was asked (follow-ups only where their
     * answer opened one), in survey order.
     */
    public static function writtenAsked(array $answers): array
    {
        $out = [];
        foreach (self::questions() as $key => $q) {
            if ($q['type'] !== 'text') {
                continue;
            }
            if (isset($q['parent']) && !self::followupOpen($q, $answers[$q['parent']] ?? null)) {
                continue;
            }
            $out[$key] = $q;
        }
        return $out;
    }

    /** The survey is for everyone except admins. */
    public static function appliesTo(?string $role): bool
    {
        return in_array($role, [User::ROLE_AGENT, User::ROLE_CSA, User::ROLE_SUPERVISOR, User::ROLE_MANAGER], true);
    }

    /**
     * Where this person stands with the current survey.
     * Cached in the session once they have submitted, so finished people cost no query.
     *
     * `more` is set for people who answered but left written answers empty
     * (see more()); null otherwise.
     *
     * @return array{done:bool, skips:int, skips_left:int, snoozed_until:?string, more:?array}
     */
    public static function state(int $userId): array
    {
        if (!empty($_SESSION['staff_survey_done'][self::KEY])) {
            return ['done' => true, 'skips' => 0, 'skips_left' => 0, 'snoozed_until' => null, 'more' => self::more($userId)];
        }
        $done = DB::table('staff_survey_responses')->where('survey_key', self::KEY)->where('user_id', $userId)->exists();
        if ($done) {
            $_SESSION['staff_survey_done'][self::KEY] = true;
            return ['done' => true, 'skips' => 0, 'skips_left' => 0, 'snoozed_until' => null, 'more' => self::more($userId)];
        }
        $p = DB::table('staff_survey_prompts')->where('survey_key', self::KEY)->where('user_id', $userId)->first();
        $skips = (int) ($p->skips ?? 0);
        return [
            'done' => false,
            'skips' => $skips,
            'skips_left' => max(0, self::MAX_SKIPS - $skips),
            'snoozed_until' => $p->snoozed_until ?? null,
            'more' => null,
        ];
    }

    // ---- "one more minute": written answers from people who already answered ----

    private static function moreKey(): string
    {
        return self::KEY . ':more';
    }

    /**
     * For someone who sent the survey writing (almost) nothing in their own
     * words: the (up to 4) empty questions worth asking again, follow-ups about
     * their own low scores first. Null when there is nothing to ask, they already answered
     * this card, said "No thanks" MORE_MAX_DECLINES times, or are snoozed.
     * A "nothing to ask" result is cached in the session.
     *
     * @return ?array{questions:list<array>, declines_left:int}
     */
    public static function more(int $userId): ?array
    {
        if (($_SESSION['staff_survey_more'][self::KEY] ?? null) === 'none') {
            return null;
        }
        $none = static function () {
            $_SESSION['staff_survey_more'][self::KEY] = 'none';
            return null;
        };
        $answersJson = DB::table('staff_survey_responses')->where('survey_key', self::KEY)->where('user_id', $userId)->value('answers');
        if ($answersJson === null) {
            return null; // not answered yet: the main survey handles them
        }
        $answers = json_decode((string) $answersJson, true) ?: [];
        if (!empty($answers['_meta']['more_at'])) {
            return $none();
        }
        $p = DB::table('staff_survey_prompts')->where('survey_key', self::moreKey())->where('user_id', $userId)->first();
        $declines = (int) ($p->skips ?? 0);
        if ($declines >= self::MORE_MAX_DECLINES) {
            return $none();
        }
        if (!empty($p->snoozed_until) && strtotime($p->snoozed_until) > time()) {
            return null; // asked recently; not cached, so it can come back after the snooze
        }
        $empty = self::emptyWritten($answers);
        $written = count(self::writtenAsked($answers)) - count($empty);
        if (!$empty || $written >= self::MORE_IF_WRITTEN_UNDER) {
            return $none();
        }
        $all = self::questions();
        $questions = [];
        foreach (array_slice($empty, 0, 4) as $key) {
            $q = $all[$key];
            $context = null;
            if (isset($q['parent'])) {
                // Remind them what they answered, so the question makes sense on its own.
                $parent = $all[$q['parent']];
                $given = $answers[$q['parent']];
                $context = match ($parent['type']) {
                    'scale' => 'You gave ' . $given . ' out of 5 to: “' . $parent['label'] . '”',
                    'nps' => 'You gave ' . $given . ' out of 10 to: “' . $parent['label'] . '”',
                    default => 'You answered “' . $given . '” to: “' . $parent['label'] . '”',
                };
            }
            $questions[] = ['key' => $key, 'type' => 'text', 'label' => $q['label'], 'placeholder' => $q['placeholder'] ?? '',
                'starters' => $q['starters'] ?? [], 'context' => $context];
        }
        return ['questions' => $questions, 'declines_left' => self::MORE_MAX_DECLINES - $declines];
    }

    /** Written questions they were asked but left empty: follow-ups first, then the most useful general ones. */
    private static function emptyWritten(array $answers): array
    {
        $asked = self::writtenAsked($answers);
        $empty = array_keys(array_filter($asked, fn($q, $k) => trim((string) ($answers[$k] ?? '')) === '', ARRAY_FILTER_USE_BOTH));
        $rank = ['feedback' => 1, 'frustration' => 2, 'boss' => 3, 'best' => 4, 'skill' => 5];
        usort($empty, function ($a, $b) use ($asked, $rank) {
            $fa = isset($asked[$a]['parent']) ? 0 : 1;
            $fb = isset($asked[$b]['parent']) ? 0 : 1;
            return [$fa, $rank[$a] ?? 0] <=> [$fb, $rank[$b] ?? 0];
        });
        return $empty;
    }

    /** "No thanks" on the one-more-minute card. */
    public static function moreDecline(int $userId): void
    {
        $until = date('Y-m-d H:i:s', time() + self::MORE_SNOOZE_HOURS * 3600);
        DB::statement(
            'INSERT INTO staff_survey_prompts (survey_key, user_id, skips, snoozed_until) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE skips = LEAST(skips + 1, 255), snoozed_until = ?',
            [self::moreKey(), $userId, $until, $until]
        );
        $_SESSION['staff_survey_more'][self::KEY] = 'none'; // not again this session
        unset($_SESSION['staff_survey_due']);
    }

    /**
     * Save the one-more-minute answers into their existing response. Only fills
     * answers that were empty; never overwrites what they wrote before.
     *
     * @throws SurveyInvalid when nothing was written
     */
    public static function moreSubmit(int $userId, mixed $answers, mixed $meta = null): void
    {
        $row = DB::table('staff_survey_responses')->where('survey_key', self::KEY)->where('user_id', $userId)->first(['id', 'answers', 'feedback']);
        if (!$row) {
            throw new SurveyInvalid('Please fill in the survey first.', []);
        }
        $current = json_decode((string) $row->answers, true) ?: [];
        $asked = self::writtenAsked($current);
        $answers = is_array($answers) ? $answers : [];
        $added = 0;
        $errors = [];
        foreach ($answers as $key => $v) {
            if (!is_string($key) || !isset($asked[$key]) || trim((string) ($current[$key] ?? '')) !== '') {
                continue;
            }
            $t = self::cleanText($v);
            if (mb_strlen($t) > self::TEXT_MAX) {
                $errors[$key] = 'Please keep this under ' . number_format(self::TEXT_MAX) . ' characters.';
                continue;
            }
            if ($t !== '') {
                $current[$key] = $t;
                $added++;
            }
        }
        if ($errors) {
            throw new SurveyInvalid('One answer is too long.', $errors);
        }
        if (!$added) {
            throw new SurveyInvalid('Write at least one answer, or press "No thanks".', []);
        }
        $m = self::cleanMeta($meta);
        $current['_meta'] = is_array($current['_meta'] ?? null) ? $current['_meta'] : [];
        $current['_meta']['more_at'] = date('Y-m-d H:i:s');
        $current['_meta']['more_secs'] = $m['secs'] ?? null;
        $current['_meta']['voice'] = (int) ($current['_meta']['voice'] ?? 0) + (int) ($m['voice'] ?? 0);
        DB::table('staff_survey_responses')->where('id', $row->id)->update([
            'answers'  => json_encode($current, JSON_UNESCAPED_UNICODE),
            'feedback' => ($row->feedback ?? '') !== '' ? $row->feedback : (($current['feedback'] ?? '') !== '' ? $current['feedback'] : null),
        ]);
        $_SESSION['staff_survey_more'][self::KEY] = 'none';
        unset($_SESSION['staff_survey_due']);
    }

    private static function cleanText(mixed $v): string
    {
        return is_string($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '') : '';
    }

    /**
     * How they took the survey, as reported by the pop-up: seconds spent, seconds
     * per page, how often they used voice typing, a starter phrase, or were
     * nudged to write. Every number is clamped; anything else is dropped.
     */
    private static function cleanMeta(mixed $meta): array
    {
        $meta = is_array($meta) ? $meta : [];
        $int = fn($v, $max) => is_numeric($v) ? max(0, min($max, (int) $v)) : null;
        $out = [];
        foreach (['secs' => 86400, 'voice' => 200, 'starters' => 200, 'nudged' => 20] as $k => $max) {
            $v = $int($meta[$k] ?? null, $max);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        if (isset($meta['pages']) && is_array($meta['pages'])) {
            $out['pages'] = array_values(array_map(fn($v) => $int($v, 86400) ?? 0, array_slice($meta['pages'], 0, count(self::PAGES))));
        }
        return $out;
    }

    /** "Not now": counts a skip and snoozes the idle pop-up. Refused once the skips are used up. */
    public static function skip(int $userId): array
    {
        $s = self::state($userId);
        if ($s['done']) {
            return $s;
        }
        if ($s['skips_left'] <= 0) {
            throw new \DomainException('Please fill in the survey now. It only takes a few minutes.');
        }
        $until = date('Y-m-d H:i:s', time() + self::SNOOZE_MINUTES * 60);
        DB::statement(
            'INSERT INTO staff_survey_prompts (survey_key, user_id, skips, snoozed_until) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE skips = LEAST(skips + 1, 255), snoozed_until = ?',
            [self::KEY, $userId, $until, $until]
        );
        unset($_SESSION['staff_survey_due']);
        return self::state($userId);
    }

    /**
     * Check and save the answers.
     *
     * @throws SurveyInvalid with a plain-English message and ->fields (question key => message)
     */
    public static function submit(int $userId, string $role, mixed $answers, mixed $meta = null): void
    {
        if (!is_array($answers)) {
            throw new SurveyInvalid('Please answer the questions and try again.', []);
        }
        $clean = [];
        $errors = [];
        foreach (self::questions() as $key => $q) {
            $v = $answers[$key] ?? null;
            switch ($q['type']) {
                case 'scale':
                case 'nps':
                    $max = $q['type'] === 'nps' ? 10 : 5;
                    $min = $q['type'] === 'nps' ? 0 : 1;
                    if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
                        $errors[$key] = 'Please pick an answer.';
                        break;
                    }
                    $clean[$key] = (int) $v;
                    break;
                case 'choice':
                    if (!is_string($v) || !in_array($v, $q['options'], true)) {
                        $errors[$key] = 'Please pick an answer.';
                        break;
                    }
                    $clean[$key] = $v;
                    break;
                case 'multi':
                    $v = is_array($v) ? array_values(array_unique(array_filter($v, 'is_string'))) : [];
                    $v = array_values(array_intersect($v, $q['options']));
                    if (!$v) {
                        $errors[$key] = 'Please pick at least one.';
                        break;
                    }
                    if (count($v) > ($q['max'] ?? 3)) {
                        $errors[$key] = 'Please pick up to ' . ($q['max'] ?? 3) . '.';
                        break;
                    }
                    $clean[$key] = $v;
                    break;
                default: // text
                    // A follow-up only counts when their answer above opened it
                    // (they may have changed that answer after writing).
                    if (isset($q['parent']) && !self::followupOpen($q, $clean[$q['parent']] ?? null)) {
                        break;
                    }
                    $t = self::cleanText($v);
                    if (mb_strlen($t) > self::TEXT_MAX) {
                        $errors[$key] = 'Please keep this under ' . number_format(self::TEXT_MAX) . ' characters.';
                        break;
                    }
                    if ($t === '' && !empty($q['required'])) {
                        $errors[$key] = 'Please write a few words.';
                        break;
                    }
                    $clean[$key] = $t;
            }
        }
        if ($errors) {
            throw new SurveyInvalid('A few questions still need an answer.', $errors);
        }
        $clean['_meta'] = self::cleanMeta($meta);
        $skips = (int) (DB::table('staff_survey_prompts')->where('survey_key', self::KEY)->where('user_id', $userId)->value('skips') ?? 0);
        try {
            DB::table('staff_survey_responses')->insert([
                'survey_key' => self::KEY,
                'user_id'    => $userId,
                'role'       => mb_substr($role, 0, 20),
                'answers'    => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'feedback'   => ($clean['feedback'] ?? '') !== '' ? $clean['feedback'] : null,
                'skips'      => min(255, $skips),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Already sent (a double click or a second tab): that's fine.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
        $_SESSION['staff_survey_done'][self::KEY] = true;
        unset($_SESSION['staff_survey_due']);
    }

    // ---- admin ------------------------------------------------------------------

    /** Everything the admin page needs: responses, who hasn't answered, and the summaries. */
    public static function results(): array
    {
        $rows = DB::table('staff_survey_responses as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.survey_key', self::KEY)->orderByDesc('r.created_at')
            ->get(['r.id', 'r.user_id', 'r.role', 'r.answers', 'r.feedback', 'r.skips', 'r.created_at', 'u.name', 'u.email']);

        $centreMap = [];
        try {
            $centreMap = (new CentreService())->resolveMap();
        } catch (Throwable) {
        }

        $responses = [];
        foreach ($rows as $r) {
            $responses[] = [
                'id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'name' => $r->name, 'email' => $r->email,
                'role' => $r->role, 'centre' => $centreMap[(int) $r->user_id] ?? null,
                'answers' => json_decode((string) $r->answers, true) ?: [], 'feedback' => $r->feedback,
                'skips' => (int) $r->skips, 'at' => $r->created_at,
            ];
        }

        $answered = array_column($responses, 'user_id');
        $prompts = DB::table('staff_survey_prompts')->where('survey_key', self::KEY)->pluck('skips', 'user_id')->all();
        $pending = DB::table('users')
            ->whereIn('role', [User::ROLE_AGENT, User::ROLE_CSA, User::ROLE_SUPERVISOR, User::ROLE_MANAGER])
            ->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at')->where('is_dev', 0)
            ->when($answered, fn($q) => $q->whereNotIn('id', $answered))
            ->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn($u) => ['id' => (int) $u->id, 'name' => $u->name, 'role' => $u->role, 'centre' => $centreMap[(int) $u->id] ?? null, 'skips' => (int) ($prompts[$u->id] ?? 0)])
            ->all();

        return ['responses' => $responses, 'pending' => $pending, 'summary' => self::summary($responses)];
    }

    /** Averages, eNPS and answer counts across all responses. */
    public static function summary(array $responses): array
    {
        $n = count($responses);
        $out = ['count' => $n, 'scales' => [], 'choices' => [], 'enps' => null];
        foreach (self::questions() as $key => $q) {
            $vals = [];
            foreach ($responses as $r) {
                if (array_key_exists($key, $r['answers'])) {
                    $vals[] = $r['answers'][$key];
                }
            }
            if ($q['type'] === 'scale') {
                $out['scales'][$key] = ['label' => $q['label'], 'avg' => $vals ? round(array_sum($vals) / count($vals), 1) : null];
            } elseif ($q['type'] === 'nps') {
                if ($vals) {
                    $promoters = count(array_filter($vals, fn($v) => $v >= 9));
                    $detractors = count(array_filter($vals, fn($v) => $v <= 6));
                    $out['enps'] = (int) round(($promoters - $detractors) / count($vals) * 100);
                }
            } elseif (in_array($q['type'], ['choice', 'multi'], true)) {
                $counts = array_fill_keys($q['options'], 0);
                foreach ($vals as $v) {
                    foreach ((array) $v as $one) {
                        if (isset($counts[$one])) {
                            $counts[$one]++;
                        }
                    }
                }
                $out['choices'][$key] = ['label' => $q['label'], 'counts' => $counts, 'type' => $q['type']];
            }
        }
        return $out;
    }
}

