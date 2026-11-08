<?php

/**
 * Birthday-reminders module for webtrees 2.x.
 */

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

require_once __DIR__ . '/src/RemindersModule.php';

// This script must return an object that implements ModuleCustomInterface.
return new RemindersModule();
