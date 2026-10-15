<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Регистрация шорткода [china_cars_catalog]
 */
add_shortcode('china_cars_catalog', 'cci_render_cars_catalog');

/**
 * Вспомогательная функция безопасного перенаправления с сохранением flash-статуса в transient
 */
function cci_redirect_with_status($status, $lead_id = 0, $car_title = '', $form_values = []) {
    $flash_id = wp_generate_password(16, false);
    set_transient('cci_lead_flash_' . $flash_id, [
        'status'      => $status,
        'lead_id'     => intval($lead_id),
        'car_title'   => $car_title,
        'form_values' => $form_values
    ], 120);

    $redirect_url = wp_get_referer();
    if (!$redirect_url) {
        $redirect_url = home_url($_SERVER['REQUEST_URI'] ?? '/');
    }
    $redirect_url = remove_query_arg(['cci_notice'], $redirect_url);
    $redirect_url = add_query_arg('cci_notice', $flash_id, $redirect_url);

    if (headers_sent()) {
        echo '<script>window.location.href = ' . json_encode($redirect_url) . ';</script>';
    } else {
        wp_safe_redirect($redirect_url);
    }
    exit;
}

/**
 * Серверная обработка заявки (PRG-паттерн: защита от повторной отправки при F5 / обновлении страницы)
 */
function cci_handle_lead_form_submission() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cci_lead_submit'])) {
        return;
    }

    global $wpdb;
    $table_cars  = $wpdb->prefix . 'car_catalog';
    $table_leads = $wpdb->prefix . 'car_leads';

    if (function_exists('cci_ensure_leads_table')) {
        cci_ensure_leads_table();
    }

    $form_values = [
        'name'    => isset($_POST['client_name']) ? trim(sanitize_text_field(wp_unslash($_POST['client_name']))) : '',
        'phone'   => isset($_POST['client_phone']) ? trim(sanitize_text_field(wp_unslash($_POST['client_phone']))) : '',
        'method'  => isset($_POST['contact_method']) ? trim(sanitize_text_field(wp_unslash($_POST['contact_method']))) : '',
        'comment' => isset($_POST['client_comment']) ? trim(sanitize_textarea_field(wp_unslash($_POST['client_comment']))) : '',
        'lot_id'  => isset($_POST['car_lot_id']) ? trim(sanitize_text_field(wp_unslash($_POST['car_lot_id']))) : ''
    ];

    $raw_method = $form_values['method'];

    // 0. Скрытая «ловушка» для ботов
    if (cci_is_bot()) {
        cci_redirect_with_status('success', 0, 'автомобиль', []);
    }

    // 1. Проверка nonce
    if (!isset($_POST['cci_lead_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cci_lead_nonce'])), 'cci_lead_action')) {
        cci_redirect_with_status('invalid_nonce', 0, '', $form_values);
    }

    // 2. Валидация имени (минимум 2 символа)
    if (mb_strlen($form_values['name']) < 2 || mb_strlen($form_values['name']) > 120) {
        cci_redirect_with_status('invalid_name', 0, '', $form_values);
    }

    // 3. Валидация номера телефона (не менее 10 цифр)
    $digits_only = preg_replace('/[^0-9]/', '', $form_values['phone']);
    if (strlen($digits_only) < 10 || strlen($digits_only) > 15) {
        cci_redirect_with_status('invalid_phone', 0, '', $form_values);
    }

    // 4. Строгая проверка способа связи с нормализацией ключа
    $method_map = [
        'whatsapp'          => 'whatsapp',
        'WhatsApp'          => 'whatsapp',
        'telegram'          => 'telegram',
        'Telegram'          => 'telegram',
        'phone'             => 'phone',
        'Звонок'            => 'phone',
        'Телефонный звонок' => 'phone',
    ];

    if (!isset($method_map[$raw_method])) {
        $form_values['method'] = '';
        cci_redirect_with_status('invalid_contact_method', 0, '', $form_values);
    }
    $clean_method = $method_map[$raw_method];

    // 5. Загружаем лот исключительно из базы данных и только со статусом active
    $car = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_cars} WHERE lot_id = %s AND status = 'active' LIMIT 1",
        $form_values['lot_id']
    ));

    if (!$car) {
        cci_redirect_with_status('car_not_found', 0, '', $form_values);
    }

    $confirmed_car_title = $car->brand . ' ' . $car->model . ' (' . $car->year . ' г.)';

    // 6. Серверный пересчет стоимости
    $calc_data = function_exists('cci_calculate_car_turnkey_price') 
        ? cci_calculate_car_turnkey_price($car) 
        : (function_exists('cci_calculate_turnkey_price') ? cci_calculate_turnkey_price($car) : null);
    $total_rub = ($calc_data && isset($calc_data['total_rub'])) ? (float)$calc_data['total_rub'] : 0.0;

    // 7. СОХРАНЯЕМ В БАЗУ ДАННЫХ ДО ВЫЗОВА wp_mail() строго по схеме таблицы wp_car_leads
    $inserted = $wpdb->insert(
        $table_leads,
        [
            'car_lot_id'       => $car->lot_id,
            'car_name'         => $confirmed_car_title,
            'calculated_price' => $total_rub,
            'client_name'      => $form_values['name'],
            'client_phone'     => $form_values['phone'],
            'contact_method'   => $clean_method,
            'client_comment'   => $form_values['comment'],
            'utm'              => cci_utm_string(),
            'status'           => 'new',
            'email_sent'       => 0,
            'form_token'       => wp_generate_password(24, false),
            'created_at'       => current_time('mysql'),
        ],
        ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );

    if (!$inserted) {
        cci_redirect_with_status('db_error', 0, '', $form_values);
    }

    $created_lead_id = $wpdb->insert_id;

    // 8. Письмо менеджеру (с кнопкой «Открыть заявку в админке»)
    $mail_sent = cci_send_lead_email($created_lead_id);

    // 9. Результат отправки

    if ($mail_sent) {
        $wpdb->update($table_leads, ['email_sent' => 1], ['id' => $created_lead_id], ['%d'], ['%d']);
        cci_redirect_with_status('success', $created_lead_id, $confirmed_car_title, [
            'name' => '', 'phone' => '', 'method' => '', 'comment' => '', 'lot_id' => ''
        ]);
    } else {
        cci_redirect_with_status('mail_failed', $created_lead_id, $confirmed_car_title, $form_values);
    }
}
add_action('template_redirect', 'cci_handle_lead_form_submission');

