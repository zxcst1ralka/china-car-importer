<?php
/**
 * Страница «Проверка VIN»: форма оплаты, обработчик, вебхук ЮKassa.
 * Шорткод: [china_cars_vin]
 */
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('china_cars_vin', 'cci_render_vin_page');
add_action('template_redirect', 'cci_handle_vin_submit');
add_action('rest_api_init', function () {
    register_rest_route('cci/v1', '/yookassa', [
        'methods'             => 'POST',
        'callback'            => 'cci_yk_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function cci_vin_redirect($type, $msg, $extra = []) {
    cci_flash_redirect($type === 'ok' ? 'ok' : 'err', $msg, add_query_arg($extra, cci_page_url('vin-check')));
}

/**
 * Обработка формы: создаём платёж ЮKassa (или заявку без оплаты, если ключи не заполнены)
 */
function cci_handle_vin_submit() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cci_vin_submit'])) {
        return;
    }

    if (cci_is_bot()) {
        wp_safe_redirect(add_query_arg(['vin_check_success' => 1, 'mode' => 'unpaid', 'rid' => 0], cci_page_url('vin-check')));
        exit;
    }

    if (!isset($_POST['cci_vin_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cci_vin_nonce'])), 'cci_vin_action')) {
        cci_vin_redirect('err', 'Сессия устарела. Обновите страницу и попробуйте ещё раз.');
    }

    $query = isset($_POST['query_value']) ? trim(sanitize_text_field(wp_unslash($_POST['query_value']))) : '';
    $phone = isset($_POST['client_phone']) ? trim(sanitize_text_field(wp_unslash($_POST['client_phone']))) : '';
    $email = isset($_POST['client_email']) ? sanitize_email(wp_unslash($_POST['client_email'])) : '';

    $is_vin  = (bool) preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $query);
    $is_link = !$is_vin && mb_strlen($query) >= 8 && mb_strlen($query) <= 300 && (bool) preg_match('~[./:]~', $query);

    if (!$is_vin && !$is_link) {
        cci_vin_redirect('err', 'Введите 17-значный VIN (без букв I, O, Q) или ссылку на объявление.');
    }
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
        cci_vin_redirect('err', 'Проверьте телефон: нужно не меньше 10 цифр.');
    }
    if (!is_email($email)) {
        cci_vin_redirect('err', 'Укажите корректный e-mail — на него придёт отчёт.');
    }
    if (empty($_POST['agree'])) {
        cci_vin_redirect('err', 'Нужно согласие на обработку персональных данных.');
    }

    $shop_id = get_option('cci_yk_shop_id');
    $secret  = get_option('cci_yk_secret_key');

    // Онлайн-оплата не подключена (нет ключей ЮKassa): сохраняем заявку без оплаты, менеджер свяжется с клиентом
    if (empty($shop_id) || empty($secret)) {
        global $wpdb;
        cci_ensure_vin_table();
        $is_vin_q = $is_vin;
        $wpdb->insert($wpdb->prefix . 'vin_requests', [
            'type'           => $is_vin_q ? 'vin' : 'url',
            'query_value'    => $query,
            'client_phone'   => $phone,
            'client_email'   => $email,
            'amount_rub'     => $is_vin_q ? (int) get_option('cci_vin_price_rub', 1500) : (int) get_option('cci_url_price_rub', 2500),
            'payment_id'     => '',
            'payment_status' => 'unpaid',
            'created_at'     => current_time('mysql'),
        ]);
        $new_id = (int) $wpdb->insert_id;
        if ($new_id) {
            cci_send_vin_unpaid_email($new_id);
        }
        wp_safe_redirect(add_query_arg(['vin_check_success' => 1, 'mode' => 'unpaid', 'rid' => $new_id], cci_page_url('vin-check')));
        exit;
    }

    $result = cci_create_vin_payment($query, $phone, $email);
    if (is_wp_error($result)) {
        cci_vin_redirect('err', 'Не удалось создать платёж: ' . $result->get_error_message());
    }
    if (empty($result['confirmation_url'])) {
        cci_vin_redirect('err', 'Платёжная система не вернула ссылку на оплату. Попробуйте ещё раз.');
    }
    wp_redirect($result['confirmation_url']); // внешний домен ЮKassa
    exit;
}

/**
 * Вебхук ЮKassa: статус перепроверяем запросом к API, телу запроса не доверяем.
 * Адрес для кабинета ЮKassa: /wp-json/cci/v1/yookassa
 */
function cci_yk_webhook(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $pid  = isset($body['object']['id']) ? sanitize_text_field($body['object']['id']) : '';
    if ($pid === '') {
        return new WP_REST_Response(['ok' => false], 400);
    }

    $payment = cci_yk_api_request('payments/' . rawurlencode($pid), [], 'GET');
    if (is_wp_error($payment)) {
        return new WP_REST_Response(['ok' => false], 500); // ЮKassa повторит запрос
    }

    global $wpdb;
    $table = $wpdb->prefix . 'vin_requests';
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE payment_id = %s", $pid));
    $new   = isset($payment['status']) ? sanitize_key($payment['status']) : '';

    if (!$row || !in_array($new, ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true)) {
        return new WP_REST_Response(['ok' => true], 200);
    }
    if ($row->payment_status === $new || $row->payment_status === 'succeeded') {
        return new WP_REST_Response(['ok' => true], 200);
    }

    $wpdb->update($table, ['payment_status' => $new], ['id' => $row->id]);
    if ($new === 'succeeded' && function_exists('cci_send_vin_payment_emails')) {
        cci_send_vin_payment_emails((int) $row->id);
    }
    return new WP_REST_Response(['ok' => true], 200);
}

