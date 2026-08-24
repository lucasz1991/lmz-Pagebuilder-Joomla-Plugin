<?php

/**
 * @package     LMZ.Plugin
 * @subpackage  System.lmzsuitemenu
 *
 * @copyright   (C) 2026 LMZ Media. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace LMZ\Plugin\System\Lmzsuitemenu\Extension;

use Joomla\CMS\Menu\AdministratorMenuItem;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Hebt die LMZ J-Suite aus "Komponenten" in die oberste Menüleiste.
 *
 * WARUM ÜBERHAUPT EIN PLUGIN
 * --------------------------
 * Die oberste Leiste des Backends kommt NICHT aus der Datenbank, sondern aus
 * einem Preset (administrator/components/com_menus/presets/default.xml). Ein
 * Platz zwischen "Menüs" und "Komponenten" ist über Menüeinträge also gar
 * nicht erreichbar - dort landet alles zwangsläufig im Sammelpunkt
 * "Komponenten".
 *
 * Der naheliegende Weg wäre ein EIGENES Preset. Der ist hier bewusst nicht
 * gewählt: ein Preset ersetzt die Leiste vollständig, es kann das
 * Joomla-Preset nicht erweitern. Die Seite würde damit jede künftige
 * Änderung am Kernmenü verpassen, ohne dass es jemand merkt. Dieses Plugin
 * greift stattdessen an genau einer Stelle ein - es fügt einen Knoten hinzu
 * und blendet den alten aus. Alles andere bleibt Joomla. Schaltet man das
 * Plugin ab, steht die Suite wieder unter "Komponenten"; nichts geht
 * verloren.
 *
 * WIE DER EINGRIFF FUNKTIONIERT
 * -----------------------------
 * mod_menu löst onPreprocessMenuItems für JEDE Ebene des Baums aus. Für einen
 * Wurzelknoten sind zwei Schritte nötig, und beide zählen:
 *
 *   1. $wurzel->addChild($knoten)  - nur das landet im Baum und wird gezeichnet
 *   2. $event->updateItems($items) - nur das schickt den Knoten durch die
 *      Aufbereitung (Rechte, Sprache, $item->text)
 *
 * Fehlt der erste Schritt, entsteht ein Knoten, der verarbeitet, aber nie
 * gezeichnet wird; fehlt der zweite, erscheint er ohne Beschriftung.
 */
