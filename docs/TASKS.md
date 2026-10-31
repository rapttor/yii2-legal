# Tasks

| Status | Task | Model |
|---|---|---|
| done | Component, bootstrap routes, controller + neutral views, LegalLinks widget, console check/export, archive + last-good fallback, tests (10) | Sonnet |
| done | Languages: `languages` option (default all available), `publishDrafts`, language-aware URLs/routes (`/legal/<lang>/<doc>`), `LanguageSwitcher` widget (`only`, `labels`, `hideIfSingle`), `hreflang` + `Content-Language`, fallback notice, per-language export/check/archive (18 tests) | Sonnet |
| done | v2.0.0: consents registry + gate + page (table or JSON attribute store), third-party resource consent, email stop (one-click) and confirmed block-all with Postar adapter, CheckoutDisclosure, withdrawal function, `legal/agents`, new migration, per-language messages (48 tests) | Sonnet, Haiku (translations) |
| todo | Finish PostarBlocklist once postar.vip publishes its blocklist endpoint (read its llm.txt / openapi.json), add a live-API test with a sandbox key | Sonnet |
| todo | Rate limit "block all" requests per IP as well (today per address: 3 per hour) | Sonnet |
| todo (owner) | Commit, tag `v0.1.0`, push, register on Packagist; requires `rapttor/legal` 2026.10.x to be published first | Haiku |
| todo | Admin screen: edit config, preview documents, show archive diff between renders | Sonnet |
| todo | Cookie-consent banner hook (reads `feature.analytics/ads`), consent log | Sonnet |
| later | Per-language `language` override per request; `sr` texts once `rapttor/legal` has them | Haiku |
