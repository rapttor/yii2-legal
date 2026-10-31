# rapttor/yii2-legal

Legal pages for Yii2 apps from **one company config**: Terms, Privacy, Cookies, Acceptable Use, Legal Notice (imprint), Copyright/notice-and-action, Security disclosure, Sub-processors, and, when the matching feature is on, Refunds, DPA, Game Rules and App Terms. The texts come from [`rapttor/legal`](https://github.com/rapttor/rapttor.legal) (bundled by Composer, no runtime network access); this package renders them as sanitised HTML inside your app. It has no dependency on any other Rapttor package.

> Templates, not legal advice. Have counsel review them before production use (see the `rapttor/legal` README).

## Install

```bash
composer require rapttor/yii2-legal
```

## Configure (one place)

```php
'components' => [
    'legal' => [
        'class' => rapttor\yii2legal\Legal::class,
        'config' => [
            'company' => [
                'name' => 'ACME d.o.o.',
                'address' => 'Main St 1, 11000 Belgrade, Serbia',
                'country' => 'Serbia',
                'email' => 'hello@acme.com',
                'website' => 'https://acme.com',
                // optional: 'privacy_email', 'legal_email', 'reg_id', 'tax_id', 'representative', 'imprint_extra'
            ],
            'feature' => ['payments' => true, 'subscriptions' => true, 'consumers' => true],
        ],
        // or: 'configFile' => '@app/config/legal.json'   (written by vendor/bin/init.php, the placeholder builder)
    ],
],
```

Generate the config interactively with the builder instead of writing it by hand: `vendor/bin/init.php` (profiles: website, saas_b2b, saas_b2c, community, shop, mobile_app, game). Only five values are required; every other value is derived or defaulted, so the email, address and company name you set once appear in every document.

The package registers its routes by itself (Composer bootstrap): `/legal` (index) and `/legal/<document>` (for example `/legal/privacy`, `/legal/acceptable-use`). With `enablePrettyUrl` off, use `?r=legal/view&doc=privacy`.

## Use

```php
use rapttor\yii2legal\widgets\LegalLinks;

echo LegalLinks::widget();                                  // footer list of every published document
echo LegalLinks::widget(['only' => ['terms', 'privacy']]);  // chosen ones, in this order

Yii::$app->legal->url('acceptable_use');   // '/legal/acceptable-use'
Yii::$app->legal->documents();             // what this site publishes
Yii::$app->legal->html('privacy');         // sanitised HTML (cached)
Yii::$app->legal->release();               // installed rapttor/legal version, e.g. 2.0.0
```

Link to the Terms and Privacy Policy at sign-up and checkout. Documents that your features do not need are not published, and `url('dpa')` and similar for them are not valid.

## Languages

```php
'legal' => [
    'class' => rapttor\yii2legal\Legal::class,
    'languages' => ['en', 'sr', 'sr-Cyrl', 'hr'],   // offered languages, in switcher order; omit for every available one
    'publishDrafts' => false,                      // draft translations stay hidden until a lawyer reviews them
    'config' => [...],
],
```

- Pages: `/legal/terms` (the application's language), `/legal/hr/terms`, `/legal/sr-Cyrl/terms`. A language that is not offered returns 404; a document that is not translated yet shows the English one with a notice (`fallbackNotice`).
- `<?= rapttor\yii2legal\widgets\LanguageSwitcher::widget() ?>` lists every offered language by its own name and links to the same document in each. Narrow or reorder it where you place it: `['only' => ['en', 'sr']]`, rename with `'labels' => ['sr' => 'SR']`, `'hideIfSingle' => false` to keep a one-language list. On non-document pages it links to the legal index.
- `hreflang` alternates and `Content-Language` are set for you; text direction follows the language (`dir="rtl"` for Arabic).
- `LegalLinks::widget(['lang' => 'sr'])` lists the documents in a chosen language.
- Serbian Cyrillic is generated from the Latin text. Croatian, Bosnian and Montenegrin use their own vocabulary and `law.jurisdiction` (`hr`, `ba`, `me`) for governing law and authority.

## Acceptance log and re-consent

Keep proof of who agreed to which version. Run the migration once (`php yii migrate`; the package registers its migration namespace) and record acceptance when a user signs up:

```php
// sign-up form
echo rapttor\yii2legal\widgets\AcceptanceCheckbox::widget();   // "I accept the Terms and the Privacy Policy" (documents: 'acceptDocs', default terms + privacy)

// sign-up action
if (!rapttor\yii2legal\widgets\AcceptanceCheckbox::posted()) { /* show an error */ }
Yii::$app->legal->acceptance()->record($user->id, null, 'signup');   // stores document, version, language, time, keyed IP hash
```

When a document's MAJOR or MINOR version changes (a PATCH wording fix never asks again), logged-in users are sent to `/legal/accept` before they continue. Add the filter to the app config: `'as legalAcceptance' => ['class' => rapttor\yii2legal\filters\AcceptanceFilter::class]`. `Yii::$app->legal->acceptance()->pending($userId)` and `accepted($userId)` answer the same questions in code.

## Cookie consent

For sites with `feature.analytics` or `feature.ads`: put `<?= rapttor\yii2legal\widgets\CookieBanner::widget() ?>` once in the layout (equal "Accept all" / "Reject all" buttons; nothing is stored before the visitor acts) and `<?= rapttor\yii2legal\widgets\CookieSettingsLink::widget() ?>` in the footer. Gate your scripts: `if (Yii::$app->legal->consent()->allows('analytics')) { ... }`. Global Privacy Control (`Sec-GPC: 1`) counts as a refusal and hides the banner. The choice lives in the `legal_consent` cookie for 180 days; list it in your cookie table.

## Consents checklist (after sign-in)

Instead of one tick box, keep a **versioned list of consents with the account**.

- **On by default for every app** (required): the documents in `acceptDocs` (Terms, Privacy), the **age and legal-age confirmation** (`policy.age_gate` 13, 16, 18 or 21; name the purpose in `policy.age_topics`) and the **upload and copyright responsibility**. They apply unless the owner sets `'feature' => ['age_gate' => false]` or `['uploads' => false]` explicitly; `legal/check` calls a switched-off default out.
- **Opt-in, only if the app is about it**: gambling (`feature.gambling`, `policy.gambling_age`: its own age and lawfulness confirmation; a tick box is not real age verification), product emails (`feature.marketing`, optional), each third-party resource in `data.external` (optional), and your own app-specific requirements in `consents` (for example chat rules or distribution of content).

Required items block use; optional ones are asked once per version and may be declined; a new version of an item asks again.

```php
'consents' => [['key' => 'chat_rules', 'required' => true, 'version' => '1', 'label' => ['en' => 'I will follow the chat rules.', 'sr' => 'Poštovaću pravila četa.']]],
```

```php
'as legalConsents' => ['class' => rapttor\yii2legal\filters\ConsentGate::class],      // sends signed-in users (anonymous accounts too) to /legal/consents
// where the answers live (default: table legal_consent; or a JSON column of your user model):
'consentStore' => ['class' => rapttor\yii2legal\stores\JsonAttributeConsentStore::class, 'modelClass' => app\models\User::class, 'attribute' => 'consents'],
Yii::$app->legal->consents()->granted($user->id, 'uploads');                              // check a consent in code
```

Run `php yii migrate` once: the package registers its migrations (acceptance log, consents, email suppression, withdrawals).

### Consent data in the user profile (JSON)

To keep every answer with the user record instead of a separate table, add a column and point the store at it:

```php
// migration
$this->addColumn('{{%user}}', 'consents', $this->text()->null());          // or $this->json()->null() where supported

// legal component
'consentStore' => ['class' => rapttor\yii2legal\stores\JsonAttributeConsentStore::class, 'modelClass' => app\models\User::class, 'attribute' => 'consents'],
```
The column then holds, per consent key, the version, whether it was granted, when, in which language and where: `{"terms":{"v":"1.1","granted":true,"at":1761900000,"lang":"en","source":"consents"},"age":{"v":"18","granted":true,...}}`. Documents accepted here are also written to the acceptance log (evidence with time, version and a hashed IP). `Yii::$app->legal->consents()->state($user->id)` returns the array for exports.

### The "we updated our terms" popup

`AcceptanceFilter` (first release) only showed the documents. It is deprecated and now behaves exactly like `ConsentGate`, so one screen carries everything: documents, the age and legal-age confirmation (`feature.age_gate`, `policy.age_gate` = 13, 16, 18 or 21, `policy.age_topics`), upload responsibility and optional choices. If the age confirmation does not appear, `php yii legal/check` tells you why (feature flag off, gate not attached, route excluded, old package version).

## Third-party resources and fonts

Self-host fonts, scripts and icons. What must come from another server is declared in the config and loaded only with consent:

```php
'data' => ['external' => [['key' => 'maps', 'name' => 'Maps', 'provider' => 'Acme Maps', 'country' => 'EU', 'purpose' => 'map tiles']]],
if (Yii::$app->legal->external()->allows('maps')) { /* load it */ } else { /* fallback */ }
```
The cookie banner and settings page list each item with the note that the IP address is sent; the Privacy and Cookie Policies show the same table.

## Email: stop optional mail, block all mail

```php
$email = Yii::$app->legal->email();
if ($email->isSuppressed($to, 'optional')) { return; }                   // news, tips: respect the one-click stop
$headers = $email->unsubscribeHeaders($to);                              // List-Unsubscribe + List-Unsubscribe-Post (RFC 8058), optional mail only
$footer = $email->footer($to, optional: true);                           // ['html' =>, 'text' =>]: "stop all email from <service>" in EVERY email
```
Optional mail stops with one click, at once. "Stop all email" asks for the address, sends one last email with a single-use link (valid 24 hours; a page with a button, so mail scanners cannot trigger it), and only that confirmation adds the address to the no-send list, locally and at your email provider through `blocklist`. Sign-in and security emails carry only the footer link. Wire your mailer once:

```php
'mailSender' => fn($to, $subject, $html, $text, $headers) => Yii::$app->postar->send([...]),   // default: the Yii mailer component
'blocklist' => ['class' => rapttor\yii2legal\blocklist\PostarBlocklist::class],   // POST /v1/unsubscribes; key from POSTAR_API_KEY
'providerFooter' => true,   // Postar appends its own unsubscribe link to every email: do not add a second one   // documented endpoint /v1/unsubscribes, key from POSTAR_API_KEY
```

## Pay button, withdrawal, instructions for AI agents

- `CheckoutDisclosure::widget(['price' => '€9.99', 'period' => 'month', 'button' => 'Subscribe', 'checkbox' => true])` renders the renewal terms **next to the pay button** with links to Terms, Refunds and Privacy; store `$widget->context()` with the acceptance record (keep it at least three years).
- `WithdrawalLink::widget()` and `/legal/withdraw`: the EU withdrawal function (from 19 June 2026) with a confirmation step, an acknowledgement email and `Legal::EVENT_WITHDRAWAL` for your refund handling.
- `php yii legal/agents --llms` writes the security and compliance checklist for AI coding agents, filtered to your app's features, into `AGENTS.md` and `llms.txt`. Re-run it when features change.

## Claims, structured tables, review of updates

- **Claims:** the documents state things about your product (account deletion, MFA, response times...). `php yii legal/claims` lists them; confirm each one that is true with `'confirmedClaims' => ['mfa', ...]`; `php yii legal/check` fails while a relevant claim is unconfirmed (`--skipClaims` ignores that). Fix the product or the feature flags instead of confirming something false.
- **Tables:** give `config['data']['subprocessors']` (`name`, `purpose`, `location`) and `config['data']['cookies']` (`name`, `provider`, `purpose`, `category`, `duration`) as lists; the sub-processor list and the cookie policy tables are generated in the page's language.
- **Updates:** with `'requireApproval' => true` the site keeps serving the last approved version after `composer update rapttor/legal` until you run `php yii legal/status --diff` (see what changed) and `php yii legal/approve`.

## Licence page

Set `'license' => ['id' => 'MIT']` in the config (pick an id with `vendor/bin/license.php`) and the site publishes `/legal/license` with the chosen licence and its full text. Use `license.notice` for extra parameters such as a BUSL change date.

## Console

```bash
php yii legal/check                 # validates the config, lists published documents; exit 1 if required values are missing (CI)
php yii legal/export web/legal html # static HTML (or md) of every document
```

## Safety

- Markdown is rendered with GFM, then passed through **HTML Purifier**; `<`/`>` in config values are escaped first; only `http`, `https`, `mailto`, `tel` links survive.
- If a required config value goes missing after a page was published, the **last archived render is served** (and a warning logged) instead of a page with `[placeholder]` markers.
- Every distinct render is archived as Markdown in `@runtime/legal` (`archive`, `archivePath` options): what was in force, and from when. Back it up if you need legal evidence.
- HTML is cached in the `cache` component, keyed by release, language and config.

## Updating

`composer update rapttor/legal`, read the release notes, then deploy. When a document's MAJOR or MINOR version changed, tell your users before it takes effect, as your own Terms and Privacy Policy require.

## Options (`Legal`)

`config`, `configFile`, `routePrefix` (`legal`), `language` (defaults to app language, English fallback), `cache` (`cache` or `null`), `archive`, `archivePath`, `purifier` (extra HTML Purifier options), `libraryRoot` (tests).

## Test

```bash
composer install && vendor/bin/phpunit -c phpunit.xml
```
