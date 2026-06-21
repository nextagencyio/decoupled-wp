# Known issue: "Tab name duplication for Content" (Carbon Fields warning)

## Symptom

Admin-only yellow notice in wp-admin:

> Your site seems to be slightly misconfigured.
> Carbon Fields library encountered errors that may prevent your custom
> fields or theme options to work properly. Here's a quick summary of the
> issue:
> **Tab name duplication for Content**

## Impact

Cosmetic. Admin-only. Does **not** affect:

- the block/page editor or any custom field behavior,
- content data,
- GraphQL / the REST API,
- the public decoupled frontend.

A full space reset (wipe DB + re-import the model + content) does **not**
clear it — proving it's plugin-level, not stale data or a specific model.

## Root cause

Carbon Fields throws this when two tabs registered on the **same container
(post type)** share the same label.

In dc-core, page/resource field groups are registered from config in
`includes/fields-page.php` (`dc_register_configured_field_group()`). Each
tab's label came from:

```php
$container->add_tab(__((string) ($tab['label'] ?? 'Content'), 'dc-core'), $fields);
```

The `?? 'Content'` fallback means **any tab whose config omits `label`
becomes "Content"**. A model with two or more unlabeled tabs (common in
imported CAPER-style models) therefore registers two tabs both named
"Content" → duplication → the warning.

The config validator (`includes/config/loader.php`, the `$tabs` loop ~L253)
checks tab structure but does **not** require or dedupe tab labels, so the
collision passes validation and only surfaces at registration time.

## Fix

Dedupe tab labels at registration so collisions auto-suffix
("Content", "Content 2", …) instead of duplicating. Implemented in
`includes/fields-page.php` (see the `$used_tab_labels` map in
`dc_register_configured_field_group()`). Defensive at the right layer:
the warning becomes impossible regardless of what a model supplies.

(Optionally, the validator in `config/loader.php` could also reject or
normalize duplicate/empty tab labels at import time — not required once the
registration-side dedupe is in place.)

## Rollout note

The fix is plugin-side. It only reaches existing WordPress tenants after the
`decoupled-wp` image is rebuilt and rolled out (see CLAUDE.md "WordPress
updates" / "Rolling new image out to existing tenants"). Because the issue is
purely cosmetic, it can safely ride along with the next planned WP plugin
update rather than warranting a dedicated fleet deploy.
