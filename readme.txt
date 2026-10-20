=== Pipedrive Lead Forms ===
Contributors: wpmike
Tags: pipedrive, leads, forms, crm
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Configurable front-end forms that log every submission locally and create leads in Pipedrive, with file uploads, bot protection and cache safe submission.

== Description ==

Pipedrive Lead Forms renders configurable front-end lead forms, logs every
submission to a custom database table, and pushes leads to Pipedrive with
retries. It is built to never lose a lead and to stay safe behind page
caches and CDNs.

Forms support an optional file upload field. By default every file type that
WordPress considers safe is accepted; site owners can narrow the allowed file
types and set a maximum file size in the settings. Uploaded files are stored in
a protected directory, attached to the backup email and attached to the
Pipedrive lead.

A Hidden field type sends a fixed value with every submission, for example to
prefill a Pipedrive organization field. The value is set in the form editor,
added on the server and never shown to the visitor.

= Cookies =

The plugin reads cookies that other plugins have stored in the visitor's
browser and sends their values with the lead. It never sets cookies itself.
Typical use: the UTM values of HandL UTM Grabber.

Which cookies are read is set under Settings > Cookies. By default these are
the standard UTM parameters and the Google Ads click ID: utm_source,
utm_medium, utm_campaign, utm_term, utm_content and gclid. With HandL UTM
Grabber, add handl_original_ref (where the visitor originally came from) and
handl_landing_page (the first page visited) to also send the referrer and
landing page. Their values are read when the form is submitted and appear as a "Cookies" block in the Pipedrive lead note, the
backup email and the submission log. Leave the list empty to add nothing.

HandL only stores its values after the visitor gave marketing consent, so
without consent nothing is sent. HandL keeps each parameter separately: a later
visit with only utm_source updates utm_source and keeps the earlier
utm_campaign.

The note is readable but cannot be filtered in Pipedrive. To report on a value,
add a "Cookie value" field to the form: pick the cookie name from the
suggestions (the list from the settings) or type any other name, and map the
field to a Pipedrive lead field. Leads share their custom fields with deals, so
create the fields in Pipedrive as deal fields first.

To keep a value out of the note, remove it with the `pdlead_cookie_data`
filter:

`add_filter( 'pdlead_cookie_data', function ( $data ) { unset( $data['gclid'] ); return $data; } );`

Updates are delivered via GitHub, not wordpress.org.

== Installation ==

1. Upload the `pipedrive-lead-forms` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen in WordPress.
3. Configure your Pipedrive API token and forms under the plugin settings.

= Hosting notes =

* Behind Cloudflare, add `define( 'PDLEAD_TRUST_CF_IP', true );` to wp-config.php so the rate limit sees each visitor's real IP address. The settings page shows a reminder when Cloudflare is detected.
* Uploaded files are stored in `wp-content/uploads/pdlead-uploads/` under random names. Apache is blocked from serving them by an .htaccess file. On nginx, add a rule such as `location ^~ /wp-content/uploads/pdlead-uploads/ { deny all; }` to the server block; files are downloaded through the submission log either way.
* WP Rocket works without extra settings: the plugin excludes its script, its config and Turnstile from "Delay JavaScript execution", keeps its styles in "Remove Unused CSS" and clears the WP Rocket cache when a form or the settings are saved. With another page cache, clear the cache after changing a form, since the form is part of the cached page.
* Leads are sent to Pipedrive right after the visitor gets the response (PHP-FPM or LiteSpeed). Failed attempts are retried by WP-Cron, so on a site with WP-Cron disabled a real system cron must call wp-cron.php.

== Changelog ==

