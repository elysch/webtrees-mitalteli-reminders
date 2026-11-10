<?php

/**
 * Event-reminders module (birthdays and wedding anniversaries) for webtrees 2.2 and 2.3.
 */

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

require_once __DIR__ . '/src/RemindersModule.php';

// This script must return an object that implements ModuleCustomInterface.
return new RemindersModule();
