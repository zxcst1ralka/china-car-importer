<?php
/**
 * Админка: меню, обзор (главная страница плагина), уведомления, стили
 */
if (!defined('ABSPATH')) {
    exit;
}

/* ---- Уведомления в админке (после действий с редиректом) ---- */
function cci_admin_flash($type, $msg) {
    $key  = 'cci_aflash_' . get_current_user_id();
    $list = get_transient($key);
    $list = is_array($list) ? $list : [];
    $list[] = [$type === 'ok' ? 'success' : 'error', $msg];
    set_transient($key, $list, 120);
}

add_action('admin_notices', function () {
    $key  = 'cci_aflash_' . get_current_user_id();
    $list = get_transient($key);
    if (!is_array($list)) {
        return;
    }
    delete_transient($key);
    foreach ($list as $n) {
        echo '<div class="notice notice-' . esc_attr($n[0]) . ' is-dismissible"><p>' . esc_html($n[1]) . '</p></div>';
    }
});

/* ---- Стили и скрипты только на страницах плагина ---- */
add_action('admin_enqueue_scripts', function () {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (strpos($page, 'china-car') !== 0) {
        return;
    }
    wp_enqueue_style('cci-admin', cci_asset_url('css/admin.css'), [], cci_asset_ver('css/admin.css'));
    if (in_array($page, ['china-car-cars', 'china-car-vin-requests'], true)) {
        wp_enqueue_media();
    }
    wp_enqueue_script('cci-admin', cci_asset_url('js/admin.js'), ['jquery'], cci_asset_ver('js/admin.js'), true);
});

/* ---- Меню ---- */
add_action('admin_menu', 'cci_register_admin_menu', 5);
add_action('admin_menu', 'cci_register_settings_menu', 20);

function cci_register_admin_menu() {
    global $wpdb;
    $new = 0;
    if (get_option('cci_leads_db_ver')) {
        $new = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}car_leads WHERE status = 'new'");
    }
    $bubble = $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '';

    $dash = add_menu_page('Авто из Китая', 'Авто из Китая' . $bubble, 'manage_options', 'china-car-import', 'cci_render_dashboard', 'dashicons-car', 25);
    add_action('load-' . $dash, 'cci_dashboard_handle');

    add_submenu_page('china-car-import', 'Обзор', 'Обзор', 'manage_options', 'china-car-import', 'cci_render_dashboard');

    $leads = add_submenu_page('china-car-import', 'Заявки клиентов', 'Заявки' . $bubble, 'manage_options', 'china-car-leads', 'cci_render_leads_page');
    add_action('load-' . $leads, 'cci_leads_handle_actions');

    $cars = add_submenu_page('china-car-import', 'Автомобили', 'Автомобили', 'manage_options', 'china-car-cars', 'cci_render_cars_page');
    add_action('load-' . $cars, 'cci_cars_handle_actions');

    $vin = add_submenu_page('china-car-import', 'Проверки VIN', 'Проверки VIN', 'manage_options', 'china-car-vin-requests', 'cci_render_vin_requests_page');
    add_action('load-' . $vin, 'cci_vin_handle_actions');

}

function cci_register_settings_menu() {
    add_submenu_page('china-car-import', 'Настройки', 'Настройки', 'manage_options', 'china-car-settings', 'cci_render_settings_page');
}

/* ---- Обзор ---- */
function cci_dashboard_handle() {
    if (!current_user_can('manage_options') || empty($_POST['cci_setup_pages'])) {
        return;
    }
    check_admin_referer('cci_setup_pages_action', 'cci_pages_nonce');
    $notes = cci_setup_site_pages();
    cci_admin_flash('ok', 'Страницы сайта настроены. ' . implode(' ', $notes));
    wp_safe_redirect(admin_url('admin.php?page=china-car-import'));
    exit;
}

