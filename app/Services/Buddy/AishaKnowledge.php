<?php

namespace App\Services\Buddy;

/**
 * AishaKnowledge — what Aisha knows beyond the agent's own numbers.
 *
 * Aisha v2 (23 Oct 2026). The usage report showed agents asking how to do
 * things in the CRM and how to handle customers — Mike asked how to take notes
 * from passengers and what to do when he misses the right words — and she
 * refused ("I can't give specific advice on customer interaction"), or worse,
 * invented CRM steps that do not exist. Two small knowledge bases fix that:
 *
 *  - CRM guide: how-to for every agent-facing flow, written from the actual
 *    screens (button labels, menus, who may do what). Grounded, so she stops
 *    guessing.
 *  - Sales playbook: honest phone-sales coaching for selling flights to US
 *    customers. No fake urgency, no lies about fares or seats.
 *
 * Retrieval is plain keyword scoring — a few dozen short entries do not need
 * embeddings, and it runs in microseconds with zero API cost. The model calls
 * crm_help / sales_tips, gets the best 2–3 entries, and answers in its own
 * words from them.
 */
final class AishaKnowledge
{
    /** @return array<int, array{id: string, title: string, keywords: string, text: string, roles?: string}> */
    public static function crmGuide(): array
    {
        return AishaCrmGuide::ENTRIES;
    }

    /** @return array<int, array{id: string, title: string, keywords: string, text: string}> */
    public static function playbook(): array
    {
        return self::PLAYBOOK;
    }

