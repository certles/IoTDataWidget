=== IoT Data Widget – Home Assistant, MQTT & Smart Home Sensors ===
Contributors: wpveloxa
Tags: home assistant, temperature, smart-home, mqtt, sensor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show Home Assistant and smart home sensor readings on your WordPress site with a shortcode or block. Pro adds more sources, history charts and alerts.

== Description ==

IoT Data Widget lets you embed current values from smart home sensors and devices — temperature, humidity, tank levels, device status, anything with a numeric or text value — on any WordPress page or post. Activating the plugin drops you straight into a guided setup wizard that gets you from "no connection" to a working widget and a ready-to-paste shortcode in a few clicks.

The free version is fully functional, not a crippled trial: unlimited connections, Home Assistant, push mode, Generic REST, the Card/Status list/Gauge display styles, the shortcode and Gutenberg block, the setup wizard, and the diagnostics page all work with no time limit and no license. Pro adds more data sources, history graphs and alerts.

= What you can build with it =

* A "conditions right now" box on a guesthouse or holiday-rental site: outdoor temperature, room temperatures, humidity.
* A status panel for a workshop, greenhouse or server room: a pump or relay shown as "Running" with a green dot, a tank level on a gauge.
* A kiosk tablet or lobby screen with a full-screen, theme-free page of current values (Pro).
* An alert by email or webhook when a freezer, aquarium or boiler leaves its safe range (Pro).

= Data sources =

