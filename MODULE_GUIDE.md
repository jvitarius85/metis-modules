# Module Development Guide

Modules provide functional capabilities within Métis.

## Structure

Example module layout:

```
module/
├── controller
├── service
├── api
├── ui
```

## Rules

Modules should:

- rely on core services
- avoid duplicated logic
- enforce permission checks
- maintain clear separation of responsibilities

## Website CMS Boundary

Website is the CMS module. CMS editor, rendering, template, revision, reusable
block, menu, media, banner, popup, redirect, and theme behavior belongs under:

```
system/modules/website
system/src/Metis/Modules/Website
```

Do not add a parallel CMS module or `metis_cms_*` AJAX actions. Editor/admin
actions should use Website AJAX names, Website permissions, and existing Secure
Enclave gates.

Website content that stores or renders rich text, HTML, reusable blocks,
templates, banners, popups, or web parts must pass through the shared runtime
sanitization boundary. Unsafe tags, event handlers, unsafe URL schemes, and
dangerous style values are stripped before public rendering.

Revision restore should write back through Website services, and revision
compare should show user-meaningful differences for title, slug/path, status,
template, structured blocks, and SEO fields when present.

## Example Modules

```
modules/
├── people
├── communications
├── donations
├── drive
├── governance
├── website
```
