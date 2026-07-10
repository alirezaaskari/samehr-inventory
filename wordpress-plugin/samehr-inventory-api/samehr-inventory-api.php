<?php
/**
 * Plugin Name: Samehr Inventory API
 * Description: Secure WooCommerce inventory API for the Samehr Android application.
 * Version: 1.0.0
 * Author: Samehr Store
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: samehr-inventory
 */

defined('ABSPATH') || exit;

final class Samehr_Inventory_API {
    const NS = 'samehr-inventory/v1';
    const CAP = 'samehr_manage_inventory';
    const LOG_TABLE = 'samehr_inventory_log';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('woocommerce_reduce_order_stock', [__CLASS__, 'log_order_reduction']);
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            change_amount int NOT NULL,
            stock_before int NULL,
            stock_after int NULL,
            action varchar(30) NOT NULL,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY product_id (product_id), KEY created_at (created_at)
        ) {$charset};");
        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role) $role->add_cap(self::CAP);
        }
        if (!get_option('samehr_low_stock_threshold')) update_option('samehr_low_stock_threshold', 5);
    }

    public static function routes() {
        register_rest_route(self::NS, '/me', ['methods'=>'GET','callback'=>[__CLASS__,'me'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route(self::NS, '/products', ['methods'=>'GET','callback'=>[__CLASS__,'products'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route(self::NS, '/products/(?P<id>\d+)/stock', ['methods'=>'POST','callback'=>[__CLASS__,'update_stock'],'permission_callback'=>[__CLASS__,'can_edit']]);
        register_rest_route(self::NS, '/logs', ['methods'=>'GET','callback'=>[__CLASS__,'logs'],'permission_callback'=>[__CLASS__,'can_view']]);
        register_rest_route(self::NS, '/summary', ['methods'=>'GET','callback'=>[__CLASS__,'summary'],'permission_callback'=>[__CLASS__,'can_view']]);
    }

    public static function can_view() { return is_user_logged_in() && (current_user_can(self::CAP) || current_user_can('edit_products') || current_user_can('read')); }
    public static function can_edit() { return is_user_logged_in() && (current_user_can(self::CAP) || current_user_can('edit_products')); }

    private static function ensure_wc() {
        return function_exists('wc_get_product') ? true : new WP_Error('woocommerce_required', 'WooCommerce is not active.', ['status'=>503]);
    }

    public static function me() {
        $u = wp_get_current_user();
        return ['id'=>$u->ID,'name'=>$u->display_name,'roles'=>$u->roles,'can_edit'=>self::can_edit()];
    }

    public static function products(WP_REST_Request $r) {
        if (is_wp_error($e = self::ensure_wc())) return $e;
        $page = max(1, (int)$r->get_param('page'));
        $per = min(100, max(1, (int)($r->get_param('per_page') ?: 30)));
        $search = sanitize_text_field((string)$r->get_param('search'));
        $low_only = rest_sanitize_boolean($r->get_param('low_stock'));
        $threshold = (int)get_option('samehr_low_stock_threshold', 5);
        $args = ['status'=>'publish','limit'=>$per,'page'=>$page,'paginate'=>true,'orderby'=>'name','order'=>'ASC'];
        if ($search) $args['s'] = $search;
        $result = wc_get_products($args);
        $items = [];
        foreach ($result->products as $p) {
            $qty = $p->get_stock_quantity();
            $is_low = $p->managing_stock() && $qty !== null && $qty <= $threshold;
            if ($low_only && !$is_low) continue;
            $items[] = ['id'=>$p->get_id(),'name'=>$p->get_name(),'sku'=>$p->get_sku(),'stock_quantity'=>$qty,'stock_status'=>$p->get_stock_status(),'manage_stock'=>$p->managing_stock(),'low_stock'=>$is_low,'image'=>wp_get_attachment_image_url($p->get_image_id(),'thumbnail') ?: ''];
        }
        return ['items'=>$items,'page'=>$page,'pages'=>(int)$result->max_num_pages,'total'=>(int)$result->total,'threshold'=>$threshold];
    }

    public static function update_stock(WP_REST_Request $r) {
        if (is_wp_error($e = self::ensure_wc())) return $e;
        $product = wc_get_product((int)$r['id']);
        if (!$product) return new WP_Error('not_found','Product not found.',['status'=>404]);
        $mode = sanitize_key((string)$r->get_param('mode'));
        $amount = (int)$r->get_param('amount');
        $note = sanitize_textarea_field((string)$r->get_param('note'));
        if (!in_array($mode, ['set','increase','decrease'], true)) return new WP_Error('invalid_mode','Mode must be set, increase or decrease.',['status'=>400]);
        if ($amount < 0) return new WP_Error('invalid_amount','Amount must be zero or greater.',['status'=>400]);
        $before = (int)($product->get_stock_quantity() ?? 0);
        $after = $mode === 'set' ? $amount : ($mode === 'increase' ? $before + $amount : max(0, $before - $amount));
        $product->set_manage_stock(true);
        $product->set_stock_quantity($after);
        $product->set_stock_status($after > 0 ? 'instock' : 'outofstock');
        $product->save();
        self::insert_log($product->get_id(), $after - $before, $before, $after, $mode, $note);
        return ['success'=>true,'product_id'=>$product->get_id(),'stock_before'=>$before,'stock_after'=>$after,'stock_status'=>$product->get_stock_status()];
    }

    private static function insert_log($product_id, $change, $before, $after, $action, $note='') {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.self::LOG_TABLE, ['product_id'=>$product_id,'user_id'=>get_current_user_id(),'change_amount'=>$change,'stock_before'=>$before,'stock_after'=>$after,'action'=>$action,'note'=>$note,'created_at'=>current_time('mysql')], ['%d','%d','%d','%d','%d','%s','%s','%s']);
    }

    public static function logs(WP_REST_Request $r) {
        global $wpdb;
        $limit = min(100, max(1, (int)($r->get_param('limit') ?: 50)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}".self::LOG_TABLE." ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        foreach ($rows as &$row) { $p = function_exists('wc_get_product') ? wc_get_product((int)$row['product_id']) : null; $row['product_name'] = $p ? $p->get_name() : '#'.$row['product_id']; $row['user_name'] = get_the_author_meta('display_name', (int)$row['user_id']); }
        return ['items'=>$rows];
    }

    public static function summary() {
        if (is_wp_error($e = self::ensure_wc())) return $e;
        $threshold = (int)get_option('samehr_low_stock_threshold', 5);
        $ids = wc_get_products(['status'=>'publish','limit'=>-1,'return'=>'ids']);
        $low=$out=$managed=0;
        foreach ($ids as $id) { $p=wc_get_product($id); if (!$p || !$p->managing_stock()) continue; $managed++; $q=(int)$p->get_stock_quantity(); if ($q<=0) $out++; elseif ($q<=$threshold) $low++; }
        return ['products'=>count($ids),'managed'=>$managed,'low_stock'=>$low,'out_of_stock'=>$out,'threshold'=>$threshold];
    }

    public static function log_order_reduction($order) {
        foreach ($order->get_items() as $item) { $p=$item->get_product(); if ($p && $p->managing_stock()) { $after=(int)$p->get_stock_quantity(); $qty=(int)$item->get_quantity(); self::insert_log($p->get_id(), -$qty, $after+$qty, $after, 'order', 'Order #'.$order->get_id()); } }
    }

    public static function admin_menu() { add_submenu_page('woocommerce','Samehr Inventory','Samehr Inventory','manage_woocommerce','samehr-inventory',[__CLASS__,'settings_page']); }
    public static function settings() { register_setting('samehr_inventory','samehr_low_stock_threshold',['type'=>'integer','sanitize_callback'=>'absint','default'=>5]); }
    public static function settings_page() { ?>
        <div class="wrap"><h1>Samehr Inventory</h1><p>Use a WordPress Application Password in the Android app. Create it from Users → Profile → Application Passwords.</p><form method="post" action="options.php"><?php settings_fields('samehr_inventory'); ?><table class="form-table"><tr><th>Low stock threshold</th><td><input type="number" min="0" name="samehr_low_stock_threshold" value="<?php echo esc_attr(get_option('samehr_low_stock_threshold',5)); ?>"></td></tr></table><?php submit_button(); ?></form></div>
    <?php }
}

register_activation_hook(__FILE__, ['Samehr_Inventory_API','activate']);
Samehr_Inventory_API::init();
