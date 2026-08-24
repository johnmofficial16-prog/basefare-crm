<?php

namespace App\Services;

use App\Models\User;

/**
 * EmailSignature — the sign-off block appended to agent-authored customer email.
 *
 * Why a service and not a stored blob: the obvious shortcut is to keep each
 * agent's finished signature HTML in a column and paste it into the mail. That
 * makes a rebrand — new logo, new toll-free number, a changed legal footer — a
 * row-by-row data migration across every account, and it puts operator-supplied
 * markup straight into outbound mail. Here the DB holds only the parts that
 * genuinely differ per agent (title, direct line, extension); everything else
 * is rendered from this one template, so a brand change is a single edit.
 *
 * Markup constraints are email's, not the web's: tables rather than flex
 * (Gmail's sanitiser drops flex), literal hex rather than CSS variables, Arial
 * rather than a webfont, and an absolute https logo URL — Gmail and Outlook
 * both strip `data:` images, so an inlined logo arrives as a broken box.
 *
 * The mark is the base-fare.com site logo (logo-v4), a neon feather-plane that
 * only exists on a dark ground — there is no transparent cut-out of it and a
 * glow cannot be keyed off its background cleanly. It is therefore shown the
 * way the website's own footer shows it: a small rounded dark tile. The corner
 * radius is baked into the PNG's alpha rather than set with border-radius,
 * which Outlook desktop ignores. Do NOT swap in the globe-and-plane mark from
 * the repo root — that belongs to Trio Tours, a separate entity, and is used
 * only on payroll slips and the LOI letterhead.
 *
 * Colours match ETicketEmailService so a signed email and an e-ticket read as
 * the same sender.
 */
class EmailSignature
{
    const SUPPORT_EMAIL = 'reservation@base-fare.com';
    const TOLL_FREE     = '888 608 4011';
    const WEB_LABEL     = 'base-fare.com';
    const WEB_URL       = 'https://base-fare.com';
    const LOGO_PATH     = '/assets/img/basefare-logo-email.png';

    /** Brand palette — kept in sync with ETicketEmailService. */
    const INK   = '#0f1e3c';
    const DEEP  = '#1a3a6b';
    const BODY  = '#1e293b';
    const MUTED = '#94a3b8';
    const RULE  = '#e2e8f0';

    /**
     * Roles whose outbound mail carries a signature.
     *
     * Admin only, by decision: a personal sign-off is an identity claim to the
     * customer, and only the desk that owns the booking end-to-end should make
     * one. Agent and supervisor mail still goes out under the branded Base Fare
     * shell with the shared Reservation Desk footer — it is not unsigned, just
     * not personally attributed. Widening this is a one-line change here.
     */
    const SIGNED_ROLES = [User::ROLE_ADMIN];

    /**
     * Customer-facing job titles per role.
     *
     * Internal role names leak org structure the customer has no use for, and
     * "csa" means nothing outside this building. A new account is immediately
     * presentable without an admin filling anything in.
     */
    const ROLE_TITLES = [
        User::ROLE_ADMIN      => 'Reservation Desk',
        User::ROLE_MANAGER    => 'Reservations Manager',
        User::ROLE_SUPERVISOR => 'Reservations Supervisor',
        User::ROLE_AGENT      => 'Travel Consultant',
        User::ROLE_CSA        => 'Customer Service',
    ];

    // =========================================================================
    // FIELD RESOLUTION
    // =========================================================================

    /**
     * Merge the stored per-agent fields over the role-derived defaults.
     *
     * @return array{name:string,title:string,direct:string,ext:string,email:string,enabled:bool}
     */
    public static function fields(?User $user): array
    {
        $stored = [];
        if ($user && is_array($user->email_signature)) {
            $stored = $user->email_signature;
        }

        $role = $user->role ?? User::ROLE_AGENT;

        return [
            'name'    => trim((string) ($user->name ?? '')) ?: 'Reservation Desk',
            'title'   => trim((string) ($stored['title'] ?? '')) ?: (self::ROLE_TITLES[$role] ?? 'Travel Consultant'),
            'direct'  => trim((string) ($stored['direct'] ?? '')) ?: self::TOLL_FREE,
            'ext'     => trim((string) ($stored['ext'] ?? '')),
            // The agent's own mailbox is deliberately NOT used: replies must
            // land on the shared desk that the IMAP poller watches, otherwise a
            // customer reply goes to one person's inbox and the thread dies
            // when they are off shift.
            'email'   => self::SUPPORT_EMAIL,
            // Role gate first: an agent account carrying stored signature fields
            // (set before the gate existed, or left behind by a demotion) must
            // still not sign its mail.
            'enabled' => in_array($role, self::SIGNED_ROLES, true)
                         && (!array_key_exists('enabled', $stored) || (bool) $stored['enabled']),
        ];
    }

