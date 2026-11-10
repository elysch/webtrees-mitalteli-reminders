<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Cron endpoint of the Event reminders module: POST-only, one subscriber per
 * request, executed with that subscriber's own privileges. It is the module
 * action "Cron" of webtrees' built-in module route (the URL is shown as
 * "Cron endpoint" in the module's administration page).
 *
 *   GET  <cron endpoint>   -> the session's CSRF token (text/plain). webtrees'
 *                             CheckCsrf middleware requires it on every POST
 *                             and gives a cron client no other way to get one.
 *   POST <cron endpoint>   -> form fields (the token is never in the URL):
 *        _csrf   CSRF token from the GET above (same session cookie)
 *        token   the module's cron_token
 *        action  list | run | preview
 *        xref    subscriber XREF            (run, preview)
 *        send    1 = really send, else dry run   (run)
 *        lang    language tag override      (preview)
 *
 * WHY ONE SUBSCRIBER PER REQUEST (process isolation): webtrees caches privacy
 * decisions per PHP process under a key that does NOT contain the user
 * ("show-{xref}-{tree}-{access level}", plus the visible-tree list), while the
 * decision itself depends on the user (relationship privacy / path length).
 * Switching users inside one process could therefore reuse another
 * subscriber's decisions. A fresh process per subscriber cannot.
 */
trait ReminderCron
{
    private const REMINDER_XREF_PATTERN = '/^[A-Za-z0-9]{1,20}$/';

