# Changelog

All notable changes to this package are documented here.

## 1.3.0

### Changed
- The logs screen (`Tools`) keeps using the shared WPify classes (`wpify-badge-*`, `wpify-notice-*`, `wpifycf-select`), so inside WPify plugins it takes their look, and ships its own defaults for them in `:where()` (zero specificity) for screens where those styles are not loaded. The defaults follow the WordPress admin palette.
- The DEBUG level uses `wpify-badge-light` instead of the non-existent `wpify-badge-default`.
- Inline styles moved to `wpify-log__*` classes.

### Security
- The logs screen requires `manage_options` by default instead of `read`. A custom `capability` in the menu args still takes precedence.
- Escaped the page title, file list, column headers and log context. A log entry containing HTML or `<script>` could break the screen or run in the admin.
- The selected log file is read from the validated path (strict `in_array`) instead of the raw request value.

### Fixed
- No PHP warning for an empty string value in a log entry.
- "Display log" button label is translatable.
