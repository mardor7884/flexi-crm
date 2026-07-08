<?php
/**
 * Flexi-Dent CRM integráció — motor
 *
 * Fluent Forms beküldéskor a Flexi-Dent CRM API-t hívja: páciens-keresés email
 * alapján, szükség esetén létrehozás, majd kommunikációs log rögzítése.
 *
 * A form-konfigurációt a `flexicrm_forms_config` filter adja (site-onként a téma).
 * A CRM felé küldött form-azonosítót a `flexicrm_resolve_form_id()` adja. A
 * beküldés-log fejléce a `get_site_url()`-t is tartalmazza (multisite-azonosítás).
 *
 * Doc:
 * https://documenter.getpostman.com/view/12779716/U16bwUhn
 * https://fluentforms.com/docs/fluentform_submission_inserted/
 *
 * Hitelesítés a wp-config.php-ban:
 *   define( 'FLEXI_API_USERNAME', '...' );
 *   define( 'FLEXI_API_PASSWORD', '...' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/////////////////////////////////////////////////////////////////////

/**
 * A CRM felé küldött form-azonosító feloldása.
 *
 * Ha a form-config megadja a `webform_id`-t (a Flexi-oldali „Webform ID" string,
 * pl. `Dental Network_kozpontositott_sleepsol_2026`), azt küldjük; ha nincs, a
 * Fluent Forms form számából képzett `webform-<N>` a fallback.
 *
 * @param  array $formConfig
 * @param  int   $formId
 * @return string
 */
function flexicrm_resolve_form_id( array $formConfig, int $formId ): string {
	$webformId = trim( (string) ( $formConfig['webform_id'] ?? '' ) );
	return $webformId !== '' ? $webformId : "webform-{$formId}";
}

/**
 * Alap helper: GET vagy POST kérés Basic Auth-tal
 *
 * GET esetén a $params query stringbe kerülnek.
 * POST esetén a $params JSON body-ba kerülnek (vagy form-urlencoded, ha $format='form').
 *
 * @param  string $method    'GET' vagy 'POST'
 * @param  string $endpoint  pl. 'add-patient'
 * @param  array  $params    paraméterek
 * @return array             ['success', 'status', 'data', 'error']
 */
function flexicrm_api_request( $method, $endpoint, $params = [], $format = 'json' ) {
	$url = FLEXICRM_API_BASE_URL . $endpoint;

	$args = [
		'method'  => $method,
		'timeout' => 15,
		'headers' => flexicrm_auth_header(),
	];

	if ( $method === 'GET' ) {
		$url = add_query_arg( $params, $url );
	} elseif ( $format === 'form' ) {
		$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		$args['body'] = $params;
	} else {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body'] = json_encode( $params );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return [
			'success' => false,
			'status'  => 0,
			'data'    => null,
			'error'   => $response->get_error_message(),
		];
	}

	$status = wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	return [
		'success' => ( $status >= 200 && $status < 300 ),
		'status'  => $status,
		'data'    => $body,
		'error'   => null,
	];
}

/**
 * Páciens keresése email cím alapján (GET)
 *
 * @param  string $email
 * @return array  API válasz
 */
function flexicrm_get_patient_by_email( $email ) {
	return flexicrm_api_request( 'GET', 'get-patients', [ 'email' => $email ] );
}

/**
 * Páciens létrehozása (POST, JSON body)
 *
 * FIGYELEM: a CRM-ben last_name = keresztnév, first_name = vezetéknév,
 * ezért szándékosan fordítva küldjük.
 *
 * @param  string $first_name  keresztnév (az űrlapból)
 * @param  string $last_name   vezetéknév (az űrlapból)
 * @param  string $email
 * @return array  API válasz
 */
