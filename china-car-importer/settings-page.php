<?php
/**
 * Админка: настройки (вкладки «Курсы и расходы», «Оплата и проверки», «Контакты и почта»)
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'cci_register_settings');

function cci_register_settings() {
    $float = function ($v) {
        return max(0.0, floatval(str_replace(',', '.', (string) $v)));
    };
    $int  = function ($v) {
        return absint($v);
    };
    $text = 'sanitize_text_field';

    // Курсы и расходы
    foreach ([
        'cci_cny_rate' => 13.50, 'cci_eur_rate' => 102.00, 'cci_logistics_cny' => 14000,
        'cci_ussuriysk_expenses_rub' => 55000, 'cci_commission_rub' => 50000,
    ] as $key => $def) {
        register_setting('cci_g_calc', $key, ['type' => 'number', 'sanitize_callback' => $float, 'default' => $def]);
    }

    // Оплата и проверки
    register_setting('cci_g_pay', 'cci_vin_price_rub', ['type' => 'number', 'sanitize_callback' => $int, 'default' => 1500]);
    register_setting('cci_g_pay', 'cci_url_price_rub', ['type' => 'number', 'sanitize_callback' => $int, 'default' => 2500]);
    register_setting('cci_g_pay', 'cci_yk_shop_id', ['type' => 'string', 'sanitize_callback' => $text, 'default' => '']);
    register_setting('cci_g_pay', 'cci_yk_secret_key', [
        'type' => 'string',
        'default' => '',
        // Пустое поле = «оставить сохранённый ключ» (ключ в форме не показывается)
        'sanitize_callback' => function ($v) {
            $v = sanitize_text_field((string) $v);
            return $v === '' ? (string) get_option('cci_yk_secret_key', '') : $v;
        },
    ]);

    // Чек по 54-ФЗ (передаётся в ЮKassa вместе с платежом)
    register_setting('cci_g_pay', 'cci_yk_receipt', ['type' => 'string', 'sanitize_callback' => function ($v) { return $v ? '1' : ''; }, 'default' => '']);
    register_setting('cci_g_pay', 'cci_yk_vat_code', ['type' => 'number', 'sanitize_callback' => function ($v) { $v = (int) $v; return ($v >= 1 && $v <= 6) ? $v : 1; }, 'default' => 1]);
    register_setting('cci_g_pay', 'cci_yk_tax_system', ['type' => 'number', 'sanitize_callback' => function ($v) { $v = (int) $v; return ($v >= 1 && $v <= 6) ? $v : 0; }, 'default' => 0]);

    // Сайт и аналитика
    register_setting('cci_g_site', 'cci_home_title', ['type' => 'string', 'sanitize_callback' => $text, 'default' => '']);
    register_setting('cci_g_site', 'cci_stat_delivered', ['type' => 'number', 'sanitize_callback' => $int, 'default' => 0]);
    register_setting('cci_g_site', 'cci_stat_since', ['type' => 'number', 'sanitize_callback' => $int, 'default' => 0]);
    foreach (['cci_terms_prepay', 'cci_terms_stages', 'cci_terms_warranty', 'cci_terms_return'] as $key) {
        register_setting('cci_g_site', $key, ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '']);
    }
    register_setting('cci_g_site', 'cci_metrika_id', ['type' => 'string', 'sanitize_callback' => function ($v) { return preg_replace('/\D/', '', (string) $v); }, 'default' => '']);
    register_setting('cci_g_site', 'cci_head_code', [
        'type' => 'string', 'default' => '',
        'sanitize_callback' => function ($v) {
            return current_user_can('unfiltered_html') ? (string) $v : (string) get_option('cci_head_code', '');
        },
    ]);

    // Контакты и почта
    foreach (['cci_contact_phone', 'cci_contact_telegram', 'cci_contact_whatsapp', 'cci_notify_email', 'cci_company_name', 'cci_company_inn', 'cci_company_address'] as $key) {
        register_setting('cci_g_contact', $key, ['type' => 'string', 'sanitize_callback' => $text, 'default' => '']);
    }
}

// Запоминаем, когда в последний раз меняли курсы (показываем напоминание в «Обзоре»)
foreach (['cci_cny_rate', 'cci_eur_rate'] as $cci_rate_opt) {
    add_action('update_option_' . $cci_rate_opt, function () {
        update_option('cci_rates_updated', time());
    });
}

function cci_settings_row($label, $input_html, $help = '') {
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $input_html;
    if ($help !== '') {
        echo '<p class="description">' . wp_kses_post($help) . '</p>';
    }
    echo '</td></tr>';
}

function cci_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас недостаточно прав для доступа к этой странице.');
    }
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'calc';
    $tabs = ['calc' => 'Курсы и расходы', 'pay' => 'Оплата и проверки', 'site' => 'Сайт и аналитика', 'contact' => 'Контакты и почта'];
    if (!isset($tabs[$tab])) {
        $tab = 'calc';
    }
    $base = admin_url('admin.php?page=china-car-settings');
    $o    = function ($k, $d = '') {
        return esc_attr(get_option($k, $d));
    };
    ?>
    <div class="wrap cci-admin">
        <h1>Настройки</h1>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $k => $n): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', $k, $base)); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($n); ?></a>
            <?php endforeach; ?>
        </h2>

        <?php if (!empty($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Настройки сохранены.</strong> Цены на сайте обновятся сразу.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php" class="cci-settings">
            <?php settings_fields('cci_g_' . $tab); ?>
            <table class="form-table" role="presentation">
            <?php if ($tab === 'calc'):
                $upd = (int) get_option('cci_rates_updated', 0);
                ?>
                <tr><td colspan="2" class="cci-help" style="padding:12px 16px">Эти значения используются во всех расчётах: в карточках каталога, в калькуляторе на главной и в заявках. <b>Курсы нужно обновлять вручную</b>
                    <?php echo $upd ? '(последний раз вы меняли их ' . esc_html(date_i18n('d.m.Y', $upd)) . ')' : ''; ?>.</td></tr>
                <?php
                cci_settings_row('Курс юаня, ₽', '<input name="cci_cny_rate" type="number" step="0.01" min="0" value="' . $o('cci_cny_rate', 13.5) . '" class="small-text"> ₽ за 1 ¥', 'Курс, по которому вы покупаете юани (например, продажа в вашем банке).');
                cci_settings_row('Курс евро, ₽', '<input name="cci_eur_rate" type="number" step="0.01" min="0" value="' . $o('cci_eur_rate', 102) . '" class="small-text"> ₽ за 1 €', 'Курс ЦБ для расчёта таможенной пошлины.');
                cci_settings_row('Логистика до Уссурийска', '<input name="cci_logistics_cny" type="number" step="1" min="0" value="' . $o('cci_logistics_cny', 14000) . '" class="small-text"> ¥', 'Стоимость доставки автомобиля из Китая в юанях.');
                cci_settings_row('Расходы в Уссурийске', '<input name="cci_ussuriysk_expenses_rub" type="number" step="1" min="0" value="' . $o('cci_ussuriysk_expenses_rub', 55000) . '" class="small-text"> ₽', 'СВХ, лаборатория, СБКТС, ЭПТС.');
                cci_settings_row('Ваша комиссия', '<input name="cci_commission_rub" type="number" step="1" min="0" value="' . $o('cci_commission_rub', 50000) . '" class="small-text"> ₽', 'Фиксированная комиссия. Показывается на сайте в блоке цифр и входит в цену.');
            elseif ($tab === 'pay'):
                $webhook = rest_url('cci/v1/yookassa');
                $has_keys = get_option('cci_yk_shop_id') && get_option('cci_yk_secret_key');
                ?>
                <tr><td colspan="2" class="cci-help" style="padding:12px 16px">
                    Платная проверка автомобиля по VIN или ссылке. <?php echo $has_keys ? '<b style="color:#1a7f37">Онлайн-оплата подключена.</b>' : '<b style="color:#b32d2e">Онлайн-оплата не подключена:</b> заявки на проверку будут приходить без оплаты, менеджер выставит счёт вручную.'; ?>
                </td></tr>
                <?php
                cci_settings_row('Проверка по VIN', '<input name="cci_vin_price_rub" type="number" min="0" value="' . $o('cci_vin_price_rub', 1500) . '" class="small-text"> ₽', 'Оплата списывается сразу.');
                cci_settings_row('Проверка по ссылке', '<input name="cci_url_price_rub" type="number" min="0" value="' . $o('cci_url_price_rub', 2500) . '" class="small-text"> ₽', 'Деньги замораживаются на карте клиента, а вы списываете их кнопкой в разделе «Проверки VIN», когда убедитесь, что отчёт возможен.');
                ?>
                <tr><th colspan="2"><h2 style="margin:20px 0 0">Подключение ЮKassa</h2></th></tr>
                <?php
                cci_settings_row('Shop ID', '<input name="cci_yk_shop_id" type="text" value="' . $o('cci_yk_shop_id') . '" class="regular-text" placeholder="например, 123456">', 'Идентификатор магазина из личного кабинета ЮKassa.');
                cci_settings_row('Секретный ключ', '<input name="cci_yk_secret_key" type="password" value="" class="regular-text" autocomplete="new-password" placeholder="' . ($has_keys ? '••••••••  (сохранён, оставьте пустым, чтобы не менять)' : 'live_… или test_…') . '">', 'Ключ из «Интеграция → Ключи API». Для проверки без реальных списаний используйте тестовый магазин ЮKassa и его тестовые ключи.');
                $vat = (int) get_option('cci_yk_vat_code', 1);
                $tax = (int) get_option('cci_yk_tax_system', 0);
                $vat_opts = [1 => 'Без НДС', 2 => 'НДС 0%', 3 => 'НДС 10%', 4 => 'НДС 20%', 5 => 'НДС 10/110', 6 => 'НДС 20/120'];
                $tax_opts = [0 => 'Не передавать (использовать настройки магазина)', 1 => 'ОСН', 2 => 'УСН (доходы)', 3 => 'УСН (доходы минус расходы)', 5 => 'ЕСХН', 6 => 'Патент'];
                $vat_html = '<select name="cci_yk_vat_code">';
                foreach ($vat_opts as $k => $n) { $vat_html .= '<option value="' . $k . '"' . selected($vat, $k, false) . '>' . esc_html($n) . '</option>'; }
                $vat_html .= '</select>';
                $tax_html = '<select name="cci_yk_tax_system">';
                foreach ($tax_opts as $k => $n) { $tax_html .= '<option value="' . $k . '"' . selected($tax, $k, false) . '>' . esc_html($n) . '</option>'; }
                $tax_html .= '</select>';
                cci_settings_row('Чек по 54-ФЗ', '<label><input type="checkbox" name="cci_yk_receipt" value="1" ' . checked(get_option('cci_yk_receipt'), '1', false) . '> Передавать данные для чека вместе с платежом</label>', 'Включите, если в ЮKassa у магазина подключены чеки. Тогда платёж без данных чека будет отклонён. Чек уйдёт на e-mail клиента.');
                cci_settings_row('НДС в чеке', $vat_html);
                cci_settings_row('Система налогообложения', $tax_html, 'Обычно достаточно настроек магазина в ЮKassa.');
                cci_settings_row('Адрес для уведомлений', '<input type="text" readonly value="' . esc_attr($webhook) . '" class="large-text code" onclick="this.select()">', 'Укажите этот адрес в кабинете ЮKassa: «Интеграция → HTTP-уведомления» и отметьте события <code>payment.succeeded</code>, <code>payment.waiting_for_capture</code>, <code>payment.canceled</code>. Без него сайт не узнает, что клиент оплатил. Адрес должен быть доступен из интернета (на локальном сайте .local он работать не будет).');
            elseif ($tab === 'site'):
                ?>
                <tr><td colspan="2" class="cci-help" style="padding:12px 16px">Тексты и цифры, которые усиливают доверие к сайту. Всё необязательно: пустые блоки посетителям не показываются.
                    <b>Отзывы, кейсы, документы и вопросы-ответы</b> добавляются в соответствующих разделах меню «Авто из Китая».</td></tr>
                <tr><th colspan="2"><h2 style="margin:10px 0 0">Заголовок главной страницы</h2></th></tr>
                <?php
                cci_settings_row('Заголовок во вкладке браузера и в поиске', '<input name="cci_home_title" type="text" value="' . $o('cci_home_title') . '" class="large-text" placeholder="Автомобили из Китая под ключ">', 'Если оставить пустым, будет «Автомобили из Китая под ключ». Название сайта в начало не добавляется.');
                ?>
                <tr><th colspan="2"><h2 style="margin:10px 0 0">Цифры на главной</h2></th></tr>
                <?php
                cci_settings_row('Доставлено автомобилей', '<input name="cci_stat_delivered" type="number" min="0" value="' . $o('cci_stat_delivered', 0) . '" class="small-text">', 'Только реальное число. Оставьте 0 или пусто, чтобы не показывать.');
                cci_settings_row('Работаем с года', '<input name="cci_stat_since" type="number" min="1990" max="' . (int) date('Y') . '" value="' . ($o('cci_stat_since', 0) ?: '') . '" class="small-text" placeholder="2019">', 'Покажем «N+ лет на рынке».');
                ?>
                <tr><th colspan="2"><h2 style="margin:20px 0 0">Условия сделки</h2></th></tr>
                <?php
                cci_settings_row('Предоплата', '<textarea name="cci_terms_prepay" rows="3" class="large-text" placeholder="Например: 10% от стоимости автомобиля после подписания договора.">' . esc_textarea(get_option('cci_terms_prepay', '')) . '</textarea>', 'Опишите так, как закреплено в вашем договоре.');
                cci_settings_row('Этапы оплаты', '<textarea name="cci_terms_stages" rows="3" class="large-text" placeholder="Например: 1) предоплата, 2) оплата выкупа, 3) таможня, 4) остаток при выдаче.">' . esc_textarea(get_option('cci_terms_stages', '')) . '</textarea>');
                cci_settings_row('Гарантии', '<textarea name="cci_terms_warranty" rows="3" class="large-text" placeholder="Что гарантируете: цену, сроки, документы, страховку груза…">' . esc_textarea(get_option('cci_terms_warranty', '')) . '</textarea>');
                cci_settings_row('Если автомобиль не подойдёт', '<textarea name="cci_terms_return" rows="3" class="large-text" placeholder="Что происходит, если отчёт показал проблемы или клиент передумал.">' . esc_textarea(get_option('cci_terms_return', '')) . '</textarea>');
                ?>
                <tr><th colspan="2"><h2 style="margin:20px 0 0">Аналитика</h2></th></tr>
                <?php
                cci_settings_row('Номер счётчика Яндекс.Метрики', '<input name="cci_metrika_id" type="text" value="' . $o('cci_metrika_id') . '" class="regular-text" placeholder="12345678">', 'Цифры из кода счётчика. Посещения администраторов не считаются. На сайте уже настроены цели: <code>lead_modal_open</code>, <code>quiz_step_2</code>, <code>quiz_submit</code>, <code>calc_used</code>, <code>vin_started</code>, <code>vin_submit</code>, <code>tg_click</code>, <code>wa_click</code>, <code>phone_click</code>. Создайте их в Метрике как «JavaScript-событие».');
                cci_settings_row('Свой код в &lt;head&gt;', '<textarea name="cci_head_code" rows="4" class="large-text code" ' . (current_user_can('unfiltered_html') ? '' : 'disabled') . ' placeholder="Другие счётчики, пиксели (необязательно)">' . esc_textarea(get_option('cci_head_code', '')) . '</textarea>', 'Вставляйте только код из проверенных источников.');
                ?>
                <tr><td colspan="2" class="cci-help" style="padding:12px 16px"><b>Шрифты.</b> Чтобы сайт не обращался к Google (в России это может работать нестабильно), положите файлы <code>manrope.woff2</code> и <code>unbounded.woff2</code> в папку плагина <code>assets/fonts</code>. Сайт подхватит их автоматически.</td></tr>
                <?php
            else:
                cci_settings_row('Почта для уведомлений', '<input name="cci_notify_email" type="text" value="' . $o('cci_notify_email') . '" class="regular-text" placeholder="' . esc_attr(get_option('admin_email')) . '">', 'Сюда приходят письма о новых заявках и оплаченных проверках. Можно несколько адресов через запятую. Если пусто, используется почта администратора сайта (' . esc_html(get_option('admin_email')) . ').');
                ?>
                <tr><th colspan="2"><h2 style="margin:20px 0 0">Контакты в шапке и подвале сайта</h2></th></tr>
                <?php
                cci_settings_row('Наименование', '<input name="cci_company_name" type="text" value="' . $o('cci_company_name') . '" class="regular-text" placeholder="ИП Иванов Иван Иванович">', 'Показывается в подвале и в политике конфиденциальности.');
                cci_settings_row('ИНН / ОГРНИП', '<input name="cci_company_inn" type="text" value="' . $o('cci_company_inn') . '" class="regular-text" placeholder="ИНН 0000000000">');
                cci_settings_row('Адрес', '<input name="cci_company_address" type="text" value="' . $o('cci_company_address') . '" class="large-text" placeholder="Город, улица, дом">');
                cci_settings_row('Телефон', '<input name="cci_contact_phone" type="text" value="' . $o('cci_contact_phone') . '" class="regular-text" placeholder="+7 (900) 000-00-00">', 'Если оставить пустым, на сайте телефон не показывается.');
                cci_settings_row('Telegram', '<input name="cci_contact_telegram" type="text" value="' . $o('cci_contact_telegram') . '" class="regular-text" placeholder="username">', 'Имя пользователя без @.');
                cci_settings_row('WhatsApp', '<input name="cci_contact_whatsapp" type="text" value="' . $o('cci_contact_whatsapp') . '" class="regular-text" placeholder="79000000000">', 'Номер цифрами, с кодом страны.');
            endif; ?>
            </table>
            <?php submit_button('Сохранить'); ?>
        </form>
    </div>
    <?php
}
