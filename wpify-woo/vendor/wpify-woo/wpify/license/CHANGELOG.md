# Changelog

All notable changes to this package are documented here.

## [2.3.1]

### Removed
- The `woocommerce/action-scheduler` dependency. The license check only calls the Action Scheduler of WooCommerce (when it exists), so the bundled copy was never used and only added about 0.8 MB to every plugin.

## [2.3.0]

### Added
- When the server blocks the license script (e.g. HTTP 403 on the plugin's vendor folder), the license field says so and names the blocked file, instead of showing a bare text input.
- Polish, German, Hungarian and Romanian translations (alongside Czech and Slovak), also for the license field.
- A locale without its own translation uses another locale of the same language, e.g. de_AT, de_CH and de_DE_formal use the German one.

## [2.2.0]

### Added
- The license field shows whether WPify considers the license valid for this site and why not (activated for another domain, activation not found, subscription expired or on hold), together with the renewal date.

### Changed
- The stored validity (`valid`) always comes from WPify: from the regular update check (`wpify_updates_metadata` action of `wpify/updates`), from the settings field (saved when it differs) and from the scheduled license check, which now sends the site's domain. No extra request is made. The stored activation stays even when the license is not valid, so the validity recovers on its own once the license is renewed or activated for the domain.
- For a license activated for another domain or no longer found, the field offers "Activate domain".
- On multisite, the license always uses the main site's domain (license field, scheduled check, activation and deactivation links), the same as update checks of `wpify/updates`.
- On multisite, only a super admin can deactivate the license (it belongs to the whole network); others don't get the button.
- The scheduled license check also sends the plugin slug, so WPify answers it on the plugin's site like the update check.
- `get_license_domain()` and `current_user_can_deactivate()` are public, for plugins that build their own license links.

### Fixed
- Activations from 2.0.7 and older are no longer shown as "Not valid" in the license field.
- PHP strings of the package are translated (the translation file was looked up one directory too high).

## [2.1.0]

### Changed
- The license field also carries the shared WPify notice classes (`wpify-notice wpify-notice-success|warning|error`), so inside WPify plugins it takes their colors. Its own colors are defaults in `:where()` (zero specificity) for plugins without those styles, unchanged from before. The `active` / `warning` / `not-active` classes stay for older woo-core copies.
- Removed the unused `.wpify-woo-license-control` styles.

### Security
- Activation and deactivation callbacks require `manage_options` (`manage_network_options` for a network license on multisite). They run on `admin_init`, which also fires for unauthenticated `admin-ajax.php` requests.

### Fixed
- Deactivating the domain works: the callback no longer requires an activation token and checks the right query arg.
- Fixed the misplaced parenthesis in the activation check that skipped the `wpify-license-action` test.
- The plugin update cache is cleared after activation or deactivation, so updates become available (or stop) right away.
- `wpify-license-action` is removed from the URL after the redirect.
- Strings follow the admin's profile language (`determine_locale()`) instead of the site language.
- Without a license, the field no longer requests license details that always end with 404.