function flexicrm_add_patient( $first_name, $last_name, $email, $phone = '', $comment = '', $offer_subject = '', $url = '', $ip = '', $patient_group_id = 0 ) {
	$patient = [
		'last_name'        => $first_name,  // szándékosan fordítva
		'first_name'       => $last_name,   // szándékosan fordítva
		'email'            => $email,
		'language'         => flexicrm_get_language(),
		'email_enable'     => 1,
		'sms_enable'       => 1,
		'patient_group_id' => (int) $patient_group_id,
	];

	$parsed_phone = flexicrm_parse_phone( $phone );
	if ( $parsed_phone ) {
		$patient['phone'] = $parsed_phone;
	}
	if ( ! empty( $comment ) ) {
		$patient['comment'] = $comment;
	}
	if ( ! empty( $offer_subject ) ) {
		$patient['offer_subject'] = $offer_subject;
	}
	if ( ! empty( $url ) ) {
		$patient['url'] = $url;
	}
	if ( ! empty( $ip ) ) {
		$patient['ip'] = $ip;
	}

	return flexicrm_api_request( 'POST', 'add-patient', [ 'patient' => $patient ] );
}

/**
 * Kommunikációs log szövegének összeállítása
 *
 * @param  array $data  ['first_name', 'last_name', 'email', 'phone', 'comment', 'url']
 * @param  string $type  'Foglalás' vagy 'Üzenet'
 * @return string
 */
function flexicrm_build_log_body( $data, $type = 'Foglalás' ) {
	$d = wp_parse_args( $data, [
		'first_name' => '',
		'last_name'  => '',
		'email'      => '',
		'phone'      => '',
		'comment'    => '',
		'url'        => '',
	] );

	$site = get_bloginfo( 'name' );
	$body = "";

	if ( $type === 'Üzenet' ) {
		$body .= '<p>Új üzenet érkezett a weboldalról az alábbi adatokkal:</p>';
	} else {
		$body .= '<p>Új időpont foglalás érkezett a weboldalról az alábbi adatokkal:</p>';
	}

	$body .= '<p><strong>Név:</strong> ' . esc_html( $d['last_name'] ) . ' ' . esc_html( $d['first_name'] ) . '</p>';
	$body .= '<p><strong>E-mail:</strong> ' . esc_html( $d['email'] ) . '</p>';
	$body .= '<p><strong>Telefonszám:</strong> ' . esc_html( $d['phone'] ) . '</p>';
	$body .= '<p><strong>Üzenet:</strong> ' . nl2br( esc_html( $d['comment'] ) ) . '</p>';
	if ( ! empty( $d['url'] ) ) {
		$body .= '<p><strong>Forrás URL:</strong> ' . esc_html( $d['url'] ) . '</p>';
	}

	$body .= '<p>Üdvözlettel,<br>' . esc_html( $site ) . '</p>';
	return $body;
}

/**
 * Kommunikációs log létrehozása
 *
 * @param  int    $patient_id  0 ha ismeretlen
 * @param  array  $data        ['first_name', 'last_name', 'email', 'phone', 'comment', 'url']
 * @param  string $form_id     a CRM felé küldött form-azonosító (Webform ID vagy webform-<N>)
 * @param  array  $meta        form config (type, subject, log_prefix, receiver_email)
 * @return array  API válasz
 */
function flexicrm_add_communication_log( $patient_id, array $data, $form_id = '', array $meta = [] ) {
	// A kommunikációs log tárgya a Webform ID ($form_id itt a feloldott Webform ID).
	$subject = $form_id;
	$body    = flexicrm_build_log_body( $data, $meta['type'] ?? 'Foglalás' );

	return flexicrm_api_request( 'POST', 'crm/communication-log/add', [
		'patient_id'           => (int) $patient_id,
		'type'                 => 'email',
		'direction'            => 'in',
		'receiver'             => $meta['receiver_email'] ?? '',
		'sender'               => $data['email']          ?? '',
		'subject'              => $subject,
		'body'                 => $body,
		'registrator_user_id'  => 0,
		'related_user_id'      => 0,
		'form_id'              => $form_id,
	], 'form' );
}

