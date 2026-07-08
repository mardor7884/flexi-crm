# Flexi CRM (DentalNetwork)

A DentalNetwork landingek Flexi-Dent CRM integrációjának közös motorja: Fluent Forms
beküldéseket továbbít a Flexi-Dent CRM-be, site-onként a témából érkező konfigurációval.

## Mit csinál

Fluent Forms beküldéskor (`fluentform/submission_inserted`) a Flexi-Dent public API-t hívja:

1. `get-patients` — megnézi, létezik-e már a páciens az email alapján;
2. ha nem, `add-patient` — létrehozza (`patient_group_id`-val, `offer_subject`-tel);
3. mindig `crm/communication-log/add` — kommunikációs logot ír.

## Felépítés

- `flexi-crm.php` — fő fájl: konstansok, hook-bekötés (guarddal).
- `includes/engine.php` — a motor (`flexicrm_*` függvények).

A **site-specifikus form-konfiguráció NEM itt van**, hanem a témában, a
`flexicrm_forms_config` filteren át (lásd az Obsidian-tervet). Egy form-config kulcsai:
`type`, `subject`, `patient_group_id`, `log_prefix`, `webform_id`, `receiver_email`,
`names_key`, `fields`.

## Webform ID

A CRM felé küldött `form_id` a config `webform_id` kulcsából jön (a Flexi-oldali
„Webform ID" string, pl. `Dental Network_kozpontositott_sleepsol_2026`). Ha nincs
megadva, a fallback a Fluent Forms form számából képzett `webform-<N>`.
Lásd `flexicrm_resolve_form_id()`.

## Guard — nincs dupla feldolgozás

A plugin a WP-ben a téma előtt töltődik be, de a hookját csak `init`-en, guarddal köti be:

- **ha a téma saját kezelőt regisztrál ugyanerre a beküldés-eseményre** (a
  `flexi_handle_submission` jelenléte jelzi), **a plugin INERT marad** — nincs dupla CRM-log;
- ha nincs ilyen kezelő, a plugin köti be a hookot és feldolgozza a beküldést.

Így a plugin akárhány site-ra kitelepíthető anélkül, hogy ütközne egy témaszintű kezelővel.

## Hitelesítés

`wp-config.php`-ban, install-szinten:

```php
define( 'FLEXI_API_USERNAME', '...' );
define( 'FLEXI_API_PASSWORD', '...' );
```

Nélkülük a plugin nem köti be a hookot (log-üzenettel jelzi). A titok Bitwardenben.

## Log

`wp-content/logs/flexi-crm.log` (`.htaccess`-szel védve). A beküldés-log fejléce
tartalmazza a `get_site_url()`-t (multisite-azonosítás).

## Teszt

`flexicrm_test( 'Keresztnév', 'Vezetéknév', 'email@example.com', '+36301234567', 'Üzenet', <form_id> )`
— csak adminként. **Éles CRM-hívásokat indít** (teszt-pácienst hozhat létre), a
teszt-pácienst utána jelezni/töröltetni.

## Verzió

- **1.1.1** — a MainWP frissítési folyamat éles validálása (PUC self-update próba; nincs viselkedés-változás).
- **1.1.0** — a kommunikációs log **tárgya (`subject`) a Webform ID** (a korábbi „`<log_prefix>` `<subject>`" helyett); **önfrissítés GitHubról** (plugin-update-checker, `main` ág) → MainWP-ből frissíthető.
- 1.0.0 — első kiadás.

## Frissítés kiadása

1. Verzió-bump a `flexi-crm.php` `Version:` fejlécében (pl. 1.1.1).
2. `git commit` + `git push` a `main` ágra.
3. A telepített oldalakon (wp-admin / MainWP) pár órán belül megjelenik a „frissítés elérhető"; MainWP-ből egy kattintással kiadható. (A PUC ~12 órás cache-ét a MainWP „Check for updates"/Sync felülírja.)
