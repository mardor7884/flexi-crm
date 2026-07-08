<?php
/**
 * Plugin Name:       Flexi CRM (DentalNetwork)
 * Description:        Flexi-Dent CRM integráció Fluent Forms beküldésekhez — a DentalNetwork landingek közös motorja. A per-site űrlap-konfiguráció a témából érkezik a `flexicrm_forms_config` filteren keresztül.
 * Version:           1.1.1
 * Author:            MaD Works
 * Requires PHP:      8.0
 * Network:           true
 *
 * A motor egy helyen él; a site-specifikus form-konfigurációt a téma adja a
 * `flexicrm_forms_config` filteren.
 *
 * A plugin csak akkor köti be a Fluent Forms hookját, ha ugyanarra a beküldés-
 * eseményre a téma nem regisztrál saját kezelőt (ezt a `flexi_handle_submission`
 * függvény jelenléte jelzi) — így nem fordulhat elő dupla feldolgozás. Lásd a
 * `init` guardot lent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FLEXICRM_API_BASE_URL' ) ) {
	define( 'FLEXICRM_API_BASE_URL', 'https://publicapi.flexi-dent.hu/api/' );
}
if ( ! defined( 'FLEXICRM_LOG_FILE' ) ) {
	define( 'FLEXICRM_LOG_FILE', WP_CONTENT_DIR . '/logs/flexi-crm.log' );
}

require_once __DIR__ . '/includes/engine.php';

/**
 * Önfrissítés a GitHub-repóból (plugin-update-checker).
 * A `main` ág `flexi-crm.php` Version-fejlécét figyeli; ha az magasabb a telepítettnél,
 * a WordPress/MainWP „frissítés elérhető"-t mutat. Kiadás menete: verzió-bump + push a main-re.
 */
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$flexicrmUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/mardor7884/flexi-crm/',
	__FILE__,
	'flexi-crm'
);
$flexicrmUpdateChecker->setBranch( 'main' );

/**
 * Hook-bekötés az `init`-en (ekkor a téma functions.php-ja már lefutott).
 *
 * Guard #1: ha a téma saját kezelőt regisztrál ugyanerre a beküldés-eseményre
 *           (a `flexi_handle_submission` jelenléte jelzi), a plugin INERT marad —
 *           így nincs dupla feldolgozás. Ha nincs ilyen, a plugin köti be a hookot.
 * Guard #2: a Flexi API hitelesítő adatai a wp-config.php-ban (install-szintű titok).
 */
add_action( 'init', function () {
	if ( function_exists( 'flexi_handle_submission' ) ) {
		return; // a téma saját kezelőt futtat — a plugin nem avatkozik be
	}

	if ( ! defined( 'FLEXI_API_USERNAME' ) || ! defined( 'FLEXI_API_PASSWORD' ) ) {
		flexicrm_log( 'KONFIG HIBA: FLEXI_API_USERNAME és/vagy FLEXI_API_PASSWORD nincs definiálva a wp-config.php-ban. A FluentForms integráció nem aktív.' );
		return;
	}

	add_action( 'fluentform/submission_inserted', 'flexicrm_handle_submission', 20, 3 );
} );
