<?php
/**
 * Операции с автомобилями каталога: сохранение, архив, удаление.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Генерация уникального номера лота: CN-GEE-4821 */
function cci_generate_lot_id($brand) {
    global $wpdb;
    $table = $wpdb->prefix . 'car_catalog';
    $slug  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', remove_accents((string) $brand)));
    $slug  = substr($slug !== '' ? $slug : 'CAR', 0, 3);
    do {
        $lot    = 'CN-' . $slug . '-' . wp_rand(1000, 9999);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE lot_id = %s", $lot));
    } while ($exists);
    return $lot;
}

/**
 * Проверка и подготовка данных формы автомобиля.
 * Возвращает [array $data, array $errors].
 */
function cci_validate_car_input($src) {
    $errors = [];
    $get = function ($k) use ($src) {
        return isset($src[$k]) ? trim(wp_unslash($src[$k])) : '';
    };

    $brand = sanitize_text_field($get('brand'));
    $model = sanitize_text_field($get('model'));
    $year  = (int) $get('year');
    $price = (float) str_replace([' ', ','], ['', '.'], $get('price_cny'));
    $etype = sanitize_key($get('engine_type'));
    $drive = sanitize_key($get('drive_type'));
    $status = $get('status') === 'archive' ? 'archive' : 'active';

    if ($brand === '') { $errors[] = 'Укажите марку.'; }
    if ($model === '') { $errors[] = 'Укажите модель.'; }
    if ($year < 2000 || $year > (int) date('Y') + 1) { $errors[] = 'Год выпуска указан неверно.'; }
    if ($price <= 0) { $errors[] = 'Укажите цену в юанях (больше нуля).'; }
    if (!in_array($etype, ['petrol', 'diesel', 'hybrid', 'ev'], true)) { $etype = 'petrol'; }
    if (!in_array($drive, ['fwd', 'rwd', '4wd'], true)) { $drive = 'fwd'; }

    $cc = $etype === 'ev' ? 0 : (int) $get('engine_cc');
    if ($etype !== 'ev' && ($cc < 500 || $cc > 8000)) {
        $errors[] = 'Объём двигателя укажите в см³ (например, 1998 для 2.0 л).';
    }

    $reg = $get('reg_date');
    if ($reg !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg)) {
        $errors[] = 'Дата регистрации указана неверно.';
        $reg = '';
    }

    $images = [];
    $raw    = json_decode($get('images'), true);
    if (is_array($raw)) {
        foreach ($raw as $u) {
            $u = esc_url_raw((string) $u);
            if ($u !== '') { $images[] = $u; }
        }
    }

    $data = [
        'brand'       => $brand,
        'model'       => $model,
        'year'        => $year,
        'reg_date'    => $reg !== '' ? $reg : null,
        'mileage'     => max(0, (int) $get('mileage')),
        'engine_type' => $etype,
        'engine_cc'   => $cc,
        'power_hp'    => max(0, (int) $get('power_hp')),
        'drive_type'  => $drive,
        'price_cny'   => $price,
        'vin'         => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $get('vin'))),
        'description' => sanitize_textarea_field($get('description')),
        'cdn_images'  => wp_json_encode($images, JSON_UNESCAPED_SLASHES),
        'status'      => $status,
    ];
    return [$data, $errors];
}

/** Создание (id = 0) или обновление автомобиля. Возвращает id или WP_Error. */
function cci_save_car($id, $data) {
    global $wpdb;
    $table = $wpdb->prefix . 'car_catalog';
    $data['hash'] = md5($data['price_cny'] . $data['status'] . $data['mileage']);

    if ($id > 0) {
        $cur_slug = $wpdb->get_var($wpdb->prepare("SELECT slug FROM {$table} WHERE id = %d", $id));
        if ($cur_slug === '' || $cur_slug === null) {
            $lot = $wpdb->get_var($wpdb->prepare("SELECT lot_id FROM {$table} WHERE id = %d", $id));
            $data['slug'] = cci_make_car_slug($data['brand'], $data['model'], $data['year'], (string) $lot);
        }
        $ok = $wpdb->update($table, $data, ['id' => $id]);
        return $ok === false ? new WP_Error('db', 'Не удалось сохранить автомобиль.') : $id;
    }
    $data['lot_id'] = cci_generate_lot_id($data['brand']);
    $data['slug']   = cci_make_car_slug($data['brand'], $data['model'], $data['year'], $data['lot_id']);
    $ok = $wpdb->insert($table, $data);
    return $ok ? (int) $wpdb->insert_id : new WP_Error('db', 'Не удалось добавить автомобиль.');
}

/** Перевод автомобиля в «Архив» (страница остаётся, но в каталоге не показывается) */
function cci_archive_car($lot_id) {
    global $wpdb;
    return $wpdb->update($wpdb->prefix . 'car_catalog', ['status' => 'archive'], ['lot_id' => $lot_id]);
}

/** Возврат из архива */
function cci_restore_car($lot_id) {
    global $wpdb;
    return $wpdb->update($wpdb->prefix . 'car_catalog', ['status' => 'active'], ['lot_id' => $lot_id]);
}

/** Полное удаление */
function cci_delete_car($id) {
    global $wpdb;
    return $wpdb->delete($wpdb->prefix . 'car_catalog', ['id' => (int) $id], ['%d']);
}
