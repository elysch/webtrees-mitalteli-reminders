<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\Contracts\UserInterface;

/**
 * The "From" identity for reminder emails.
 *
 * EmailService builds the From header from $from->email() / $from->realName(),
 * so a minimal UserInterface is all that is needed. The address and display
 * name come from the module's control-panel settings (email_from /
 * email_from_name); neutral defaults apply when they are unset.
 */
class ReminderSender implements UserInterface
{
    public function __construct(
        private readonly string $emailAddress = '',
        private readonly string $displayName = ''
    ) {
    }

    public function id(): int
    {
        return 0;
    }

    public function email(): string
    {
        return $this->emailAddress !== '' ? $this->emailAddress : 'no-reply@localhost';
    }

    public function realName(): string
    {
        return $this->displayName !== '' ? $this->displayName : 'Birthday Reminders';
    }

    public function userName(): string
    {
        return '';
    }

    public function getPreference(string $setting_name, string $default = ''): string
    {
        return $default;
    }

    public function setPreference(string $setting_name, string $setting_value): void
    {
    }
}
