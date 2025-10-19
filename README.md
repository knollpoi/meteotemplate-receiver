# Meteotemplate Receiver (v1.8.0)

WordPress plugin to receive Meteobridge/Meteotemplate-style updates and store them locally. 
Provides a REST API, shortcode, and Gutenberg block with unit conversions (temp, pressure, wind, rain), wind direction (degrees/compass), and optional ingest security (IP/FQDN allowlists, PASS).

The plugin was created to allow meteobridge to upload weather data to Wordpress using the Meteotemplate API. This plugin duplicates the API documented at https://www.meteotemplate.com/web/wiki/wikiAPI.php.

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


## Display Options

The Meteotemplate Receiver plugin lets you display the latest stored weather data using either a **shortcode** or a **Gutenberg block**. Both support unit conversions, formatting, and field selection.

---

### Shortcode Reference

The `[meteodata]` shortcode can be used in any page, post, or widget.

#### Syntax
```text
[meteodata key="FIELD" ...]
[meteodata keys="FIELD1,FIELD2,..." ...]
```

- `key` → Show a single measurement (e.g., `T` for temperature).
- `keys` → Show multiple fields (comma-separated). Takes precedence over `key`.

#### Attributes

| Attribute    | Values / Example | Description |
|--------------|-----------------|-------------|
| `format`     | `inline` (default), `list`, `table`, `json` | How to render values. |
| `decimals`   | integer (e.g. `1`) | Number of decimal places. |
| `suffix`     | e.g. `" °F"` | Extra text appended to a single value. |
| `t_unit`     | `C`, `F` | Convert temperature. |
| `p_unit`     | `hPa`, `mb`, `inHg`, `kPa` | Convert pressure. |
| `w_unit`     | `mps`, `kmh`, `mph`, `kn` | Convert wind speed/gust. |
| `r_unit`     | `mm`, `in` | Convert rainfall. |
| `dir_format` | `degrees`, `compass` | Wind direction format (raw degrees or 16-point compass). |

#### Supported Keys

- **Temperature**: `T`, `TMX` (max), `TMN` (min), `TIN` (indoor)  
- **Humidity**: `H`, `HIN` (indoor)  
- **Pressure**: `P`  
- **Wind**: `W` (speed), `G` (gust), `S` (direction)  
- **Rain**: `R` (total), `RR` (rate)  
- **Other**: `B` (brightness), `UV`, `SN` (snow), `SD` (snow depth), `L` (lightning), `NL` (noise), `SW` (software version)  

#### Examples

**Single temperature in °F**
```text
[meteodata key="T" t_unit="F" decimals="1"]
```
→ `66.6 °F`

**Multi-field inline summary**
```text
[meteodata keys="T,P,W,S" format="inline" t_unit="F" p_unit="inHg" w_unit="mph" dir_format="compass"]
```
→ `66.6 °F | 29.95 inHg | 12 mph | NW`

**Table with conversions**
```text
[meteodata keys="T,H,P,W,G,R" format="table" t_unit="F" p_unit="inHg" w_unit="mph" r_unit="in" decimals="1"]
```

| Value       |
|-------------|
| 66.6 °F     |
| 55 %        |
| 29.95 inHg  |
| 12 mph      |
| 18 mph      |
| 0.02 in     |

**JSON output**
```text
[meteodata keys="T,H,P" format="json"]
```
```json
{"T":"66.6 °F","H":"55 %","P":"29.95 inHg"}
```

---

### Gutenberg Block Reference

The block is called **Meteodata Card** and is available in the block editor under *Widgets*.  
It uses the same backend logic as the shortcode.

#### Block Options (Inspector Sidebar)

| Option                 | Values / Example         | Description |
|------------------------|--------------------------|-------------|
| **Measurements to display** | Checkboxes (T, H, P, W, G, S, R, RR, etc.) | Choose fields. |
| **Display style**      | `Inline`, `List`, `Table` | Rendering mode. |
| **Decimal places**     | `0`, `1`, `2`, …         | Round values. |
| **Temperature unit**   | `C`, `F`                 | Convert temps. |
| **Pressure unit**      | `hPa`, `mb`, `inHg`, `kPa` | Convert pressure. |
| **Wind speed unit**    | `mps`, `kmh`, `mph`, `kn` | Convert wind/gusts. |
| **Rainfall unit**      | `mm`, `in`               | Convert rainfall. |
| **Wind direction format** | `Degrees`, `Compass`   | Show `312 °` or `NW`. |

#### Display Behavior
- **Values only** with units (no labels). You can add labels in your page text.  
- **List style** → values in a bulleted list.  
- **Inline style** → values separated by `|`.  
- **Table style** → values in a one-column table.  
- Decimal rounding is applied *after* conversion.

#### Examples

**Inline block with T, P, W**
```
66.6 °F | 29.95 inHg | 12 mph
```

**List block with T, H, R**
- 66.6 °F  
- 55 %  
- 0.02 in  

**Table block with W, G, S**
| Value |
|-------|
| 12 mph |
| 18 mph |
| NW |

---

### Quick Reference

| Feature / Setting         | Shortcode Attribute                     | Block Option (Inspector)        | Notes |
|---------------------------|------------------------------------------|---------------------------------|-------|
| **Fields to display**     | `key="T"` or `keys="T,P,W"`             | Checkbox list (T, H, P, W, etc.) | Use `key` for single field or `keys` for multiple. |
| **Display style**         | `format="inline" \| list \| table \| json` | Inline / List / Table dropdown  | JSON available only in shortcode. |
| **Decimal places**        | `decimals="1"`                          | Numeric input (0, 1, 2, …)      | Applied after conversion. |
| **Temperature unit**      | `t_unit="C"` or `t_unit="F"`            | Select: C or F                  | Conversion depends on “Incoming Units” setting. |
| **Pressure unit**         | `p_unit="hPa"`/`mb`/`inHg`/`kPa`        | Select: hPa, mb, inHg, kPa      | Converts barometric pressure. |
| **Wind speed unit**       | `w_unit="mps"`/`kmh`/`mph`/`kn`         | Select: m/s, km/h, mph, kn      | Applies to W (speed) and G (gust). |
| **Rainfall unit**         | `r_unit="mm"` or `r_unit="in"`          | Select: mm or in                | Applies to R (total) and RR (rate). |
| **Wind direction format** | `dir_format="degrees"` or `compass`     | Select: Degrees or Compass      | Compass uses 16-point rose (e.g. NNE). |
| **Suffix** (single value) | `suffix=" °F"`                          | N/A                             | Appended to a single value. |
| **Labels**                | *(none; values only)*                   | *(none; values only)*           | You provide labels manually in your layout. |

---

⚠️ **Important:** Make sure **Incoming Units** in *Settings → Meteotemplate Receiver* match the units your Meteobridge sends. Conversion in both shortcode and block depends on this source unit configuration.
