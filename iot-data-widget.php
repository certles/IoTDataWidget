<?php

/**
 * Plugin Name:       IoT Data Widget – Home Assistant, MQTT & Smart Home Sensors
 * Description:       Displays live data from smart home devices (Home Assistant, REST API, MQTT, Shelly/Tasmota/ESPHome presets, Shelly Cloud) on your website via shortcode and Gutenberg block, with a guided setup wizard, a redesigned and auto-refreshing appearance, and a built-in diagnostics page. Pro adds threshold alerts, email reports, and a public embed mode.
 * Version:           1.0.12
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            WPVeloxa
 * Author URI:        https://wpveloxa.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iot-data-widget
 * Domain Path:       /languages
 *
 *
 * @package IoT_Data_Widget
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
    // Wyjście, gdy plik wywołany bezpośrednio.
}
/**
 * Faza 5 planu freemium (docs/archiwum/iot-data-widget-plan-freemium.md) — znacznik
 * "fs_premium_only" w nagłówku wyżej wskazuje preprocesorowi Freemiusa (Faza
 * 6, generowanie wersji darmowej) ścieżki do CAŁKOWITEGO usunięcia z paczki
 * WP.org. Obejmuje: includes/premium/ (chart/sparkline — Faza 4, embed
 * pełnoekranowy z tokenem — Faza 5) oraz, dopisane przy audycie paczki
 * darmowej (Faza 6, 2026-09-14), public/js/chart-widget.js i
 * public/css/chart-widget.css — jedyne dwa pliki front-endowe używane
 * WYŁĄCZNIE przez styl chart (renderer premium + includes/premium/class-embed.php),
 * bez żadnej zależności ze strony darmowych stylów. Ich wp_enqueue_script()/
 * _style() w IOTDW_Shortcode::enqueue_assets() jest już warunkowane
 * iotdw_is_premium() (nie tylko obecnością stylu "chart" w treści), więc
 * fizyczna nieobecność tych dwóch plików w paczce darmowej nigdy nie jest
 * odwoływana — bez potrzeby file_exists() guard, w przeciwieństwie do
 * includes/premium/ niżej (require_once klasy PHP wymaga takiego guardu, sam
 * <script src="..."> nie).
 *
 * Alerty/raporty e-mail/eksport CSV — dokończone przy audycie paczki
 * darmowej (Faza 6, 2026-09-14): każda z pięciu klas Pro
 * (IOTDW_Alert_Manager, IOTDW_Alert_Notifier, IOTDW_Email_Report_Manager,
 * IOTDW_Email_Report_Sender, IOTDW_History_Export) została rozdzielona na
 * "szkielet zawsze potrzebny" (zostaje w includes/ — schemat tabeli, CRUD
 * bez wartości biznesowej do ochrony, stałe hooków crona, orkiestracja
 * potrzebna przez IOTDW_Plugin::activate()/IOTDW_Diagnostics_Page
 * niezależnie od tieru) i "faktyczną logikę Pro" (przeniesiona do
 * includes/premium/, więc trafia pod znacznik wyżej):
 *   - IOTDW_Alert_Manager (CRUD reguł + schemat)  -> zostaje
 *     IOTDW_Alert_Evaluator (nasłuch/ocena/zapis)  -> includes/premium/
 *   - IOTDW_Alert_Notifier (cała klasa, zero zależności ze strony free) -> includes/premium/
 *   - IOTDW_Email_Report_Manager (CRUD raportów, brak wartości do ochrony, typ-hint zależności szkieletu) -> zostaje w całości
 *   - IOTDW_Email_Report_Sender (SEND_HOOK + orkiestracja) -> zostaje
 *     IOTDW_Email_Report_Dispatcher (generowanie/wysyłka treści) -> includes/premium/
 *   - IOTDW_History_Export (fetch_all()/fetch_batch(), teraz statyczne, zero zależności)   -> zostaje
 *     IOTDW_History_Csv_Export (formularz admina + streamowanie CSV) -> includes/premium/
 * IOTDW_Email_Report_Manager jest jedynym z piątki, którego NIE podzielono —
 * cała jego treść to CRUD bez logiki biznesowej, a jest typ-hintowany
 * konstruktorem w IOTDW_Email_Report_Sender (szkielet) i
 * IOTDW_Email_Reports_Page (Pro-gatowany ekran) — rozdzielenie kosztowałoby
 * realne rozluźnienie tego typowania bez żadnej korzyści (nic tam nie ma do
 * ochrony IP). Szczegółowe uzasadnienie każdej z tych decyzji — w docblocku
 * odpowiedniej klasy.
 */
