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

    /**
     * The questions, in pages. Types: scale (1–5), nps (0–10), choice (one),
     * multi (up to `max`), text (free, optional unless `required`).
     */
    public const PAGES = [
        [
            'title' => 'Your experience here',
            'intro' => 'Tap the answer closest to how you feel. There are no right or wrong answers.',
            'questions' => [
                ['key' => 'happy', 'type' => 'scale', 'label' => 'Overall, how happy are you working here?', 'low' => 'Very unhappy', 'high' => 'Very happy'],
                ['key' => 'recommend', 'type' => 'nps', 'label' => 'How likely are you to recommend working here to a friend?', 'low' => 'Not at all likely', 'high' => 'Extremely likely'],
                ['key' => 'valued', 'type' => 'scale', 'label' => 'Do you feel your work is noticed and valued?', 'low' => 'Not at all', 'high' => 'Very much'],
                ['key' => 'support', 'type' => 'scale', 'label' => 'How well supported do you feel by the people you report to?', 'low' => 'Not supported', 'high' => 'Fully supported'],
                ['key' => 'tools', 'type' => 'scale', 'label' => 'Do you have the training and tools to do your job well?', 'low' => 'Not at all', 'high' => 'Completely'],
                ['key' => 'workload', 'type' => 'scale', 'label' => 'How manageable are your workload and shift timings?', 'low' => 'Too much', 'high' => 'Very manageable'],
            ],
        ],
        [
            'title' => 'How you feel',
            'intro' => 'A few words is enough.',
            'questions' => [
                ['key' => 'shift_end', 'type' => 'choice', 'label' => 'How do you usually feel at the end of a shift?',
                    'options' => ['Energised', 'Satisfied', 'Tired but fine', 'Drained', 'Stressed']],
                ['key' => 'stay', 'type' => 'choice', 'label' => 'Do you see yourself working here a year from now?',
                    'options' => ['Yes, definitely', 'Probably', 'Not sure', 'Probably not', 'No']],
                ['key' => 'best', 'type' => 'text', 'label' => 'What is the best thing about working here?', 'placeholder' => 'For example: my team, the incentives, learning every day'],
                ['key' => 'frustration', 'type' => 'text', 'label' => 'What frustrates you most at work?', 'placeholder' => 'Anything that gets in the way of doing your best'],
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
                ['key' => 'skill', 'type' => 'text', 'label' => 'Which skill would you most like to learn?', 'placeholder' => 'For example: closing sales, GDS, English, leading people'],
            ],
        ],
        [
            'title' => 'How we can help',
            'intro' => 'Your ideas shape what the company does next.',
            'questions' => [
                ['key' => 'help', 'type' => 'multi', 'max' => 3, 'label' => 'What could the company do to help you reach your goals? Pick up to 3.',
                    'options' => ['More training sessions', 'A clear promotion path', 'A better incentive plan', 'A mentor', 'Flexible shift options', 'Recognition and awards', 'Health and wellness support', 'Better tools and systems', 'Transport support']],
                ['key' => 'boss', 'type' => 'text', 'label' => 'If you ran the company for one day, what one thing would you change?', 'placeholder' => 'One change that would make the biggest difference'],
                ['key' => 'feedback', 'type' => 'text', 'label' => 'Anything else you would like to tell the management?', 'placeholder' => 'Your feedback, ideas or concerns'],
            ],
        ],
    ];

    /** Every question, by key. */
    public static function questions(): array
    {
        $out = [];
        foreach (self::PAGES as $page) {
            foreach ($page['questions'] as $q) {
                $out[$q['key']] = $q;
            }
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
     * @return array{done:bool, skips:int, skips_left:int, snoozed_until:?string}
     */
    public static function state(int $userId): array
    {
        if (!empty($_SESSION['staff_survey_done'][self::KEY])) {
            return ['done' => true, 'skips' => 0, 'skips_left' => 0, 'snoozed_until' => null];
        }
        $done = DB::table('staff_survey_responses')->where('survey_key', self::KEY)->where('user_id', $userId)->exists();
        if ($done) {
            $_SESSION['staff_survey_done'][self::KEY] = true;
            return ['done' => true, 'skips' => 0, 'skips_left' => 0, 'snoozed_until' => null];
        }
        $p = DB::table('staff_survey_prompts')->where('survey_key', self::KEY)->where('user_id', $userId)->first();
        $skips = (int) ($p->skips ?? 0);
        return [
            'done' => false,
            'skips' => $skips,
            'skips_left' => max(0, self::MAX_SKIPS - $skips),
            'snoozed_until' => $p->snoozed_until ?? null,
        ];
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
    public static function submit(int $userId, string $role, mixed $answers): void
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
                    $t = is_string($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '') : '';
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