function flexicrm_verify_patient( string $email ): void {
	flexicrm_log( "[ELLENŐRZÉS] Visszakérdezés a CRM-be – email: {$email}" );
	$result = flexicrm_get_patient_by_email( $email );
	flexicrm_log( "[ELLENŐRZÉS] Válasz: " . wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
}

function flexicrm_auth_header(): array {
	if ( ! defined( 'FLEXI_API_USERNAME' ) || ! defined( 'FLEXI_API_PASSWORD' ) ) {
		flexicrm_log( 'KONFIG HIBA: FLEXI_API_USERNAME és/vagy FLEXI_API_PASSWORD nincs definiálva a wp-config.php-ban.' );
		return [ 'Accept' => 'application/json' ];
	}

	return [
		'Authorization' => 'Basic ' . base64_encode( FLEXI_API_USERNAME . ':' . FLEXI_API_PASSWORD ),
		'Accept'        => 'application/json',
	];
}

/**
 * Magyar telefonszámot darabolja szét az API struktúrájának megfelelően.
 * Elfogad: +3630..., 0630..., 630..., 06 30 ... formátumokat
 *
 * Validáció:
 * - csak számjegyeket enged át (betűk, egyéb karakterek → null)
 * - körzet (area): 1 vagy 2 jegyű, érvényes magyar mobil/vezetékes prefix
 * - szám (number): pontosan 6 vagy 7 jegyű
 *
 * @param  string $phone
 * @return array|null  ['country', 'area', 'number'] vagy null ha üres/érvénytelen
 */
function flexicrm_parse_phone( $phone ) {
	// + jelet megtartjuk az elején, minden mást (szóköz, kötőjel, zárójel stb.) eltávolítunk
	$phone = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );

	if ( empty( $phone ) ) {
		return null;
	}

	// +36 vagy 06 prefix levágása -> marad pl. 701234567
	$phone = preg_replace( '/^(\+36|06)/', '', $phone );

	// Csak számjegyek maradhatnak
	if ( ! ctype_digit( $phone ) ) {
		flexicrm_log( "Telefonszám eldobva (nem numerikus karakter): {$phone}" );
		return null;
	}

	// Magyar körzet: 1 vagy 2 jegy + utána 6 vagy 7 számjegy = összesen 8 vagy 9 jegy
	$len = strlen( $phone );
	if ( $len !== 8 && $len !== 9 ) {
		flexicrm_log( "Telefonszám eldobva (váratlan hossz: {$len}): {$phone}" );
		return null;
	}

	// Érvényes magyar körzetek: mobil (20, 30, 31, 50, 70) és vezetékes (1 + területi)
	$valid_area_prefixes = [ '1', '20', '21', '30', '31', '50', '51', '52', '56', '57',
							  '62', '63', '66', '68', '69', '70', '72', '73', '74', '75',
							  '76', '77', '78', '79', '82', '83', '84', '85', '87', '88',
							  '89', '92', '93', '94', '95', '96', '99' ];

	// Budapest vezetékes körzete egyjegyű ('1'), minden más jelenlegi prefix kétjegyű.
	$area_length = str_starts_with( $phone, '1' ) ? 1 : 2;
	$area        = substr( $phone, 0, $area_length );
	$number      = substr( $phone, $area_length );

	if ( ! in_array( $area, $valid_area_prefixes, true ) ) {
		flexicrm_log( "Telefonszám eldobva (ismeretlen körzet: {$area}): {$phone}" );
		return null;
	}

	if ( strlen( $number ) !== 7 && strlen( $number ) !== 6 ) {
		flexicrm_log( "Telefonszám eldobva (érvénytelen előfizetői számhossz): {$phone}" );
		return null;
	}

	return [
		'country' => '36',
		'area'    => $area,
		'number'  => $number,
	];
}

/**
 * Aktuális oldal nyelve Polylang alapján, fallback: 'hu'
 */
function flexicrm_get_language() {
	return function_exists( 'pll_current_language' ) ? pll_current_language() : 'hu';
}

/**
 * Látogató IP-je, proxy-tudatosan
 */
function flexicrm_get_client_ip() {
	$sources = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	];
	foreach ( $sources as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $ip;
			}
		}
	}
	return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
}

