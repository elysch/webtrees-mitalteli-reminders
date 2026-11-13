<?php

/**
 * Event-reminders module (birthdays and wedding anniversaries) for webtrees 2.2 and 2.3.
 */

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

// 1. Version gate. This file is deliberately old-PHP-compatible (see its header)
//    and is the ONLY file loaded before the version check: the rest of the
//    module uses PHP 8.1+ syntax, so merely including it on an old webtrees /
//    PHP would end in a ParseError before any check could run.
require_once __DIR__ . '/src/MitalteliRemindersVersionCheck.php';

if (!MitalteliRemindersVersionCheck::isWebtreesVersionAcceptable()) {
    // An error message has been queued for the user. Do not load the module.
    return;
}

// 2. Only now it is safe to load the module class (and, through it, the rest).
require_once __DIR__ . '/src/MitalteliRemindersModule.php';

// This script must return an object that implements ModuleCustomInterface.
return new MitalteliRemindersModule();
