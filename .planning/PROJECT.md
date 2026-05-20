# Flxpnt — Flxpoint ↔ WooCommerce Image Sync

## What This Is

A WordPress plugin that syncs product and variation images from Flxpoint into WooCommerce, using SKU as the matching key. It's a focused integration tool: it handles images only — no pricing, inventory, or product details — keeping the scope tight and the sync fast.

## Core Value

WooCommerce product images stay in sync with Flxpoint without manual effort — match by SKU, run hourly or on demand, and images are always current.

## Requirements

### Validated

- ✓ Admin settings page with Flxpoint API credentials — existing
- ✓ API connection test (Bearer token auth) — existing
- ✓ WordPress Plugin Boilerplate architecture — existing

### Active

- [ ] Sync product images from Flxpoint to WooCommerce (matched by SKU)
- [ ] Sync variation images from Flxpoint to WooCommerce (matched by variation SKU)
- [ ] "Sync Now" manual trigger in admin
- [ ] Hourly scheduled sync via WP-Cron
- [ ] Configurable image handling per product: download to media library or link externally
- [ ] Update existing products in place (replace images, don't delete/recreate)
- [ ] Sync log visible in admin (what was synced, what failed)

### Out of Scope

- Syncing product details (name, description, short description) — images only
- Syncing pricing or inventory/stock levels — images only
- Creating new WooCommerce products from Flxpoint — only update existing SKU matches
- Bidirectional sync — Flxpoint is always the source of truth
- Order sync from WooCommerce to Flxpoint

## Context

- **Existing codebase:** WordPress plugin scaffolded from WP Plugin Boilerplate with admin settings page, API connection test, and empty public-facing side
- **Architecture:** Single `Flxpnt` orchestrator class with admin/public separation, Loader-based hook registry
- **Flxpoint API:** REST API at `api.flxpoint.com` with Bearer token auth — base URL and token stored in WordPress options
- **WooCommerce:** Plugin describes itself as "bridge between Flxpoint and Woocommerce" but no WooCommerce integration exists yet
- **No external dependencies:** Zero Composer/npm packages, relies entirely on WordPress core APIs

## Constraints

- **Tech stack:** PHP + WordPress (no build step, no external packages), ES5 JavaScript (no transpilation), plain CSS
- **Compatibility:** Must work with WooCommerce active — add WooCommerce dependency check
- **Pattern:** Follow existing WP Plugin Boilerplate conventions (Loader-based hooks, admin/public separation)
- **Naming:** All identifiers must use the `flxpnt` prefix (options, transients, hooks, CSS/JS handles)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| One-way sync (Flxpoint → WooCommerce) | Flxpoint is the catalog source of truth | — Pending |
| SKU-based matching, skip unmatched | Prevents unwanted product creation, only updates what already exists | — Pending |
| Images only (no details, pricing, inventory) | Focused scope for faster delivery | — Pending |
| Configurable download-or-link for images | Flexibility: some hosts have storage limits, others want local copies | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-21 after initialization*
