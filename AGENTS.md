# Instructions for AI coding agents: rapttor/yii2-legal

Yii2 package that publishes legal pages (terms, privacy, cookies, acceptable use, imprint, refunds, DPA, game rules, app terms, licence...) from ONE company config, in several languages. The texts come from `rapttor/legal` (bundled, no network at runtime). It is independent of every other Rapttor package. Read this file before integrating or changing it.

You are not a lawyer and neither is this package: **never claim the result makes a site legally compliant**, and never invent facts about the owner's company.

## Integrate into a Yii2 app (follow in order)

1. `composer require rapttor/yii2-legal` (pulls `rapttor/legal`). The extension bootstraps itself; do not register routes by hand.
2. **Ask the owner** (do not guess) for the five required values: `company.name` (legal entity), `company.address` (one line), `company.country`, `company.email`, `company.website`. Optional: `company.privacy_email`, `company.legal_email` (set them only if those mailboxes really exist; otherwise leave unset and everything goes to `company.email`), `company.reg_id`, `company.tax_id`, `company.representative`, `company.imprint_extra`, `dpo.email`, `eu_rep`, `uk_rep`, `product.name`.
3. **Ask the owner which features the app really has** and set only those under `feature`: `consumers` (sells to individuals), `payments`, `subscriptions`, `credits`, `ugc` (users publish content or interact), `ai`, `api`, `analytics`, `marketing`, `ads`, `location`, `processor_role` (processes personal data on behalf of business customers), `mobile`, `game`, `minors` (users under 18 with guardian consent; also set `policy.min_age`). A flag that is true makes the texts promise things (see "Promises" below); a flag that is false removes the clauses. Set `law.jurisdiction` (`rs`, `hr`, `ba`, `me`, `gb`, `de`) or the three `law.governing`, `law.venue`, `law.authority` values.
   Shortcut: `vendor/bin/init.php` asks these questions and writes a minimal JSON config (`--profile=website|saas_b2b|saas_b2c|community|shop|mobile_app|game`, `--preset=rs|hr|ba|me|gb|de|eu|other`, `--roles` only if the role mailboxes exist).
4. Register the component (web config; also console config for `legal/check`):
   ```php
   'components' => ['legal' => [
       'class' => rapttor\yii2legal\Legal::class,
       'configFile' => '@app/config/legal.json',   // or 'config' => [...]
       'languages' => null,                         // null = every available language; or ['en', 'sr', 'sr-Cyrl', 'hr']
       'publishDrafts' => false,                    // keep false; see Rules
   ]],
   ```
5. Link the pages from the app. Never hard-code `/legal/...`: use `Yii::$app->legal->url('terms')` (current language) or `->url('terms', 'sr')`.
   - Footer: `<?= rapttor\yii2legal\widgets\LegalLinks::widget() ?>` (all published documents; `['only' => ['terms','privacy']]` to choose).
   - Language switcher: `<?= rapttor\yii2legal\widgets\LanguageSwitcher::widget() ?>` (default: all offered languages; `['only' => ['en','sr']]`, `['labels' => ['sr' => 'SR']]`, `['hideIfSingle' => false]`).
   - Sign-up and checkout: a checkbox or notice linking Terms and Privacy Policy (use `->url()`); apps that sell to consumers also link `refunds`.
6. **Sign-up and logins:** add `AcceptanceCheckbox::widget()` to the sign-up form, call `Yii::$app->legal->acceptance()->record($user->id)` when the form validates (never record without the checkbox), run `php yii migrate`, and add the `AcceptanceFilter` (`'as legalAcceptance'`) so users re-accept after a MAJOR/MINOR change. Do not weaken the consent to a pre-ticked box or a notice.
7. **Cookies:** if `feature.analytics` or `feature.ads` is on, put `CookieBanner::widget()` once in the layout and `CookieSettingsLink::widget()` in the footer, and load analytics or ad scripts only inside `if (Yii::$app->legal->consent()->allows('analytics'))` (or `'advertising'`). Never set non-essential cookies before consent.
8. **Claims:** run `php yii legal/claims`. For each relevant claim, check the app does it; confirm true ones in `confirmedClaims`; report or build the ones that are not true. Never confirm a claim just to make `legal/check` pass.
9. `php yii legal/check` must exit 0 (lists published documents per language; fails on missing config and unconfirmed claims). Add it to CI.
7. Pages: `/legal` (index), `/legal/<document>`, `/legal/<lang>/<document>` (for example `/legal/acceptable-use`, `/legal/hr/terms`). Documents with underscores use hyphens in the URL. Use the application's layout. Style with the host CSS (`.legal`, `.legal-notice`, `.legal-meta`, `.legal-languages`, `.legal-links`); the views use plain markup and no theme variables.