function cci_render_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас недостаточно прав для доступа к этой странице.');
    }
    global $wpdb;
    $t_leads = $wpdb->prefix . 'car_leads';
    $t_vin   = $wpdb->prefix . 'vin_requests';
    $t_cars  = $wpdb->prefix . 'car_catalog';

    $new_leads  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_leads} WHERE status = 'new'");
    $work_leads = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_leads} WHERE status = 'in_progress'");
    $cars_on    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_cars} WHERE status = 'active'");
    $vin_report = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_vin} WHERE payment_status = 'succeeded' AND report_sent_at IS NULL");
    $vin_hold   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t_vin} WHERE payment_status IN ('waiting_for_capture','unpaid')");

    $pages_ok = false;
    $front    = (int) get_option('page_on_front');
    $home     = $front ? get_post($front) : null;
    $cat      = get_page_by_path('catalog');
    $vinp     = get_page_by_path('vin-check');
    if ($home && strpos($home->post_content, '[china_cars_landing') !== false && $cat && $vinp) {
        $pages_ok = true;
    }
    $upd      = (int) get_option('cci_rates_updated', 0);
    $days     = $upd ? (int) floor((time() - $upd) / DAY_IN_SECONDS) : null;
    $contacts = get_option('cci_contact_phone') || get_option('cci_contact_telegram') || get_option('cci_contact_whatsapp');
    $pay_ok   = get_option('cci_yk_shop_id') && get_option('cci_yk_secret_key');
    $locale   = get_locale();

    $checks = [
        ['ok' => $pages_ok, 'text' => 'Страницы сайта (главная, каталог, проверка VIN) настроены', 'fix' => 'pages'],
        ['ok' => $cars_on > 0, 'text' => 'В каталоге есть автомобили', 'link' => admin_url('admin.php?page=china-car-cars&add=1'), 'link_text' => 'Добавить автомобиль'],
        ['ok' => $upd && $days <= 7, 'text' => $upd ? 'Курсы валют обновлялись ' . ($days === 0 ? 'сегодня' : $days . ' дн. назад') . ($days > 7 ? ': пора обновить' : '') : 'Курсы валют ещё не обновлялись вручную, проверьте их', 'link' => admin_url('admin.php?page=china-car-settings&tab=calc'), 'link_text' => 'Курсы и расходы'],
        ['ok' => (bool) $contacts, 'text' => 'Контакты (телефон, Telegram, WhatsApp) указаны', 'link' => admin_url('admin.php?page=china-car-settings&tab=contact'), 'link_text' => 'Указать контакты'],
        ['ok' => cci_count_items('cci_review') > 0, 'text' => 'Добавлены отзывы клиентов (доверие)', 'link' => admin_url('post-new.php?post_type=cci_review'), 'link_text' => 'Добавить отзыв'],
        ['ok' => cci_count_items('cci_case') > 0, 'text' => 'Добавлены кейсы выданных автомобилей', 'link' => admin_url('post-new.php?post_type=cci_case'), 'link_text' => 'Добавить кейс'],
        ['ok' => (bool) (get_option('cci_terms_prepay') || get_option('cci_terms_stages')), 'text' => 'Описаны условия сделки (предоплата, этапы оплаты)', 'link' => admin_url('admin.php?page=china-car-settings&tab=site'), 'link_text' => 'Заполнить'],
        ['ok' => (bool) get_option('cci_company_name'), 'text' => 'Указаны реквизиты компании', 'link' => admin_url('admin.php?page=china-car-settings&tab=contact'), 'link_text' => 'Указать реквизиты'],
        ['ok' => (bool) get_option('cci_metrika_id'), 'text' => 'Подключена Яндекс.Метрика', 'link' => admin_url('admin.php?page=china-car-settings&tab=site'), 'link_text' => 'Подключить'],
        ['ok' => (bool) $pay_ok, 'text' => $pay_ok ? 'Онлайн-оплата проверок подключена (ЮKassa)' : 'Онлайн-оплата не подключена: проверки приходят без оплаты', 'link' => admin_url('admin.php?page=china-car-settings&tab=pay'), 'link_text' => 'Подключить ЮKassa'],
        ['ok' => strpos($locale, 'ru') === 0, 'text' => 'Язык админки WordPress — русский', 'link' => admin_url('options-general.php'), 'link_text' => 'Настройки → Общие → Язык сайта'],
    ];
    ?>
    <div class="wrap cci-admin">
        <h1>Авто из Китая: обзор</h1>

        <div class="cci-cards">
            <a class="cci-card <?php echo $new_leads ? 'cci-card-hot' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=china-car-leads&status_filter=new')); ?>">
                <span class="cci-card-n"><?php echo $new_leads; ?></span><span class="cci-card-t">новых заявок</span>
                <small><?php echo $work_leads; ?> в работе</small>
            </a>
            <a class="cci-card" href="<?php echo esc_url(admin_url('admin.php?page=china-car-cars')); ?>">
                <span class="cci-card-n"><?php echo $cars_on; ?></span><span class="cci-card-t">авто на сайте</span>
                <small>управление каталогом</small>
            </a>
            <a class="cci-card <?php echo $vin_report ? 'cci-card-hot' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=china-car-vin-requests')); ?>">
                <span class="cci-card-n"><?php echo $vin_report; ?></span><span class="cci-card-t">проверок ждут отчёта</span>
                <small><?php echo $vin_hold; ?> ждут вашего решения по оплате</small>
            </a>
        </div>

        <p class="cci-actions">
            <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=china-car-cars&add=1')); ?>">+ Добавить автомобиль</a>
            <a class="button button-hero" href="<?php echo esc_url(admin_url('admin.php?page=china-car-leads')); ?>">Открыть заявки</a>
            <a class="button button-hero" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener">Открыть сайт ↗</a>
        </p>

        <div class="cci-box">
            <h2>Готовность сайта</h2>
            <ul class="cci-checks">
                <?php foreach ($checks as $c): ?>
                    <li class="<?php echo $c['ok'] ? 'ok' : 'warn'; ?>">
                        <span class="cci-dot"><?php echo $c['ok'] ? '✓' : '!'; ?></span>
                        <span class="cci-ct"><?php echo esc_html($c['text']); ?></span>
                        <?php if (!$c['ok']): ?>
                            <?php if (($c['fix'] ?? '') === 'pages'): ?>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('cci_setup_pages_action', 'cci_pages_nonce'); ?>
                                    <input type="hidden" name="cci_setup_pages" value="1">
                                    <button class="button button-small button-primary" type="submit">Настроить автоматически</button>
                                </form>
                            <?php else: ?>
                                <a class="button button-small" href="<?php echo esc_url($c['link']); ?>"><?php echo esc_html($c['link_text']); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="cci-box">
            <h2>Как пользоваться</h2>
            <ol class="cci-steps">
                <li><b>Добавьте автомобили</b> в разделе «Автомобили»: марка, цена в юанях, параметры, фото. Цена «под ключ» посчитается сама.</li>
                <li><b>Следите за курсами</b> в «Настройки → Курсы и расходы»: при их изменении цены на сайте обновятся мгновенно.</li>
                <li><b>Обрабатывайте заявки</b>: о каждой приходит письмо; в разделе «Заявки» отмечайте «В работу» и «Закрыть».</li>
                <li><b>Проверки VIN</b>: после оплаты клиентом подготовьте отчёт и отправьте его одной кнопкой в разделе «Проверки VIN».</li>
            </ol>
            <p class="cci-muted">Письма на локальном сайте (.local) не уходят наружу. На рабочем сайте для надёжной доставки писем установите плагин WP Mail SMTP.</p>
        </div>
    </div>
    <?php
}
