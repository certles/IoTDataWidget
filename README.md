# IoT Data Widget — Home Assistant, MQTT & Smart Home Sensors

Show live values from your smart home devices — temperature, humidity, tank levels, device status — on any WordPress page, with a shortcode or a Gutenberg block.

[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/iot-data-widget?label=WordPress.org)](https://wordpress.org/plugins/iot-data-widget/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

> **This repository is a read-only mirror** of the free version published on [WordPress.org](https://wordpress.org/plugins/iot-data-widget/). Each release here is the exact code from the WordPress.org SVN tag. Development happens in a private repository.

## Install

Install from **Plugins → Add New** in your WordPress admin (search for “IoT Data Widget”) or download it from [WordPress.org](https://wordpress.org/plugins/iot-data-widget/). Requires WordPress 6.0+ and PHP 8.1+.

## What the free version includes

No time limit, no license, no limit on the number of connections:

- **Data sources:** Home Assistant (any entity, picked from a searchable list), push mode (your device or a Home Assistant automation sends readings to your site — works on any hosting), Generic REST (any JSON API).
- **Display styles:** Card, Status list and Gauge, with status rules (e.g. show “Running” with a green dot instead of `1`).
- **Setup wizard** from “no connection” to a working widget, shortcode, **Gutenberg block** and a diagnostics page.
- Credentials (Home Assistant tokens, API authorization headers) are stored encrypted.

A Pro version with more data sources (Shelly, Tasmota, ESPHome, Supla, MQTT), history charts and alerts is available at [wpveloxa.com](https://wpveloxa.com/iot-data-widget/).

## Links

- Demo: <https://demo-iot.wpveloxa.com/>
- Guide (Home Assistant sensor on WordPress): <https://wpveloxa.com/guides/home-assistant-sensor-wordpress/>
- Support: [WordPress.org support forum](https://wordpress.org/support/plugin/iot-data-widget/)

## Issues and contributions

Please report problems on the [WordPress.org support forum](https://wordpress.org/support/plugin/iot-data-widget/) — it is monitored there. Because this is a mirror, pull requests cannot be merged directly; describe the change in the forum or an issue instead.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). The bundled Freemius SDK (`vendor/freemius/`) is distributed under its own GPL-compatible license.