    /** Module action "Cron", GET: hand out the session's CSRF token. */
    public function getCronAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->cronText(csrf_token());
    }

    /** Module action "Cron", POST: list subscribers, or run/preview one of them. */
    public function postCronAction(ServerRequestInterface $request): ResponseInterface
    {
        $body       = Validator::parsedBody($request);
        $configured = (string) $this->getPreference('cron_token', '');
        $supplied   = $body->string('token', '');

        if ($configured === '' || !hash_equals($configured, $supplied)) {
            Log::addAuthenticationLog('Event reminders cron: request rejected (missing or invalid token)');

            return $this->cronText('Forbidden', StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $action = $body->string('action', '');

        if ($action === 'list') {
            return $this->cronList();
        }

        $xref = $body->string('xref', '');

        if (!in_array($action, ['run', 'preview'], true) || preg_match(self::REMINDER_XREF_PATTERN, $xref) !== 1) {
            return $this->cronText('Bad request', StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        return $this->cronSubscriber($body, $action, $xref);
    }

    /** One XREF per line. */
    private function cronList(): ResponseInterface
    {
        try {
            $xrefs = [];

            foreach ((new ReminderSubscriptions())->all() as $row) {
                $xref = (string) ($row['gedid'] ?? '');

                if (preg_match(self::REMINDER_XREF_PATTERN, $xref) === 1) {
                    $xrefs[$xref] = $xref;
                }
            }

            return $this->cronText($xrefs === [] ? '' : implode("\n", $xrefs) . "\n");
        } catch (Throwable $e) {
            Log::addErrorLog('Event reminders cron (list): ' . $e->getMessage());

            return $this->cronText("ERR   list failed\n", StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function cronSubscriber(Validator $body, string $action, string $xref): ResponseInterface
    {
        try {
            $row = (new ReminderSubscriptions())->find($xref);

            if ($row === null) {
                return $this->cronLine("ERR   {$xref} — not subscribed");
            }

            /** @var UserService $user_service */
            $user_service = Registry::container()->get(UserService::class);
            $recipient    = $user_service->find((int) ($row['user_id'] ?? 0));

            if ($recipient === null) {
                return $this->cronLine("ERR   {$xref} — no webtrees user for this subscriber");
            }

            // Only accounts that could sign in normally are ever impersonated.
            if (
                $recipient->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) !== '1' ||
                $recipient->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) !== '1'
            ) {
                return $this->cronLine("skip  {$xref} — account not approved or e-mail not verified");
            }

            // Run with the subscriber's own privileges. Always log out again.
            Auth::login($recipient);

            try {
                return $this->cronAsSubscriber($body, $recipient, $row, $xref, $action);
            } finally {
                Auth::logout();
            }
        } catch (Throwable $e) {
            Log::addErrorLog('Event reminders cron: ' . $e->getMessage());

            return $this->cronLine("EXC   {$xref} — " . $this->cronClean($e->getMessage()));
        }
    }

    /**
     * @param array<string,mixed> $row Subscription row joined to the user (ReminderSubscriptions::find)
     */
    private function cronAsSubscriber(Validator $body, UserInterface $recipient, array $row, string $xref, string $action): ResponseInterface
    {
        // Fail closed: carry on only if the session really is the subscriber.
        if (Auth::id() !== $recipient->id()) {
            return $this->cronLine("ERR   {$xref} — could not switch to the subscriber's account");
        }

        // Resolved AFTER login: TreeService::all() only lists trees this user may see.
        $tree = $this->resolveTree((string) $this->getPreference('tree', self::DEFAULT_TREE));

        if ($tree === null) {
            return $this->cronLine("ERR   {$xref} — tree not found or not visible to this subscriber");
        }

        // The subscription is keyed by XREF only. Make sure this account really
        // is that person in THIS tree before building anything for them.
        if ($tree->getUserPreference($recipient, UserInterface::PREF_TREE_ACCOUNT_XREF) !== $xref) {
            return $this->cronLine("ERR   {$xref} — account is not linked to this XREF in tree " . $this->cronClean($tree->name()));
        }

        $to    = $this->cronClean((string) ($row['email'] ?? ''));
        $name  = $this->cronClean((string) ($row['namekomplett'] ?? ''));
        $ahead = (int) ($row['days_ahead'] ?? 1);
        $dist  = (int) ($row['max_distance'] ?? 0);
        $dec   = (bool) ($row['include_deceased'] ?? 0);
        $mar   = (bool) ($row['include_marriages'] ?? 0);

        $language = $recipient->getPreference('language', '');
        $default  = (string) Site::getPreference('LANGUAGE');
        $lang     = $language !== '' ? $language : ($default !== '' ? $default : 'en-US');
        $override = $body->string('lang', '');

        if ($action === 'preview' && preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})?$/', $override) === 1) {
            $lang = $override;
        }

        $this->applyLocale($lang);

        $service = new ReminderService(
            Registry::container()->get(CalendarService::class),
            Registry::container()->get(RelationshipService::class),
            $this->name(),
            fn (Tree $t): string => $this->editUrl($t)
        );

        $html = $service->digestHtml($tree, $xref, $ahead, $dist, $dec, $mar);

        if ($action === 'preview') {
            return response($html !== '' ? $html : '(no events in range for ' . $xref . ')', StatusCodeInterface::STATUS_OK)
                ->withHeader('content-type', 'text/html; charset=UTF-8')
                ->withHeader('cache-control', 'no-store');
        }

        if ($html === '') {
            return $this->cronLine("skip  {$xref} <{$to}> — no events in range");
        }

        if ($body->integer('send', 0) !== 1) {
            return $this->cronLine("PREVIEW {$xref} <{$to}> [{$lang}] — digest built ok (dry run, no mail)");
        }

        $allow = array_map('strtolower', array_filter(array_map('trim', explode(',', (string) $this->getPreference('allow_recipients', '')))));

        if ($allow !== [] && !in_array(strtolower($to), $allow, true)) {
            return $this->cronLine("hold  {$xref} <{$to}> — not on allow-list");
        }

        $brand   = trim((string) $this->getPreference('email_subject_prefix', ''));
        $subject = $brand !== '' ? $brand . ' ' . I18N::translate('Event reminders') : I18N::translate('Event reminders');
        $text    = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $from    = new ReminderSender(
            (string) $this->getPreference('email_from', ''),
            (string) $this->getPreference('email_from_name', '')
        );

        $ok = Registry::container()->get(EmailService::class)->send($from, $recipient, $from, $subject, $text, $html);

        return $this->cronLine(($ok ? 'SENT  ' : 'FAIL  ') . "{$xref} <{$to}> ({$name})");
    }

    /**
     * Per-subscriber outcomes (including errors) are answered with HTTP 200 and an
     * "ERR/EXC/skip/..." line, so the cron client can tell them apart from a
     * transport or routing problem (any non-200 status).
     */
    private function cronLine(string $line, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return $this->cronText($line . "\n", $status);
    }

    private function cronText(string $text, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return response($text, $status)
            ->withHeader('content-type', 'text/plain; charset=UTF-8')
            ->withHeader('cache-control', 'no-store')
            ->withHeader('x-content-type-options', 'nosniff');
    }

    /** Values from the database/exceptions must not be able to forge extra log lines. */
    private function cronClean(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '', 0, 200);
    }
}
