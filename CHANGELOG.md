# Changelog

## 0.1.0 (first public release)
- `Legal` component: one site config renders every published document; sanitised HTML (GFM + HTML Purifier); cache; render archive with last-good fallback.
- Languages: `languages` option (default all available), `publishDrafts`, language-aware URLs and routes, `LanguageSwitcher`, `hreflang`, `Content-Language`, fallback notice, text direction.
- Acceptance log (`legal_acceptance` table + migration), `AcceptanceFilter`, accept page, `AcceptanceCheckbox`; re-acceptance only for MAJOR/MINOR changes; IP stored as keyed hash.
- Cookie consent: `CookieBanner`, `CookieSettingsLink`, settings page, `Consent::allows()`, Global Privacy Control honoured; nothing stored before the visitor acts.
- Review gate for legal updates (`requireApproval`, `yii legal/status`, `yii legal/approve`); claims confirmation (`confirmedClaims`, `yii legal/claims`, `yii legal/check`).
- Table of contents on long documents; UI strings in English, Serbian (Latin/Cyrillic), Croatian, Bosnian, Montenegrin.
- Console: `legal/check`, `legal/claims`, `legal/status`, `legal/approve`, `legal/export`.