/**
 * Faza 3 planu freemium (docs/archiwum/iot-data-widget-plan-freemium.md) — decyzja
 * bootstrapu, zbadana i rozstrzygnięta 2026-09-08, zanim powstał jakikolwiek
 * kod integracji.
 *
 * SDK Freemiusa MUSI się zainicjalizować na samej górze tego pliku,
 * bezwarunkowo, PRZED jakimkolwiek hookiem (potwierdzone wprost w
 * dokumentacji producenta: inicjalizacja na 'plugins_loaded' jest już za
 * późno i psuje część logiki SDK) — patrz
 * https://freemius.com/help/documentation/wordpress-sdk/integrating-freemius-sdk/.
 *
 * Rzeczywisty konflikt, którego się obawiano, NIE ISTNIEJE: SDK Freemiusa
 * inicjalizuje się w kodzie top-level tego pliku (require_once start.php +
 * wywołanie fabryki z ID produktu/kluczem publicznym), zupełnie niezależnie
 * od `add_action( 'init', 'iotdw_plugin' )` niżej. Powód wyboru 'init' dla
 * RESZTY wtyczki (tłumaczenie stringów w konstruktorach modułów, WP 6.7+
 * zgłasza błąd, jeśli dzieje się to wcześniej) zostaje bez zmian —
 * przebudowa całego bootstrapu wtyczki na 'plugins_loaded' okazała się
 * niepotrzebna.
 *
 * Faza 5 (2026-09-09): produkt utworzony w panelu Freemiusa (ID 39142),
 * snippet integracyjny wklejony poniżej niemal 1:1 — jedyna zmiana to ścieżka
 * do start.php, dopasowana do instalacji przez Composer (SDK jako pakiet
 * `freemius/wordpress-sdk`, patrz composer.json) zamiast ręcznego umieszczenia
 * w katalogu `freemius/` zakładanego przez domyślny snippet z panelu.
 *
 * `idw_fs`/`$idw_fs` (nie `iotdw_...`) to nazwy wygenerowane przez SDK
 * Freemiusa dla tego produktu — zostają bez zmian, bo cała reszta integracji
 * (wywołania `idw_fs()->...` w kodzie premium, dokumentacja Freemiusa)
 * zakłada dokładnie tę nazwę.
 *
 * `public_key` i `wp_org_gatekeeper` są z definicji publiczne (Freemius:
 * `wp_org_gatekeeper` jest automatycznie usuwany z wersji darmowej przez ich
 * preprocesor, patrz Faza 6). Klucz PRYWATNY (`secret_key`) celowo NIE trafia
 * do tego pliku — SDK czyta go z osobnej stałej `WP_FS__iot-data-widget_SECRET_KEY`
 * zdefiniowanej w wp-config.php lokalnego środowiska (patrz
 * .wp-env.override.json, gitignorowany), nigdy z kodu wtyczki.
 *
 * Struktura `if ( function_exists('idw_fs') ) { ...set_basename... } else { ... }`
 * poniżej jest wymagana przez SDK (nie moja decyzja architektoniczna) —
 * mechanizm auto-deaktywacji, gdy wersja darmowa i Pro są aktywne
 * jednocześnie. Oba warianty pliku mają IDENTYCZNĄ nazwę główną
 * (iot-data-widget.php), więc gdy oba są aktywne naraz, WordPress ładuje je
 * w jakiejś kolejności — ten, który załadował się pierwszy, definiuje
 * `idw_fs()` i wykonuje całą resztę tego pliku (gałąź `else`); drugi widzi
 * już zdefiniowaną funkcję, woła tylko `set_basename()` (żeby SDK wiedział o
 * jego istnieniu i mógł go deaktywować) i NIE wykonuje reszty logiki wtyczki
 * — inaczej klasy zdefiniowane w includes/class-plugin.php zderzyłyby się
 * (fatal error "cannot redeclare class").
 */
