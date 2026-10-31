# Changelog

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
