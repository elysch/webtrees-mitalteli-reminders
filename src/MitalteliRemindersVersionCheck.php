<?php

/**
 * Webtrees version gate for the Event-reminders module.
 *
 * !!! COMPATIBILITY WARNING !!!
 * This file is loaded BEFORE anything else in the module, and it must be
 * parseable by old PHP versions (7.x) used by old webtrees releases. The rest
 * of the module uses PHP 8.1+ syntax (constructor promotion, readonly, ...),
 * which makes the old PHP *parser* abort with a ParseError the moment such a
 * file is included. So this file must NOT use any PHP 8 syntax (no typed or
 * promoted properties, no union types, no match, no named arguments, no
 * nullsafe operator, no `never`/`mixed`, no trailing comma in parameter
 * lists) and must NOT include or autoload any other file of the module.
 */

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Webtrees;

final class MitalteliRemindersVersionCheck
{
    public const CUSTOM_VERSION = '1.0.1';
    public const MODULE_NAME = 'Event reminders';

    public const MINIMUM_WEBTREES_VERSION = '2.2.6';
    // No upper limit yet. Set one if the module starts using features that
    // require a maximum version.
    public const MAXIMUM_WEBTREES_VERSION = 'undefined';

    /**
     * The installed webtrees version.
     *
     * @return string
     */
    public static function installedWebtreesVersion(): string
    {
        if (defined('WT_VERSION')) {
            return (string) constant('WT_VERSION');
        }

        return (string) Webtrees::VERSION;
    }

    /**
     * Is the installed webtrees version supported by this module?
     * Never throws and never dies: on failure it queues a flash message
     * (shown in the page webtrees renders) and returns false.
     *
     * @return bool
     */
    public static function isWebtreesVersionAcceptable(): bool
    {
        $installed = self::installedWebtreesVersion();
        $has_max   = self::MAXIMUM_WEBTREES_VERSION !== 'undefined';

        $too_old = version_compare($installed, self::MINIMUM_WEBTREES_VERSION, '<');
        $too_new = $has_max && version_compare($installed, self::MAXIMUM_WEBTREES_VERSION, '>');

        if (!$too_old && !$too_new) {
            return true;
        }

        // I18N and the module's translations are not available yet (the module
        // object does not exist), so the message is hardcoded in English.
        if ($has_max) {
            $supported = 'between ' . self::MINIMUM_WEBTREES_VERSION . ' and ' . self::MAXIMUM_WEBTREES_VERSION;
        } else {
            $supported = self::MINIMUM_WEBTREES_VERSION . ' and above';
        }

        $message = 'Version ' . self::CUSTOM_VERSION . ' of the “' . self::MODULE_NAME . '” module '
            . 'is not compatible with the installed webtrees version (' . $installed . '). '
            . 'Supported webtrees versions are ' . $supported . ' (see the README.md file). '
            . 'The module has not been loaded. '
            . 'To disable this message, upgrade webtrees or rename the module directory '
            . 'so that it contains a period (.).';

        if (class_exists(FlashMessages::class)) {
            FlashMessages::addMessage($message, 'danger');
        } else {
            error_log($message);
        }

        return false;
    }
}
