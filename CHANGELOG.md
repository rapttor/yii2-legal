# Changelog

## 2.8.3
- **Compact language strip on consent forms**: flag on top, short language name in small type below, one row for all languages (sideways scroll on a narrow screen, never wrapping into several rows); the current language is bold and underlined; Balkan uses a black circle. Option `compact` on `LanguageSwitcher` (default on for `sameRoute`). Put the strip outside a narrow card so all languages fit.

## 2.8.2 (requires rapttor/legal ^2.6.2)
- **The age checkbox says "I am of legal age in my country"; the number is only in the small print** (`consent.age` label, level in the hint). Balkan wording per the owner's rules (fajl, dodajem). Balkan switcher entry shows a black dot instead of a flag.

## 2.8.1 (requires rapttor/legal ^2.6.1)
- Regional embargoes (Crimea, Donetsk, Luhansk) are shown by their own names in the regions checkbox, not as the whole country.

## 2.8.0 (requires rapttor/legal ^2.6)
- **One Balkan language `sh`** instead of sr, sr-Cyrl, hr, bs and cnr (old tags are aliases): messages, tests and docs follow. No national flag in the switcher for it.
- **`policy.activities` tags** (and `feature.gambling`) put the countries that prohibit the activity into the required "I am not located in..." checkbox, with names in the visitor's language (intl extension), plus any names the owner typed. `yii legal/restrictions [tag]` prints the dataset; `legal/agents` writes the tag list with countries into AGENTS.md; `legal/check` hints when `policy.activities` is empty.
- Sign-up step: the title is the app name (`Legal::appName()`), the intro appears only when `Legal::$signupIntro` is set; removed `signup.title` and `signup.intro` messages.

## 2.7.1
- Document checkbox labels no longer inflect the document title (`Prihvaćam dokument: {doc}`, `Acepto: {doc}`): titles are nominative and gendered, so the old sentences were ungrammatical in several languages. Translators: never put a placeholder where the language would decline or gender it; use a colon form.

## 2.7.0 (requires rapttor/legal ^2.5)
- **Short checkbox titles with the explanation in small print** (`hint` in each consent definition; custom consents may give `hint` too). The age, uploads, gambling, regions, marketing and third-party items were reworded; the evidence hash covers label and hint.
- **The visitor picks the language of the consent forms**: `?lang=` and a `legal_lang` cookie (`Legal::visitorLanguage()`, options `languageParam`, `languageCookie`), independent of the app's language; the switcher on consent screens shows flags (`flags` option) and the new-account sign-in step can use it (`LanguageSwitcher::widget(['sameRoute' => true])`). Add the `legal_lang` cookie (preference, 1 year) to your cookie list.
- `signup.*` screen texts for the new-account step; all screen texts updated in the 12 other languages (drafts).

## 2.6.1
- The acceptance log records the real source (`signup`, `consents`, ...) of documents accepted in the consents list.

## 2.6.0 (requires rapttor/legal ^2.5)
- **`ConsentChecklist` widget**: the consents as separate unticked boxes (`consent[<key>]`) for the sign-in form of a new account, plus `Consents::missingRequired($answers)`: a new account is created only after every required item was ticked on its own, then stored with `consents()->record($user->id, $answers, 'signup')`. The consents page uses the same widget.
- Screen texts in de, fr, es, pt-BR, it, pl, nl and tr (drafts); the language switcher on consent forms renders as a row.

## 2.5.0 (requires rapttor/legal ^2.4)
- **Privacy and legal requests**: public form `/legal/request` (access, export, correction, deletion, objection, copyright, abuse, other), table `legal_request`, `Legal::requests()` (`submit`, `open`, `overdue`, `close`), mail to the privacy or legal mailbox, `Legal::EVENT_REQUEST` (`RequestEvent`) so the app can alert its admins, honeypot and a per-connection limit, internal due dates (`requestDueDays`, one month for privacy rights) that are never shown to the requester. Console `yii legal/requests` and `yii legal/request-close`. `legal/check` hints when nothing handles the event. Run `php yii migrate`.
- **Excluded regions**: `policy.excluded_regions` adds a required consent "I am not located in, and am not a resident or national of ..." (the version follows the list, so a change asks everyone again).
- **Language switcher on the consent and terms-updated screens and on the request form** (`LanguageSwitcher` option `sameRoute`, `?lang=`).
- `ConsentGate::$exceptCan`: roles or permissions (your own superadmins) that skip the gate; tenant admins are asked like everybody else.
- Messages for the new screens in sr, hr, bs and cnr.

