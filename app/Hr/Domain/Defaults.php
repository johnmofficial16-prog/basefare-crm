<?php

namespace App\Hr\Domain;

use App\Hr\Kernel;
use Throwable;

/**
 * What a fresh Trio HR starts with: the law table (only values from the
 * Oct 2026 research, each with its source), letter templates and policy
 * DRAFTS. Installed once, automatically, and never overwritten: anything an
 * admin has edited stays as they left it. Bump VERSION to add new defaults.
 *
 * Every text here is a starting draft. Templates and policies are marked for
 * review by Trio's lawyer or CA before use; policies install as drafts and are
 * only published by an admin.
 */
final class Defaults
{
    private const VERSION = 1;

    public static function ensure(): void
    {
        try {
            $installed = fn() => (int) (Kernel::hr()->table('hr_settings')->where('key', 'defaults_version')->value('value') ?? 0) >= self::VERSION;
            if ($installed()) {
                return;
            }
            // Two first requests at once take turns; the second finds it done.
            Kernel::withLock('defaults', function () use ($installed) {
                if ($installed()) {
                    return;
                }
                self::install();
                Kernel::hr()->table('hr_settings')->updateOrInsert(['key' => 'defaults_version'], ['value' => (string) self::VERSION, 'updated_at' => Kernel::now()]);
            });
        } catch (Throwable $e) {
            error_log('[Trio HR] installing defaults failed: ' . $e->getMessage());
        }
    }