function cci_render_cars_catalog($atts) {
    global $wpdb;
    $table_cars = $wpdb->prefix . 'car_catalog';

    $selected_brand  = isset($_GET['filter_brand'])  ? sanitize_text_field(wp_unslash($_GET['filter_brand']))  : '';
    $selected_engine = isset($_GET['filter_engine']) ? sanitize_text_field(wp_unslash($_GET['filter_engine'])) : '';
    $year_from       = isset($_GET['year_from']) ? intval($_GET['year_from']) : 0;
    $year_to         = isset($_GET['year_to'])   ? intval($_GET['year_to'])   : 0;
    $sort            = isset($_GET['sort_by'])   ? sanitize_text_field(wp_unslash($_GET['sort_by'])) : 'newest';

    $where  = ["status = 'active'"];
    $params = [];
    if ($selected_brand !== '') {
        $where[]  = 'brand = %s';
        $params[] = $selected_brand;
    }
    if ($selected_engine !== '') {
        $where[]  = 'engine_type = %s';
        $params[] = $selected_engine;
    }
    if ($year_from > 0) {
        $where[]  = 'year >= %d';
        $params[] = $year_from;
    }
    if ($year_to > 0) {
        $where[]  = 'year <= %d';
        $params[] = $year_to;
    }

    $order_sql = 'ORDER BY id DESC';
    if ($sort === 'price_asc') {
        $order_sql = 'ORDER BY price_cny ASC';
    } elseif ($sort === 'price_desc') {
        $order_sql = 'ORDER BY price_cny DESC';
    } elseif ($sort === 'year_desc') {
        $order_sql = 'ORDER BY year DESC, id DESC';
    }

    $query = "SELECT * FROM {$table_cars} WHERE " . implode(' AND ', $where) . " {$order_sql} LIMIT 60";
    $cars  = $params ? $wpdb->get_results($wpdb->prepare($query, $params)) : $wpdb->get_results($query);

    $all_brands  = $wpdb->get_col("SELECT DISTINCT brand FROM {$table_cars} WHERE status = 'active' ORDER BY brand ASC");
    $all_engines = $wpdb->get_col("SELECT DISTINCT engine_type FROM {$table_cars} WHERE status = 'active' ORDER BY engine_type ASC");
    $filtered    = ($selected_brand !== '' || $selected_engine !== '' || $year_from || $year_to || $sort !== 'newest');
    $base_url    = cci_page_url('catalog');

    ob_start();
    ?>
    <section class="page-head">
        <div class="wrap">
            <span class="eyebrow rv">Каталог</span>
            <h1 class="rv" style="--d:.05s">Авто из Китая<br><em>под ключ</em></h1>
            <p class="lead rv" style="--d:.1s">Цена в каждой карточке — полная стоимость во Владивостоке: автомобиль, логистика, пошлина, сборы и комиссия.</p>
        </div>
    </section>

    <section class="wrap" style="padding-bottom:clamp(70px,9vw,120px)">
        <form class="filters" method="get" action="<?php echo esc_url($base_url); ?>" data-auto>
            <div class="chips">
                <span class="lbl">Марка</span>
                <input type="radio" name="filter_brand" id="b-all" value="" <?php checked($selected_brand, ''); ?>><label for="b-all">Все</label>
                <?php foreach ($all_brands as $i => $b): ?>
                    <input type="radio" name="filter_brand" id="b-<?php echo (int) $i; ?>" value="<?php echo esc_attr($b); ?>" <?php checked($selected_brand, $b); ?>><label for="b-<?php echo (int) $i; ?>"><?php echo esc_html($b); ?></label>
                <?php endforeach; ?>
            </div>
            <div class="chips">
                <span class="lbl">Двигатель</span>
                <input type="radio" name="filter_engine" id="e-all" value="" <?php checked($selected_engine, ''); ?>><label for="e-all">Любой</label>
                <?php foreach ($all_engines as $i => $e): ?>
                    <input type="radio" name="filter_engine" id="e-<?php echo (int) $i; ?>" value="<?php echo esc_attr($e); ?>" <?php checked($selected_engine, $e); ?>><label for="e-<?php echo (int) $i; ?>"><?php echo esc_html(cci_engine_label($e)); ?></label>
                <?php endforeach; ?>
            </div>
            <div class="frow">
                <div class="grp">
                    <span class="lbl" style="margin:0">Год</span>
                    <input type="number" name="year_from" placeholder="от" min="2000" max="2030" value="<?php echo $year_from ? esc_attr($year_from) : ''; ?>">
                    <input type="number" name="year_to" placeholder="до" min="2000" max="2030" value="<?php echo $year_to ? esc_attr($year_to) : ''; ?>">
                    <select name="sort_by" aria-label="Сортировка">
                        <option value="newest" <?php selected($sort, 'newest'); ?>>Сначала новые</option>
                        <option value="price_asc" <?php selected($sort, 'price_asc'); ?>>Дешевле</option>
                        <option value="price_desc" <?php selected($sort, 'price_desc'); ?>>Дороже</option>
                        <option value="year_desc" <?php selected($sort, 'year_desc'); ?>>Свежий год</option>
                    </select>
                </div>
                <div class="grp">
                    <span class="count">Найдено: <b><?php echo (int) count($cars); ?></b></span>
                    <?php if ($filtered): ?><a class="reset" href="<?php echo esc_url($base_url); ?>">Сбросить фильтры</a><?php endif; ?>
                    <noscript><button class="btn btn-sm btn-dark" type="submit">Применить</button></noscript>
                </div>
            </div>
        </form>

        <div class="grid">
            <?php if ($cars): ?>
                <?php foreach ($cars as $car) { echo cci_render_car_card($car); } ?>
            <?php else: ?>
                <div class="empty"><h3>Ничего не нашли</h3><p>Попробуйте изменить фильтры — или оставьте заявку, и мы найдём автомобиль под ваш запрос.</p></div>
            <?php endif; ?>
        </div>
        <?php if ($cars) { echo cci_price_footnote(); } ?>
    </section>
    <?php
    return ob_get_clean();
}