* **Home Assistant** — any entity, picked from a searchable list.
* **Push mode** — your device, script or Home Assistant automation sends readings to your site; works from any hosting, with ready-to-paste snippets for Shelly, Tasmota, ESPHome and Home Assistant.
* **Generic REST** — any API that returns JSON, with or without authorization.
* **Device presets (Pro)** — Shelly, Tasmota and ESPHome (pick the device and enter its IP address, no JSONPath needed; Shelly Gen1 and ESPHome presets are in beta), and Supla (paste the channel's direct link from Supla Cloud).
* **Shelly Cloud (Pro)**.
* **MQTT (Pro)** — polled every 2 minutes, or real-time with the optional server-side listener (requires SSH access).

= Free =

No time limit, no license, unlimited connections: Home Assistant, push mode, Generic REST data sources; Card/Status list/Gauge display styles; status rules (a text and a status colour for a value or range); the setup wizard, shortcode, Gutenberg block, and diagnostics page.

= Pro =

* **More data sources** — device presets for Shelly, Tasmota, ESPHome and Supla, Shelly Cloud, and MQTT.
* **History graphs** — Chart and Mini trend (sparkline) display styles, plus Grid for entities from different connections in one widget.
* **Alerts and reports** — email or webhook alerts when a value crosses a threshold, and recurring email reports with min/max/average.
* **Embed and export** — a token-protected public embed page without the site theme, and CSV export of recorded history.
* **Manually tracked entities** — history and alerts for widgets placed outside post content, e.g. in theme templates.

Agency adds white-label branding on top of Pro. You can compare plans and upgrade from **IoT Data Widget → Upgrade** in your WordPress admin.

= External services =

This plugin connects to two services that are **not** the smart-home API/device you configure yourself:

* **Freemius** (licensing, update delivery, and — if you opt in — basic usage analytics). On first activation you're asked whether to allow anonymous diagnostic data (server environment, WordPress/PHP version, plugin version, site URL) to be sent — you can decline, and review/opt out again later from your account on freemius.com. No data about your connections, entities, or the values they return is ever included. [Terms of Service](https://freemius.com/terms/), [Privacy Policy](https://freemius.com/privacy/).
* **Open-Meteo** (optional, user-triggered) — clicking "Try it with demo data" in the setup wizard fetches live weather for a Warsaw coordinate pair from [Open-Meteo](https://open-meteo.com), purely to show a working example widget. No account or personal data involved. [Terms & Privacy](https://open-meteo.com/en/terms). Skip this step and no request is ever made.

Every other outbound request goes only to the URL/host **you** configure for a connection.

== Installation ==

1. Upload the `iot-data-widget` folder to `/wp-content/plugins/`, or install the zip through **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** screen — this opens the **setup wizard** automatically (skip this step by activating several plugins at once, or by clicking "Skip the wizard" on its first screen).
3. Follow the wizard: pick a connection type, configure and test it live, choose an entity, and copy the ready-made shortcode from the preview screen — or configure a connection manually under **IoT Data Widget** in the admin menu instead:
   * **Home Assistant**: enter your instance URL (e.g. `http://homeassistant.local:8123`) and a Long-Lived Access Token. Generate one in Home Assistant under your profile page → **Security** → **Long-Lived Access Tokens** → **Create Token**. If the URL is a local network address, also turn on **Settings → Allow local network addresses** (the wizard's "Same network as my devices" option does this for you).
   * **Push mode**: no URL/credentials to enter — the wizard generates a channel and a ready-to-paste snippet for your device instead.
   * **Generic REST, advanced mode**: enter the full URL of a JSON endpoint. Leave Authorization empty for public APIs, or enter a full header (e.g. `Authorization: Bearer xxx` or `X-API-Key: xxx`) if the API requires one.
4. Add the widget to a page (the wizard already does this for you, with a shortcode ready to paste):
   * **Gutenberg**: insert the "IoT Data Widget" block, pick your connection and entity in the sidebar — for Home Assistant, search and filter by domain instead of typing an entity ID.
   * **Shortcode**: use `[iotdw_widget]` directly — see the FAQ below for examples.

== Screenshots ==

1. Card, gauge and list widgets showing current values on a page.
2. The setup wizard previews the widget and gives you a ready-to-paste shortcode.
3. Insert the IoT Widget block and choose the connection, entity and style in the sidebar.
4. The wizard starts by asking where your site runs, so the device and WordPress can actually reach each other.

== Frequently Asked Questions ==

= Where's the human-readable source for the minified JavaScript? =

`blocks/iot-widget/build/index.js` (the Gutenberg block editor script) is a compiled bundle. Its full, human-readable source is included in this plugin's ZIP at `blocks/iot-widget/src/` — build it yourself with `npm install && npm run build` (standard `@wordpress/scripts` toolchain, see `package.json`).

= What shortcode examples are there? =

Card (single value): `[iotdw_widget connection="Home" entity="sensor.living_room_temperature"]`

Status list (several entities at once): `[iotdw_widget connection="Home" style="list" entities="sensor.temperature,sensor.humidity,sensor.battery"]`

Status list with your own labels (in the order of the entities; leave one empty to keep the device's name): `[iotdw_widget connection="Home" style="list" entities="sensor.temperature,sensor.humidity" labels="Living room,Humidity"]`

Gauge (numeric value with a min/max range): `[iotdw_widget connection="Home" style="gauge" entity="sensor.tank_level" min="0" max="100"]`

Generic REST (entity is a JSONPath into the JSON response): `[iotdw_widget connection="Weather API" entity="current_weather.temperature"]`

= Can a widget show "Running" with a green dot instead of 1? =

Yes — add status rules. In the block, open **Status rules** in the sidebar; in a shortcode, use the `states` attribute. Each rule is `condition:text:colour`, rules are separated by `;`, up to 5 per widget, and the first matching rule wins:

* A relay: `[iotdw_widget connection="Home" entity="switch.pump" states="0:Off:red;1:Running:green"]`
* Temperature thresholds, keeping the number and adding only a colour dot: `states="..18::grey;18..24::green;24..::red"`

A condition is a number (`1`), a range `18..24` (from 18, below 24), `..18` (below 18), `24..` (24 and above) or a state text such as `heat` or `off` (not case-sensitive, e.g. `states="heat:Heating:green;off:Off:grey"`). Colours: green, yellow, red, grey. Rules work in the Card, Status list and Gauge styles (in Gauge the status is shown under the dial). A widget without rules looks exactly as before.

Entities with a non-numeric state (a relay's on/off, a presence sensor's home/away...) show their real state ("ON", "home") in Card and Status list styles; a raw true/false from a JSON API shows as "On"/"Off".

= I don't want to use the setup wizard — can I skip it? =

Yes, every wizard screen has a "Skip the wizard, I'll configure manually" link that takes you straight to the regular Connections page, where everything works exactly as it did before the wizard existed. Nothing about manual configuration changed or was removed.

= Can I run the setup wizard again after my first connection? =

Yes — click "Run the setup wizard again" on the Connections page. It always creates a brand-new connection through the same guided steps; it never edits or overwrites one you already have.

= Why didn't activating the plugin open the setup wizard? =

If you activated several plugins at once (the "bulk actions" checkbox list on the Plugins screen), the wizard intentionally doesn't open — a redirect in the middle of a bulk action would be disruptive. Open it anytime from **IoT Data Widget → Setup Wizard** in the admin menu.

= How do I get a Home Assistant Long-Lived Access Token? =

In Home Assistant, click your username in the bottom-left corner to open your profile, scroll to **Security**, then **Long-Lived Access Tokens** → **Create Token**. Copy it immediately — Home Assistant only shows it once.

Home Assistant doesn't let you limit what a token can do — it inherits the full permissions of whichever account created it, and it never expires until you revoke it. We recommend creating a separate, non-administrator account in Home Assistant just for this token. If your WordPress site is on external/shared hosting, also consider push mode instead — it never stores a Home Assistant token at all.

= How are my device credentials protected? =

Credentials you enter (Home Assistant tokens and Generic REST authorization headers — plus, in Pro, MQTT broker passwords and Shelly Cloud keys) are encrypted before being stored in your WordPress database, using a key derived from your site's own configuration. This protects them if the database itself leaks — a backup, a database dump, a SQL injection in an unrelated plugin on the same install, or a staging clone. It does **not** protect against someone who has already fully compromised your server, since the encryption key lives on that same server.

If you ever uninstall the plugin, we recommend also revoking the Home Assistant token (and rotating any other credentials you entered) from the device/service side.

Push mode sidesteps this entirely: the plugin never stores any credentials to your devices, because your device sends data to the plugin instead of the other way around.

= My device is on the same network as WordPress, but the connection is refused =

For security, the plugin doesn't connect to local network addresses (like 192.168.x.x or homeassistant.local) unless you allow it. If WordPress runs on the same network as your devices — a Raspberry Pi, NAS or home server — turn on **IoT Data Widget → Settings → Allow local network addresses** (on a multisite network, only a network administrator can). Choosing "Same network as my devices" in the setup wizard does this for you. If your site is hosted externally, use push mode instead: it can't reach your home network anyway.

= Does this work with any REST API, or only Home Assistant? =

Both. The Generic REST connector (free) works with any API that returns JSON over HTTP(S), authenticated or not.

= Does the plugin send my data anywhere else? =

No. The values shown in your widgets never leave your own WordPress installation to any third party — the plugin only connects to the URL you configure in each connection. See "External services" in the description for the small number of exceptions.

= What happens if a connection or entity is misconfigured? =

The widget fails gracefully: site visitors simply see nothing where the widget would be, while logged-in administrators see a clear error message in its place.

= Will this hit my Home Assistant instance on every page view? =

No — responses are cached for 60 seconds by default (not configurable per widget in this version), so repeated page views within that window are served from cache.

= Something isn't working — what's the fastest way to get help? =

Go to **IoT Data Widget → Diagnostics** and click "Copy diagnostic report", then paste it into your support email or ticket. Tokens, keys and passwords are never included in the report.

= What languages does the plugin come with? =

English (default) and Polish. The Polish translation is included in the plugin and loads automatically on WordPress 6.7 or newer when your site language is Polish. Other languages can be contributed on [translate.wordpress.org](https://translate.wordpress.org/) — any string not yet translated falls back to English.

= What's the plugin's privacy policy? =

No telemetry beyond the opt-in Freemius diagnostics (see "External services" in the description); the plugin author never sees your data or connection details. Credentials are encrypted at rest. Fetched values are cached temporarily (WP Transients, 60s default), not logged or retained beyond that. Uninstalling leaves your data in place unless you check "Remove all plugin data" under Settings first. If the external API/instance you connect to processes personal data, your site's own privacy policy should disclose that connection.

== Changelog ==

= 1.0.12 =
* Fixed: the setup wizard no longer loses the whole selection when a Home Assistant entity name contains "<" (for example "Temp <20").
* Fixed: entity names that contain a percent sign followed by two characters (for example "Power 50%ab") are saved in full.
* Improved: an entity ID that contains characters the plugin cannot store is rejected with a clear message instead of being saved shortened.

= 1.0.11 =
* Fixed: in narrow containers and with larger text, "Data may be out of date" wraps inside the widget instead of being cut off or running outside it.

= 1.0.10 =
* Improved: when data may be out of date, Card, Status list and Gauge all show the time of the last update with the text "Data may be out of date", and the value is dimmed the same way in every style.
* Security: the connection form, configuration import and setup wizard sanitize each submitted field individually.

= 1.0.9 =
* Improved: consistent typography, spacing, units and data states for Card, Status list and Gauge, while preserving appearance settings.
* Fixed: small non-zero measurements, including negative values, retain their meaning when formatted and refreshed.
* Improved: Gauge provides an accessible measurement summary and keeps long messages separate from the arc in narrow containers.
* Improved: clearer empty and stale states without displaying missing measurements as zero.

= 1.0.8 =
* Fixed: the plans page ("Upgrade") shows the plugin icon instead of a generic one.
* Changed (Pro): Polish chart caption reads "Wykres historii: <label>", and an email report without data reads "W tym okresie nie zarejestrowano danych: <label>."

= 1.0.7 =
* Changed: Freemius SDK updated to 2.13.5.
* Changed: the plugin name now mentions Home Assistant and MQTT; the description lists every data source and typical uses.
* Changed: Polish translation — plainer, shorter messages (full sentences, one piece of information per sentence).

= 1.0.6 =
* New: status rules can match a state text, not only a number — for example `heat:Heating:green` for a climate device or `off:Off:grey`.
* New: the Status list style takes your own labels for its entries (`labels` attribute of the shortcode), instead of entity names such as `heat_pump`.
* Changed: the Polish translation uses "Pobierz plik CSV", as on translate.wordpress.org.
* Changed: the Polish default labels for on/off states are "Wł."/"Wył." — they fit any device name; your own labels can be set in the widget ("Label for on/off", or `on_label`/`off_label`). An out-of-date value is marked "nieaktualne".
* Fixed: an unavailable sensor ("unavailable"/"unknown", or text for a numeric entity) is no longer stored in the history as 0 — the widget keeps the last value and marks it as out of date.
* Fixed: push channels with a chart no longer store every reading twice (the 15-minute history snapshot skips them; push already records each reading).
* Fixed: chart times are shown in the site's time zone instead of UTC.

= 1.0.5 =
* New: an optional, dismissible request for a review after two weeks of use.
* Changed: the Polish translation follows the conventions of the Polish WordPress translation team.
* Fixed: "on"/"off" states are shown translated, and numbers use the site's decimal separator ("10,3" on a Polish site).
* Fixed: texts that come only with the Pro version (such as chart captions) are translated even when a WordPress.org language pack is installed.

= 1.0.4 =
* Added: status rules — show a text and a coloured status dot for a value or a range (for example "Running" with a green dot for a relay, or green/red for temperature thresholds); up to 5 rules per widget, in the block sidebar or with the shortcode's `states` attribute. Works in the Card, Status list and Gauge styles. Widgets without rules look exactly as before.
* Fixed: a unit set in the shortcode (`unit="°C"`) no longer disappears when the widget refreshes its value after the page loads.

= 1.0.3 =
* Added: Polish translation of the whole plugin — admin screens, setup wizard, widgets, the block editor, and the licensing screens (opt-in, account, upgrade) (WordPress 6.7 or newer).
* Changed: Pro-only styles in the block's style list are marked "(Pro)".

= 1.0.2 =
* Security: requests to local network addresses (192.168.x.x, 10.x.x.x, homeassistant.local…) are now blocked unless "Allow local network addresses" is turned on under Settings; cloud metadata and link-local addresses are always blocked, including via redirects. If this site already connects to a device on your local network, the setting is turned on automatically during the update — nothing stops working. Choosing "Same network as my devices" in the setup wizard turns it on too.
* Added: widgets placed in classic theme widget areas (sidebars, footers) now load their styles and refresh live — previously they appeared unstyled and never updated.
* Fixed: widgets line up with the content column in classic themes such as Twenty Twenty-One. Widgets placed one after another now stack vertically — use a Row or Columns block to place them side by side.
* Fixed: "Test connection" (and alert e-mails in Pro) show "On"/"Off" instead of "true"/"false" for on/off devices such as Shelly relays.
* Fixed: the free version no longer schedules or lists Pro-only background tasks (MQTT polling, email reports, value history); updating removes them.
* Fixed: the setup wizard labels Shelly Cloud and Supla as Pro, and shows an accurate message if the demo connection can't be saved.

= 1.0.1 =
* Added: "Upgrade" link in the plugin menu and the plugin list, and an upgrade link next to Pro-only styles (visible to administrators only).
* Fixed: database error when the same widget appears twice on a page — saving the page in the block editor no longer fails.
* Fixed: widget alignment in block themes such as Twenty Twenty-Five — Card and Gauge now line up with the content column, with even spacing between widgets.
* Fixed: the setup wizard now continues to entity selection after you configure a connection, instead of returning to the Connections list.

= 1.0.0 =
Initial release.

* Home Assistant, push mode, and Generic REST connectors — all free, with no connection limit.
* Guided setup wizard: pick a connection type, configure and test it live, choose an entity, and get a ready-to-paste shortcode before anything is saved.
* Card, Status list and Gauge display styles — free. Chart, Mini trend/sparkline, and Grid (recorded history) — Pro.
* `[iotdw_widget]` shortcode and a Gutenberg block with a searchable entity browser and live preview.
* Connection credentials are encrypted at rest.
* Built-in response caching, always on.
* Appearance personalization — site-wide defaults with a live preview, and per-widget overrides in the block editor.
* Diagnostics page with environment info, scheduled-task status, per-connection health (including push channel activity), and a one-click "Copy diagnostic report" button.