function flexicrm_log( string $message ): void {
	$dir = dirname( FLEXICRM_LOG_FILE );

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
	}

	$timestamp = current_time( 'Y-m-d H:i:s' );
	error_log( "[{$timestamp}] {$message}" . PHP_EOL, 3, FLEXICRM_LOG_FILE );
}

/**
 * Fő belépő: Fluent Forms beküldés feldolgozása.
 *
 * A `fluentform/submission_inserted` hookra köt (a plugin fő fájljában, guarddal).
 *
 * @param int    $entryId
 * @param array  $insertData
 * @param object $form
 */
function flexicrm_handle_submission( int $entryId, array $insertData, object $form ): void {
	$formId = (int) $form->id;
	$config = apply_filters( 'flexicrm_forms_config', [] );

	if ( ! array_key_exists( $formId, $config ) ) {
		return;
	}

	$formConfig = $config[ $formId ];
	$fields     = $formConfig['fields'];

	// Mezők kiolvasása
	$namesKey  = $formConfig['names_key'] ?? null;
	$namesData = $namesKey ? ( $insertData[ $namesKey ] ?? [] ) : $insertData;

	$firstName = sanitize_text_field( $namesData[ $fields['first_name'] ] ?? '' );
	$lastName  = sanitize_text_field( $namesData[ $fields['last_name']  ] ?? '' );
	$email     = sanitize_email(      $insertData[ $fields['email']      ] ?? '' );
	$phone     = isset( $fields['phone'] )   ? sanitize_text_field(    $insertData[ $fields['phone'] ]   ?? '' ) : '';
	$message  = isset( $fields['message'] )  ? sanitize_textarea_field( $insertData[ $fields['message'] ]  ?? '' ) : '';
	$message2 = isset( $fields['message2'] ) ? sanitize_textarea_field( $insertData[ $fields['message2'] ] ?? '' ) : '';
	$message = implode( "\r\n", array_filter( [ $message, $message2 ] ) );

	$subject   = $formConfig['subject'];
	$url       = sanitize_url( strtok( wp_get_referer() ?: get_site_url(), '?' ) );
	$ip        = flexicrm_get_client_ip();
	$crmFormId = flexicrm_resolve_form_id( $formConfig, $formId );

	flexicrm_log( "=== Új beküldés [Entry #{$entryId} | Form #{$formId} | {$formConfig['type']} | " . get_site_url() . "] ===" );
	flexicrm_log( "Adatok: {$lastName} {$firstName} | {$email} | {$phone}" );
	flexicrm_log( "Tárgy: {$subject} | CRM form_id: {$crmFormId} | URL: {$url} | IP: {$ip}" );

	// 1. Létezik-e már a páciens?
	$check      = flexicrm_get_patient_by_email( $email );
	$exists = ! empty( $check['data']['status_bool'] )
		  && ! empty( $check['data']['data']['patients'] );
	$patient_id = 0;

	if ( $exists ) {
		$patient_id = $check['data']['data']['patients'][0]['pt_id'] ?? 0;
		flexicrm_log( "Páciens már létezik. patient_id: {$patient_id}" );
	} elseif ( $check['error'] ) {
		flexicrm_log( "HIBA az email ellenőrzéskor: " . $check['error'] );
	} else {
		// 2. Létrehozás
		flexicrm_log( "Páciens nem található, létrehozás indul…" );
		$create = flexicrm_add_patient( $firstName, $lastName, $email, $phone, $message, $subject, $url, $ip, $formConfig['patient_group_id'] ?? 0 );

		if ( ! empty( $create['data']['status_bool'] ) && $create['data']['status_bool'] === true ) {
			$patient_id = $create['data']['new_patient_id'] ?? 0;
			flexicrm_log( "Páciens létrehozva. new_patient_id: {$patient_id}" );
			flexicrm_verify_patient( $email );
		} else {
			$errors = $create['data']['error_messages'] ?? $create['error'] ?? 'ismeretlen hiba';
			flexicrm_log( "HIBA a létrehozáskor: " . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ) );
		}
	}

	// 3. Kommunikációs log – mindig létrehozzuk
	flexicrm_log( "Kommunikációs log rögzítése… patient_id: {$patient_id}" );

	$log = flexicrm_add_communication_log( $patient_id, [
		'first_name' => $firstName,
		'last_name'  => $lastName,
		'email'      => $email,
		'phone'      => $phone,
		'comment'    => $message,
		'url'        => $url,
	], $crmFormId, $formConfig );

	if ( ! empty( $log['data']['status_bool'] ) && $log['data']['status_bool'] === true ) {
		$logId = $log['data']['new_communication_log_id'] ?? '(ismeretlen)';
		flexicrm_log( "Log rögzítve. new_communication_log_id: {$logId}" );
	} else {
		$errors = $log['data']['error_messages'] ?? $log['error'] ?? 'ismeretlen hiba';
		flexicrm_log( "HIBA a log rögzítésekor: " . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ) );
	}

	flexicrm_log( "=== Feldolgozás befejezve [Entry #{$entryId}] ===\r\n" );
}