    /**
     * Best entries for a question. Returns up to $limit entries with a score,
     * or the list of topic titles when nothing matches (so the model can say
     * what it does know instead of inventing).
     */
    public static function search(array $entries, string $query, int $limit = 3): array
    {
        $words = self::words($query);
        if ($words === []) {
            return ['matches' => [], 'topics' => array_column($entries, 'title')];
        }
        $scored = [];
        foreach ($entries as $e) {
            $kw    = ' ' . mb_strtolower($e['keywords'] ?? '') . ' ';
            $title = ' ' . mb_strtolower($e['title']) . ' ';
            $text  = ' ' . mb_strtolower($e['text']) . ' ';
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($kw, ' ' . $w)) {
                    $score += 3;
                }
                if (str_contains($title, $w)) {
                    $score += 2;
                }
                if (str_contains($text, $w)) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $scored[] = [$score, $e];
            }
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        $best = array_slice($scored, 0, $limit);
        if ($best === [] || $best[0][0] < 3) {
            return ['matches' => array_map(fn($x) => self::shape($x[1]), $best),
                    'topics' => array_column($entries, 'title'),
                    'note' => 'weak match — answer only what these entries actually say, or say you are not sure'];
        }
        return ['matches' => array_map(fn($x) => self::shape($x[1]), $best)];
    }

    private static function shape(array $e): array
    {
        $out = ['title' => $e['title'], 'text' => $e['text']];
        if (!empty($e['roles'])) {
            $out['who_can'] = $e['roles'];
        }
        return $out;
    }

    /** Lower-case words of 3+ letters, light stemming, stop words removed. */
    private static function words(string $q): array
    {
        $q = mb_strtolower($q);
        $q = str_replace(['e-ticket', 'e ticket'], 'eticket', $q);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'and', 'for', 'how', 'what', 'can', 'you', 'with', 'does', 'this', 'that', 'from',
                 'have', 'has', 'are', 'was', 'when', 'where', 'who', 'why', 'will', 'should', 'would', 'could',
                 'into', 'about', 'there', 'their', 'they', 'them', 'your', 'mine', 'our', 'any', 'tell', 'need',
                 'want', 'give', 'please', 'kya', 'hai', 'kaise', 'kare', 'karo', 'karna', 'mujhe', 'mera',
                 'meri', 'mere', 'aur', 'nahi', 'yaar', 'one', 'get', 'got', 'customer', 'customers'];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, $stop, true)) {
                continue;
            }
            foreach (['ing', 'ed', 'es', 's'] as $suf) {
                if (mb_strlen($p) > 5 && str_ends_with($p, $suf)) {
                    $p = mb_substr($p, 0, -mb_strlen($suf));
                    break;
                }
            }
            $out[$p] = true;
        }
        return array_keys($out);
    }

    /**
     * Phone-sales playbook for agents selling flights to US customers.
     * Honest by rule: fares and seats genuinely change, so say it only when it
     * is true; never invent deadlines, discounts or scarcity.
     */
    private const PLAYBOOK = [
        [
            'id' => 'opening', 'title' => 'Opening a sales call',
            'keywords' => 'open opening start greet greeting first line intro introduction hello call script',
            'text' => 'Warm, short, and about them: "Hi, this is Riya from Base Fare — thanks for calling! Where are you looking to fly?" Use their name as soon as you have it. Ask permission before details ("Can I grab a few quick details so I find you the best option?"). Smile while you talk; it carries into your voice, especially at 3am.',
        ],
        [
            'id' => 'discovery', 'title' => 'Finding out what they really need',
            'keywords' => 'discovery questions needs requirement dates flexible budget layover airline passengers bags understand',
            'text' => 'Before quoting, ask: exact dates and how flexible they are, departure and arrival airports (nearby airports can be cheaper), number and ages of passengers, checked bags, preferred airline or layover limits, and budget. Repeat it back in one sentence ("So, two adults, Newark to Delhi, leaving 12 December, back mid-January, up to one stop"). Customers buy when they feel understood.',
        ],
        [
            'id' => 'think_about_it', 'title' => 'When the customer says "I\'ll think about it"',
            'keywords' => 'think about it later callback hesitant undecided not sure maybe call back decide wait close closing',
            'text' => 'Find the real hesitation: "Totally fair — is it the price, the timing, or something about the itinerary?" Solve that one thing. Be honest about fares: they do change and you cannot guarantee the price later, but never invent a deadline. If they still want time, agree a specific callback ("Shall I call you at 7pm Eastern tomorrow?"), note it, and set a reminder.',
        ],
        [
            'id' => 'cheaper_online', 'title' => 'They found it cheaper online',
            'keywords' => 'cheaper online price match expedia google flights cheap competitor lower price found better deal compare',
            'text' => 'Do not argue — compare like for like. Ask for the exact itinerary they saw. Check: total price including bags and seat fees, the fare\'s change and refund rules, layovers and connection times, and that it is the same airline and dates. Explain the value you add honestly (a real person to call when plans change). If yours is genuinely more expensive for the same thing, say so and look for a better option.',
        ],
        [
            'id' => 'too_expensive', 'title' => 'Handling "it\'s too expensive"',
            'keywords' => 'expensive price high costly budget afford cost objection money cheaper option',
            'text' => 'Acknowledge it, then widen the options: nearby dates (midweek is often cheaper), nearby airports, one extra stop, or a different airline. Show two or three choices at different prices so they choose rather than refuse. Break the total down per person and include what is in it (bags, seats). Never pressure — a customer who feels pushed does not come back.',
        ],
        [
            'id' => 'trust', 'title' => 'Customer worried it is a scam',
            'keywords' => 'scam trust fraud legit genuine safe secure worried suspicious card authorization verify company',
            'text' => 'Stay calm and transparent. Explain the steps: they receive a card authorization to review and sign themselves, the charge matches what was agreed, and the e-ticket with the airline reference comes by email so they can check the booking on the airline\'s own website. Offer to wait while they verify. Never ask for card details in chat or email.',
        ],
        [
            'id' => 'angry', 'title' => 'Calming an angry customer',
            'keywords' => 'angry upset shout shouting rude complaint irritated frustrated calm deescalate yelling',
            'text' => 'Let them finish. Lower your pace and volume. Acknowledge the feeling ("I completely understand — I\'d be upset too"), apologise for the experience without blaming anyone, then move to what you can do and by when. Write down the facts as they talk. If it is beyond you, say exactly who will help and when. It is not personal — take a breather afterwards.',
        ],
        [
            'id' => 'delay_cancel', 'title' => 'Flight delayed or cancelled',
            'keywords' => 'delay delayed cancel cancelled cancellation schedule change missed connection rebook refund compensation',
            'text' => 'Empathy first, then options. For a cancellation or significant schedule change by the airline on a US flight, the customer is generally entitled to a refund if they choose not to travel, or a rebooking. Check the airline\'s offer and the fare rules before promising anything, then explain the options clearly and note what they chose.',
        ],
        [
            'id' => 'notes', 'title' => 'Taking notes from the passenger',
            'keywords' => 'notes note taking details passenger information write down name dob passport spelling record capture',
            'text' => 'Capture names exactly as on the passport (first, middle, last), date of birth, a phone and email for the itinerary, travel dates, airports, bag needs and any special requests. Read spellings back using the phonetic alphabet. Confirm everything at the end in one summary. Never write card numbers in notes, chat or email — card details only go through the card authorization.',
        ],
        [
            'id' => 'blank', 'title' => 'When you miss the right words or a detail',
            'keywords' => 'miss forgot blank words stuck nervous mistake wrong detail mess up confidence fumble',
            'text' => 'It happens to everyone. Buy a second honestly: "Let me double-check that so it\'s perfect for you." Then summarise what you have and ask for the missing piece. If you said something wrong, correct it straight away — customers trust honesty. Keep a one-page cheat sheet (key questions, common fare rules) next to you for the night shift.',
        ],
        [
            'id' => 'phonetic', 'title' => 'Spelling on the phone (phonetic alphabet)',
            'keywords' => 'spell spelling phonetic alphabet nato letters pnr name confirm reference alpha bravo',
            'text' => 'A Alpha, B Bravo, C Charlie, D Delta, E Echo, F Foxtrot, G Golf, H Hotel, I India, J Juliet, K Kilo, L Lima, M Mike, N November, O Oscar, P Papa, Q Quebec, R Romeo, S Sierra, T Tango, U Uniform, V Victor, W Whiskey, X X-ray, Y Yankee, Z Zulu. Say numbers digit by digit.',
        ],
        [
            'id' => 'closing', 'title' => 'Closing the sale',
            'keywords' => 'close closing deal confirm book booking finalize yes ready commit',
            'text' => 'Summarise the itinerary and the total in one breath, then ask a clear question: "Shall I go ahead and lock this in for you?" If they say yes, move straight to the card authorization so nothing cools off. If they hesitate, ask what is holding them back rather than repeating the pitch.',
        ],
        [
            'id' => 'follow_up', 'title' => 'Follow-ups and callbacks',
            'keywords' => 'follow up followup callback call back later remind voicemail message chase',
            'text' => 'Agree the time in the customer\'s time zone and note it. Call between about 9am and 8pm their local time. If you get voicemail: your name, Base Fare, the trip in a few words, and your callback number — under 20 seconds. Ask Aisha to remind you at the right time.',
        ],
        [
            'id' => 'upsell', 'title' => 'Offering seats, bags or insurance without pushing',
            'keywords' => 'upsell add on addon seat seats baggage bags insurance protection extra offer cross sell',
            'text' => 'Offer one add-on at a time, only when it fits the trip: "Since you\'re travelling with family, would you like seats together?" Explain the benefit in one sentence and accept a no gracefully. Travel protection makes sense for expensive or non-refundable fares; say what it covers and what it does not.',
        ],
        [
            'id' => 'timezones', 'title' => 'US time zones and calling windows',
            'keywords' => 'time zone timezone eastern central mountain pacific est pst cst ist difference calling window us time hawaii alaska',
            'text' => 'Main US zones: Eastern (New York, Florida), Central (Chicago, Texas), Mountain (Denver, Phoenix — Arizona does not change clocks), Pacific (Los Angeles, Seattle), plus Alaska and Hawaii. During US daylight time (mid-March to early November) Eastern is 9.5 hours behind India; in winter, 10.5 hours. Call between about 9am and 8pm their local time.',
        ],
        [
            'id' => 'rapport', 'title' => 'Building rapport',
            'keywords' => 'rapport friendly connect relationship small talk build trust personal warm',
            'text' => 'Use their name, match their pace, and ask about the trip ("Visiting family or a holiday?"). Remember one personal detail and use it later in the call. Be genuinely helpful even on small questions — people buy from someone they like and trust.',
        ],
        [
            'id' => 'night_shift', 'title' => 'Staying fresh on the night shift',
            'keywords' => 'tired sleepy sleep fresh energy break stress night shift focus coffee water stretch',
            'text' => 'Stand up and stretch for a minute between calls, drink water, and step away on your breaks rather than scrolling. A short walk or fresh air beats a third coffee. Light snacks over heavy meals keep you sharp until morning.',
        ],
    ];
}