Which documents appear depends on config: always `privacy`, `terms`, `acceptable_use`, `cookies`, `impressum`, `copyright`, `security`, `subprocessors`; plus `dpa` (`feature.processor_role`), `refunds` (`feature.payments`), `game_rules` (`feature.game`), `app_eula` (`feature.mobile`), `license` (when `license.id` is set; pick an id with `vendor/bin/license.php`). Never assume a document exists: ask `Yii::$app->legal->has('refunds')` or iterate `->documents()`.

## Rules you must follow

- **Never invent company data, registration numbers, addresses, mailboxes or jurisdiction.** Ask the owner. Do not use placeholder-looking values in production config.
- **Never edit files under `vendor/rapttor/legal`** to change wording. Text changes belong in the `rapttor/legal` repo and ship as a release.
- **`publishDrafts` stays `false`** unless the owner explicitly says to show unreviewed translations. Translations are machine drafts (`status: draft`) until a native-speaking lawyer sets `reviewed`. The page will fall back to English with a notice. Never mark a translation `reviewed`.
- **Output goes through `Legal::html()` only** (GFM, then HTML Purifier). Do not render document Markdown yourself, do not add a raw-HTML path, do not echo config values into the pages elsewhere without `Html::encode`.
- Do not hard-code document ids or language lists in app code beyond what the owner asked; use `documents()`, `offered()`, `languageList()`.
- Structured data for the sub-processor and cookie tables goes in `config['data']['subprocessors'|'cookies']` (lists of rows), not as hand-written Markdown. Keep it accurate: it is what the Privacy Policy and Cookie Policy show.
- `requireApproval` is for sites that must review legal updates before they go live: use `legal/status --diff` and `legal/approve`, never edit `approved.json` by hand.
- Missing required config must fail loudly (`legal/check`) or serve the last archived render; never publish `[company.name]`-style markers.
- Updating legal text: `composer update rapttor/legal`, read the release notes, run `php yii legal/check` and the tests, deploy. If a document's MAJOR or MINOR version changed, the owner must notify users as the Terms and Privacy Policy require before the change takes effect. Renders are archived in `@runtime/legal`; keep that folder in backups.

## Promises the texts make (the app must keep them, or the owner must be told)

When a feature flag is on, the published text states these things. Check each against the app; if the app does not do it, build it or report the gap to the owner instead of leaving a false statement live.

- **Cookies:** a cookie-settings link in the footer; consent requested before non-essential cookies with refusal as easy as acceptance; Global Privacy Control treated as a refusal. The package ships `CookieBanner`, `CookieSettingsLink` and the settings page; the app must place them and gate its scripts with `consent()->allows()`.
- **Accounts and privacy rights:** self-service account deletion (in settings, and inside mobile apps at Settings, Account, Delete account); privacy requests answered within one month and acknowledged within 72 hours at `privacy@`/`company.email`; a data export (30 days after termination) for content.
- **Security statements:** encryption in transit, access control, multi-factor authentication where offered (and for administrative access when `processor_role` is on), breach notification (72 hours to the authority, `policy.breach_hours` to business customers), security reports acknowledged within 3 business days.
- **Money:** subscription cancellation in account settings; notice before a price change or renewal; refunds per the Refunds page; consumer withdrawal wording at checkout with the express-consent step for digital content.
- **Content and abuse:** a monitored `legal_email` (or `company.email`) for abuse, copyright and DSA notices; statements of reasons and appeals for moderation decisions (appeal within 6 months); notice-and-action process.
- **Games:** purchase limits or parental controls, loot-box odds shown before purchase, 60 days' notice before end of service, anti-cheat disclosure.
- **Users under 18 (`minors`):** guardian consent flow and data-deletion on request.

## Changing the package

- Pipeline in `src/Legal.php`: config escape of `<`/`>` (not in `table.*`), `Library::render`, GFM, anchor conversion (`## Title {#id}`), HTML Purifier. Keep all four steps.
- Language logic lives in `rapttor/legal` (`Library::resolveLanguage`, `published`); the wrapper only applies the site's `languages` and `publishDrafts` and builds URLs. Routes are added by `Bootstrap` with the language rules first, restricted to real language tags, so `/legal/sr` is not read as a document called "sr".
- Keep PHP >= 8.2, `declare(strict_types=1)`, PSR-4 `rapttor\yii2legal\`. Tests: `vendor/bin/phpunit -c phpunit.xml` (needs a Yii2 install; set `LEGAL_TEST_AUTOLOAD` to a vendor autoloader if the package is symlinked). Every new behaviour needs a test.
- Do not add runtime dependencies beyond Yii2 and `rapttor/legal`. No dependency on any theme package.

## Files

`src/Legal.php` component, `src/Bootstrap.php` routes, `src/controllers/LegalController.php`, `src/views/legal/`, `src/widgets/LegalLinks.php`, `src/widgets/LanguageSwitcher.php`, `src/console/LegalController.php` (`legal/check`, `legal/export`), `tests/LegalTest.php`.