/**
 * TESZT függvény - csak adminnak látható
 *
 * Példa hívás (ideiglenesen, pl. egy admin oldalon):
 *   flexicrm_test( 'János', 'Teszt', 'teszt@example.com', '+36301234567', 'Üzenet szövege.', 3 );
 *
 * FIGYELEM: ez ÉLES CRM-hívásokat indít (teszt-pácienst hozhat létre). Csak
 * tudatos, kontrollált teszteléshez, és a teszt-pácienst utána jelezni/töröltetni.
 *
 * @param  string $first_name
 * @param  string $last_name
 * @param  string $email
 * @param  string $phone
 * @param  string $comment
 * @param  int    $form_id    Form ID (ugyanaz, mint a `flexicrm_forms_config` filterben)
 * @param  string $url
 * @param  string $ip
 */
function flexicrm_test( $first_name, $last_name, $email, $phone = '', $comment = '', $form_id = 0, $url = '', $ip = '' ) {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	$config     = apply_filters( 'flexicrm_forms_config', [] );
	$formConfig = $config[ $form_id ] ?? [];
	$type             = $formConfig['type']             ?? 'Foglalás';
	$offer_subject    = $formConfig['subject']          ?? 'Időpontkérés';
	$patient_group_id = $formConfig['patient_group_id'] ?? 0;
	$crm_form_id      = flexicrm_resolve_form_id( $formConfig, (int) $form_id );

	$url = strtok( $url ?: ( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : get_site_url() ), '?' );
	$ip  = $ip  ?: flexicrm_get_client_ip();

	flexicrm_log( "=== TESZT indítása ===" );
	flexicrm_log( "Adatok: {$last_name} {$first_name} | {$email} | {$phone}" );
	flexicrm_log( "Típus: {$type} | CRM form_id: {$crm_form_id} | URL: {$url} | IP: {$ip}" );

	echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:20px;margin:20px;font-size:13px;line-height:1.6;border-radius:6px;">';
	echo "<strong style='color:#9cdcfe;'>Flexi-Dent CRM teszt</strong>\r\n";
	echo str_repeat( '-', 50 ) . "\r\n";
	echo "Bemeneti adatok : {$first_name} {$last_name} | {$email} | {$phone} | {$comment}\r\n";
	echo "Típus / tárgy   : {$offer_subject}\r\n";
	echo "CRM form_id     : {$crm_form_id}\r\n";
	echo "URL / IP        : {$url} | {$ip}\r\n";
	echo "API felé küldve : last_name={$first_name} first_name={$last_name} (fordított – CRM logika)\r\n\r\n";

	// 1. lépés: már létezik-e ez az email?
	echo "<strong style='color:#dcdcaa;'>1. Email ellenőrzés (get-patients)...</strong>\r\n";
	$check = flexicrm_get_patient_by_email( $email );
	echo "HTTP státusz : " . $check['status'] . "\r\n";
	echo "API válasz   : " . print_r( $check['data'], true ) . "\r\n";

	flexicrm_log( "get-patients [{$check['status']}]: " . wp_json_encode( $check['data'], JSON_UNESCAPED_UNICODE ) );

	if ( $check['error'] ) {
		echo "<span style='color:#f44747;'>WP hiba: " . $check['error'] . "</span>\r\n";
		flexicrm_log( "HIBA az email ellenőrzéskor: " . $check['error'] );
		echo '</pre>';
		return;
	}

	$exists     = ! empty( $check['data']['status_bool'] )
				  && ! empty( $check['data']['data']['patients'] );
	$patient_id = 0;

	if ( $exists ) {
		$patient_id = $check['data']['data']['patients'][0]['pt_id'] ?? 0;
		echo "\r\n<span style='color:#4ec9b0;'>=> Páciens MÁR LÉTEZIK. patient_id: {$patient_id}</span>\r\n";
		flexicrm_log( "Páciens már létezik. patient_id: {$patient_id}" );
	} else {
		echo "\r\n<strong style='color:#dcdcaa;'>2. Páciens létrehozása (add-patient)...</strong>\r\n";
		flexicrm_log( "Páciens nem található, létrehozás indul…" );

		$create = flexicrm_add_patient( $first_name, $last_name, $email, $phone, $comment, $offer_subject, $url, $ip, $patient_group_id );
		echo "HTTP státusz : " . $create['status'] . "\r\n";
		echo "API válasz   : " . print_r( $create['data'], true ) . "\r\n";

		flexicrm_log( "add-patient [{$create['status']}]: " . wp_json_encode( $create['data'], JSON_UNESCAPED_UNICODE ) );

		if ( $create['error'] ) {
			echo "<span style='color:#f44747;'>WP hiba: " . $create['error'] . "</span>\r\n";
			flexicrm_log( "HIBA a létrehozáskor (WP): " . $create['error'] );
			echo '</pre>';
			return;
		} elseif ( ! empty( $create['data']['status_bool'] ) ) {
			$patient_id = $create['data']['new_patient_id'] ?? 0;
			echo "\r\n<span style='color:#4ec9b0;'>=> Sikeresen létrehozva. new_patient_id: {$patient_id}</span>\r\n";
			flexicrm_log( "Páciens létrehozva. new_patient_id: {$patient_id}" );
			flexicrm_verify_patient( $email );
		} else {
			$errors = $create['data']['error_messages'] ?? '(nincs részlet)';
			echo "\r\n<span style='color:#f44747;'>=> HIBA a létrehozáskor: " . print_r( $errors, true ) . "</span>\r\n";
			flexicrm_log( "HIBA a létrehozáskor: " . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ) );
		}
	}

	// 3. lépés: kommunikációs log
	echo "\r\n<strong style='color:#dcdcaa;'>3. Kommunikációs log rögzítése...</strong>\r\n";
	flexicrm_log( "Kommunikációs log rögzítése… patient_id: {$patient_id}" );

	$log = flexicrm_add_communication_log( $patient_id, [
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'email'      => $email,
		'phone'      => $phone,
		'comment'    => $comment,
		'url'        => $url,
	], $crm_form_id, $formConfig );
	echo "HTTP státusz : " . $log['status'] . "\r\n";
	echo "API válasz   : " . print_r( $log['data'], true ) . "\r\n";

	flexicrm_log( "communication-log [{$log['status']}]: " . wp_json_encode( $log['data'], JSON_UNESCAPED_UNICODE ) );

	if ( ! empty( $log['data']['status_bool'] ) && $log['data']['status_bool'] === true ) {
		$log_id = $log['data']['new_communication_log_id'] ?? '(ismeretlen)';
		echo "\r\n<span style='color:#4ec9b0;'>=> Log sikeresen rögzítve. new_communication_log_id: {$log_id}</span>\r\n";
		flexicrm_log( "Log rögzítve. new_communication_log_id: {$log_id}" );
	} else {
		$errors = $log['data']['error_messages'] ?? '(nincs részlet)';
		echo "\r\n<span style='color:#f44747;'>=> HIBA a log rögzítésekor: " . print_r( $errors, true ) . "</span>\r\n";
		flexicrm_log( "HIBA a log rögzítésekor: " . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ) );
	}

	flexicrm_log( "=== TESZT befejezve ===\r\n" );

	echo '</pre>';
}
