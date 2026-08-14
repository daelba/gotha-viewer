<?php
declare(strict_types=1);

/**
 * Jednoduchá lokalizace cs/de/en.
 * Jazyk se řeší query parametrem ?lang= a ukládá do session.
 */
final class I18n
{
    public const DEFAULT_LANG = 'cs';
    public const LANGS = ['cs', 'de', 'en'];

    /** @var array<string,array<string,string>> */
    private const DICT = [
        'cs' => [
            'app.title'        => 'Gotha viewer',
            'app.subtitle'     => 'Šlechtické rody z gotha.wikibase.cloud',
            'nav.families'     => 'Rody',
            'action.refresh'   => 'Aktualizovat data',
            'action.refreshed' => 'Cache byla vymazána (%d souborů). Data se načtou znovu.',
            'table.name'       => 'Rod',
            'table.name_de'    => 'Název (de)',
            'table.members'    => 'Osob',
            'table.wikidata'   => 'Wikidata',
            'filter.placeholder' => 'Filtrovat rody…',
            'families.empty'   => 'Nebyly nalezeny žádné rody.',
            'family.no_members'=> 'Tento rod zatím nemá v databázi žádné zaznamenané osoby.',
            'family.search'    => 'Hledat osobu ve stromu…',
            'family.other_family'      => 'Přechod na rod %s',
            'family.other_family_icon' => 'Ikona jiného rodu',
            'family.dup_link'          => 'Přejít na druhý výskyt osoby',
            'family.dup_link_icon'     => 'Ikona přepnutí na druhý box',
            'family.zoom_in'           => 'Přiblížit',
            'family.zoom_out'          => 'Oddálit',
            'family.zoom_reset'        => 'Obnovit 100 %',

            'family.back'      => 'Zpět na seznam rodů',
            'person.detail'    => 'Detail osoby',
            'person.loading'   => 'Načítám…',
            'person.close'     => 'Zavřít',
            'person.born'      => 'nar.',
            'person.died'      => 'zemř.',
            'person.wikidata'  => 'Profil na Wikidatech',
            'error.endpoint'   => 'Nepodařilo se načíst data z gotha-api. Zkuste to prosím později.',
            'error.404'        => 'Stránka nebyla nalezena.',
            'lang.cs' => 'Čeština',
            'lang.de' => 'Deutsch',
            'lang.en' => 'English',
        ],
        'de' => [
            'app.title'        => 'Gotha viewer',
            'app.subtitle'     => 'Adelsgeschlechter aus gotha.wikibase.cloud',
            'nav.families'     => 'Geschlechter',
            'action.refresh'   => 'Daten aktualisieren',
            'action.refreshed' => 'Cache geleert (%d Dateien). Daten werden neu geladen.',
            'table.name'       => 'Geschlecht',
            'table.name_de'    => 'Name (de)',
            'table.members'    => 'Personen',
            'table.wikidata'   => 'Wikidata',
            'filter.placeholder' => 'Geschlechter filtern…',
            'families.empty'   => 'Keine Geschlechter gefunden.',
            'family.no_members'=> 'Für dieses Geschlecht sind noch keine Personen erfasst.',
            'family.search'    => 'Person im Stammbaum suchen…',
            'family.other_family'      => 'Wechsel zum Geschlecht %s',
            'family.other_family_icon' => 'Symbol für anderes Geschlecht',
            'family.dup_link'          => 'Zur anderen Position der Person wechseln',
            'family.dup_link_icon'     => 'Symbol für Wechsel zum zweiten Feld',
            'family.zoom_in'           => 'Vergrößern',
            'family.zoom_out'          => 'Verkleinern',
            'family.zoom_reset'        => 'Auf 100 % zurücksetzen',


            'family.back'      => 'Zurück zur Übersicht',
            'person.detail'    => 'Personendetail',
            'person.loading'   => 'Laden…',
            'person.close'     => 'Schließen',
            'person.born'      => 'geb.',
            'person.died'      => 'gest.',
            'person.wikidata'  => 'Wikidata-Profil',
            'error.endpoint'   => 'Daten von gotha-api konnten nicht geladen werden.',
            'error.404'        => 'Seite nicht gefunden.',
            'lang.cs' => 'Čeština',
            'lang.de' => 'Deutsch',
            'lang.en' => 'English',
        ],
        'en' => [
            'app.title'        => 'Gotha viewer',
            'app.subtitle'     => 'Noble families from gotha.wikibase.cloud',
            'nav.families'     => 'Families',
            'action.refresh'   => 'Refresh data',
            'action.refreshed' => 'Cache cleared (%d files). Data will be reloaded.',
            'table.name'       => 'Family',
            'table.name_de'    => 'Name (de)',
            'table.members'    => 'Persons',
            'table.wikidata'   => 'Wikidata',
            'filter.placeholder' => 'Filter families…',
            'families.empty'   => 'No families found.',
            'family.no_members'=> 'No persons recorded for this family yet.',
            'family.search'    => 'Search person in tree…',
            'family.other_family'      => 'Go to family %s',
            'family.other_family_icon' => 'Other family icon',
            'family.dup_link'          => 'Jump to the other instance of this person',
            'family.dup_link_icon'     => 'Jump-to-other-instance icon',
            'family.zoom_in'           => 'Zoom in',
            'family.zoom_out'          => 'Zoom out',
            'family.zoom_reset'        => 'Reset to 100 %',


            'family.back'      => 'Back to families',
            'person.detail'    => 'Person detail',
            'person.loading'   => 'Loading…',
            'person.close'     => 'Close',
            'person.born'      => 'b.',
            'person.died'      => 'd.',
            'person.wikidata'  => 'Wikidata profile',
            'error.endpoint'   => 'Failed to load data from gotha-api. Please try again later.',
            'error.404'        => 'Page not found.',
            'lang.cs' => 'Čeština',
            'lang.de' => 'Deutsch',
            'lang.en' => 'English',
        ],
    ];

    private static string $lang = self::DEFAULT_LANG;

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $req = $_GET['lang'] ?? null;
        if (is_string($req) && in_array($req, self::LANGS, true)) {
            $_SESSION['lang'] = $req;
        }
        $sess = $_SESSION['lang'] ?? null;
        self::$lang = is_string($sess) && in_array($sess, self::LANGS, true)
            ? $sess
            : self::DEFAULT_LANG;
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key): string
    {
        return self::DICT[self::$lang][$key]
            ?? self::DICT[self::DEFAULT_LANG][$key]
            ?? $key;
    }

    /**
     * Vybere label podle aktuálního jazyka s postupným fallbackem.
     * @param array{cs:?string,de:?string,en:?string} $labels
     */
    public static function pick(array $labels): string
    {
        $order = [
            'cs' => ['cs', 'de', 'en'],
            'de' => ['de', 'cs', 'en'],
            'en' => ['en', 'de', 'cs'],
        ][self::$lang];

        foreach ($order as $l) {
            $v = $labels[$l] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return '';
    }
}
