<?php
/**
 * Plugin Name: China Car Importer
 * Description: Автопортал по продаже авто из Китая: каталог, калькулятор «под ключ», заявки, платная проверка VIN (ЮKassa), лендинг.
 * Version: 2.3.1
 * Author: Vadim Deko
 * Text Domain: china-car-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CCI_PLUGIN_VERSION', '2.3.1');

// 1. Подключение модулей плагина
$cci_modules = [
    'importer-logic.php',
    'content-types.php',
    'analytics.php',
    'car-pages.php',
    'calculator-logic.php',
    'yookassa-handler.php',
    'email-notifications.php',
    'leads-page.php',
    'vin-requests-page.php',
    'cars-admin.php',
    'settings-page.php',
    'admin-panel.php',
    'public-catalog.php',
    'landing-page.php',
    'vin-check-page.php',
    'legal-page.php',
    'site-frontend.php',
];

foreach ($cci_modules as $cci_module) {
    $cci_file = plugin_dir_path(__FILE__) . $cci_module;
    if (file_exists($cci_file)) {
        require_once $cci_file;
    }
}

// 2. Таблицы базы данных
function cci_ensure_catalog_table() {
    global $wpdb;
    $current = '1.1';
    if (get_option('cci_catalog_db_ver') === $current) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = $wpdb->prefix . 'car_catalog';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        lot_id varchar(64) NOT NULL DEFAULT '',
        slug varchar(190) NOT NULL DEFAULT '',
        vin varchar(30) NOT NULL DEFAULT '',
        brand varchar(100) NOT NULL DEFAULT '',
        model varchar(100) NOT NULL DEFAULT '',
        year int(4) NOT NULL DEFAULT 0,
        reg_date date NULL,
        mileage int(11) NOT NULL DEFAULT 0,
        engine_type varchar(30) NOT NULL DEFAULT 'petrol',
        engine_cc int(11) NOT NULL DEFAULT 0,
        power_hp int(11) NOT NULL DEFAULT 0,
        drive_type varchar(20) NOT NULL DEFAULT 'fwd',
        price_cny decimal(12,2) NOT NULL DEFAULT 0.00,
        description text NULL,
        cdn_images longtext NULL,
        status varchar(30) NOT NULL DEFAULT 'active',
        hash varchar(64) NOT NULL DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY lot_id (lot_id),
        KEY slug (slug),
        KEY status (status),
        KEY brand_model (brand, model)
    ) {$charset};");
    if (function_exists('cci_backfill_car_slugs')) {
        cci_backfill_car_slugs();
    }
    update_option('cci_catalog_db_ver', $current);
}

register_activation_hook(__FILE__, 'cci_activate_plugin');

function cci_activate_plugin() {
    cci_ensure_catalog_table();
    cci_ensure_leads_table();
    cci_ensure_vin_table();
}

// Обновление структуры БД при выходе новой версии (плагин мог быть просто заменён без повторной активации)
add_action('admin_init', function () {
    cci_ensure_catalog_table();
    cci_ensure_leads_table();
    cci_ensure_vin_table();
});