    /** Whether this role signs its mail at all, before the per-user toggle. */
    public static function roleSigns(?User $user): bool
    {
        return in_array($user->role ?? '', self::SIGNED_ROLES, true);
    }

    /** Whether this user's mail should carry a signature at all. */
    public static function enabled(?User $user): bool
    {
        return self::fields($user)['enabled'];
    }

    /**
     * Absolute base URL for asset links in mail.
     *
     * Mirrors ETicket::publicUrl() rather than sharing a helper, because a
     * missing APP_URL must degrade to a working production URL here too — an
     * email is already out the door by the time anyone notices a broken link.
     */
    public static function baseUrl(): string
    {
        $base = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (empty($base)) {
            $base = 'https://crm.base-fare.com';
        }
        if (!preg_match('~^(?:f|ht)tps?://~i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        return rtrim($base, '/');
    }

    /**
     * Absolute, cache-busted logo URL.
     *
     * The path never changes, so replacing the image leaves every browser and
     * mail client serving whatever it cached — the same failure Asset::url()
     * exists to prevent for JS, and it hid a logo swap for a full deploy cycle
     * here. Appending the file's mtime changes the URL whenever the bytes
     * change. A query string does not affect which file Apache serves, so mail
     * already sent carrying an older ?v= still resolves fine.
     */
    public static function logoUrl(): string
    {
        $abs   = dirname(__DIR__, 2) . '/public' . self::LOGO_PATH;
        $mtime = @filemtime($abs);

        return self::baseUrl() . self::LOGO_PATH
             . ($mtime !== false ? '?v=' . $mtime : '');
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * HTML signature block. Returns '' when the agent has it switched off,
     * so callers can concatenate unconditionally.
     */
    public static function html(?User $user): string
    {
        $f = self::fields($user);
        if (!$f['enabled']) {
            return '';
        }

        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $name  = $e($f['name']);
        $title = $e($f['title']);
        $logo  = $e(self::logoUrl());
        $mail  = $e($f['email']);
        $web   = $e(self::WEB_URL);
        $webLb = $e(self::WEB_LABEL);

        $ink   = self::INK;
        $deep  = self::DEEP;
        $body  = self::BODY;
        $muted = self::MUTED;
        $rule  = self::RULE;

        // Phone: label carries the extension, href carries only diallable
        // digits — a "ext." inside tel: breaks the link on iOS.
        $phoneRow = '';
        if ($f['direct'] !== '') {
            $label = $e($f['direct']) . ($f['ext'] !== '' ? ' &nbsp;ext.&nbsp;' . $e($f['ext']) : '');
            $href  = $e(self::telHref($f['direct']));
            $phoneRow =
                '<tr><td style="padding:0 0 3px;font:400 12px/1.5 Arial,Helvetica,sans-serif;color:' . $body . ';">'
                . '<span style="color:' . $muted . ';">Tel&nbsp;&nbsp;</span>'
                . '<a href="tel:' . $href . '" style="color:' . $deep . ';text-decoration:none;">' . $label . '</a>'
                . '</td></tr>';
        }

        return <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:26px;">
  <tr>
    <td style="padding:0 20px 0 0;vertical-align:middle;">
      <img src="{$logo}" alt="Base Fare" width="88" height="88" style="display:block;border:0;outline:none;text-decoration:none;">
    </td>
    <td style="padding:2px 0 2px 20px;border-left:3px solid {$ink};vertical-align:middle;">
      <table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr><td style="padding:0 0 1px;font:700 16px/1.3 Arial,Helvetica,sans-serif;color:{$ink};">{$name}</td></tr>
        <tr><td style="padding:0 0 9px;font:600 11px/1.4 Arial,Helvetica,sans-serif;color:{$deep};letter-spacing:.7px;text-transform:uppercase;">{$title} &middot; Base Fare</td></tr>
        {$phoneRow}
        <tr><td style="padding:0 0 3px;font:400 12px/1.5 Arial,Helvetica,sans-serif;color:{$body};">
          <span style="color:{$muted};">Email&nbsp;&nbsp;</span>
          <a href="mailto:{$mail}" style="color:{$deep};text-decoration:none;">{$mail}</a>
        </td></tr>
        <tr><td style="padding:2px 0 0;font:400 12px/1.5 Arial,Helvetica,sans-serif;color:{$body};">
          <span style="color:{$muted};">Web&nbsp;&nbsp;</span>
          <a href="{$web}" style="color:{$deep};text-decoration:none;">{$webLb}</a>
        </td></tr>
      </table>
    </td>
  </tr>
</table>
<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:12px;">
  <tr><td style="border-top:1px solid {$rule};padding:9px 0 0;font:400 10px/1.5 Arial,Helvetica,sans-serif;color:{$muted};max-width:520px;">
    This message and any attachments are intended only for the addressee and may contain confidential booking information. If it reached you in error, please delete it and notify the sender.
  </td></tr>
</table>
HTML;
    }

    /**
     * Plain-text signature, for the AltBody every outbound mail carries.
     * Without this a text-only client shows the message ending mid-air with no
     * sender identity at all.
     */
    public static function plain(?User $user): string
    {
        $f = self::fields($user);
        if (!$f['enabled']) {
            return '';
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '--';
        $lines[] = $f['name'];
        $lines[] = $f['title'] . ' | Base Fare';
        $lines[] = '';
        if ($f['direct'] !== '') {
            $lines[] = 'Tel:   ' . $f['direct'] . ($f['ext'] !== '' ? ' ext. ' . $f['ext'] : '');
        }
        $lines[] = 'Email: ' . $f['email'];
        $lines[] = 'Web:   ' . self::WEB_LABEL;
        $lines[] = '';
        $lines[] = 'This message and any attachments are intended only for the';
        $lines[] = 'addressee and may contain confidential booking information.';
        $lines[] = 'If it reached you in error, please delete it and notify the sender.';

        return implode("\n", $lines);
    }

    // =========================================================================
    // INPUT
    // =========================================================================

    /**
     * Normalise submitted form fields into the shape stored in the column.
     *
     * Returns null when nothing meaningful was supplied and the signature is
     * left on, so the row stays NULL and keeps following role defaults rather
     * than freezing today's defaults into every account as stored values.
     *
     * @param  array $data Raw request body
     * @return array{title:string,direct:string,ext:string,enabled:bool}|null
     */
    public static function fromInput(array $data): ?array
    {
        $title   = trim((string) ($data['sig_title']  ?? ''));
        $direct  = trim((string) ($data['sig_direct'] ?? ''));
        $ext     = trim((string) ($data['sig_ext']    ?? ''));
        $enabled = !empty($data['sig_enabled']);

        // Length caps mirror the column's practical limits and stop a pasted
        // paragraph from wrecking the layout of every email an agent sends.
        $title  = mb_substr($title, 0, 60);
        $direct = mb_substr($direct, 0, 30);
        $ext    = mb_substr(preg_replace('/[^0-9]/', '', $ext) ?? '', 0, 8);

        if ($enabled && $title === '' && $direct === '' && $ext === '') {
            return null;
        }

        return [
            'title'   => $title,
            'direct'  => $direct,
            'ext'     => $ext,
            'enabled' => $enabled,
        ];
    }

    /** Strip everything a phone dialler cannot use, and assume +1 for 10 digits. */
    private static function telHref(string $raw): string
    {
        $d = preg_replace('/[^0-9+]/', '', $raw) ?? '';
        if ($d !== '' && $d[0] !== '+' && strlen($d) === 10) {
            $d = '+1' . $d;
        }
        return $d;
    }
}