if ( function_exists( 'idw_fs' ) ) {
    idw_fs()->set_basename( false, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( !function_exists( 'idw_fs' ) ) {
        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- nazwy idw_fs/$idw_fs/idw_fs_loaded narzucone przez snippet Freemiusa.
        /**
         * Zwraca (i przy pierwszym wywołaniu inicjalizuje) instancję SDK
         * Freemiusa dla tego produktu. Nazwa funkcji/zmiennej globalnej
         * wygenerowana przez panel Freemiusa — patrz komentarz wyżej.
         *
         * @return Freemius
         */
        function idw_fs() {
            global $idw_fs;
            if ( !isset( $idw_fs ) ) {
                // `require` (nie `require_once`) celowo — pakiet
                // freemius/wordpress-sdk ma w composer.json
                // `"autoload": {"files": ["start.php"]}`, więc Composer
                // dociąga start.php automatycznie przy każdym autoloadzie,
                // czasem ZANIM WordPress zdefiniuje ABSPATH (np. w PHPUnit,
                // gdzie `vendor/bin/phpunit` ładuje autoloader przed
                // bootstrapem WP) — start.php ma na początku `if ( !
                // defined('ABSPATH') ) { return; }` i wtedy przerywa się, nic
                // nie definiując. PHP i tak zapamiętuje tę ścieżkę jako
                // "już wciągniętą" (require_once/include_once dzielą tę samą
                // pamięć, niezależnie od tego, czy poprzednie wciągnięcie
                // faktycznie coś zdefiniowało) — więc kolejny `require_once`
                // tutaj byłby cichym no-opem i fs_dynamic_init() nigdy by nie
                // powstało. Guard na function_exists() + zwykły `require`
                // wymusza realne wykonanie pliku raz jeszcze, tym razem z już
                // zdefiniowanym ABSPATH.
                if ( !function_exists( 'fs_dynamic_init' ) ) {
                    require __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';
                }
                $idw_fs = fs_dynamic_init( array(
                    'id'               => '39142',
                    'slug'             => 'iot-data-widget',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_1160b6d2619db29502e43062fafd7',
                    'is_premium'       => false,
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'    => 'iot-data-widget',
                        'support' => false,
                        'pricing' => true,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $idw_fs;
        }

        idw_fs();
        // Ikona na stronie cennika SDK. Bez tego SDK szuka pliku {slug}.* we własnym katalogu
        // assets/img i na zwykłych witrynach (nie localhost) wstawia ogólną ikonę.
        idw_fs()->add_filter( 'plugin_icon', static function () {
            return __DIR__ . '/public/img/plugin-icon.png';
        } );
        do_action( 'idw_fs_loaded' );
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
    }
    define( 'IOTDW_VERSION', '1.0.12' );
    define( 'IOTDW_PLUGIN_FILE', __FILE__ );
    define( 'IOTDW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'IOTDW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    /**
     * Faza 5 — jedyny punkt prawdy "czy ta instalacja ma Pro/Agency", używany
     * przez WSZYSTKIE bramki free/Pro w kodzie (limit połączeń, typy
     * konektorów, alerty, raporty e-mail, CSV, embed, branding), zamiast
     * każdego miejsca osobno wołającego `idw_fs()->can_use_premium_code()`.
     * Dwa powody: (1) testy PHPUnit mogą przełączyć zachowanie przez
     * `add_filter( 'iotdw_is_premium', '__return_true' )` bez mockowania
     * wewnętrznego stanu SDK Freemiusa; (2) jedno miejsce do zmiany, gdyby
     * kiedyś doszła inna metoda sprawdzania (np. os obny plan Agency).
     *
     * Celowo NIE `is__premium_only()` (sufiks) — te metody są znacznikami dla
     * preprocesora Freemiusa do usuwania całych bloków kodu z wersji
     * darmowej; tu potrzebny jest zwykły runtime-check obecny w OBU
     * wersjach (wersja darmowa też musi wiedzieć, że limit/gating jej
     * dotyczy).
     *
     * @return bool
     */
    function iotdw_is_premium() : bool {
        $is_premium = function_exists( 'idw_fs' ) && idw_fs()->can_use_premium_code();
        return (bool) apply_filters( 'iotdw_is_premium', $is_premium );
    }

    require_once IOTDW_PLUGIN_DIR . 'includes/class-plugin.php';
    /**
     * Zamiast osobnego uninstall.php — Freemius SDK sam rejestruje
     * WP-owy uninstall hook (register_uninstall_hook()) dla produktów, które
     * nie mają własnego uninstall.php, i po zakończeniu własnej logiki
     * (opcjonalna ankieta "dlaczego odinstalowujesz") woła zdarzenie
     * 'after_uninstall'. Fizyczny plik uninstall.php ma pierwszeństwo w
     * rdzeniu WP (wp-admin/includes/plugin.php, uninstall_plugin()) — gdyby
     * tu został, ankieta Freemiusa i to zdarzenie nigdy by się nie odpaliły.
     * Patrz https://freemius.com/help/documentation/wordpress-sdk/uninstall/.
     */
    idw_fs()->add_action( 'after_uninstall', array('IOTDW_Plugin', 'uninstall') );
    /**
     * Zwraca główną instancję wtyczki.
     *
     * @return IOTDW_Plugin
     */
    function iotdw_plugin() {
        return IOTDW_Plugin::instance();
    }

    // Init, nie plugins_loaded — moduły wtyczki (np. Connector Manager) tłumaczą
    // stringi w konstruktorach, a WP 6.7+ zgłasza to jako błąd, jeśli dzieje się
    // wcześniej niż init.
    add_action( 'init', 'iotdw_plugin' );
    register_activation_hook( __FILE__, array('IOTDW_Plugin', 'activate') );
    register_deactivation_hook( __FILE__, array('IOTDW_Plugin', 'deactivate') );
}