## 2.4.0
- Requires `rapttor/yii2-metadata ^1.0`: `MetadataConsentStore` is now a thin wrapper over its `Metadata` component (uses the app's `metadata` component when registered, so `noHistory`, `historyMax`, `export()` and `deleteAll()` apply; otherwise a default one on `metadataTable`). The table is created by `Schema::create` via that package.
- History entries now have the metadata package's shape: `entry`, `rev`, `replaced_at`, `value` (was `key` plus the old fields). `MetadataConsentStore::history($userId, $consent = null)`.
- AGENTS.md: register the `metadata` component, delete and export a user's rows with the account.

## 2.3.0
- **Consents are kept in the shared `rapttor_metadata` table** (`MetadataConsentStore`, now the default): one row per user, `key=legal`, `remote_table=profile`, `remote_id=<user id>`, `content` (current state, JSON), `history` (earlier versions of changed answers, up to 200), `rev` (checked on save, so concurrent saves cannot overwrite each other). Table, key and remote table are configurable (`metadataTable`, `metadataKey`, `metadataRemoteTable`); other features can use the same table under other keys.
- Removed `TableConsentStore` and the `legal_consent` table (nothing used it); `JsonAttributeConsentStore` stays an option. `php yii migrate` creates the metadata table. Per-entry `history` inside the state is gone (the row's `history` replaces it).

## 2.2.0 (requires rapttor/legal ^2.2)
- **One checkbox per document**: `AcceptanceCheckbox` renders a separate unticked box for every document and `posted($docs)` is true only if each has its own ticked box (`legal_accept[<doc>]`). A single shared box no longer counts. **Breaking for custom code** that posted `legal_accept=1`.
- **Proof of acceptance with the user profile**: consent entries now carry `ev` (keyed hashes of IP, browser details and Accept-Language, of the wording shown and of the document text) and `history` (earlier answers, last 20); `legal_acceptance` gets `ua_hash` and the evidence in `context`. New `Legal::evidence()`, `hash()`, `hashKey()`. Run `php yii migrate` (adds `legal_consent.evidence` and `legal_acceptance.ua_hash`).

## 2.1.0
- `AcceptanceFilter` (the "we updated our terms" page) is deprecated and now behaves like `ConsentGate`: apps wired with it get the whole checklist, including the age and legal-age confirmation, after updating.
- Documents accepted in the checklist are also written to the acceptance log.
- `Legal::hints()` and `legal/check` hints: anonymous accounts without `age_gate`, missing `age_topics`, `data.external` vs `feature.external`, DMCA agent, missing consent column, AGENTS.md without the checklist block.
- `PostarBlocklist` uses Postar's documented `/v1/unsubscribes` (add, status, remove, 409 handling); `Legal::$providerFooter` for providers that append their own unsubscribe link; a provider that refuses the confirmation email (422) no longer breaks the "block all" request.
- Docs: consent data as JSON in the user profile, troubleshooting for missing consent screens.
- **Age and legal-age confirmation and upload responsibility are on by default** (requires rapttor/legal ^2.1): the plugin applies the catalogue's defaults to its config, so no app has to remember to switch them on. New opt-in gambling consent (own age and lawfulness confirmation); app-specific requirements stay custom `consents` items; hints call out a default that was switched off.

## 2.0.0 (requires rapttor/legal ^2.0)
- Consents registry: versioned required/optional consents kept with the account (table, or a JSON attribute of your user model), `ConsentGate` filter, `/legal/consents` page; age confirmation, upload responsibility, marketing and third-party resources included by feature flag.
- Third-party resources: `External::allows()`, per-item consent in the banner and cookie settings with the IP-address note, account choices sync the browser cookie.
- Email: one-click stop for optional mail (RFC 8058 headers, signed links), confirmed "block all email" with a single-use link, suppression table, footer helper, `PostarBlocklist` and `BlocklistInterface` adapters, `mailSender`.
- `CheckoutDisclosure` (renewal terms next to the pay button, consent context for the 3-year record), `WithdrawalLink` + `/legal/withdraw` (EU withdrawal function) with `EVENT_WITHDRAWAL`.
- `yii legal/agents`: writes the checklist for AI coding agents into AGENTS.md / llms.txt. New migration for the new tables and the `context` column.
- UI strings for the new pages in English, Serbian (Latin/Cyrillic), Croatian, Bosnian and Montenegrin (drafts); messages now one file per language.

## 0.1.0 (first public release)
- `Legal` component: one site config renders every published document; sanitised HTML (GFM + HTML Purifier); cache; render archive with last-good fallback.
- Languages: `languages` option (default all available), `publishDrafts`, language-aware URLs and routes, `LanguageSwitcher`, `hreflang`, `Content-Language`, fallback notice, text direction.
- Acceptance log (`legal_acceptance` table + migration), `AcceptanceFilter`, accept page, `AcceptanceCheckbox`; re-acceptance only for MAJOR/MINOR changes; IP stored as keyed hash.
- Cookie consent: `CookieBanner`, `CookieSettingsLink`, settings page, `Consent::allows()`, Global Privacy Control honoured; nothing stored before the visitor acts.
- Review gate for legal updates (`requireApproval`, `yii legal/status`, `yii legal/approve`); claims confirmation (`confirmedClaims`, `yii legal/claims`, `yii legal/check`).
- Table of contents on long documents; UI strings in English, Serbian (Latin/Cyrillic), Croatian, Bosnian, Montenegrin.
- Console: `legal/check`, `legal/claims`, `legal/status`, `legal/approve`, `legal/export`.
