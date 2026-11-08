<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Administration page + sidebar display mode for the Reminders module.
 *
 * Used by RemindersModule together with ModuleConfigTrait. Every setting is a
 * plain module preference (table wt_module_setting), with the same names the
 * README documents, so existing installations keep working unchanged.
 *
 * Design rules:
 *  - Administrators only (checked here as well as by webtrees' ModuleAction).
 *  - All input is validated BEFORE anything is stored; on any error nothing
 *    is saved (all-or-nothing).
 *  - The cron token is never rendered back into the page. It can only be kept,
 *    replaced, or regenerated (and a regenerated one is shown once).
 *  - The cron must not run as an administrator: every digest is built with
 *    that account's privacy level.
 */
trait ReminderSettings
{
    private const REMINDER_MAX_RECIPIENTS = 100;

    /** @return array<int,string> */
    public function sidebarModes(): array
    {
        return ['full', 'compact', 'collapsed', 'hidden'];
    }

    /**
     * How the panel on individual pages is shown (issue #4).
     * Unknown/corrupt values fall back to the historical behaviour ("full").
     */
    public function sidebarMode(): string
    {
        $mode = $this->getPreference('sidebar_mode', 'full');

        return in_array($mode, $this->sidebarModes(), true) ? $mode : 'full';
    }

    /**
     * Choose the sidebar template for the configured display mode.
     *
     * @param array<string,mixed> $vars The variables RemindersModule already computes.
     */
    protected function renderReminderSidebar(array $vars): string
    {
        $mode = $this->sidebarMode();

        if ($mode === 'compact') {
            return view($this->name() . '::sidebar-compact', $vars);
        }

        $html = view($this->name() . '::sidebar', $vars);

        if ($mode !== 'collapsed') {
            return $html;
        }

        $summary = '';

        if (($vars['is_self'] ?? false) === true) {
            $summary = I18N::translate('This is your own profile.');
        } elseif (is_string($vars['relationship'] ?? null)) {
            $summary = $vars['relationship'];
        }

        return view($this->name() . '::sidebar-collapsed', [
            'title'   => $this->title(),
            'summary' => $summary,
            'content' => $html,
        ]);
    }

    private function requireReminderAdmin(): void
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireReminderAdmin();

        $this->layout = 'layouts/administration';

        /** @var UserService $user_service */
        $user_service = Registry::container()->get(UserService::class);
        /** @var TreeService $tree_service */
        $tree_service = Registry::container()->get(TreeService::class);

        $current_id   = $this->getPreference('run_as_user_id');
        $current_user = ctype_digit($current_id) && $current_id !== '' ? $user_service->find((int) $current_id) : null;

        $users = [];
        foreach ($user_service->all() as $user) {
            $is_current = $current_user !== null && $user->id() === $current_user->id();

            if (Auth::isAdmin($user) && !$is_current) {
                continue; // administrators can't be newly selected
            }

            $users[$user->id()] = $user->realName() . ' (' . $user->userName() . ')';
        }

        $trees = [];
        foreach ($tree_service->all() as $tree) {
            $trees[$tree->name()] = $tree->title();
        }

        return $this->viewResponse($this->name() . '::admin-config', [
            'title'               => $this->title() . ' — ' . I18N::translate('Preferences'),
            'action_url'          => $this->getConfigLink(),
            'cron_url'            => $this->reminderCronUrl(),
            'has_token'           => $this->getPreference('cron_token') !== '',
            'users'               => $users,
            'run_as_user_id'      => $current_user?->id() ?? 0,
            'run_as_is_admin'     => $current_user !== null && Auth::isAdmin($current_user),
            'trees'               => $trees,
            'tree'                => $this->getPreference('tree'),
            'email_from'          => $this->getPreference('email_from'),
            'email_from_name'     => $this->getPreference('email_from_name'),
            'email_subject_prefix' => $this->getPreference('email_subject_prefix'),
            'allow_recipients'    => str_replace(',', "\n", $this->getPreference('allow_recipients')),
            'sidebar_mode'        => $this->sidebarMode(),
            'sidebar_modes'       => $this->sidebarModes(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireReminderAdmin();

        $params = (array) $request->getParsedBody();
        $str    = static fn (string $key): string => is_string($params[$key] ?? null) ? trim($params[$key]) : '';

        /** @var UserService $user_service */
        $user_service = Registry::container()->get(UserService::class);
        /** @var TreeService $tree_service */
        $tree_service = Registry::container()->get(TreeService::class);

        $errors    = [];
        $values    = [];
        $generated = null;

        // --- Cron token: keep / replace / regenerate. Never echoed back. ---
        $current_token = $this->getPreference('cron_token');
        $submitted     = $str('cron_token');

        if ($submitted !== '') {
            if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $submitted) !== 1) {
                $errors[] = I18N::translate('The cron token must be 32–128 characters: letters, digits, “-” and “_”.');
            } else {
                $values['cron_token'] = $submitted;
            }
        } elseif ($current_token === '' || !empty($params['regenerate_token'])) {
            $generated            = bin2hex(random_bytes(32));
            $values['cron_token'] = $generated;
        }

        // --- Account the cron runs as: must exist; never a (new) administrator. ---
        $run_as = $str('run_as_user_id');

        if ($run_as === '') {
            $values['run_as_user_id'] = '';
        } elseif (preg_match('/^[1-9][0-9]{0,9}$/', $run_as) !== 1) {
            $errors[] = I18N::translate('The selected user does not exist.');
        } else {
            $user = $user_service->find((int) $run_as);

            if ($user === null) {
                $errors[] = I18N::translate('The selected user does not exist.');
            } elseif (Auth::isAdmin($user) && $run_as !== $this->getPreference('run_as_user_id')) {
                $errors[] = I18N::translate('An administrator account cannot be used to run the cron.');
            } else {
                $values['run_as_user_id'] = (string) $user->id();
            }
        }

        // --- Tree: empty = first/only tree. ---
        $tree = $str('tree');

        if ($tree !== '' && !$tree_service->all()->has($tree)) {
            $errors[] = I18N::translate('The selected tree does not exist.');
        } else {
            $values['tree'] = $tree;
        }

        // --- Email envelope. ---
        $from = $str('email_from');

        if ($from === '' || mb_strlen($from) > 254 || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = I18N::translate('The sender address is not a valid email address.');
        } else {
            $values['email_from'] = $from;
        }

        $values['email_from_name']      = $this->reminderCleanText($str('email_from_name'), 100);
        $values['email_subject_prefix'] = $this->reminderCleanText($str('email_subject_prefix'), 60);

        // --- Test recipients (CSV). ---
        $recipients = [];
        $parts      = preg_split('/[\s,;]+/', $str('allow_recipients'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_unique($parts) as $address) {
            if (mb_strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = I18N::translate('Invalid email address: %s', e($address));
            } else {
                $recipients[] = $address;
            }
        }

        if (count($recipients) > self::REMINDER_MAX_RECIPIENTS) {
            $errors[] = I18N::translate('Too many test recipients (maximum %s).', I18N::number(self::REMINDER_MAX_RECIPIENTS));
        }

        $values['allow_recipients'] = implode(',', $recipients);

        // --- Panel display mode (issue #4). ---
        $mode = $str('sidebar_mode');

        if (!in_array($mode, $this->sidebarModes(), true)) {
            $errors[] = I18N::translate('Invalid display mode.');
        } else {
            $values['sidebar_mode'] = $mode;
        }

        // --- All-or-nothing: store only if everything validated. ---
        if ($errors !== []) {
            foreach (array_unique($errors) as $error) {
                FlashMessages::addMessage($error, 'danger');
            }

            return redirect($this->getConfigLink());
        }

        $changed = [];

        foreach ($values as $key => $value) {
            if ($this->getPreference($key) !== $value) {
                $this->setPreference($key, $value);
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            // Names only — never log secrets.
            Log::addConfigurationLog('Module ' . $this->name() . ' preferences changed: ' . implode(', ', $changed));
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        if ($generated !== null) {
            FlashMessages::addMessage(
                I18N::translate('New cron token (shown only once): %s', '<code>' . e($generated) . '</code>'),
                'info'
            );
        }

        return redirect($this->getConfigLink());
    }

    private function reminderCleanText(string $value, int $max): string
    {
        // Strip control characters (CR/LF would enable header injection).
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    private function reminderCronUrl(): string
    {
        try {
            return route(static::class . ':cron');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Strings of the admin page. English is the source language; only Spanish is
     * provided here, other languages fall back to English.
     *
     * @return array<string,string>
     */
    private function reminderAdminTranslations(string $primary): array
    {
        if ($primary !== 'es') {
            return [];
        }

        return [
            'Daily cron' => 'Cron diario',
            'Tree' => 'Árbol',
            'Cron token' => 'Token del cron',
            'A token is set. Leave this field empty to keep it.' => 'Hay un token configurado. Deja este campo vacío para conservarlo.',
            'No token is set: the cron route answers 403 until one exists. A random one is generated when you save.' => 'No hay token: la ruta del cron responde 403 hasta que exista uno. Se generará uno aleatorio al guardar.',
            'Or paste your own token (32–128 characters: letters, digits, “-”, “_”).' => 'O pega tu propio token (32–128 caracteres: letras, dígitos, “-”, “_”).',
            'Generate a new random token (invalidates the current one)' => 'Generar un token aleatorio nuevo (invalida el actual)',
            'Cron URL' => 'URL del cron',
            'Replace TOKEN with the cron token. Without send=1 it is a dry run: previews are built but no mail is sent.' => 'Sustituye TOKEN por el token del cron. Sin send=1 es una corrida en seco: se generan vistas previas pero no se envía correo.',
            'Run the cron as' => 'Ejecutar el cron como',
            '(none — guest)' => '(ninguno — invitado)',
            'Use a dedicated account with the lowest role that can read the relationships. Every digest is built with this account’s privacy level.' => 'Usa una cuenta dedicada con el rol más bajo que pueda leer los parentescos. Todos los resúmenes se generan con el nivel de privacidad de esta cuenta.',
            'The selected account is an administrator. Digests are built with administrator visibility, which can disclose private data to subscribers. Choose a regular member.' => 'La cuenta seleccionada es administradora. Los resúmenes se generan con visibilidad de administrador, lo que puede revelar datos privados a los suscriptores. Elige un miembro normal.',
            '(administrator — not recommended)' => '(administrador — no recomendado)',
            '(first tree)' => '(primer árbol)',
            'Email' => 'Correo',
            'From address' => 'Dirección del remitente',
            'From name' => 'Nombre del remitente',
            'Subject prefix' => 'Prefijo del asunto',
            'Test recipients' => 'Destinatarios de prueba',
            'One address per line (or comma-separated). When not empty, ONLY these addresses receive mail.' => 'Una dirección por línea (o separadas por comas). Si no está vacío, SOLO estas direcciones reciben correo.',
            'Test mode is active: only the addresses above receive mail.' => 'Modo de prueba activo: solo las direcciones anteriores reciben correo.',
            'Individual page panel' => 'Panel de la página del individuo',
            'Display mode' => 'Modo de visualización',
            'Full' => 'Completo',
            'Current behaviour: relationship, follow toggle and details.' => 'Comportamiento actual: parentesco, botón de seguimiento y detalles.',
            'Compact' => 'Compacto',
            'One line: relationship badge plus a small follow button.' => 'Una línea: insignia de parentesco y un botón pequeño de seguimiento.',
            'Collapsed' => 'Colapsado',
            'Full panel, folded by default; click the summary line to expand.' => 'Panel completo, plegado por defecto; haz clic en la línea de resumen para expandirlo.',
            'Hidden' => 'Oculto',
            'No panel. This also removes the “remind me” toggle from individual pages; follows remain available on each member’s Reminders page.' => 'Sin panel. También quita el botón “recordarme” de las páginas de individuos; el seguimiento sigue disponible en la página Cumpleaños de cada miembro.',
            'The cron token must be 32–128 characters: letters, digits, “-” and “_”.' => 'El token del cron debe tener 32–128 caracteres: letras, dígitos, “-” y “_”.',
            'The selected user does not exist.' => 'El usuario seleccionado no existe.',
            'An administrator account cannot be used to run the cron.' => 'No se puede usar una cuenta de administrador para ejecutar el cron.',
            'The selected tree does not exist.' => 'El árbol seleccionado no existe.',
            'The sender address is not a valid email address.' => 'La dirección del remitente no es un correo válido.',
            'Invalid email address: %s' => 'Correo no válido: %s',
            'Too many test recipients (maximum %s).' => 'Demasiados destinatarios de prueba (máximo %s).',
            'Invalid display mode.' => 'Modo de visualización no válido.',
            'New cron token (shown only once): %s' => 'Nuevo token del cron (se muestra una sola vez): %s',
        ];
    }
}
