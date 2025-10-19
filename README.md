# Meteotemplate Receiver (v1.8.0)

WordPress plugin to receive Meteobridge/Meteotemplate-style updates and store them locally. 
Provides a REST API, shortcode, and Gutenberg block with unit conversions (temp, pressure, wind, rain), 
wind direction (degrees/compass), and optional ingest security (IP/FQDN allowlists, PASS).

## Structure
```
src/                      # Plugin source files
  meteotemplate-receiver.php
  mt-block.js
  readme.txt              # WordPress readme (changelog, etc.)
dist/                     # Build outputs (.zip)
build.sh                  # Build script (bash)
Makefile                  # Convenience wrapper for build
.github/workflows/release.yml  # (optional) GitHub Actions packaging on tag
```

## Build (local)
Requirements: bash, `zip` utility.

```bash
./build.sh
# or
make build
```

The script reads the version from `src/meteotemplate-receiver.php`, creates a folder named `meteotemplate-receiver/` with the plugin files, and zips it to `dist/meteotemplate-receiver-<VERSION>.zip` (upload this in WP: Plugins → Add New → Upload Plugin).

## WordPress Endpoint
- Update: `/wp-json/meteotemplate/v1/update` (also supports `/update/api.php` for Meteobridge compatibility)
- Latest: `/wp-json/meteotemplate/v1/latest?keys=T,H,P`

## Shortcode
```text
[meteodata keys="T,H,P" format="inline" t_unit="F" p_unit="inHg" w_unit="mph" r_unit="in" decimals="1" dir_format="compass"]
```
Values are rendered without labels; units are appended (e.g., `66.6 °F`).

## Notes
- Incoming units are configurable in **Settings → Meteotemplate Receiver** and should match what Meteobridge sends.
- For ingest protection, use IP/CIDR or FQDN allowlists and optional `PASS` shared secret.
