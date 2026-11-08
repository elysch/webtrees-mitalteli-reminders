<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Module\ModuleSidebarTrait;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleSidebarInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\RelationshipsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

require_once __DIR__ . '/ReminderSubscriptions.php';
require_once __DIR__ . '/ReminderFollows.php';
require_once __DIR__ . '/ReminderRelationships.php';
require_once __DIR__ . '/ReminderService.php';
require_once __DIR__ . '/ReminderSender.php';
require_once __DIR__ . '/ReminderSettings.php';

use Mitalteli\Webtrees\Util\Reminders\ReminderSettings;
use Mitalteli\Webtrees\Util\Reminders\ReminderRelationships;

/**
 * A birthday-reminder module for webtrees.
 *
 * SAFETY: boot() registers routes + the view namespace ONLY. Every fragile
 * operation (DB, relationship BFS, mail) runs inside a per-request handler, so
 * a failure can only break this module's own page — never the whole site.
 */
class RemindersModule extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, ModuleSidebarInterface, RequestHandlerInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleSidebarTrait;
    use ViewResponseTrait;
    use ModuleConfigTrait;
    use ReminderSettings;

    protected const ROUTE_URL  = '/tree/{tree}/reminders';
    protected const ROUTE_CRON = '/reminders-cron';

    // Tree used by the (guest-accessible, tree-less) cron route. Empty means
    // "use the first/only tree" — see resolveTree().
    private const DEFAULT_TREE = '';

    /**
     * Register routes and the view namespace. Nothing else may live here.
     */
    public function boot(): void
    {
        $router = Registry::routeFactory()->routeMap();

        $router->get(static::class, static::ROUTE_URL, $this);
        $router->post(static::class . ':save', static::ROUTE_URL, $this);
        $router->get(static::class . ':cron', static::ROUTE_CRON, $this);
        $router->allows(RequestMethodInterface::METHOD_POST);

        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function title(): string
    {
        return I18N::translate('Reminders');
    }

    public function description(): string
    {
        return I18N::translate('Birthday reminders for relatives in the family tree based on the work made by jbh4x82 at https://github.com/jbh4x82/wt_reminders.');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function customModuleAuthorName(): string
    {
        return 'elysch';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/elysch/webtrees-mitalteli-reminders/issues';
    }

    /**
     * Digest strings in the common family languages. The cron calls I18N::init()
     * with each subscriber's language before rendering, and webtrees merges these
     * into the active translator (see I18N::init -> customTranslations). Languages
     * outside this set fall back to the English source strings (core content —
     * dates, relationship labels — still localises for any webtrees language).
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        $primary = strtolower(substr($language, 0, 2));

        $catalogue = [
            'de' => [
                'Birthdays'                                    => 'Geburtstage',
                'Reminders'                                    => 'Geburtstage',
                'Today'                                        => 'Heute',
                'Tomorrow'                                     => 'Morgen',
                'Send birthday email'                          => 'Geburtstags-E-Mail senden',
                'Happy birthday'                               => 'Alles Gute zum Geburtstag',
                'year'                                         => 'Jahr',
                'years'                                        => 'Jahre',
                'Your current settings'                        => 'Ihre aktuellen Einstellungen',
                'Maximum distance to relative'                 => 'Maximaler Verwandtschaftsabstand',
                'Unlimited'                                    => 'Unbegrenzt',
                'Notifications: %s day ahead'                  => 'Benachrichtigungen: %s Tag im Voraus',
                'Notifications: %s days ahead'                 => 'Benachrichtigungen: %s Tage im Voraus',
                'Manage your birthday reminder subscriptions'  => 'Geburtstagserinnerungen verwalten',
                'Relationship to you'                          => 'Verwandtschaft zu dir',
                'Show full relationship'                       => 'Vollständige Verwandtschaft anzeigen',
                'This is your own profile.'                    => 'Das ist dein eigenes Profil.',
                'Remind me of this birthday'                   => 'An diesen Geburtstag erinnern',
                'Stop reminding me'                            => 'Erinnerung beenden',
                'You will be reminded of this birthday.'       => 'Du wirst an diesen Geburtstag erinnert.',
                'Manage all reminders'                         => 'Alle Erinnerungen verwalten',
                'Followed'                                     => 'Abonniert',
                'People you follow'                            => 'Personen, denen du folgst',
                'Always remind me of these birthdays, no matter how distant the relative.' => 'Erinnere mich immer an diese Geburtstage, egal wie entfernt verwandt.',
                'Search for a person by name'                  => 'Person nach Namen suchen',
                'Follow'                                       => 'Folgen',
                'Following'                                    => 'Abonniert',
                'Remove'                                       => 'Entfernen',
                'You are not following anyone yet.'            => 'Du folgst noch niemandem.',
                'No matches found.'                            => 'Keine Treffer gefunden.',
                'Include birthdays of deceased people'        => 'Geburtstage Verstorbener einschließen',
                'Including birthdays of deceased people'       => 'Geburtstage Verstorbener werden einbezogen',
                'Deceased'                                     => 'Verstorben',
                'today'                                        => 'heute',
                'tomorrow'                                     => 'morgen',
                'in %s day'                                    => 'in %s Tag',
                'in %s days'                                   => 'in %s Tagen',
                '%s year old'                                  => '%s Jahr alt',
                '%s years old'                                 => '%s Jahre alt',
                'Upcoming birthdays'                           => 'Bevorstehende Geburtstage',
                'Birthdays in the next month, based on your settings above.' => 'Geburtstage im nächsten Monat, basierend auf deinen Einstellungen oben.',
                'No upcoming birthdays in the next month.'     => 'Keine Geburtstage im nächsten Monat.',
                'Days ahead'                                   => 'Tage im Voraus',
                'Save'                                         => 'Speichern',
                'Subscribe'                                    => 'Abonnieren',
                'Unsubscribe'                                  => 'Abbestellen',
                'You are subscribed to birthday reminders.'    => 'Du hast die Geburtstagserinnerungen abonniert.',
                'You are not currently subscribed to birthday reminders.' => 'Du hast die Geburtstagserinnerungen derzeit nicht abonniert.',
                'Your account is not linked to a person in this family tree, so birthday reminders cannot be set up. Please ask an administrator to link your account.' => 'Dein Konto ist mit keiner Person in diesem Stammbaum verknüpft, daher können keine Geburtstagserinnerungen eingerichtet werden. Bitte bitte einen Administrator, dein Konto zu verknüpfen.',
                'Children, parents and siblings'               => 'Kinder, Eltern und Geschwister',
                'Grandparents/children, aunts/uncles'          => 'Großeltern/-kinder, Tanten/Onkel',
                'First cousins (incl. spouses)'                => 'Cousins ersten Grades (inkl. Ehepartner)',
                'First cousins and their children'             => 'Cousins ersten Grades und deren Kinder',
                'Second cousins (incl. spouses)'               => 'Cousins zweiten Grades (inkl. Ehepartner)',
                'Second cousins and their children'            => 'Cousins zweiten Grades und deren Kinder',
                'Third cousins (incl. spouses)'                => 'Cousins dritten Grades (inkl. Ehepartner)',
                'Third cousins and their children'             => 'Cousins dritten Grades und deren Kinder',
                'Fourth cousins (incl. spouses)'               => 'Cousins vierten Grades (inkl. Ehepartner)',
                'Fourth cousins and their children'            => 'Cousins vierten Grades und deren Kinder',
            ],
            'fr' => [
                'Birthdays'                                    => 'Anniversaires',
                'Reminders'                                    => 'Anniversaires',
                'Today'                                        => "Aujourd'hui",
                'Tomorrow'                                     => 'Demain',
                'Send birthday email'                          => "Envoyer un e-mail d'anniversaire",
                'Happy birthday'                               => 'Joyeux anniversaire',
                'year'                                         => 'an',
                'years'                                        => 'ans',
                'Your current settings'                        => 'Vos paramètres actuels',
                'Maximum distance to relative'                 => 'Distance maximale de parenté',
                'Unlimited'                                    => 'Illimitée',
                'Notifications: %s day ahead'                  => "Notifications : %s jour à l'avance",
                'Notifications: %s days ahead'                 => "Notifications : %s jours à l'avance",
                'Manage your birthday reminder subscriptions'  => "Gérer vos rappels d'anniversaire",
                'Relationship to you'                          => 'Lien de parenté avec vous',
                'Show full relationship'                       => 'Afficher la parenté complète',
                'This is your own profile.'                    => 'Ceci est votre propre profil.',
                'Remind me of this birthday'                   => 'Me rappeler cet anniversaire',
                'Stop reminding me'                            => 'Ne plus me le rappeler',
                'You will be reminded of this birthday.'       => 'Vous serez informé de cet anniversaire.',
                'Manage all reminders'                         => 'Gérer tous les rappels',
                'Followed'                                     => 'Suivi',
                'People you follow'                            => 'Personnes que vous suivez',
                'Always remind me of these birthdays, no matter how distant the relative.' => 'Rappelez-moi toujours ces anniversaires, quelle que soit la distance de parenté.',
                'Search for a person by name'                  => 'Rechercher une personne par son nom',
                'Follow'                                       => 'Suivre',
                'Following'                                    => 'Suivi',
                'Remove'                                       => 'Retirer',
                'You are not following anyone yet.'            => "Vous ne suivez encore personne.",
                'No matches found.'                            => 'Aucun résultat trouvé.',
                'Include birthdays of deceased people'        => 'Inclure les anniversaires des personnes décédées',
                'Including birthdays of deceased people'       => 'Les anniversaires des personnes décédées sont inclus',
                'Deceased'                                     => 'Décédé(e)',
                'today'                                        => "aujourd'hui",
                'tomorrow'                                     => 'demain',
                'in %s day'                                    => 'dans %s jour',
                'in %s days'                                   => 'dans %s jours',
                '%s year old'                                  => '%s an',
                '%s years old'                                 => '%s ans',
                'Upcoming birthdays'                           => 'Anniversaires à venir',
                'Birthdays in the next month, based on your settings above.' => 'Anniversaires du mois à venir, selon vos paramètres ci-dessus.',
                'No upcoming birthdays in the next month.'     => 'Aucun anniversaire le mois prochain.',
                'Days ahead'                                   => "Jours à l'avance",
                'Save'                                         => 'Enregistrer',
                'Subscribe'                                    => "S'abonner",
                'Unsubscribe'                                  => 'Se désabonner',
                'You are subscribed to birthday reminders.'    => "Vous êtes abonné(e) aux rappels d'anniversaire.",
                'You are not currently subscribed to birthday reminders.' => "Vous n'êtes pas actuellement abonné(e) aux rappels d'anniversaire.",
                'Your account is not linked to a person in this family tree, so birthday reminders cannot be set up. Please ask an administrator to link your account.' => "Votre compte n'est lié à aucune personne de cet arbre généalogique ; les rappels d'anniversaire ne peuvent donc pas être configurés. Veuillez demander à un administrateur de lier votre compte.",
                'Children, parents and siblings'               => 'Enfants, parents, frères et sœurs',
                'Grandparents/children, aunts/uncles'          => 'Grands-parents/petits-enfants, oncles/tantes',
                'First cousins (incl. spouses)'                => 'Cousins germains (conjoints inclus)',
                'First cousins and their children'             => 'Cousins germains et leurs enfants',
                'Second cousins (incl. spouses)'               => 'Cousins issus de germains (conjoints inclus)',
                'Second cousins and their children'            => 'Cousins issus de germains et leurs enfants',
                'Third cousins (incl. spouses)'                => 'Cousins au troisième degré (conjoints inclus)',
                'Third cousins and their children'             => 'Cousins au troisième degré et leurs enfants',
                'Fourth cousins (incl. spouses)'               => 'Cousins au quatrième degré (conjoints inclus)',
                'Fourth cousins and their children'            => 'Cousins au quatrième degré et leurs enfants',
            ],
            'it' => [
                'Birthdays'                                    => 'Compleanni',
                'Reminders'                                    => 'Compleanni',
                'Today'                                        => 'Oggi',
                'Tomorrow'                                     => 'Domani',
                'Send birthday email'                          => 'Invia email di auguri',
                'Happy birthday'                               => 'Buon compleanno',
                'year'                                         => 'anno',
                'years'                                        => 'anni',
                'Your current settings'                        => 'Le tue impostazioni attuali',
                'Maximum distance to relative'                 => 'Distanza massima di parentela',
                'Unlimited'                                    => 'Illimitata',
                'Notifications: %s day ahead'                  => 'Notifiche: %s giorno in anticipo',
                'Notifications: %s days ahead'                 => 'Notifiche: %s giorni in anticipo',
                'Manage your birthday reminder subscriptions'  => 'Gestisci i promemoria dei compleanni',
                'Relationship to you'                          => 'Parentela con te',
                'Show full relationship'                       => 'Mostra la parentela completa',
                'This is your own profile.'                    => 'Questo è il tuo profilo.',
                'Remind me of this birthday'                   => 'Ricordami questo compleanno',
                'Stop reminding me'                            => 'Smetti di ricordarmelo',
                'You will be reminded of this birthday.'       => 'Ti ricorderemo questo compleanno.',
                'Manage all reminders'                         => 'Gestisci tutti i promemoria',
                'Followed'                                     => 'Seguito',
                'People you follow'                            => 'Persone che segui',
                'Always remind me of these birthdays, no matter how distant the relative.' => 'Ricordami sempre questi compleanni, indipendentemente dal grado di parentela.',
                'Search for a person by name'                  => 'Cerca una persona per nome',
                'Follow'                                       => 'Segui',
                'Following'                                    => 'Seguito',
                'Remove'                                       => 'Rimuovi',
                'You are not following anyone yet.'            => 'Non segui ancora nessuno.',
                'No matches found.'                            => 'Nessun risultato trovato.',
                'Include birthdays of deceased people'        => 'Includi i compleanni delle persone decedute',
                'Including birthdays of deceased people'       => 'I compleanni delle persone decedute sono inclusi',
                'Deceased'                                     => 'Deceduto/a',
                'today'                                        => 'oggi',
                'tomorrow'                                     => 'domani',
                'in %s day'                                    => 'tra %s giorno',
                'in %s days'                                   => 'tra %s giorni',
                '%s year old'                                  => '%s anno',
                '%s years old'                                 => '%s anni',
                'Upcoming birthdays'                           => 'Compleanni in arrivo',
                'Birthdays in the next month, based on your settings above.' => 'Compleanni del prossimo mese, in base alle tue impostazioni qui sopra.',
                'No upcoming birthdays in the next month.'     => 'Nessun compleanno nel prossimo mese.',
                'Days ahead'                                   => 'Giorni in anticipo',
                'Save'                                         => 'Salva',
                'Subscribe'                                    => 'Iscriviti',
                'Unsubscribe'                                  => 'Annulla iscrizione',
                'You are subscribed to birthday reminders.'    => 'Sei iscritto ai promemoria dei compleanni.',
                'You are not currently subscribed to birthday reminders.' => 'Al momento non sei iscritto ai promemoria dei compleanni.',
                'Your account is not linked to a person in this family tree, so birthday reminders cannot be set up. Please ask an administrator to link your account.' => 'Il tuo account non è collegato a nessuna persona di questo albero genealogico, quindi non è possibile impostare i promemoria dei compleanni. Chiedi a un amministratore di collegare il tuo account.',
                'Children, parents and siblings'               => 'Figli, genitori e fratelli/sorelle',
                'Grandparents/children, aunts/uncles'          => 'Nonni/nipoti, zii/zie',
                'First cousins (incl. spouses)'                => 'Cugini di primo grado (coniugi inclusi)',
                'First cousins and their children'             => 'Cugini di primo grado e i loro figli',
                'Second cousins (incl. spouses)'               => 'Cugini di secondo grado (coniugi inclusi)',
                'Second cousins and their children'            => 'Cugini di secondo grado e i loro figli',
                'Third cousins (incl. spouses)'                => 'Cugini di terzo grado (coniugi inclusi)',
                'Third cousins and their children'             => 'Cugini di terzo grado e i loro figli',
                'Fourth cousins (incl. spouses)'               => 'Cugini di quarto grado (coniugi inclusi)',
                'Fourth cousins and their children'            => 'Cugini di quarto grado e i loro figli',
            ],
            'es' => [
                'Birthdays'                                    => 'Cumpleaños',
                'Reminders'                                    => 'Cumpleaños',
                'Today'                                        => 'Hoy',
                'Tomorrow'                                     => 'Mañana',
                'Send birthday email'                          => 'Enviar correo de cumpleaños',
                'Happy birthday'                               => 'Feliz cumpleaños',
                'year'                                         => 'año',
                'years'                                        => 'años',
                'Your current settings'                        => 'Tu configuración actual',
                'Maximum distance to relative'                 => 'Distancia máxima de parentesco',
                'Unlimited'                                    => 'Ilimitada',
                'Notifications: %s day ahead'                  => 'Notificaciones: %s día de antelación',
                'Notifications: %s days ahead'                 => 'Notificaciones: %s días de antelación',
                'Manage your birthday reminder subscriptions'  => 'Gestiona tus recordatorios de cumpleaños',
                'Relationship to you'                          => 'Parentesco contigo',
                'Show full relationship'                       => 'Mostrar el parentesco completo',
                'This is your own profile.'                    => 'Este es tu propio perfil.',
                'Remind me of this birthday'                   => 'Recordarme este cumpleaños',
                'Stop reminding me'                            => 'Dejar de recordármelo',
                'You will be reminded of this birthday.'       => 'Te recordaremos este cumpleaños.',
                'Manage all reminders'                         => 'Gestionar todos los recordatorios',
                'Followed'                                     => 'Seguido',
                'People you follow'                            => 'Personas que sigues',
                'Always remind me of these birthdays, no matter how distant the relative.' => 'Recuérdame siempre estos cumpleaños, sin importar el grado de parentesco.',
                'Search for a person by name'                  => 'Buscar una persona por su nombre',
                'Follow'                                       => 'Seguir',
                'Following'                                    => 'Siguiendo',
                'Remove'                                       => 'Quitar',
                'You are not following anyone yet.'            => 'Todavía no sigues a nadie.',
                'No matches found.'                            => 'No se encontraron resultados.',
                'Include birthdays of deceased people'        => 'Incluir cumpleaños de personas fallecidas',
                'Including birthdays of deceased people'       => 'Se incluyen los cumpleaños de personas fallecidas',
                'Deceased'                                     => 'Fallecido/a',
                'today'                                        => 'hoy',
                'tomorrow'                                     => 'mañana',
                'in %s day'                                    => 'en %s día',
                'in %s days'                                   => 'en %s días',
                '%s year old'                                  => '%s año',
                '%s years old'                                 => '%s años',
                'Upcoming birthdays'                           => 'Próximos cumpleaños',
                'Birthdays in the next month, based on your settings above.' => 'Cumpleaños del próximo mes, según tu configuración de arriba.',
                'No upcoming birthdays in the next month.'     => 'No hay cumpleaños el próximo mes.',
                'Days ahead'                                   => 'Días de antelación',
                'Save'                                         => 'Guardar',
                'Subscribe'                                    => 'Suscribirse',
                'Unsubscribe'                                  => 'Cancelar suscripción',
                'You are subscribed to birthday reminders.'    => 'Estás suscrito a los recordatorios de cumpleaños.',
                'You are not currently subscribed to birthday reminders.' => 'Actualmente no estás suscrito a los recordatorios de cumpleaños.',
                'Your account is not linked to a person in this family tree, so birthday reminders cannot be set up. Please ask an administrator to link your account.' => 'Tu cuenta no está vinculada a ninguna persona de este árbol genealógico, por lo que no se pueden configurar los recordatorios de cumpleaños. Pide a un administrador que vincule tu cuenta.',
                'Children, parents and siblings'               => 'Hijos, padres y hermanos',
                'Grandparents/children, aunts/uncles'          => 'Abuelos/nietos, tíos/tías',
                'First cousins (incl. spouses)'                => 'Primos hermanos (cónyuges incl.)',
                'First cousins and their children'             => 'Primos hermanos y sus hijos',
                'Second cousins (incl. spouses)'               => 'Primos segundos (cónyuges incl.)',
                'Second cousins and their children'            => 'Primos segundos y sus hijos',
                'Third cousins (incl. spouses)'                => 'Primos terceros (cónyuges incl.)',
                'Third cousins and their children'             => 'Primos terceros y sus hijos',
                'Fourth cousins (incl. spouses)'               => 'Primos cuartos (cónyuges incl.)',
                'Fourth cousins and their children'            => 'Primos cuartos y sus hijos',
            ],
        ];

        return array_merge($catalogue[$primary] ?? [], $this->reminderAdminTranslations($primary));
    }

    /**
     * Set the active locale (translator + Carbon date locale) for the digest
     * about to be rendered. I18N::init() loads the webtrees catalogue + this
     * module's customTranslations(); Carbon::setLocale() localises the weekday/
     * month names in the day headers (I18N::init does NOT touch Carbon).
     */
    private function applyLocale(string $tag): void
    {
        $tag = $tag !== '' ? $tag : 'en-US';

        try {
            I18N::init($tag);
        } catch (Throwable $e) {
            I18N::init('en-US');
            $tag = 'en-US';
        }

        $primary = strtolower(substr($tag, 0, 2)) ?: 'en';

        try {
            \Carbon\Carbon::setLocale($primary);
        } catch (Throwable $e) {
            // Carbon will keep its previous locale; dates fall back gracefully.
        }
    }

    public function defaultMenuOrder(): int
    {
        return 9;
    }

    /**
     * Only show the menu to signed-in users — reminders are personal.
     */
    public function getMenu(Tree $tree): ?Menu
    {
        if (!Auth::check()) {
            return null;
        }

        return new Menu(
            $this->title(),
            route(static::class, ['tree' => $tree->name()]),
            'menu-reminders',
            ['rel' => 'nofollow']
        );
    }

    // ---------------------------------------------------------------------
    // Individual-page sidebar (ModuleSidebarInterface)
    //
    // Surfaces the two things you otherwise had to go to the settings page for,
    // right on the person's own page: (1) how this person is related to YOU,
    // and (2) a one-click "remind me of this birthday" follow toggle. Sidebars
    // render inline (no AJAX round-trip), and webtrees wraps every module's
    // boot()/render in try/catch — but we ALSO guard getSidebarContent so a
    // relationship-BFS or DB hiccup can never blank out the individual page.
    // ---------------------------------------------------------------------

    /** Place below the Family navigator (order 2) but above nothing in particular. */
    public function defaultSidebarOrder(): int
    {
        return 99;
    }

    public function sidebarTitle(Individual $individual): string
    {
        return $this->title();
    }

    /**
     * Only signed-in members whose account is linked to a person in the tree
     * see the sidebar — both the relationship label and the follow action are
     * meaningless ("related to whom?") without a linked self.
     */
    public function hasSidebarContent(Individual $individual): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if ($this->sidebarMode() === 'hidden') {
            return false;
        }        

        return $individual->tree()->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF) !== '';
    }

    public function getSidebarContent(Individual $individual): string
    {
        try {
            $tree = $individual->tree();
            $user = Auth::user();
            $self = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

            if ($self === '') {
                return '';
            }

            $is_self = $self === $individual->xref();

            // Relationship of the viewed person TO the logged-in user. Resolved
            // via common ancestors first (the meaningful lineage, e.g. "maternal
            // great ×24 grandfather"), falling back to any-relationship; closest
            // path only. See ReminderRelationships::resolveForCard.
            $relationship     = '';
            $relationship_url = '';

            if (!$is_self) {
                $subject = Registry::individualFactory()->make($self, $tree);

                if ($subject !== null) {
                    $resolved = (new ReminderRelationships(
                        Registry::container()->get(RelationshipService::class)
                    ))->resolveForCard($subject, $individual);

                    if ($resolved['label'] !== '' && $resolved['label'] !== 'You') {
                        $relationship = $resolved['label'];

                        // Link to the full "Relationships" chart, reproducing how
                        // the label was found: via-ancestors if that's where it
                        // came from, else any-relationship — always recursion 0
                        // (closest); higher recursion on this tree is far too slow.
                        try {
                            $relationship_url = route(RelationshipsChartModule::class, [
                                'tree'      => $tree->name(),
                                'xref'      => $self,
                                'xref2'     => $individual->xref(),
                                'ancestors' => $resolved['ancestors'] === true ? 1 : 0,
                                'recursion' => 0,
                            ]);
                        } catch (Throwable $e) {
                            $relationship_url = '';
                        }
                    }
                }
            }

            $is_following = false;

            try {
                $is_following = in_array($individual->xref(), (new ReminderFollows())->targets($self), true);
            } catch (Throwable $e) {
                $is_following = false;
            }

            // Short biography, if this person is in the "Famous family members"
            // gallery — shown in the same card so a celebrity's profile carries
            // their story (German or English, matching the active language).
            $bio  = '';
            $wiki = '';
            $fam  = $this->famousEntry($individual->xref());

            if ($fam !== null) {
                $german = str_starts_with(strtolower(I18N::languageTag()), 'de');
                $bio    = $german ? (string) ($fam['bio_de'] ?: $fam['bio_en']) : (string) ($fam['bio_en'] ?: $fam['bio_de']);
                $wiki   = $german ? (string) ($fam['wiki_de'] ?: $fam['wiki_en']) : (string) ($fam['wiki_en'] ?: $fam['wiki_de']);
            }

            return $this->renderReminderSidebar([
                'individual'       => $individual,
                'is_self'          => $is_self,
                'is_following'     => $is_following,
                'relationship'     => $relationship,
                'relationship_url' => $relationship_url,
                'birthday'         => $is_self ? '' : $this->birthdayLabel($individual),
                'bio'              => $bio,
                'wiki'             => $wiki,
                'action_url'       => route(static::class, ['tree' => $tree->name()]),
                'manage_url'       => route(static::class, ['tree' => $tree->name()]),
                'csrf'             => csrf_token(),
            ]);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Look up the short biography for a person in the "Famous family members"
     * gallery (wt_famous_people). Returns null when they are not a gallery
     * member. Raw mysqli — that table has a literal, un-prefixed name (it is
     * owned by the famous-people module), and reads webtrees' own DB config.
     *
     * @return array{bio_en:string,bio_de:string,wiki_en:string,wiki_de:string}|null
     */
    private function famousEntry(string $xref): ?array
    {
        try {
            $c    = parse_ini_file(dirname(__DIR__, 2) . '/data/config.ini.php');
            $db   = new \mysqli($c['dbhost'], $c['dbuser'], $c['dbpass'], $c['dbname'], (int) ($c['dbport'] ?? 3306));
            $db->set_charset('utf8mb4');
            $stmt = $db->prepare('SELECT bio_en,bio_de,wiki_en,wiki_de FROM wt_famous_people WHERE xref = ? LIMIT 1');
            $stmt->bind_param('s', $xref);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $db->close();

            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Single entry point. Dispatch on path + method so we never depend on the
     * router's internal route-name API.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The matched route arrives either in the "route" query param
        // (index.php?route=/...) or in the URI path (pretty URLs). Check both.
        $route = (string) ($request->getQueryParams()['route'] ?? $request->getUri()->getPath());

        if (str_contains($route, 'reminders-cron')) {
            return $this->handleCron($request);
        }

        if ($request->getMethod() === RequestMethodInterface::METHOD_POST) {
            return $this->handleSave($request);
        }

        // GET ?search=1&q=… is the follow-people autocomplete (returns JSON).
        if (isset($request->getQueryParams()['search'])) {
            return $this->handleSearch($request);
        }

        return $this->handleEdit($request);
    }

    /**
     * Show the current user's subscription settings.
     */
    private function handleEdit(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        if (!Auth::check()) {
            // Redirect to LoginPage preserving the original request URI so the
            // user lands here after signing in. (Previously used the string
            // 'login' alias + hardcoded route, which worked but loses any
            // additional query params on the original URL. Same fix pattern as
            // ForumModule + webtrees core AuthLoggedIn middleware.)
            return redirect(route(LoginPage::class, [
                'tree' => $tree->name(),
                'url'  => (string) $request->getUri(),
            ]));
        }

        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($xref === '') {
            return $this->viewResponse($this->name() . '::edit', [
                'title'        => $this->title(),
                'tree'         => $tree,
                'module'       => $this,
                'linked'       => false,
                'subscription' => null,
            ]);
        }

        $subscriptions = new ReminderSubscriptions();
        $subscription  = $subscriptions->find($xref);

        return $this->viewResponse($this->name() . '::edit', [
            'title'        => $this->title(),
            'tree'         => $tree,
            'module'       => $this,
            'linked'       => true,
            'xref'         => $xref,
            'subscription' => $subscription,
            'following'    => $this->followingList($tree, $xref),
            'search_url'   => route(static::class, ['tree' => $tree->name(), 'search' => '1']),
            'upcoming'     => $this->upcomingList($tree, $xref, $subscription),
            'horizon_days' => self::PREVIEW_HORIZON_DAYS,
        ]);
    }

    /** How far the settings-page "upcoming birthdays" preview looks ahead. */
    private const PREVIEW_HORIZON_DAYS = 31;

    /**
     * Build the settings-page "upcoming birthdays" preview: the same people the
     * daily digest would mention (distance filter + followed + deceased rule),
     * but over the next month. Uses the subscriber's saved settings; falls back
     * to sensible defaults when they have not subscribed yet. Never throws — a
     * preview failure must not break the settings page.
     *
     * @param array<string,mixed>|null $subscription
     *
     * @return array<int,array<string,mixed>>
     */
    private function upcomingList(Tree $tree, string $xref, ?array $subscription): array
    {
        try {
            $calendar     = Registry::container()->get(CalendarService::class);
            $relationship = Registry::container()->get(RelationshipService::class);
            $service      = new ReminderService($calendar, $relationship, $this->name());

            $max_distance     = (int) ($subscription['max_distance'] ?? 0);
            $include_deceased = (bool) ($subscription['include_deceased'] ?? false);

            return $service->upcomingBirthdays($tree, $xref, $max_distance, $include_deceased, self::PREVIEW_HORIZON_DAYS);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve a subscriber's followed XREFs into display rows for the settings
     * page (and AJAX follow/unfollow responses). Unresolvable XREFs (deleted
     * individuals) are silently dropped.
     *
     * @return array<int,array{xref:string,name:string,lifespan:string,url:string}>
     */
    private function followingList(Tree $tree, string $xref): array
    {
        $follows = new ReminderFollows();
        $rows    = [];

        foreach ($follows->targets($xref) as $target) {
            $individual = Registry::individualFactory()->make($target, $tree);

            if ($individual === null || !$individual->canShow()) {
                continue;
            }

            $rows[] = [
                'xref'       => $target,
                'name'       => strip_tags($individual->fullName()),
                'lifespan'   => strip_tags($individual->lifespan()),
                'birthday'   => $this->birthdayLabel($individual),
                'days_until' => $this->daysUntilBirthday($individual),
                'url'        => $individual->url(),
            ];
        }

        // Soonest birthday first (by days until the next one). People with an
        // unknown/imprecise birth date (days_until = null) sort to the end;
        // ties (same day) and unknowns break alphabetically.
        usort($rows, static function (array $a, array $b): int {
            $da = $a['days_until'];
            $db = $b['days_until'];

            if ($da === null && $db === null) {
                return I18N::comparator()($a['name'], $b['name']);
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return ($da <=> $db) ?: I18N::comparator()($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Whole days until an individual's NEXT birthday (0 = today), or null if the
     * birth day/month is unknown/imprecise. Used to order the "people you follow"
     * list soonest-first; mirrors the date math in birthdayLabel().
     */
    private function daysUntilBirthday(Individual $individual): ?int
    {
        $birth = $individual->getBirthDate();

        if (!$birth->isOK()) {
            return null;
        }

        $greg  = $birth->minimumDate();
        $day   = (int) $greg->day;
        $month = (int) $greg->month;

        if ($day < 1 || $month < 1 || $month > 12) {
            return null;
        }

        $tz    = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $year  = (int) $today->format('Y');

        $next = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $tz);

        if ($next === false) {
            return null;
        }

        if ($next < $today) {
            $next = \DateTimeImmutable::createFromFormat('!Y-n-j', ($year + 1) . '-' . $month . '-' . $day, $tz);

            if ($next === false) {
                return null;
            }
        }

        return (int) $today->diff($next)->days;
    }

    /**
     * "🎂 28 Jun (in 11 days)" — the next occurrence of an individual's birthday
     * and how far off it is. Returns '' if the birth day/month is unknown.
     * Day count is calendar-based (UTC, matching webtrees' internal clock); the
     * "D MMM" label is localised via the same Timestamp machinery the digest uses.
     */
    private function birthdayLabel(Individual $individual): string
    {
        $birth = $individual->getBirthDate();

        if (!$birth->isOK()) {
            return '';
        }

        $greg  = $birth->minimumDate();
        $day   = (int) $greg->day;
        $month = (int) $greg->month;

        if ($day < 1 || $month < 1 || $month > 12) {
            return ''; // imprecise date (e.g. "1983" or "JUN 1983") — no countdown
        }

        $tz    = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $year  = (int) $today->format('Y');

        $next = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $tz);

        if ($next === false) {
            return '';
        }

        if ($next < $today) {
            $next = \DateTimeImmutable::createFromFormat('!Y-n-j', ($year + 1) . '-' . $month . '-' . $day, $tz);

            if ($next === false) {
                return '';
            }
        }

        $days = (int) $today->diff($next)->days;
        $when = Registry::timestampFactory()->now()->addDays($days)->isoFormat('D MMM');

        if ($days === 0) {
            $rel = I18N::translate('today');
        } elseif ($days === 1) {
            $rel = I18N::translate('tomorrow');
        } else {
            // Single-string translate (not I18N::plural): a module's flat
            // customTranslations() map resolves for I18N::translate but NOT for
            // I18N::plural, so plurals would fall back to English. $days >= 2 here.
            $rel = I18N::translate('in %s days', I18N::number($days));
        }

        // Age they'll turn on that birthday (would-be age for the deceased).
        // Plural pattern keeps it grammatical per language ("43 years old" /
        // "43 ans" / "43 anni" / "43 años" — FR/IT/ES drop the word "old").
        $birth_year = (int) $greg->year;
        $age        = (int) $next->format('Y') - $birth_year;

        if ($birth_year > 0 && $age >= 0) {
            // Single-string translate, not I18N::plural (see note above).
            $age_phrase = $age === 1
                ? I18N::translate('%s year old', I18N::number($age))
                : I18N::translate('%s years old', I18N::number($age));

            return '🎂 ' . $when . ' ' . $birth_year . ' (' . $age_phrase . ' ' . $rel . ')';
        }

        return '🎂 ' . $when . ' (' . $rel . ')';
    }

    /**
     * JSON autocomplete for the "follow a person" search box. Auth-gated: the
     * tree is private, so anonymous callers get an empty list. Marks people the
     * subscriber already follows so the UI can show "Following" instead of "Add".
     */
    private function handleSearch(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return response(['results' => []], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();
        $self = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        $q = trim(Validator::queryParams($request)->string('q', ''));

        if (mb_strlen($q) < 2) {
            return response(['results' => []]);
        }

        // Word-wise search across ALL of a person's name rows (birth name AND
        // married names). Each query word must appear in at least one of the
        // individual's name rows — so "johanna wolff" finds Johanna Freiin von
        // Weichs zur Wenne (married name Wolff), where the given name and the
        // married surname live in different `name` rows. We GROUP BY the xref and
        // require, per word, SUM(n_full LIKE ?) > 0 across that person's rows.
        // (n_full uses an accent-insensitive collation, so ASCII tokens match
        // accented names.)
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);

        $query = DB::table('name')
            ->where('n_file', '=', $tree->id())
            ->groupBy('n_id')
            ->select('n_id')
            ->orderByRaw('MIN(n_sort)')
            ->limit(15);

        foreach ($words as $word) {
            $like = '%' . addcslashes($word, '\\%_') . '%';
            $query->havingRaw('SUM(n_full LIKE ?) > 0', [$like]);
        }

        $individuals = [];

        foreach ($query->pluck('n_id')->all() as $xref_match) {
            $individual = Registry::individualFactory()->make((string) $xref_match, $tree);

            if ($individual !== null) {
                $individuals[] = $individual;
            }
        }

        $already = [];
        try {
            $already = array_flip((new ReminderFollows())->targets($self));
        } catch (Throwable $e) {
            $already = [];
        }

        $results = [];

        foreach ($individuals as $individual) {
            $x = $individual->xref();

            if ($x === $self || !$individual->canShow()) {
                continue; // can't follow your own birthday; respect privacy
            }

            $results[] = [
                'xref'      => $x,
                'name'      => strip_tags($individual->fullName()),
                'lifespan'  => strip_tags($individual->lifespan()),
                'birthday'  => $this->birthdayLabel($individual),
                'following' => isset($already[$x]),
            ];
        }

        return response(['results' => $results]);
    }

    /**
     * Save subscription changes for the current user.
     */
    private function handleSave(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        $back = redirect(route(static::class, ['tree' => $tree->name()]));

        if (!Auth::check()) {
            return $back;
        }

        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($xref === '') {
            return $back;
        }

        $params = (array) $request->getParsedBody();
        $action = $params['action'] ?? '';

        // --- Follow / unfollow an individual birthday (AJAX from the settings
        // page). Independent of the subscribe form, so handle it first and never
        // touch the days_ahead/max_distance prefs. ----------------------------
        if ($action === 'follow' || $action === 'unfollow') {
            return $this->handleFollow($request, $tree, $xref, $action, $params);
        }

        $days_ahead       = (int) ($params['days_ahead'] ?? 1);
        $max_distance     = (int) ($params['max_distance'] ?? 0);
        $include_deceased = !empty($params['include_deceased']);

        // Clamp to sane ranges (max one week ahead).
        $days_ahead   = max(1, min(7, $days_ahead));
        $max_distance = max(0, min(100, $max_distance));

        $subscriptions = new ReminderSubscriptions();

        if ($action === 'unsubscribe') {
            $subscriptions->unsubscribe($xref);
        } else {
            $subscriptions->subscribe($xref);
            $subscriptions->updatePrefs($xref, $days_ahead, $max_distance, $include_deceased);
        }

        return $back;
    }

    /**
     * Add or remove a followed birthday. Called from handleSave for the
     * follow/unfollow actions. The JS sends ajax=1 and expects JSON containing
     * the refreshed "following" list; a plain (no-JS) submit redirects back.
     *
     * @param array<string,mixed> $params
     */
    private function handleFollow(
        ServerRequestInterface $request,
        Tree $tree,
        string $xref,
        string $action,
        array $params
    ): ResponseInterface {
        $target = trim((string) ($params['target'] ?? ''));
        $ajax   = (string) ($params['ajax'] ?? '') === '1';

        // XREFs are alphanumeric (I123, X45, …). Reject anything else, and never
        // let someone follow their own birthday.
        if ($target !== '' && $target !== $xref && preg_match('/^[A-Za-z0-9]+$/', $target) === 1) {
            $follows = new ReminderFollows();

            if ($action === 'follow') {
                // Only follow real, visible individuals.
                $individual = Registry::individualFactory()->make($target, $tree);

                if ($individual !== null && $individual->canShow()) {
                    $follows->follow($xref, $target);

                    // A followed birthday only ever reaches someone who has a
                    // subscription row (the cron iterates subscribers). Someone
                    // following straight from an individual page may never have
                    // visited the settings form — so auto-subscribe them with
                    // sane defaults, but NEVER clobber an existing subscriber's
                    // saved preferences (distance / days-ahead / deceased).
                    try {
                        $subs = new ReminderSubscriptions();

                        if ($subs->find($xref) === null) {
                            $subs->subscribe($xref);
                            $subs->updatePrefs($xref, 1, 0, false);
                        }
                    } catch (Throwable $e) {
                        // Best-effort: the follow is recorded regardless.
                    }
                }
            } else {
                $follows->unfollow($xref, $target);
            }
        }

        if ($ajax) {
            return response([
                'ok'        => true,
                'following' => $this->followingList($tree, $xref),
            ]);
        }

        return redirect(route(static::class, ['tree' => $tree->name()]));
    }

    /**
     * Cron entry point: build and (optionally) send digests for all subscribers.
     *
     * Guarded by a token preference. Without ?send=1 it only renders previews
     * (no mail). An optional "allow_recipients" preference (CSV of emails)
     * restricts who actually receives mail — used for the first live run.
     */
    private function handleCron(ServerRequestInterface $request): ResponseInterface
    {
        $configured_token = (string) $this->getPreference('cron_token', '');
        $supplied_token   = Validator::queryParams($request)->string('token', '');

        if ($configured_token === '' || !hash_equals($configured_token, $supplied_token)) {
            return response('Forbidden', StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $user_service = Registry::container()->get(UserService::class);

        // The cron runs unauthenticated; the family tree is private, so without
        // elevation webtrees privacy hides all events and relationships. Run as
        // a configured user (a tree member/admin) so the engine can read data.
        $run_as = (int) $this->getPreference('run_as_user_id', '0');

        if ($run_as > 0) {
            $run_as_user = $user_service->find($run_as);

            if ($run_as_user !== null) {
                Auth::login($run_as_user);
            }
        }

        $really_send = Validator::queryParams($request)->integer('send', 0) === 1;

        $allow_raw = (string) $this->getPreference('allow_recipients', '');
        $allow     = array_filter(array_map('trim', explode(',', $allow_raw)));

        $tree_name = (string) $this->getPreference('tree', self::DEFAULT_TREE);
        $tree      = $this->resolveTree($tree_name);

        if ($tree === null) {
            return response('Tree not found: ' . $tree_name, StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }

        $calendar     = Registry::container()->get(CalendarService::class);
        $relationship = Registry::container()->get(RelationshipService::class);
        $email        = Registry::container()->get(EmailService::class);

        $service       = new ReminderService($calendar, $relationship, $this->name());
        $subscriptions = new ReminderSubscriptions();
        $from          = new ReminderSender(
            (string) $this->getPreference('email_from', ''),
            (string) $this->getPreference('email_from_name', '')
        );

        // --- Test affordance (token-gated, read-only, sends NO mail) ---------
        // Render ONE subscriber's digest as HTML in a chosen language so all
        // five languages can be eyeballed safely:
        //   ?route=/reminders-cron&token=…&preview_xref=I45&lang=de   (NO &send=1)
        $preview_xref = Validator::queryParams($request)->string('preview_xref', '');

        if ($preview_xref !== '') {
            $sub      = $subscriptions->find($preview_xref);
            $p_ahead  = (int) ($sub['days_ahead'] ?? 1);
            $p_dist   = (int) ($sub['max_distance'] ?? 0);
            $p_dec    = (bool) ($sub['include_deceased'] ?? 0);
            $this->applyLocale(Validator::queryParams($request)->string('lang', ''));
            $html = $service->digestHtml($tree, $preview_xref, $p_ahead, $p_dist, $p_dec);

            return response($html !== '' ? $html : '(no events in range for ' . $preview_xref . ')', StatusCodeInterface::STATUS_OK)
                ->withHeader('content-type', 'text/html; charset=UTF-8');
        }

        // Fallback language for subscribers who never chose one in webtrees.
        $default_lang = (string) Site::getPreference('LANGUAGE');

        $log   = [];
        $log[] = $really_send ? '=== SENDING ===' : '=== DRY RUN (no mail) ===';
        $log[] = 'Allow-list: ' . ($allow === [] ? '(all)' : implode(', ', $allow));
        $log[] = '';

        foreach ($subscriptions->all() as $row) {
            $xref  = (string) ($row['gedid'] ?? '');
            $to    = (string) ($row['email'] ?? '');
            $name  = (string) ($row['namekomplett'] ?? '');
            $uid   = (int) ($row['user_id'] ?? 0);
            $ahead = (int) ($row['days_ahead'] ?? 1);
            $dist  = (int) ($row['max_distance'] ?? 0);
            $dec   = (bool) ($row['include_deceased'] ?? 0);

            try {
                // Localise the digest to the subscriber's own webtrees language
                // (falls back to the site default, then English).
                $recipient = $user_service->find($uid);
                $lang      = $recipient !== null ? $recipient->getPreference('language', '') : '';
                $lang_used = $lang !== '' ? $lang : ($default_lang !== '' ? $default_lang : 'en-US');
                $this->applyLocale($lang_used);

                $html = $service->digestHtml($tree, $xref, $ahead, $dist, $dec);

                if ($html === '') {
                    $log[] = "skip  {$xref} <{$to}> — no events in range";
                    continue;
                }

                if (!$really_send) {
                    $log[] = "PREVIEW {$xref} <{$to}> [{$lang_used}] — digest built ok";
                    continue;
                }

                if ($allow !== [] && !in_array($to, $allow, true)) {
                    $log[] = "hold  {$xref} <{$to}> — not on allow-list";
                    continue;
                }

                if ($recipient === null) {
                    $log[] = "ERR   {$xref} <{$to}> — no webtrees user id={$uid}";
                    continue;
                }

                $brand   = trim((string) $this->getPreference('email_subject_prefix', ''));
                $subject = $brand !== '' ? $brand . ' ' . I18N::translate('Birthdays') : I18N::translate('Birthdays');
                $text    = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
                $ok      = $email->send($from, $recipient, $from, $subject, $text, $html);

                $log[] = ($ok ? 'SENT  ' : 'FAIL  ') . "{$xref} <{$to}> ({$name})";
            } catch (Throwable $e) {
                $log[] = "EXC   {$xref} <{$to}> — " . $e->getMessage();
            }
        }

        return response(implode("\n", $log), StatusCodeInterface::STATUS_OK)
            ->withHeader('content-type', 'text/plain; charset=UTF-8');
    }

    /**
     * Resolve a tree by name WITHOUT the visibility filter in TreeService::all()
     * — the cron runs as a guest and the family tree is private, so all() would
     * hide it. We read the gedcom row directly and build the Tree.
     */
    private function resolveTree(string $name): ?Tree
    {
        // Called after elevation (run-as admin), so TreeService::all() returns
        // the private tree too. Using the service avoids depending on the Tree
        // constructor signature (which changed in webtrees 2.2.6).
        $trees = Registry::container()->get(\Fisharebest\Webtrees\Services\TreeService::class)->all();

        // No tree configured ⇒ use the first/only tree (the common single-tree case).
        if ($name === '') {
            return $trees->first();
        }

        return $trees->first(static fn (Tree $t): bool => $t->name() === $name);
    }
}