    public static function install(): void
    {
        $db = Kernel::hr();
        $now = Kernel::now();

        foreach (self::law() as $row) {
            $exists = $db->table('hr_law_values')->where('key', $row[0])->where('scope', $row[3])->where('effective_from', $row[4])->exists();
            if (!$exists) {
                $db->table('hr_law_values')->insert([
                    'key' => $row[0], 'label' => $row[1], 'value' => $row[2][0], 'unit' => $row[2][1], 'scope' => $row[3],
                    'effective_from' => $row[4], 'status' => $row[5], 'source' => $row[6], 'note' => $row[7] ?? null, 'created_at' => $now,
                ]);
            }
        }

        foreach (self::templates() as $i => $t) {
            if (!$db->table('hr_letter_templates')->where('code', $t['code'])->exists()) {
                $db->table('hr_letter_templates')->insert([
                    'code' => $t['code'], 'name' => $t['name'], 'subject' => $t['subject'], 'body' => trim($t['body']),
                    'fields' => json_encode($t['fields'] ?? []), 'needs_ack' => (int) ($t['ack'] ?? 0),
                    'staff_can_request' => (int) ($t['request'] ?? 0), 'sort' => $i, 'updated_at' => $now,
                ]);
            }
        }

        foreach (self::policies() as $p) {
            if (!$db->table('hr_policies')->where('code', $p['code'])->exists()) {
                $db->table('hr_policies')->insert([
                    'code' => $p['code'], 'version' => 1, 'title' => $p['title'], 'summary' => $p['summary'], 'body' => trim($p['body']),
                    'audience' => $p['audience'] ?? 'all', 'kind' => $p['kind'] ?? 'policy', 'status' => 'draft',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** [key, label, [value, unit], scope, effective_from, status, source, note] */
    private static function law(): array
    {
        $codes = 'Labour Codes in force from 21 Nov 2025 · research report 04';
        return [
            ['epf_wage_ceiling', 'EPF wage ceiling', ['25000', '₹ a month'], 'India', '2026-09-17', 'in_force', 'S.O. 5109(E), 17 Sep 2026 (SCC Online, BDO)', 'PF covers staff earning up to this. Ask the CA whether PF applies to Trio.'],
            ['epf_wage_ceiling', 'EPF wage ceiling', ['15000', '₹ a month'], 'India', '2014-09-01', 'superseded', 'EPF scheme amendment, 2014', null],
            ['epf_threshold', 'PF applies to establishments with at least', ['20', 'employees'], 'India', '2025-11-21', 'in_force', 'Code on Social Security, 2020 · ' . $codes, null],
            ['esi_wage_ceiling', 'ESI wage ceiling', ['21000', '₹ a month'], 'India', '2017-01-01', 'in_force', 'ESIC notification, 2017 · HR plan §11', 'Applies at 10+ employees in notified areas. Check SAS Nagar (Mohali) and East Singhbhum (Jamshedpur).'],
            ['wages_due_day', 'Pay monthly wages before the end of', ['7', 'th of the next month'], 'India', '2025-11-21', 'in_force', 'Code on Wages, 2019 · ' . $codes, null],
            ['final_settlement_days', 'Pay everything due to a leaver within', ['2', 'working days'], 'India', '2025-11-21', 'in_force', 'Code on Wages, 2019 · ' . $codes, null],
            ['max_deduction_share', 'Most that can be deducted from wages', ['50', '% of wages'], 'India', '2025-11-21', 'in_force', 'Code on Wages, 2019 · ' . $codes, 'Only the deductions the Code allows.'],
            ['tax_rebate_limit', 'Taxable income with no tax (new regime)', ['1200000', '₹ a year'], 'India', '2026-04-01', 'in_force', 'Income-tax rules for 2026-27, new regime · research report 03', 'With the standard deduction, salary up to about ₹12.75 lakh pays no tax.'],
            ['standard_deduction', 'Standard deduction on salary (new regime)', ['75000', '₹ a year'], 'India', '2026-04-01', 'in_force', 'Income-tax rules for 2026-27 · research report 03', null],
            ['psdt_amount', 'Punjab State Development Tax', ['200', '₹ a month'], 'Punjab', '2018-04-01', 'in_force', 'Punjab State Development Tax Act, 2018 · research report 03', 'Deducted from salaried income-tax payees. The CA confirms who counts.'],
            ['posh_ic_threshold', 'POSH Internal Committee needed from', ['10', 'employees at a workplace'], 'India', '2013-12-09', 'in_force', 'Sexual Harassment of Women at Workplace Act, 2013', null],
            ['grievance_committee_threshold', 'Grievance Redressal Committee needed from', ['20', 'workers'], 'India', '2025-11-21', 'in_force', 'Industrial Relations Code, 2020 · ' . $codes, null],
            ['gratuity_service', 'Service needed for gratuity', ['5', 'years (1 year for fixed-term)'], 'India', '2025-11-21', 'in_force', 'Code on Social Security, 2020 · ' . $codes, null],
            ['maternity_leave', 'Maternity leave', ['26', 'weeks'], 'India', '2017-04-01', 'in_force', 'Maternity Benefit (Amendment) Act, 2017', null],
            ['dpdp_main_duties', 'Data protection (DPDP) main duties apply from', ['13 May 2027', null], 'India', '2027-05-13', 'in_force', 'Digital Personal Data Protection Rules, 2025 · research report 04', 'Aadhaar must stay optional and masked.'],
        ];
    }

    private static function templates(): array
    {
        return [
            [
                'code' => 'appointment', 'name' => 'Appointment letter', 'subject' => 'Appointment as {{employee.designation}}', 'ack' => 1,
                'fields' => [
                    ['key' => 'start_date', 'label' => 'Date of joining', 'type' => 'date', 'required' => true, 'from' => 'employee.date_joined_raw'],
                    ['key' => 'monthly_salary', 'label' => 'Fixed monthly salary (₹, before tax)', 'type' => 'money', 'required' => true],
                    ['key' => 'shift_hours', 'label' => 'Working hours', 'type' => 'text', 'required' => true, 'default' => 'Up to 9 hours a day including breaks, recorded by punching in and out'],
                    ['key' => 'weekly_off', 'label' => 'Weekly off', 'type' => 'text', 'required' => true, 'default' => 'One day a week'],
                ],
                'body' => <<<'TXT'
Dear {{employee.name}},

We are pleased to confirm your appointment with {{company.legal_name}} ("Trio") on the following terms.

- **Position:** {{employee.designation}}
- **Date of joining:** {{fields.start_date}}
- **Place of work:** our {{employee.centre}} centre
- **Employee code:** {{employee.code}}
- **Salary:** a fixed salary of ₹{{fields.monthly_salary}} a month, plus incentives under the incentive plan in force at the time. Tax is deducted at source as the law requires.
- **Working hours:** {{fields.shift_hours}}.
- **Weekly off:** {{fields.weekly_off}}.
- **Notice period:** {{employee.notice_days}} days on either side.
- **Leave and holidays:** as set out in the leave policy in force, which meets the law of the state where you work.

Your salary is paid to your bank account before the 7th of the following month. Your payslips, policies and leave are in Trio HR.

In your work you will see customer and card details. You must keep them confidential, during and after your employment, and follow Trio's customer and card data policy.

Please confirm that you accept these terms by acknowledging this letter in Trio HR.

We look forward to working with you.
TXT,
            ],
            [
                'code' => 'experience', 'name' => 'Experience certificate', 'subject' => 'Experience certificate', 'request' => 1,
                'fields' => [],
                'body' => <<<'TXT'
**To whom it may concern**

This is to certify that {{employee.name}} (employee code {{employee.code}}) {{employee.service_line}}

During this time, {{employee.first}} worked as {{employee.a_designation}} at our {{employee.centre}} centre.

This certificate is issued at {{employee.first}}'s request.
TXT,
            ],
            [
                'code' => 'relieving', 'name' => 'Relieving letter', 'subject' => 'Relieving letter',
                'fields' => [],
                'body' => <<<'TXT'
Dear {{employee.name}},

This is to confirm that you have been relieved of your duties as {{employee.a_designation}} with {{company.legal_name}} at the end of your last working day, {{employee.last_working_day}}.

Your final settlement is shared with you separately in Trio HR. For 12 months you can still sign in to Trio HR to download your payslips and tax forms.

Please remember that the customer and card details you saw at work must stay confidential.

We thank you for your contribution and wish you well.
TXT,
            ],
            [
                'code' => 'salary_certificate', 'name' => 'Salary certificate', 'subject' => 'Salary certificate', 'request' => 1,
                'fields' => [
                    ['key' => 'monthly_salary', 'label' => 'Fixed monthly salary (₹, before tax)', 'type' => 'money', 'required' => true],
                    ['key' => 'purpose', 'label' => 'Needed for', 'type' => 'text', 'required' => true, 'from' => 'request.purpose'],
                ],
                'body' => <<<'TXT'
**To whom it may concern**

This is to certify that {{employee.name}} (employee code {{employee.code}}) {{employee.service_line}}

{{employee.first}} works as {{employee.a_designation}} at our {{employee.centre}} centre and receives a fixed salary of ₹{{fields.monthly_salary}} a month, plus incentives.

This certificate is issued at {{employee.first}}'s request, for {{fields.purpose}}.
TXT,
            ],
            [
                'code' => 'address_proof', 'name' => 'Address proof letter', 'subject' => 'Address proof', 'request' => 1,
                'fields' => [['key' => 'purpose', 'label' => 'Needed for', 'type' => 'text', 'required' => true, 'from' => 'request.purpose']],
                'body' => <<<'TXT'
**To whom it may concern**

This is to certify that {{employee.name}} (employee code {{employee.code}}) {{employee.service_line}}

According to our records, {{employee.first}}'s address is:

{{employee.address}}

This letter is issued at {{employee.first}}'s request, for {{fields.purpose}}.
TXT,
            ],
            [
                'code' => 'loi', 'name' => 'Letter of intent', 'subject' => 'Letter of intent', 'ack' => 1,
                'fields' => [
                    ['key' => 'start_date', 'label' => 'Expected date of joining', 'type' => 'date', 'required' => true, 'from' => 'employee.date_joined_raw'],
                    ['key' => 'monthly_salary', 'label' => 'Fixed monthly salary offered (₹, before tax)', 'type' => 'money', 'required' => true],
                    ['key' => 'valid_until', 'label' => 'Offer open until', 'type' => 'date', 'required' => true],
                ],
                'body' => <<<'TXT'
Dear {{employee.name}},

Following our discussions, we are pleased to tell you that {{company.legal_name}} intends to employ you as {{employee.a_designation}} at our {{employee.centre}} centre.

- **Expected date of joining:** {{fields.start_date}}
- **Fixed salary offered:** ₹{{fields.monthly_salary}} a month, plus incentives under the incentive plan in force.

This letter states our intent. It becomes an offer of employment once your documents are verified, and your appointment letter sets out the full terms.

Please confirm your acceptance in Trio HR by {{fields.valid_until}}. After that date this letter lapses.
TXT,
            ],
        ];
    }

    private static function policies(): array
    {
        return [
            [
                'code' => 'privacy_notice', 'title' => 'Staff privacy notice',
                'summary' => 'What we keep about you, why, who sees it, and how long we keep it.',
                'body' => <<<'TXT'
# What we keep
Your contact details, job details, ID and tax numbers (PAN, and Aadhaar only if you choose to give it), bank details, documents you upload, your clock-ins from the CRM, leave, letters and pay.

# Why
To employ you and pay you, to deduct and file tax, to meet labour law, and to keep the business safe.

# Who sees it
- **HR admins** at Trio. Every time anyone opens your bank or ID details, it is recorded.
- **Trio's CA**, for tax and payroll returns.
- **Banks**, to pay your salary.
- **Government bodies**, only when the law requires it.

# How it is protected
PAN, Aadhaar, bank details and your documents are encrypted. Only HR admins can open them, and only after re-entering their password.

# How long
We keep employment records for 8 years after you leave, unless the law needs longer.

# Your choices
You can see your details in Trio HR at any time and ask for a change. Talk to the HR admin about anything in this notice.
TXT,
            ],
            [
                'code' => 'code_of_conduct', 'title' => 'Code of conduct',
                'summary' => 'How we work with customers and with each other.',
                'body' => <<<'TXT'
# With customers
- Be honest about fares, rules and fees. Never promise what the airline won't give.
- Get the customer's clear consent before any charge.
- Keep every booking and call recorded in the CRM.

# With each other
- Treat everyone with respect. Harassment, bullying and discrimination are not tolerated.
- Be on time for your shift, and tell your team leader early if you can't be.

# With the company
- Use company systems only for work.
- Report mistakes and problems straight away. Reporting honestly is never held against you.

Breaking this code can lead to action under Trio's disciplinary process, up to dismissal.
TXT,
            ],
            [
                'code' => 'card_data', 'title' => 'Customer and card data',
                'summary' => 'How to handle customer and card details safely.',
                'body' => <<<'TXT'
You will hear and see customers' card details. Card scheme rules and the law require that they stay safe.

- **Never** write card numbers or security codes on paper, in chats, in emails or in notes.
- **Never** photograph or screenshot a screen with card details.
- Enter card details only in the CRM screens built for them.
- Don't share your CRM login with anyone, and lock your screen when you step away.
- If you think card details have been exposed, tell your team leader at once.

Your access to card details ends the moment you leave Trio. Breaking these rules is serious misconduct.
TXT,
            ],
            [
                'code' => 'posh', 'title' => 'Prevention of sexual harassment (POSH)',
                'summary' => 'Our zero-tolerance policy, and how to raise a complaint.',
                'body' => <<<'TXT'
Trio does not tolerate sexual harassment of anyone at work, including during night shifts, on company transport and in work chats.

# What counts
Any unwelcome physical contact or advances, a demand or request for sexual favours, sexually coloured remarks, showing pornography, or any other unwelcome conduct of a sexual nature.

# How to complain
Speak to or write to any member of Trio's Internal Committee, or to the HR admin, who will pass it on. A complaint should normally be made within three months of the incident; the Committee can allow more time if there was a good reason for the delay.

# What happens next
The Committee looks into the complaint fairly and in confidence, and gives both sides a hearing.

# No retaliation
Nobody will be treated badly for making a complaint in good faith or for helping an inquiry.
TXT,
            ],
            [
                'code' => 'night_consent', 'title' => 'Consent to work night shifts', 'kind' => 'consent', 'audience' => 'women_nights',
                'summary' => 'Your written consent to work between 8 pm and 6 am. You can withdraw it at any time.',
                'body' => <<<'TXT'
The law in Punjab lets women work between 8 pm and 6 am only with their written consent, and with safety measures in place.

By signing, you agree to work night shifts on the roster, between 8 pm and 6 am.

- Trio provides the safety measures the law requires, including safe transport where needed.
- You can withdraw this consent at any time from Trio HR. Withdrawing it will never be held against you; HR will change your roster to day work where possible.
TXT,
            ],
        ];
    }
}