function cci_render_vin_page($atts) {
    $vin_price = (int) get_option('cci_vin_price_rub', 1500);
    $url_price = (int) get_option('cci_url_price_rub', 2500);
    $no_pay    = empty(get_option('cci_yk_shop_id')) || empty(get_option('cci_yk_secret_key'));
    $prefill   = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $home      = home_url('/');

    ob_start();

    if (!empty($_GET['vin_check_success'])) {
        $mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : '';
        ?>
        <section class="page-head"><div class="wrap">
            <div class="done rv">
                <span class="eyebrow">Готово</span>
                <h2><?php echo $mode === 'unpaid' ? 'Заявка принята' : 'Платёж принят'; ?></h2>
                <p><?php
                    if ($mode === 'unpaid') {
                        echo 'Менеджер свяжется с вами, выставит счёт и после оплаты пришлёт отчёт на e-mail.';
                    } else {
                        echo 'Мы получили оплату и формируем отчёт по базам Китая. Готовый русскоязычный отчёт придёт на e-mail.';
                    }
                ?></p>
                <a class="btn btn-primary" href="<?php echo esc_url($home); ?>">На главную</a>
                <a class="btn btn-ghost" href="<?php echo esc_url(cci_page_url('catalog')); ?>">В каталог</a>
            </div>
        </div></section>
        <?php
        return ob_get_clean();
    }
    ?>
    <section class="page-head">
        <div class="wrap">
            <span class="eyebrow rv">Проверка по VIN</span>
            <h1 class="rv" style="--d:.05s">Узнайте всё<br>про авто <em>до покупки</em></h1>
        </div>
    </section>

    <section class="wrap" style="padding-bottom:clamp(70px,9vw,120px)">
        <div class="vinpage">
            <div class="glass rv" data-vin="<?php echo (int) $vin_price; ?>" data-url="<?php echo (int) $url_price; ?>"<?php echo $no_pay ? ' data-nopay="1"' : ''; ?>>
                <form class="form" method="post" action="<?php echo esc_url(cci_page_url('vin-check')); ?>">
                    <?php wp_nonce_field('cci_vin_action', 'cci_vin_nonce'); ?>
                    <input type="hidden" name="cci_vin_submit" value="1">
                    <?php echo cci_honeypot_field(); ?>
                    <div class="f">
                        <label for="vin-q">VIN или ссылка на лот</label>
                        <input id="vin-q" type="text" name="query_value" required maxlength="300" autocomplete="off" spellcheck="false" placeholder="LSVFA2183N2019821 или https://…" value="<?php echo esc_attr($prefill); ?>" style="font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.06em">
                        <div class="vcells" aria-hidden="true"></div>
                    </div>
                    <div class="mode"><b data-mode-price><?php echo esc_html(cci_money($vin_price)); ?>&nbsp;₽</b><span data-mode-txt></span></div>
                    <div class="f"><label for="v-phone">Телефон</label><input id="v-phone" type="tel" name="client_phone" required placeholder="+7 (999) 000-00-00" autocomplete="tel"></div>
                    <div class="f"><label for="v-mail">E-mail для отчёта</label><input id="v-mail" type="email" name="client_email" required placeholder="you@mail.ru" autocomplete="email"></div>
                    <label class="chk"><input type="checkbox" name="agree" value="1" required> Согласен на обработку персональных данных<?php $cci_pp = get_page_by_path('privacy'); echo ($cci_pp && $cci_pp->post_status === 'publish') ? ' (<a href="' . esc_url(get_permalink($cci_pp)) . '" target="_blank" rel="noopener" style="text-decoration:underline">политика</a>)' : ''; ?></label>
                    <button class="btn btn-primary btn-block" type="submit"><span data-pay-label>Оплатить</span> <span class="arrow">→</span></button>
                    <div class="agree"><?php echo $no_pay ? 'Менеджер свяжется с вами, выставит счёт и после оплаты отправит отчёт.' : 'Оплата банковской картой через ЮKassa. Данные карты нам не передаются.'; ?></div>
                </form>
            </div>

            <div class="rv" style="--d:.12s">
                <div class="report" style="transform:rotate(-1.6deg)">
                    <div class="report-top"><i>Chaboshi · 查博士</i><span>Пример отчёта</span></div>
                    <h4>Geely Monjaro 2.0T 4WD (2023)</h4>
                    <div class="vn">VIN LSVF•••••••••9821</div>
                    <div class="checks"><div>Без тяжких ДТП</div><div>Не утопленник</div><div>Без пожаров</div></div>
                    <div class="odo"><span>Последний пробег у дилера</span><b>14 200 км</b></div>
                    <div class="stamp">Оригинальный пробег</div>
                </div>
                <div class="vtabs">
                    <div><em>1</em><p><strong>Вводите VIN</strong>Либо вставляете ссылку на объявление — тогда мы разберём лот вручную.</p></div>
                    <div><em>2</em><p><strong>Оплачиваете онлайн</strong>Чистый VIN списывается сразу. Для ссылки деньги лишь замораживаются и списываются после подтверждения.</p></div>
                    <div><em>3</em><p><strong>Получаете отчёт на e-mail</strong>Русскоязычный отчёт: ДТП, утопление, пожары, реальный пробег, история ТО и страховых.</p></div>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