final class Lmzsuitemenu extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    /* CMSPlugin selbst kennt weder setDatabase() noch getDatabase() - beides
       kommt aus diesem Trait. Ohne ihn nimmt der Dienstanbieter zwar die
       Datenbank entgegen, der Aufruf endet aber in einem Fatal Error. */
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * Der Menüeintrag der Suite unter "Komponenten". Wird einmal je Aufruf
     * aus der Datenbank aufgelöst - eine feste Nummer wäre nur auf DIESER
     * Installation richtig.
     */
    private ?int $alteWurzel = null;

    private bool $aufgeloest = false;

    public static function getSubscribedEvents(): array
    {
        return ['onPreprocessMenuItems' => 'onPreprocessMenuItems'];
    }

    public function onPreprocessMenuItems($event): void
    {
        $app = $this->getApplication();

        if (!$app || !$app->isClient('administrator')) {
            return;
        }

        /* Denselben Ereignisnamen lösen auch mod_submenu und der
           Preset-Import aus. Nur die Menüleiste ist gemeint. */
        if ($event->getArgument('context') !== 'com_menus.administrator.module') {
            return;
        }

        /* KEINE "schon erledigt"-Sperre auf der Instanz: das Plugin lebt den
           ganzen Aufruf lang, ein zweites Menümodul baut aber einen FRISCHEN
           Baum. Eine solche Sperre würde diesem zweiten Baum die Suite still
           vorenthalten - und sie schützt vor nichts: das Ereignis feuert je
           Baum zwar rund vierzehnmal, aber nur ein einziges Mal auf der
           Wurzelebene, und genau die prüft der nächste Absatz. */

        /* Im Wiederherstellungsmodus baut mod_menu ein Notmenü auf, weil der
           reguläre Baum unbrauchbar ist. Da hilft ein zusätzlicher Punkt
           niemandem - dort bleibt alles, wie Joomla es vorsieht. */
        $modulParams = $event->getArgument('params');

        if ($modulParams !== null && $modulParams->get('recovery', false)) {
            return;
        }

        $items = $event->getArgument('subject', []);

        if ($items === []) {
            return;
        }

        $erstes = reset($items);
        $wurzel = $erstes instanceof AdministratorMenuItem ? $erstes->getParent() : null;

        /* Die Wurzel ist der einzige Knoten OHNE Elternknoten. Auf $item->level
           lässt sich das nicht prüfen: die Ebenen werden erst nach dem
           vollständigen Aufbau gesetzt. */
        if ($wurzel === null || $wurzel->getParent() !== null) {
            return;
        }

        /* Die Beschriftungen stammen aus den .sys.ini-Dateien der Komponenten.
           mod_menu lädt sie sonst erst beim jeweiligen Eintrag - und für
           Überschriften ohne Link gar nicht, weil es das Kürzel aus dem Link
           zieht. Deshalb hier einmal selbst laden, dann stimmt jede
           Beschriftung unabhängig von der Reihenfolge. */
        $sprache = $app->getLanguage();

        foreach (['com_lmzpagebuilder', 'com_lmzstudiomodules', 'com_lmzcontentapi'] as $kuerzel) {
            $sprache->load($kuerzel . '.sys', JPATH_ADMINISTRATOR)
                || $sprache->load($kuerzel . '.sys', JPATH_ADMINISTRATOR . '/components/' . $kuerzel);
        }

        $this->komponentenpunktAusblenden($items);

        $knoten = $this->sammelpunktBauen();
        $wurzel->addChild($knoten);
        $this->hinterMenuesEinsortieren($wurzel, $knoten);

        $items[] = $knoten;

        /* array_values ist Pflicht: nachfolgende Abonnenten greifen auf
           $items[0] zu (so etwa plg_system_lmzlogicalmenus). Eine Liste mit
           Lücken in den Schlüsseln wäre dort ein Fatal Error. */
        $event->updateItems(array_values($items));
    }

    /**
     * Nimmt die Suite aus dem Sammelpunkt "Komponenten".
     *
     * Der Container liest seine Kinder erst NACH diesem Ereignis aus der
     * Datenbank und lässt dabei alles weg, was in seinem Parameter
     * "hideitems" steht. Eine einzige Nummer genügt: der Ausschluss greift
     * auf id UND parent_id, nimmt also die Wurzel samt allen Kindern mit.
     *
     * @param  array<int, AdministratorMenuItem>  $items
     */
    private function komponentenpunktAusblenden(array $items): void
    {
        $alte = $this->alteWurzelId();

        if ($alte === null) {
            return;
        }

        foreach ($items as $item) {
            if (!$item instanceof AdministratorMenuItem || $item->type !== 'container') {
                continue;
            }

            $params = $item->getParams();
            $verstecke = (array) $params->get('hideitems', []);

            if (!\in_array($alte, array_map('intval', $verstecke), true)) {
                $verstecke[] = $alte;
                $params->set('hideitems', array_values($verstecke));
                $item->setParams($params);
            }
        }
    }

    /**
     * Setzt den neuen Knoten direkt hinter "Menüs".
     *
     * addChild() hängt immer hinten an - ohne diesen Schritt stünde die Suite
     * hinter "Hilfe". Umsortiert wird durch erneutes addChild() in der
     * Wunschreihenfolge; setParent() zieht den Knoten dabei zuerst aus der
     * Liste und hängt ihn neu an. Joomla selbst sortiert die Kinder des
     * Komponenten-Containers auf genau diesem Weg alphabetisch.
     */
    private function hinterMenuesEinsortieren($wurzel, AdministratorMenuItem $knoten): void
    {
        $kinder = array_values($wurzel->getChildren());
        $reihe = [];
        $gesetzt = false;

        foreach ($kinder as $kind) {
            if ($kind === $knoten) {
                continue;
            }

            $reihe[] = $kind;

            /* "Menüs" wird am Kennzeichen dashboard erkannt, nicht am Titel:
               der Titel ist ein Sprachschlüssel, den ein anderes Preset
               anders benennen darf. */
            if (!$gesetzt && $this->istMenuepunkt($kind)) {
                $reihe[] = $knoten;
                $gesetzt = true;
            }
        }

        if (!$gesetzt) {
            /* Kein "Menüs" gefunden (fremdes Preset, Wiederherstellungsmodus):
               dann bleibt der Knoten am Ende - sichtbar ist besser als weg. */
            return;
        }

        foreach ($reihe as $kind) {
            $wurzel->addChild($kind);
        }
    }

    private function istMenuepunkt(AdministratorMenuItem $kind): bool
    {
        if (($kind->dashboard ?? '') === 'menus') {
            return true;
        }

        return $kind->title === 'MOD_MENU_MENUS';
    }

    /**
     * Der Baum der Suite.
     *
     * Aufbau bewusst flach: was täglich benutzt wird, hängt unmittelbar unter
     * der Wurzel und ist einen Klick entfernt. Nur die drei seltenen,
     * technischen Punkte liegen unter "Verwaltung". Eine Gliederung nach
     * Bauteilen (Page Builder / Karten / API) wäre für den Redakteur ohne
     * Bedeutung - er sucht eine Aufgabe, kein Bauteil.
     *
     * Kopf- und Fußbereich bekommen KEINE eigenen Einträge: die Ansicht
     * "Vorlageneinstellungen" führt beide bereits in ihrer eigenen
     * Unternavigation. Eine globale Navigation, die eine vorhandene
     * Seitennavigation nachbaut, kostet Platz und bringt nichts - die
     * Aktivmarkierung bleibt trotzdem stehen, weil mod_menu Links über
     * Präfixe vergleicht.
     *
     * WER HIER ETWAS ERGÄNZT: neue Ansichten der Suite gehören in diese
     * Liste. Sie erscheinen NICHT mehr von selbst, weil der Sammelpunkt
     * "Komponenten" die Einträge der Suite ausblendet.
     */
    private function sammelpunktBauen(): AdministratorMenuItem
    {
        $wurzel = new AdministratorMenuItem([
            'id' => -918000,
            'title' => 'COM_LMZPAGEBUILDER',
            'alias' => 'lmz-j-suite',
            'type' => 'heading',
            'link' => '',
            /* Ohne das Präfix "class:" liest mod_menu den Wert als Bilddatei;
               MIT einem eigenen "icon-" davor entstünde "icon-icon-cube". */
            'class' => 'class:cube',
            'scope' => 'default',
            'params' => '{}',
        ]);

        $laufend = -918001;
        $anhaengen = static function (AdministratorMenuItem $eltern, array $daten) use (&$laufend): AdministratorMenuItem {
            $kind = new AdministratorMenuItem(array_merge([
                'id' => $laufend--,
                'type' => 'component',
                'link' => '',
                'scope' => 'default',
                'params' => '{}',
            ], $daten));
            $eltern->addChild($kind);

            return $kind;
        };

        $anhaengen($wurzel, [
            'title' => 'COM_LMZPAGEBUILDER_PROJECTS',
            'alias' => 'lmz-seiten',
            'link' => 'index.php?option=com_lmzpagebuilder&view=projects',
        ]);
        $anhaengen($wurzel, [
            'title' => 'COM_LMZSTUDIOMODULES_MAPS',
            'alias' => 'lmz-karten',
            'link' => 'index.php?option=com_lmzstudiomodules&view=maps',
        ]);
        $anhaengen($wurzel, [
            'title' => 'COM_LMZPAGEBUILDER_TEMPLATE_SETTINGS',
            'alias' => 'lmz-vorlage',
            'link' => 'index.php?option=com_lmzpagebuilder&view=templatesettings',
        ]);

        /* Trennt das tägliche Bearbeiten von dem, was man beobachtet oder
           bestätigt. mod_menu entfernt nur WIEDERHOLTE Trennlinien - eine am
           Ende bleibt stehen, sofern nicht schon davor eine steht. Hier kann
           das nur eintreten, wenn einem Benutzer alle drei folgenden Punkte
           gleichzeitig durch fehlende Rechte wegfallen; dann steht ein
           Strich zu viel, mehr nicht. */
        $anhaengen($wurzel, ['type' => 'separator', 'title' => '', 'alias' => 'lmz-trenner-1']);

        $anhaengen($wurzel, [
            'title' => 'COM_LMZPAGEBUILDER_ASSISTANT',
            'alias' => 'lmz-assistent',
            'link' => 'index.php?option=com_lmzpagebuilder&view=assistant',
        ]);
        $anhaengen($wurzel, [
            'title' => 'COM_LMZPAGEBUILDER_INSIGHTS',
            'alias' => 'lmz-analyse',
            'link' => 'index.php?option=com_lmzpagebuilder&view=insights',
        ]);

        $verwaltung = $anhaengen($wurzel, [
            'title' => 'PLG_SYSTEM_LMZSUITEMENU_GROUP_ADMIN',
            'alias' => 'lmz-verwaltung',
            'type' => 'heading',
            'link' => '',
        ]);

        $anhaengen($verwaltung, [
            'title' => 'COM_LMZPAGEBUILDER_TRANSFER_TITEL',
            'alias' => 'lmz-uebertragung',
            'link' => 'index.php?option=com_lmzpagebuilder&view=transfer',
        ]);
        $anhaengen($verwaltung, [
            'title' => 'COM_LMZCONTENTAPI',
            'alias' => 'lmz-contentapi',
            'link' => 'index.php?option=com_lmzcontentapi&view=dashboard',
        ]);
        /* Zeigt auf com_config - mod_menu blendet den Punkt für alle aus, die
           kein core.admin haben. Das ist gewollt. */
        $anhaengen($verwaltung, [
            'title' => 'COM_LMZPAGEBUILDER_SETTINGS',
            'alias' => 'lmz-einstellungen',
            'link' => 'index.php?option=com_config&view=component&component=com_lmzpagebuilder',
        ]);

        return $wurzel;
    }

    /**
     * Sucht den Menüeintrag der Suite unter "Komponenten".
     *
     * Über den Link, nicht über eine Nummer: die Nummer ist auf jeder
     * Installation eine andere, und genau daran sind die früheren
     * Direktverweise dieses Projekts gescheitert.
     */
    private function alteWurzelId(): ?int
    {
        if ($this->aufgeloest) {
            return $this->alteWurzel;
        }

        $this->aufgeloest = true;

        try {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__menu'))
                ->where(
                    [
                        $db->quoteName('client_id') . ' = 1',
                        $db->quoteName('menutype') . ' = ' . $db->quote('main'),
                        $db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_lmzpagebuilder'),
                    ]
                )
                ->order($db->quoteName('lft'));

            $treffer = $db->setQuery($query, 0, 1)->loadResult();
            $this->alteWurzel = $treffer !== null ? (int) $treffer : null;
        } catch (\Throwable $fehler) {
            /* Ohne den Ausschluss erschiene die Suite doppelt - unschön, aber
               kein Grund, das Backend mit einer Ausnahme anzuhalten. */
            $this->alteWurzel = null;
        }

        return $this->alteWurzel;
    }
}
