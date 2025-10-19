<?php
/**
 * Plugin Name: Meteotemplate Receiver
 * Description: Receives Meteobridge/Meteotemplate-style updates, stores locally with retention; displays latest via shortcode or Gutenberg block; supports unit conversions (temp, pressure, wind, rain), IP/CIDR & FQDN allowlists, optional PASS, and wind direction as degrees or compass.
 * Version: 1.8.0
 * Author: TBL Farms / Kevin Noll
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

class MT_Receiver_StoreOnly_1_8_0 {
    const OPT_GROUP    = 'mt_rx_opts';
    const OPT_RET      = 'mt_rx_retention_days';
    const OPT_SRC_T    = 'mt_rx_src_temp';
    const OPT_SRC_P    = 'mt_rx_src_press';
    const OPT_SRC_W    = 'mt_rx_src_wind';
    const OPT_SRC_R    = 'mt_rx_src_rain';

    const OPT_ENF_IP   = 'mt_rx_enforce_ip';
    const OPT_ALLOWIP  = 'mt_rx_allow_ipcidr';
    const OPT_ENF_DNS  = 'mt_rx_enforce_dns';
    const OPT_ALLOWDNS = 'mt_rx_allow_fqdn';
    const OPT_REQPASS  = 'mt_rx_require_ingest_pass';
    const OPT_PASS     = 'mt_rx_ingest_pass';

    const CRON_HOOK    = 'mt_rx_purge_old';
    const DB_VER       = '3';

    private $table;

    private $allowed_params = [
        'U','PASS','T','TMX','TMN','H','P','W','G','B','R','RR','S','UV','SS','CC',
        'TIN','HIN','SN','SD','L','NL','SW'
    ];

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mt_relay_readings';

        add_action('admin_menu',      [$this, 'add_admin_menu']);
        add_action('admin_init',      [$this, 'register_settings']);
        add_action('rest_api_init',   [$this, 'register_rest']);
        add_action('init',            [$this, 'register_shortcode']);
        add_action('init',            [$this, 'register_block']);
        add_action(self::CRON_HOOK,   [$this, 'purge_old_rows']);

        register_activation_hook(__FILE__,   [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
        register_uninstall_hook(__FILE__,    [self::class, 'on_uninstall']);
    }

    public function on_activate() {
        $this->maybe_create_table();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
        if (get_option(self::OPT_RET, null) === null) add_option(self::OPT_RET, 30);
        foreach ([self::OPT_SRC_T => 'C', self::OPT_SRC_P => 'hPa', self::OPT_SRC_W => 'kmh', self::OPT_SRC_R => 'mm'] as $k=>$v) {
            if (get_option($k, null) === null) add_option($k, $v);
        }
        foreach ([self::OPT_ENF_IP, self::OPT_ENF_DNS, self::OPT_REQPASS] as $flag) {
            if (get_option($flag, null) === null) add_option($flag, 0);
        }
        foreach ([self::OPT_ALLOWIP, self::OPT_ALLOWDNS, self::OPT_PASS] as $k) {
            if (get_option($k, null) === null) add_option($k, '');
        }
    }

    public function on_deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    public static function on_uninstall() { }

    private function maybe_create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $this->table;
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            u_unix BIGINT NULL,
            sw VARCHAR(64) NULL,
            client_ip VARBINARY(16) NULL,
            params_json LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY received_at (received_at),
            KEY u_unix (u_unix),
            KEY sw (sw)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('mt_rx_db_ver', self::DB_VER);
    }

    public function add_admin_menu() {
        add_options_page(
            'Meteotemplate Receiver v1.8.0',
            'Meteotemplate Receiver',
            'manage_options',
            'mt-rx',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPT_GROUP, self::OPT_RET, ['type'=>'integer','sanitize_callback'=>function($v){ $v=(int)$v; return max(1,min(3650,$v)); }]);
        foreach ([self::OPT_SRC_T, self::OPT_SRC_P, self::OPT_SRC_W, self::OPT_SRC_R] as $k) {
            register_setting(self::OPT_GROUP, $k, ['type'=>'string','sanitize_callback'=>[$this,'sanitize_choice']]);
        }
        register_setting(self::OPT_GROUP, self::OPT_ENF_IP,  ['type'=>'boolean','sanitize_callback'=>function($v){ return (int)!empty($v); }]);
        register_setting(self::OPT_GROUP, self::OPT_ALLOWIP, ['type'=>'string','sanitize_callback'=>[$this,'sanitize_multiline']]);
        register_setting(self::OPT_GROUP, self::OPT_ENF_DNS, ['type'=>'boolean','sanitize_callback'=>function($v){ return (int)!empty($v); }]);
        register_setting(self::OPT_GROUP, self::OPT_ALLOWDNS,['type'=>'string','sanitize_callback'=>[$this,'sanitize_multiline']]);
        register_setting(self::OPT_GROUP, self::OPT_REQPASS, ['type'=>'boolean','sanitize_callback'=>function($v){ return (int)!empty($v); }]);
        register_setting(self::OPT_GROUP, self::OPT_PASS,    ['type'=>'string','sanitize_callback'=>function($v){ return (string)$v; }]);
    }

    private function options_html($options, $selected){ $out=''; foreach($options as $val=>$label){ $out.='<option value="'.esc_attr($val).'" '.selected($val,$selected,false).'>'.esc_html($label).'</option>'; } return $out; }
    public function sanitize_choice($v){ return preg_replace('/[^A-Za-z]/','',$v); }
    public function sanitize_multiline($txt){ $txt=str_replace("\r\n","\n",(string)$txt); $lines=array_filter(array_map('trim', preg_split('/[\n,]+/',$txt))); return implode("\n", array_unique($lines)); }

    public function render_settings_page() {
        if(!current_user_can('manage_options')) return;
        $active = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        echo '<div class="wrap">';
        echo '<h1>Meteotemplate Receiver v1.8.0</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="'.esc_url(admin_url('options-general.php?page=mt-rx&tab=settings')).'" class="nav-tab '.($active==='settings'?'nav-tab-active':'').'">Settings</a>';
        echo '<a href="'.esc_url(admin_url('options-general.php?page=mt-rx&tab=security')).'" class="nav-tab '.($active==='security'?'nav-tab-active':'').'">Security</a>';
        echo '<a href="'.esc_url(admin_url('options-general.php?page=mt-rx&tab=changelog')).'" class="nav-tab '.($active==='changelog'?'nav-tab-active':'').'">Changelog</a>';
        echo '</h2>';
        if ($active==='changelog') { $this->render_changelog_tab(); }
        elseif ($active==='security') { $this->render_security_tab(); }
        else { $this->render_settings_tab(); }
        echo '</div>';
    }

    private function render_settings_tab(){
        echo '<form method="post" action="options.php">'; settings_fields(self::OPT_GROUP);
        echo '<h2 class="title">Receiver Settings</h2>';
        echo '<table class="form-table">';
        $v=(int)get_option(self::OPT_RET,30);
        echo '<tr><th><label for="ret">Retention (days)</label></th><td><input id="ret" type="number" min="1" max="3650" name="'.esc_attr(self::OPT_RET).'" value="'.esc_attr($v).'" style="width:100px"></td></tr>';
        $t = esc_attr(get_option(self::OPT_SRC_T,'C')); $p = esc_attr(get_option(self::OPT_SRC_P,'hPa')); $w = esc_attr(get_option(self::OPT_SRC_W,'kmh')); $r = esc_attr(get_option(self::OPT_SRC_R,'mm'));
        echo '<tr><th>Incoming Units</th><td><div style="display:flex; gap:16px; flex-wrap:wrap;">';
        echo '<label>Temperature <select name="'.esc_attr(self::OPT_SRC_T).'">'.$this->options_html(['C'=>'°C','F'=>'°F'],$t).'</select></label>';
        echo '<label>Pressure <select name="'.esc_attr(self::OPT_SRC_P).'">'.$this->options_html(['hPa'=>'hPa','mb'=>'mb','inHg'=>'inHg','kPa'=>'kPa'],$p).'</select></label>';
        echo '<label>Wind <select name="'.esc_attr(self::OPT_SRC_W).'">'.$this->options_html(['mps'=>'m/s','kmh'=>'km/h','mph'=>'mph','kn'=>'knots'],$w).'</select></label>';
        echo '<label>Rain <select name="'.esc_attr(self::OPT_SRC_R).'">'.$this->options_html(['mm'=>'mm','in'=>'inches'],$r).'</select></label>';
        echo '</div><p class="description">These define the units your Meteobridge sends. Shortcodes/blocks can convert on display.</p></td></tr>';
        echo '</table>'; submit_button('Save Settings'); echo '</form>';
    }

    private function render_security_tab(){
        echo '<form method="post" action="options.php">'; settings_fields(self::OPT_GROUP);
        echo '<h2 class="title">Ingest Security</h2><table class="form-table">';
        $checked_ip = checked(1,(int)get_option(self::OPT_ENF_IP,0),false);
        echo '<tr><th>IP/CIDR allowlist</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_ENF_IP).'" value="1" '.$checked_ip.'> Enforce IP/CIDR allowlist</label>';
        echo '<p><textarea name="'.esc_attr(self::OPT_ALLOWIP).'" rows="4" style="width:520px;" placeholder="Examples:&#10;203.0.113.45&#10;192.0.2.0/24&#10;2001:db8::/32">'.esc_textarea(get_option(self::OPT_ALLOWIP,'')).'</textarea></p>';
        echo '</td></tr>';
        $checked_dns = checked(1,(int)get_option(self::OPT_ENF_DNS,0),false);
        echo '<tr><th>FQDN allowlist</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_ENF_DNS).'" value="1" '.$checked_dns.'> Enforce FQDN allowlist</label>';
        echo '<p><textarea name="'.esc_attr(self::OPT_ALLOWDNS).'" rows="3" style="width:520px;" placeholder="Examples:&#10;mbridge.example.com&#10;weather-uplink.isp.net">'.esc_textarea(get_option(self::OPT_ALLOWDNS,'')).'</textarea></p>';
        echo '<p class="description">Hostnames are resolved to A/AAAA on first use and cached for 5 minutes.</p>';
        echo '</td></tr>';
        $checked_pass = checked(1,(int)get_option(self::OPT_REQPASS,0),false);
        echo '<tr><th>PASS authentication</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_REQPASS).'" value="1" '.$checked_pass.'> Require PASS to match</label>';
        echo '<p><input type="text" style="width:520px" name="'.esc_attr(self::OPT_PASS).'" value="'.esc_attr(get_option(self::OPT_PASS,'' )). '" placeholder="long-random-shared-secret"></p>';
        echo '<p class="description">PASS must be included as &amp;PASS=...; recommend HTTPS.</p>';
        echo '</td></tr></table>'; submit_button('Save Security Settings'); echo '</form>';
    }

    private function render_changelog_tab(){
        echo '<div class="card" style="max-width:900px;">';
        echo '<h2>Changelog</h2>';
        echo '<h3>v1.8.0</h3><ul>';
        echo '<li>Fix: PHP parse errors (replaced non-PHP operators, removed ellipses).</li>';
        echo '<li>Fix: Shortcode conversions apply to list/table/inline with keys=…</li>';
        echo '</ul></div>';
    }

    public function register_rest() {
        register_rest_route('meteotemplate/v1','/update',[
            'methods'=>['POST','GET'],
            'callback'=>[$this,'handle_update'],
            'permission_callback'=>function(){ return true; },
        ]);
        register_rest_route('meteotemplate/v1','/update/api.php',[
            'methods'=>['POST','GET'],
            'callback'=>[$this,'handle_update'],
            'permission_callback'=>function(){ return true; },
        ]);
        register_rest_route('meteotemplate/v1','/latest',[
            'methods'=>['GET'],
            'callback'=>function(\WP_REST_Request $req){
                $keys = $req->get_param('keys');
                $keys = is_string($keys) ? array_filter(array_map('trim', explode(',', $keys))) : [];
                $data = $this->get_latest_row($keys);
                if (is_wp_error($data)) return $data;
                return new \WP_REST_Response($data, 200);
            },
            'permission_callback'=>function(){ return true; },
        ]);
    }

    public function handle_update(\WP_REST_Request $req){
        $client_ip_str = $this->get_client_ip();
        $client_ok = $this->check_client_allowed($client_ip_str);
        if (is_wp_error($client_ok)) {
            return new \WP_REST_Response(['result'=>'Forbidden: '.$client_ok->get_error_message()], 403);
        }
        if ((int)get_option(self::OPT_REQPASS,0) === 1) {
            $provided = $this->extract_inbound_pass($req);
            $expected = (string)get_option(self::OPT_PASS,'');
            if ($expected === '' || $provided === null || !hash_equals($expected, $provided)) {
                return new \WP_REST_Response(['result'=>'Unauthorized'], 401);
            }
        }

        $incoming = $this->collect_params_from_request($req);
        $filtered = $this->filter_and_normalize_params($incoming);
        $u_unix = isset($filtered['U']) ? (int)$filtered['U'] : null;
        $sw     = isset($filtered['SW']) ? substr((string)$filtered['SW'], 0, 64) : null;
        $ip     = $this->ip_to_binary($client_ip_str);
        $stored = $this->store_row($filtered,$u_unix,$sw,$ip);
        if (is_wp_error($stored)) return $stored;

        return new \WP_REST_Response(['stored_id'=>$stored,'result'=>'Stored locally'],200);
    }

    private function extract_inbound_pass(\WP_REST_Request $req) {
        $json  = (array) $req->get_json_params();
        $body  = (array) $req->get_body_params();
        $query = (array) $req->get_query_params();
        $all   = array_change_key_case(array_merge($json ?: [], $body ?: [], $query ?: []), CASE_UPPER);
        return isset($all['PASS']) ? (string)$all['PASS'] : null;
    }

    private function collect_params_from_request(\WP_REST_Request $req){
        $json = $req->get_json_params();
        $body = (is_array($json) && !empty($json)) ? $json : $req->get_body_params();
        $query = $req->get_query_params();
        if (!empty($query)) $body = array_merge($body ?: [], $query);
        $out = [];
        foreach (($body ?: []) as $k=>$v){
            $k = strtoupper(trim((string)$k));
            if (is_scalar($v) || (is_object($v) && method_exists($v,'__toString'))) { $out[$k] = (string)$v; }
        }
        return $out;
    }

    private function filter_and_normalize_params(array $params){
        $filtered = [];
        foreach ($params as $k=>$v){
            if ($k === 'PASS') continue;
            if ($this->is_allowed_key($k)){
                if (is_string($v) && preg_match('/^-?\d+,\d+$/',$v)) $v = str_replace(',', '.', $v);
                $filtered[$k] = $v;
            }
        }
        if (!isset($filtered['U'])) $filtered['U'] = time();
        return $filtered;
    }

    private function is_allowed_key($k){
        if (in_array($k,$this->allowed_params,true)) return true;
        $patterns = [
            '/^(T|H)\d+$/','/^TS\d+$/','/^TSD\d+$/','/^LW\d+$/','/^LT\d+$/','/^SM\d+$/',
            '/^CO2_\d+$/','/^NO2_\d+$/','/^CO_\d+$/','/^SO2_\d+$/','/^O3_\d+$/','/^PP\d+$/',
            '/^[A-Z0-9]+BAT$/'
        ];
        foreach ($patterns as $p){ if (preg_match($p,$k)) return true; }
        return false;
    }

    private function store_row(array $filtered,$u_unix,$sw,$ip_bin){
        global $wpdb;
        $data = [
            'received_at'=> current_time('mysql',true),
            'u_unix'     => $u_unix ?: null,
            'sw'         => $sw ?: null,
            'client_ip'  => $ip_bin,
            'params_json'=> wp_json_encode($filtered, JSON_UNESCAPED_SLASHES)
        ];
        $fmt = ['%s','%d','%s','%s','%s'];
        $ok = $wpdb->insert($this->table,$data,$fmt);
        if ($ok === false) return new \WP_Error('mt_db_insert_failed','Failed to store reading.');
        set_transient('mt_rx_latest', $data['params_json'], 60);
        return (int)$wpdb->insert_id;
    }

    private function get_latest_row(array $keys = []){
        $cached = get_transient('mt_rx_latest');
        if ($cached){ $payload = json_decode($cached,true); }
        else {
            global $wpdb;
            $row = $wpdb->get_row("SELECT params_json FROM {$this->table} ORDER BY received_at DESC, id DESC LIMIT 1");
            if (!$row) return new \WP_Error('mt_no_data','No readings available yet.',['status'=>404]);
            $payload = json_decode($row->params_json,true); if (!is_array($payload)) $payload = [];
            set_transient('mt_rx_latest', $row->params_json, 60);
        }
        if (!empty($keys)){
            $filtered=[]; foreach($keys as $k){ $uk=strtoupper($k); if(array_key_exists($uk,$payload)) $filtered[$uk]=$payload[$uk]; }
            return ['latest'=>$filtered,'available_keys'=>array_keys($payload)];
        }
        return ['latest'=>$payload];
    }

    private function conv_temp($v,$src,$dst){ if($src===$dst) return round($v,2); $c=($src==='F')?(($v-32)*5/9):$v; if($dst==='F') return round($c*9/5+32,2); return round($c,2); }
    private function conv_press($v,$src,$dst){ $to_hpa=['hpa'=>1.0,'mb'=>1.0,'inhg'=>33.8638866667,'kpa'=>10.0]; $from_hpa=['hpa'=>1.0,'mb'=>1.0,'inhg'=>1/33.8638866667,'kpa'=>0.1]; $src=strtolower($src); $dst=strtolower($dst); if(!isset($to_hpa[$src])||!isset($from_hpa[$dst])) return $v; $hpa=$v*$to_hpa[$src]; return round($hpa*$from_hpa[$dst],2); }
    private function conv_wind($v,$src,$dst){ $to_mps=['mps'=>1.0,'kmh'=>1/3.6,'mph'=>0.44704,'kn'=>0.514444]; $from_mps=['mps'=>1.0,'kmh'=>3.6,'mph'=>2.23693629,'kn'=>1.94384449]; $src=strtolower($src); $dst=strtolower($dst); if(!isset($to_mps[$src])||!isset($from_mps[$dst])) return $v; $mps=$v*$to_mps[$src]; return round($mps*$from_mps[$dst],2); }
    private function conv_rain($v,$src,$dst){ $to_mm=['mm'=>1.0,'in'=>25.4]; $from_mm=['mm'=>1.0,'in'=>1/25.4]; $src=strtolower($src); $dst=strtolower($dst); if(!isset($to_mm[$src])||!isset($from_mm[$dst])) return $v; $mm=$v*$to_mm[$src]; return round($mm*$from_mm[$dst],2); }

    private function convert_value($key, $val, $t_unit, $p_unit, $w_unit, $r_unit){
        if (!is_numeric($val)) return $val;
        $val = (float)$val;
        $srcT = strtoupper(get_option(self::OPT_SRC_T,'C'));
        $srcP = strtolower(get_option(self::OPT_SRC_P,'hPa'));
        $srcW = strtolower(get_option(self::OPT_SRC_W,'kmh'));
        $srcR = strtolower(get_option(self::OPT_SRC_R,'mm'));
        $k = strtoupper($key);
        if (preg_match('/^(T|TMX|TMN|TIN|TS\d+|TSD\d+|T\d+)$/', $k)) { return $this->conv_temp($val, $srcT, strtoupper($t_unit ?: $srcT)); }
        if ($k === 'P') { return $this->conv_press($val, $srcP, strtolower($p_unit ?: $srcP)); }
        if ($k === 'W' || $k === 'G') { return $this->conv_wind($val, $srcW, strtolower($w_unit ?: $srcW)); }
        if ($k === 'R' || $k === 'RR') { return $this->conv_rain($val, $srcR, strtolower($r_unit ?: $srcR)); }
        return $val;
    }

    private function unit_label($key, $t_unit, $p_unit, $w_unit, $r_unit, $dir_format = 'degrees') {
        $srcT = strtoupper(get_option(self::OPT_SRC_T,'C'));
        $srcP = strtolower(get_option(self::OPT_SRC_P,'hPa'));
        $srcW = strtolower(get_option(self::OPT_SRC_W,'kmh'));
        $srcR = strtolower(get_option(self::OPT_SRC_R,'mm'));
        $T = strtoupper($t_unit ?: $srcT);
        $P = strtolower($p_unit ?: $srcP);
        $W = strtolower($w_unit ?: $srcW);
        $R = strtolower($r_unit ?: $srcR);
        $k = strtoupper($key);
        if (preg_match('/^(T|TMX|TMN|TIN|TS\d+|TSD\d+|T\d+)$/', $k)) return ($T === 'F') ? '°F' : '°C';
        if ($k === 'P') { $map = ['hpa'=>'hPa','mb'=>'mb','inhg'=>'inHg','kpa'=>'kPa']; return isset($map[$P]) ? $map[$P] : ''; }
        if ($k === 'W' || $k === 'G') { $map = ['mps'=>'m/s','kmh'=>'km/h','mph'=>'mph','kn'=>'kn']; return isset($map[$W]) ? $map[$W] : ''; }
        if ($k === 'R' || $k === 'RR') { $map = ['mm'=>'mm','in'=>'in']; return isset($map[$R]) ? $map[$R] : ''; }
        if ($k === 'S') return ($dir_format === 'degrees') ? '°' : '';
        if ($k === 'H' || $k === 'HIN') return '%';
        if ($k === 'UV') return '';
        return '';
    }

    private function deg_to_compass($deg){ $d = fmod((float)$deg, 360.0); if ($d < 0) $d += 360.0; $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW']; $idx = (int)round($d / 22.5) % 16; return $dirs[$idx]; }

    public function register_shortcode() { add_shortcode('meteodata', [$this,'shortcode_meteodata']); }

    public function shortcode_meteodata($atts = []) {
        $atts = shortcode_atts([
            'key'      => '',
            'keys'     => '',
            'format'   => 'text',
            'decimals' => '1',
            'prefix'   => '',
            'suffix'   => '',
            'fallback' => 'N/A',
            't_unit'   => '',
            'p_unit'   => '',
            'w_unit'   => '',
            'r_unit'   => '',
            'dir_format' => 'degrees',
        ], $atts, 'meteodata');

        $dec = max(0, (int)$atts['decimals']);
        $keys = [];
        if (!empty($atts['key'])) { $keys[] = strtoupper(trim($atts['key'])); }
        elseif (!empty($atts['keys'])) { $keys = array_filter(array_map(function($x){ return strtoupper(trim($x)); }, explode(',', $atts['keys']))); }

        $data = $this->get_latest_row($keys);
        if (is_wp_error($data)) return esc_html($atts['fallback']);
        $latest = $data['latest'] ?? [];
        if (empty($latest)) return esc_html($atts['fallback']);

        $t=$atts['t_unit']; $p=$atts['p_unit']; $w=$atts['w_unit']; $r=$atts['r_unit'];
        $dirfmt = ($atts['dir_format']==='compass') ? 'compass' : 'degrees';

        foreach ($latest as $k => $v) {
            if (strtoupper($k)==='S') continue;
            $latest[$k] = $this->convert_value($k, $v, $t, $p, $w, $r);
            if (is_numeric($latest[$k])) $latest[$k] = round((float)$latest[$k], $dec);
        }

        $format_value = function($k,$v) use ($t,$p,$w,$r,$dirfmt,$dec){
            $K = strtoupper($k);
            if ($K === 'S') {
                if (!is_numeric($v)) return esc_html($v);
                $val = (float)$v;
                if ($dirfmt === 'compass') return esc_html($this->deg_to_compass($val));
                $rounded = round($val, max(0,$dec));
                return esc_html($rounded . ' °');
            } else {
                $unit = $this->unit_label($K, $t, $p, $w, $r, $dirfmt);
                return esc_html(trim((is_numeric($v)?$v:$v) . (strlen($unit)?' '.$unit:'')));
            }
        };

        $fmt = strtolower($atts['format']);
        if (!empty($atts['key'])) {
            $k = strtoupper($atts['key']); $val = $latest[$k] ?? null;
            if ($k==='S' && !isset($latest[$k]) && isset($data['latest']['S'])) $val = $data['latest']['S'];
            if ($val === null) return esc_html($atts['fallback']);
            return $format_value($k,$val);
        }
        if ($fmt === 'json') { return '<pre class="meteodata-json">'.esc_html(wp_json_encode($latest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>'; }
        if ($fmt === 'list') {
            $h = '<ul class="meteodata-list">';
            foreach ($latest as $k=>$v) {
                if (!empty($keys) && !in_array(strtoupper($k), $keys, true)) continue;
                $h .= '<li>'.$format_value($k,$v).'</li>';
            }
            return $h.'</ul>';
        }
        if ($fmt === 'table') {
            $h = '<table class="meteodata-table"><tbody>';
            foreach ($latest as $k=>$v) {
                if (!empty($keys) && !in_array(strtoupper($k), $keys, true)) continue;
                $h .= '<tr><td>'.$format_value($k,$v).'</td></tr>';
            }
            return $h.'</tbody></table>';
        }
        if ($fmt === 'inline') {
            $parts = [];
            foreach ($latest as $k=>$v) {
                if (!empty($keys) && !in_array(strtoupper($k), $keys, true)) continue;
                $parts[] = $format_value($k,$v);
            }
            return esc_html(implode(' | ', $parts));
        }
        $firstK = null; $firstV = null;
        foreach ($latest as $k=>$v){ $firstK=$k; $firstV=$v; break; }
        return $format_value($firstK,$firstV);
    }

    public function register_block(){
        wp_register_script(
            'mt-rx-block',
            plugins_url('mt-block.js', __FILE__),
            ['wp-blocks','wp-element','wp-components','wp-i18n','wp-editor','wp-block-editor','wp-server-side-render'],
            '1.8.0',
            true
        );
        register_block_type('meteotemplate/meteodata-card', [
            'editor_script'   => 'mt-rx-block',
            'render_callback' => [$this, 'render_block_meteodata_card'],
            'attributes'      => [
                'fields'     => ['type'=>'array','default'=>['T','H','P']],
                'style'      => ['type'=>'string','default'=>'table'],
                'decimals'   => ['type'=>'number','default'=>1],
                't_unit'     => ['type'=>'string','default'=>''],
                'p_unit'     => ['type'=>'string','default'=>''],
                'w_unit'     => ['type'=>'string','default'=>''],
                'r_unit'     => ['type'=>'string','default'=>''],
                'dir_format' => ['type'=>'string','default'=>'degrees'],
            ],
        ]);
    }

    public function render_block_meteodata_card($attrs){
        $fields = (isset($attrs['fields']) && is_array($attrs['fields'])) ? array_map('strtoupper',$attrs['fields']) : ['T','H','P'];
        $style  = isset($attrs['style']) ? strtolower($attrs['style']) : 'table';
        $dec    = isset($attrs['decimals']) ? (int)$attrs['decimals'] : 1;
        $t=$attrs['t_unit']??''; $p=$attrs['p_unit']??''; $w=$attrs['w_unit']??''; $r=$attrs['r_unit']??'';
        $dirfmt = (isset($attrs['dir_format']) && $attrs['dir_format']==='compass') ? 'compass' : 'degrees';

        $row = $this->get_latest_row($fields);
        if (is_wp_error($row)) return '<div class="mt-card">No data</div>';
        $latest = $row['latest'] ?? [];
        $out = [];
        foreach ($fields as $k){
            if (array_key_exists($k, $latest)){
                if ($k==='S') { $out[$k] = $latest[$k]; }
                else { $val = $this->convert_value($k, $latest[$k], $t, $p, $w, $r); if (is_numeric($val)) $val = round((float)$val, $dec); $out[$k] = $val; }
            }
        }

        $makeWithUnitOrCompass = function($k, $v) use ($t, $p, $w, $r, $dirfmt, $dec) {
            $K = strtoupper($k);
            if ($K === 'S') { if (!is_numeric($v)) return $v; $val=(float)$v; if ($dirfmt==='compass') return $this->deg_to_compass($val); $rounded=round($val,max(0,$dec)); return $rounded.' °'; }
            $unit = $this->unit_label($K, $t, $p, $w, $r, $dirfmt);
            return trim($v . (strlen($unit) ? ' ' . $unit : ''));
        };

        $html = '<div class="mt-card" style="border:1px solid #ddd;border-radius:12px;padding:12px;">';
        if ($style==='list'){
            $html.='<ul class="mt-list">';
            foreach ($out as $k=>$v) { $html.='<li>'.esc_html($makeWithUnitOrCompass($k, $v)).'</li>'; }
            $html.='</ul>';
        } elseif ($style==='inline'){
            $parts=[]; foreach ($out as $k=>$v) { $parts[] = $makeWithUnitOrCompass($k,$v); }
            $html.=esc_html(implode(' | ',$parts));
        } else {
            $html.='<table class="mt-table"><tbody>';
            foreach ($out as $k=>$v) { $html.='<tr><td>'.esc_html($makeWithUnitOrCompass($k,$v)).'</td></tr>'; }
            $html.='</tbody></table>';
        }
        $html.='</div>'; return $html;
    }

    public function purge_old_rows(){ global $wpdb; $days=(int)get_option(self::OPT_RET,30); $days=max(1,min(3650,$days)); $cutoff=gmdate('Y-m-d H:i:s', time()-$days*DAY_IN_SECONDS); $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE received_at < %s", $cutoff)); }

    private function get_client_ip(){ $keys=['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR']; foreach($keys as $k){ if(!empty($_SERVER[$k])){ $val=$_SERVER[$k]; if($k==='HTTP_X_FORWARDED_FOR'){ $parts=explode(',', $val); $val=trim($parts[0]); } return $val; } } return '0.0.0.0'; }
    private function ip_to_binary($ip){ if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) return @inet_pton($ip); if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return @inet_pton($ip); return null; }

    private function check_client_allowed($client_ip_str){
        $enf_ip  = (int)get_option(self::OPT_ENF_IP,0) === 1;
        $enf_dns = (int)get_option(self::OPT_ENF_DNS,0) === 1;
        if (!$enf_ip && !$enf_dns) return true;

        $client_bin = $this->ip_to_binary($client_ip_str);
        if ($client_bin === null) return new \WP_Error('bad_ip','Invalid client IP');

        $ip_ok = true;
        if ($enf_ip) {
            $list = $this->parse_lines(get_option(self::OPT_ALLOWIP,''));
            $ip_ok = $this->match_ip_allowlist($client_ip_str, $client_bin, $list);
        }
        $dns_ok = true;
        if ($enf_dns) {
            $hosts = $this->parse_lines(get_option(self::OPT_ALLOWDNS,''));
            $dns_ok = $this->match_dns_allowlist($client_bin, $hosts);
        }

        if ($enf_ip && !$ip_ok)   return new \WP_Error('deny_ip','IP not in allowlist');
        if ($enf_dns && !$dns_ok) return new \WP_Error('deny_dns','IP not in resolved FQDN set');
        return true;
    }

    private function parse_lines($txt){ $txt=str_replace("\r\n","\n",(string)$txt); $lines=array_filter(array_map('trim', preg_split('/[\n,]+/',$txt))); return array_values(array_unique($lines)); }

    private function match_ip_allowlist($client_ip_str,$client_bin,array $list){
        if (empty($list)) return false;
        foreach ($list as $entry) {
            $entry=trim($entry);
            if ($entry==='') continue;
            if (strpos($entry,'/')===false) {
                $bin=$this->ip_to_binary($entry);
                if ($bin && $bin===$client_bin) return true;
            } else {
                if ($this->ip_in_cidr($client_ip_str,$entry)) return true;
            }
        }
        return false;
    }

    private function match_dns_allowlist($client_bin,array $hosts){
        if (empty($hosts)) return false;
        $cache_key='mt_dns_allow_cache';
        $cache=get_transient($cache_key);
        if(!is_array($cache)) $cache=[];

        $allowed_bins=[];
        foreach ($hosts as $h) {
            $h=strtolower(trim($h));
            if ($h==='') continue;

            $stale = (!isset($cache[$h]) || !is_array($cache[$h]) || (time()- (int)$cache[$h]['ts']>300));
            if ($stale) {
                $ips=$this->resolve_host_ips($h);
                $cache[$h]=['ts'=>time(),'ips'=>$ips];
            }
            foreach ($cache[$h]['ips'] as $ip) {
                $bin=$this->ip_to_binary($ip);
                if ($bin) $allowed_bins[] = $bin;
            }
        }
        set_transient($cache_key,$cache,300);

        foreach ($allowed_bins as $bin) {
            if ($bin === $client_bin) return true;
        }
        return false;
    }

    private function resolve_host_ips($host){
        $ips=[];
        if (function_exists('dns_get_record')) {
            $a=@dns_get_record($host, DNS_A);
            if (is_array($a)) foreach ($a as $rec) { if (!empty($rec['ip'])) $ips[]=$rec['ip']; }
            $aaaa=@dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) foreach ($aaaa as $rec) { if (!empty($rec['ipv6'])) $ips[]=$rec['ipv6']; }
        } else {
            $a=@gethostbynamel($host);
            if (is_array($a)) $ips=array_merge($ips,$a);
        }
        return array_values(array_unique($ips));
    }

    private function ip_in_cidr($ip_str,$cidr){
        $parts=explode('/',$cidr,2);
        if (count($parts)!=2) return false;
        list($subnet,$mask)=$parts;
        $mask=(int)$mask;

        if (strpos($subnet,':')!==false) {
            if ($mask<0||$mask>128) return false;
            $ip  = @inet_pton($ip_str);
            $net = @inet_pton($subnet);
            if ($ip === false || $net === false) return false;

            $bytes = intdiv($mask, 8);
            $bits  = $mask % 8;

            if ($bytes && substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) return false;
            if ($bits === 0) return true;

            $ip_byte  = ord(substr($ip,  $bytes, 1));
            $net_byte = ord(substr($net, $bytes, 1));
            $mask_byte = 0xFF & (0xFF << (8 - $bits));
            return ($ip_byte & $mask_byte) === ($net_byte & $mask_byte);
        }

        if ($mask<0||$mask>32) return false;
        $ip_long  = ip2long($ip_str);
        $sub_long = ip2long($subnet);
        if ($ip_long===false||$sub_long===false) return false;
        $mask_long = ($mask === 0) ? 0 : (-1 << (32-$mask));
        $mask_long = $mask_long & 0xFFFFFFFF;
        return ($ip_long & $mask_long) === ($sub_long & $mask_long);
    }
}

new MT_Receiver_StoreOnly_1_8_0();
?>