= 1.8.0 =
* New: the cookies the plugin reads are now set under Settings > Cookies instead of being fixed in the code. The list defaults to the standard UTM parameters and gclid. The same list feeds the suggestions of Cookie value fields in the form editor. An empty list adds no cookies to the note. Below the field, the settings page lists common cookies to add (Google Analytics, Google Ads, Meta, LinkedIn, Microsoft Ads, TikTok, HubSpot and HandL UTM Grabber).
* Changed: handl_landing_page and handl_original_ref (HandL UTM Grabber only) are no longer read by default. Sites using HandL that want the landing page and original referrer in the note add both names under Settings > Cookies after updating.
* Changed: the note, backup email and submission log block is now called "Cookies" instead of "Tracking", since the cookies can hold any value.
* Removed: the "Company domain" setting. The API token already identifies the Pipedrive account, so all requests now go to the shared host api.pipedrive.com. A previously saved domain is ignored and dropped on the next save; nothing needs to be changed.
* Changed: the filter `pdlead_tracking_data` is renamed to `pdlead_cookie_data`. The old name still works and triggers a deprecation notice.
* Changed: leads are sent to Pipedrive after the visitor already has the response, so a slow Pipedrive API no longer delays or times out the form.
* Changed: submissions over the rate limit are no longer rejected. They are stored with the new status "Held", are not sent to Pipedrive, and can be released with "Send to Pipedrive" in the submission log. Behind a proxy or office network real visitors share one IP address, so no lead is lost to the limit any more. Beyond three times the limit submissions are still rejected, so a flood cannot fill the log, and the backup email of a held submission carries no attachments. Turnstile is now checked before the rate limit.
* New: "Discard" for held and failed submissions in the log, after a confirmation. It marks them as spam, so they are never sent and the regular cleanup removes them.
* New: WP Rocket support out of the box. The form script, its config and Turnstile are excluded from "Delay JavaScript execution", the form styles are kept by "Remove Unused CSS", and the WP Rocket cache is cleared when a form or the plugin settings are saved.
* Fix: a bot flooding the honeypot far over the rate limit no longer adds a spam row per request.
* Changed: lead forms can only be created and edited by administrators.
* New: an admin notice on every admin screen lists failed, held and long waiting submissions, so a problem is noticed even when no email arrives.
* New: the submission log shows a warning when the lead note or a file attachment could not be added in Pipedrive.
* New: form validation errors name the affected fields: they are marked, focused and announced to screen readers; files over the upload limits are caught before sending with a clear message.
* Fix: uploaded files are stored under random names, so they cannot be guessed on servers that ignore .htaccess (nginx). Files uploaded before 1.8.0 keep their names: on nginx add the deny rule from the hosting notes.
* Fix: the form now also works when an optimizer loads the script late, and never falls back to sending the values in the page URL.
* Fix: several forms on one page, or the same form twice, no longer share element IDs.
* Fix: Turnstile only runs when it is fully configured, and a wrong secret key or a Cloudflare problem no longer blocks every visitor; such submissions are flagged instead, and an admin notice reports a secret key Cloudflare rejects.
* Fix: a request over the server upload limit (PHP or nginx) now reports that the files are too large instead of an expired session or a generic error.
* Fix: email addresses with characters such as umlauts are rejected instead of being silently changed into a different address.
* Fix: text values are capped (255 characters, 20,000 for text areas) so Pipedrive does not reject them.
* Fix: a retry after the organization was already created keeps the organization values in the note.
* Fix: a submission whose form was deleted fails with a clear message instead of creating an empty lead.
* Fix: file fields always attach their files, whatever they are mapped to, and a new file field is mapped to "Lead: File attachment" by default.
* Fix: cookie names containing a dot are read correctly.
* Fix: the form inherits the theme's font, keeps the theme's text colors and uses the theme's button style on block themes.
* Fix: uninstalling also deletes the uploaded files, also where WordPress has no direct filesystem access.

= 1.7.0 =
* New: map form fields to Pipedrive lead fields (the custom fields leads share with deals), next to the person and organization targets. Choice fields match the Pipedrive option labels. Values Pipedrive cannot accept are added to the lead note instead; if Pipedrive rejects the lead over a field value, the lead is created without the mapped lead fields and all their values go to the note, so no lead is lost.
* New: Cookie value field type. It reads the value of a named cookie at submission (for example utm_source from HandL UTM Grabber) and can be mapped like any other field, so campaign data lands in real Pipedrive fields. The cookie name can be picked from the HandL UTM Grabber names or typed freely for any other plugin. Never shown to visitors and cannot be changed through the form.
* Changed: "Test Pipedrive connection" also reloads the lead fields offered in the form editor.
* Fix: a lead label, default owner or custom field value that no longer exists in the connected Pipedrive account (for example "Provided labelId does not exist" after a label was deleted or the API token was switched) no longer makes the submission fail. Saved lead labels are checked against Pipedrive before sending and unknown ones are skipped, so everything else still arrives. If Pipedrive still rejects the lead, it is created once more without labels, owner, custom field and mapped lead fields, and the mapped values go to the note. The settings page also points out saved lead labels that no longer exist.
* New: the submission log shows a warning on sent leads when something did not reach Pipedrive as mapped: skipped labels, field values Pipedrive did not accept (listed by field), or what was dropped after a rejected lead.

