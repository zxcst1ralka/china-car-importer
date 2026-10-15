<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Базовая функция отправки запросов к API ЮKassa
 */
function cci_yk_api_request($endpoint, $data = [], $method = 'POST') {
    $shop_id = get_option('cci_yk_shop_id');
    $secret_key = get_option('cci_yk_secret_key');

    if (empty($shop_id) || empty($secret_key)) {
        return new WP_Error('no_credentials', 'Shop ID или Секретный ключ ЮKassa не заполнены в настройках.');
    }

    $url = 'https://api.yookassa.ru/v3/' . ltrim($endpoint, '/');

    $args = [
        'method'  => $method,
        'timeout' => 20,
        'headers' => [
            'Authorization'    => 'Basic ' . base64_encode($shop_id . ':' . $secret_key),
            'Content-Type'     => 'application/json',
            'Idempotence-Key'  => wp_generate_uuid4(), // Защита от дублей платежей
        ],
    ];

    if (!empty($data)) {
        $args['body'] = json_encode($data);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code < 200 || $code >= 300) {
        $msg = $body['description'] ?? 'Ошибка API ЮKassa (код ' . $code . ')';
        return new WP_Error('yk_error', $msg, $body);
    }

    return $body;
}

/**
 * Создание нового платежа (мгновенного или с холдированием)
 */
function cci_create_vin_payment($query_value, $phone, $email = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'vin_requests';

    // 1. Определяем тип: чистый 17-значный VIN или ссылка/текст
    $clean_query = trim($query_value);
    $is_pure_vin = (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $clean_query);

    $type = $is_pure_vin ? 'vin' : 'url';
    $amount = $is_pure_vin 
        ? (int) get_option('cci_vin_price_rub', 1500) 
        : (int) get_option('cci_url_price_rub', 2500);

    // Главное техническое отличие ТЗ:
    // чистый VIN -> capture = true (мгновенно)
    // ссылка -> capture = false (холдирование/заморозка)
    $capture = $is_pure_vin;

    $description = $is_pure_vin 
        ? "Проверка авто по VIN {$clean_query}" 
        : "Анализ лота по ссылке (с холдированием)";

    $payload = [
        'amount' => [
            'value'    => number_format($amount, 2, '.', ''),
            'currency' => 'RUB',
        ],
        'capture'      => $capture,
        'description'  => $description,
        'confirmation' => [
            'type'       => 'redirect',
            'return_url' => add_query_arg('vin_check_success', '1', function_exists('cci_page_url') ? cci_page_url('vin-check') : home_url('/')),
        ],
        'metadata' => [
            'query_type'  => $type,
            'query_value' => $clean_query,
            'phone'       => $phone,
        ]
    ];

    // Чек по 54-ФЗ (если включено в настройках)
    if (get_option('cci_yk_receipt') && is_email($email)) {
        $receipt = [
            'customer' => ['email' => $email],
            'items'    => [[
                'description'     => mb_substr($is_pure_vin ? 'Проверка автомобиля по VIN' : 'Анализ автомобиля по ссылке', 0, 128),
                'quantity'        => '1.00',
                'amount'          => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
                'vat_code'        => (int) get_option('cci_yk_vat_code', 1),
                'payment_mode'    => 'full_payment',
                'payment_subject' => 'service',
            ]],
        ];
        if ((int) get_option('cci_yk_tax_system', 0) > 0) {
            $receipt['tax_system_code'] = (int) get_option('cci_yk_tax_system');
        }
        $payload['receipt'] = $receipt;
    }

    $result = cci_yk_api_request('payments', $payload, 'POST');

    if (is_wp_error($result)) {
        return $result;
    }

    // Сохраняем заявку в нашу таблицу wp_vin_requests
    $wpdb->insert($table, [
        'type'           => $type,
        'query_value'    => $clean_query,
        'client_phone'   => $phone,
        'client_email'   => $email,
        'amount_rub'     => $amount,
        'payment_id'     => $result['id'],
        'payment_status' => $result['status'], // pending / waiting_for_capture
        'created_at'     => current_time('mysql'),
    ]);

    return [
        'payment_id'        => $result['id'],
        'confirmation_url'  => $result['confirmation']['confirmation_url'] ?? '',
        'type'              => $type,
        'amount'            => $amount,
        'is_held'           => !$capture
    ];
}

/**
 * Подтверждение замороженных денег (Списание холда админом)
 */
function cci_capture_held_payment($request_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'vin_requests';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $request_id));

    if (!$row || empty($row->payment_id)) {
        return new WP_Error('not_found', 'Заявка не найдена.');
    }

    // Защита: списать можно ТОЛЬКО замороженные средства
    if ($row->payment_status !== 'waiting_for_capture') {
        return new WP_Error('invalid_status', 'Невозможно списать: платеж не находится в статусе ожидания (холда).');
    }


    // Боевой запрос к API ЮKassa
    $endpoint = "payments/{$row->payment_id}/capture";
    $result = cci_yk_api_request($endpoint, [], 'POST');

    if (is_wp_error($result)) {
        return $result;
    }

    $wpdb->update($table, ['payment_status' => 'succeeded'], ['id' => $request_id]);
    return true;
}

/**
 * Отмена заморозки (Возврат денег клиенту без комиссии)
 */
function cci_cancel_held_payment($request_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'vin_requests';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $request_id));

    if (!$row || empty($row->payment_id)) {
        return new WP_Error('not_found', 'Заявка не найдена.');
    }

    // Защита: отменить можно ТОЛЬКО замороженные средства
    if ($row->payment_status !== 'waiting_for_capture') {
        return new WP_Error('invalid_status', 'Невозможно отменить: платеж уже обработан или завершен.');
    }


    // Боевой запрос к API ЮKassa
    $endpoint = "payments/{$row->payment_id}/cancel";
    $result = cci_yk_api_request($endpoint, [], 'POST');

    if (is_wp_error($result)) {
        return $result;
    }

    $wpdb->update($table, ['payment_status' => 'canceled'], ['id' => $request_id]);
    return true;
}

