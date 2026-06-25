=== Pipedrive Lead Forms ===
Contributors: wpmike
Tags: pipedrive, leads, forms, crm
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Configurable front-end forms that log every submission locally and create leads in Pipedrive, with bot protection and cache safe submission.

== Description ==

Pipedrive Lead Forms renders configurable front-end lead forms, logs every
submission to a custom database table, and pushes leads to Pipedrive with
retries. It is built to never lose a lead and to stay safe behind page
caches and CDNs.

Updates are delivered via GitHub, not wordpress.org.

== Installation ==

1. Upload the `pipedrive-lead-forms` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen in WordPress.
3. Configure your Pipedrive API token and forms under the plugin settings.

== Changelog ==

= 1.0.2 =
* Security audit pass: verified nonces, capability checks, input sanitization, output escaping and prepared SQL across the plugin. No vulnerabilities found.
* Hardening: documented the safe, constant table name database queries and other deliberate exceptions with justified phpcs annotations.
* Tooling: added a WordPress-Extra phpcs ruleset, composer dev dependencies and a GitHub Actions lint workflow so coding standards are enforced on every push. No functional changes for site visitors.

= 1.0.0 =
* Initial release.
* Front-end lead forms with local-first persistence and Pipedrive dispatch.
* Bot protection (honeypot, HMAC time token, rate limiting, optional Turnstile).
* GitHub based automatic updates via the Plugin Update Checker library.