= 1.6.0 =
* New: map form fields to Pipedrive organization fields, including custom fields and choice fields (Select options matching the Pipedrive option labels). Values Pipedrive cannot accept, and all values when an organization with the same name already exists, are added to the lead note instead. "Test Pipedrive connection" reloads the available organization fields.
* Changed: organizations and persons are created through the Pipedrive API v2, as their v1 endpoints are out of support. Leads, notes, files, labels and users stay on v1.
* New: Hidden field type. Its fixed value is set in the form editor (Options / value column), added on the server at submission and never shown to visitors, so it cannot be changed from the browser.
* New: campaign tracking. The UTM values, gclid, landing page and original referrer stored by HandL UTM Grabber are attached to every submission and shown as a "Tracking" block in the lead note, the backup email and the submission log. The plugin only reads existing cookies and never sets any.
* New: filter `pdlead_tracking_data` to change or remove tracking values before they are stored and sent.

= 1.5.0 =
* New: custom field support. Pick one of your Pipedrive custom option fields (leads share them with deals) and its value(s) in the settings; every new lead is created with that field set. Single-choice fields render as radios, multi-choice fields as checkboxes.
* Changed: settings are now validated against the connected Pipedrive account on save. Lead labels, the default owner ID and the selected custom field and its values that do not exist in the connected account (for example after switching the API token to a different account) are removed with an admin warning instead of being kept and breaking lead creation with "Provided labelId does not exist". When the Pipedrive API cannot be reached during save, stored values are kept unchanged, so a temporary outage never wipes your settings.
* Improvement: the settings page now shows admin notices, including the standard "Settings saved." confirmation and the new validation warnings.

= 1.4.0 =
* New: lead labels are now selected by name in the settings. The free-text "Lead label IDs" field is replaced by a checkbox list populated from your Pipedrive account, so you no longer have to look up and type label IDs. Existing saved label IDs keep working unchanged, and labels deleted in Pipedrive are preserved. When no valid API token is configured the selector shows a hint instead and never overwrites your saved labels.

= 1.3.0 =
* New: lead visibility setting. Choose whether new leads, persons and organizations are visible to the owner only or shared with the owner's visibility group (optionally including sub-groups, or the entire company). Defaults to Pipedrive's own default, so existing installs keep their current behavior until changed. Visibility groups require a Professional or Enterprise Pipedrive plan; on lower plans the group option behaves like "Entire company".

= 1.2.1 =
* New: form fields can now be reordered by drag and drop in the form settings. Grab the handle on the left of a field row to change the order; the new order is used both in the editor and on the front-end form.

= 1.2.0 =
* New: per-form submission messages. The success, validation, general error and sending texts can now be set individually for each form, with HTML links allowed and a fallback to the built-in defaults when left empty.
* New: each field label is also used as the input placeholder for text, email, tel and textarea fields.
* New: the submit button stays disabled until all required fields are filled.
* Improvement: the consent text is now a full-width textarea shown in its own row only for consent fields, moved out of the "Maps to" column.
* Improvement: the success and error message now appears below the submit button.

= 1.1.1 =
* Fix: the release packaging excluded the bundled update checker `vendor` directory, causing a fatal "Class PucReadmeParser not found" error when checking for updates. The `.distignore` rule is now anchored to the repository root so only the development tooling `vendor` directory is omitted.

= 1.1.0 =
* New: file upload form field with support for multiple files per field.
* Uploads default to all file types WordPress considers safe; allowed file types and a maximum file size are configurable in the settings.
* Uploaded files are stored in a protected uploads directory, attached to the backup email, and attached to the Pipedrive lead. They are removed again when their submission is cleaned up by the retention policy.

= 1.0.2 =
* Security audit pass: verified nonces, capability checks, input sanitization, output escaping and prepared SQL across the plugin. No vulnerabilities found.
* Hardening: documented the safe, constant table name database queries and other deliberate exceptions with justified phpcs annotations.
* Tooling: added a WordPress-Extra phpcs ruleset, composer dev dependencies and a GitHub Actions lint workflow so coding standards are enforced on every push. No functional changes for site visitors.

= 1.0.0 =
* Initial release.
* Front-end lead forms with local-first persistence and Pipedrive dispatch.
* Bot protection (honeypot, HMAC time token, rate limiting, optional Turnstile).
* GitHub based automatic updates via the Plugin Update Checker library.
