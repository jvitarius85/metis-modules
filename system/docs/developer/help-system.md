# Help System

## Register Help Topics

- Add `help_topics` to a module manifest to explicitly register topic ids.
- If a module omits `help_topics`, the help service falls back to `<module>.<view>` ids derived from manifest views.

## Create Walkthroughs

- Add walkthrough definitions to `docs/walkthroughs.json` or `help_custom_walkthroughs` in Settings.
- Each step should provide a CSS selector in `target`, a human-readable `message`, and an optional `advance` mode.

## Tag UI Elements

- Add `data-help="topic.id"` to buttons, links, tabs, and panels that need direct contextual help.
- Elements without an explicit tag inherit the current page topic through the help UI fallback layer.

## Extend Help Content

- Administrators can override descriptions, add custom topics, and add custom walkthroughs from Settings > Help.
- Extensions should reuse the shared help and walkthrough services rather than shipping separate tooltip or tour systems.
