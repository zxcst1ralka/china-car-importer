<?php
/**
 * Все письма сайта: заявки, оплаты, отправка отчёта клиенту.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Получатели уведомлений (настройка «Почта для уведомлений», по умолчанию — почта администратора) */
function cci_notify_recipients() {
    $raw  = (string) get_option('cci_notify_email', '');
    $list = array_values(array_filter(array_map('trim', explode(',', $raw)), 'is_email'));
    return $list ? $list : [get_option('admin_email')];
}

function cci_mail_headers() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    return [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <no-reply@' . ($host ? $host : 'localhost') . '>',
    ];
}

/**
 * Единый «каркас» письма.
 * $rows: массив [подпись, HTML-значение] — значения должны быть уже экранированы.
 */
function cci_email_shell($title, $subtitle, $rows, $btn_url = '', $btn_text = '', $head_bg = '#0f172a', $intro_html = '') {
    $out  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;color:#334155;">';
    $out .= '<div style="background:' . esc_attr($head_bg) . ';color:#fff;padding:20px 24px;"><h2 style="margin:0;font-size:19px;">' . esc_html($title) . '</h2>';
    if ($subtitle !== '') {
        $out .= '<p style="margin:6px 0 0;font-size:14px;opacity:.85;">' . esc_html($subtitle) . '</p>';
    }
    $out .= '</div><div style="padding:24px;line-height:1.6;">';
    if ($intro_html !== '') {
        $out .= '<div style="margin-bottom:16px;">' . $intro_html . '</div>';
    }
    if ($rows) {
        $out .= '<table style="width:100%;border-collapse:collapse;">';
        foreach ($rows as $r) {
            $out .= '<tr><td style="padding:7px 12px 7px 0;color:#64748b;width:150px;vertical-align:top;">' . esc_html($r[0]) . '</td><td style="padding:7px 0;">' . $r[1] . '</td></tr>';
        }
        $out .= '</table>';
    }
    if ($btn_url !== '') {
        $out .= '<p style="text-align:center;margin:26px 0 4px;"><a href="' . esc_url($btn_url) . '" style="background:#2271b1;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;display:inline-block;font-weight:600;">' . esc_html($btn_text) . '</a></p>';
    }
    $out .= '</div></div>';
    return $out;
}

/** Письмо менеджеру о новой заявке (с каталога или с лендинга). Кнопка ведёт в «Заявки». */
function cci_send_lead_email($lead_id) {
    global $wpdb;
    $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}car_leads WHERE id = %d", $lead_id));
    if (!$lead) {
        return false;
    }
    $methods = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'phone' => 'Звонок'];
    $rows = [
        ['Автомобиль', '<strong>' . esc_html($lead->car_name) . '</strong>' . ($lead->car_lot_id && $lead->car_lot_id !== 'LANDING_SELECTION' ? ' <span style="color:#64748b;">(лот ' . esc_html($lead->car_lot_id) . ')</span>' : '')],
    ];
    if ((float) $lead->calculated_price > 0) {
        $rows[] = ['Цена под ключ', '<strong style="color:#16a34a;">' . esc_html(number_format((float) $lead->calculated_price, 0, '', ' ')) . ' ₽</strong>'];
    }
    $rows[] = ['Клиент', '<strong>' . esc_html($lead->client_name) . '</strong>'];
    $rows[] = ['Телефон', '<strong style="color:#2563eb;">' . esc_html($lead->client_phone) . '</strong>'];
    $rows[] = ['Способ связи', esc_html(isset($methods[$lead->contact_method]) ? $methods[$lead->contact_method] : $lead->contact_method)];
    if (!empty($lead->client_budget)) {
        $rows[] = ['Бюджет', esc_html($lead->client_budget)];
    }
    if (!empty($lead->client_comment)) {
        $rows[] = ['Комментарий', nl2br(esc_html($lead->client_comment))];
    }
    if (!empty($lead->client_link)) {
        $rows[] = ['Ссылка на авто', '<a href="' . esc_url($lead->client_link) . '">' . esc_html($lead->client_link) . '</a>'];
    }
    if (!empty($lead->utm)) {
        $rows[] = ['Источник', esc_html($lead->utm)];
    }

    $url  = admin_url('admin.php?page=china-car-leads&highlight=' . (int) $lead->id);
    $body = cci_email_shell('Новая заявка #' . (int) $lead->id, get_bloginfo('name'), $rows, $url, 'Открыть заявку в админке');
    $subj = 'Новая заявка #' . (int) $lead->id . ': ' . $lead->car_name;
    return wp_mail(cci_notify_recipients(), $subj, $body, cci_mail_headers());
}

/** Письма об успешной оплате проверки: клиенту и менеджеру */
function cci_send_vin_payment_emails($request_id) {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}vin_requests WHERE id = %d", $request_id));
    if (!$r) {
        return false;
    }
    $sum  = number_format((float) $r->amount_rub, 0, '.', ' ') . ' ₽';
    $type = $r->type === 'vin' ? 'Проверка по VIN' : 'Анализ ссылки / лота';

    if (is_email($r->client_email)) {
        $rows = [
            ['Номер заказа', '<strong>#' . (int) $r->id . '</strong>'],
            ['Что проверяем', '<strong>' . esc_html($r->query_value) . '</strong>'],
            ['Услуга', esc_html($type)],
            ['Сумма', '<strong>' . esc_html($sum) . '</strong>'],
        ];
        $intro = '<p>Здравствуйте! Мы получили оплату. Специалист формирует отчёт по базам Китая — готовый русскоязычный отчёт придёт на этот e-mail.</p>';
        wp_mail($r->client_email, 'Оплата принята: проверка ' . $r->query_value, cci_email_shell('Заявка на проверку оплачена', get_bloginfo('name'), $rows, '', '', '#2271b1', $intro), cci_mail_headers());
    }

    $rows = [
        ['Запрос', '<strong>' . esc_html($r->query_value) . '</strong>'],
        ['Услуга', esc_html($type)],
        ['Клиент', esc_html($r->client_phone) . ' (' . esc_html($r->client_email) . ')'],
        ['Сумма', '<strong style="color:#2e7d32;">' . esc_html($sum) . '</strong>'],
    ];
    $intro = '<p>Нужно подготовить отчёт и отправить его клиенту. Это делается в админке одной кнопкой: «Отправить отчёт».</p>';
    wp_mail(cci_notify_recipients(), 'Оплачена проверка #' . (int) $r->id . ' (' . $sum . ')', cci_email_shell('Новая оплаченная проверка', get_bloginfo('name'), $rows, admin_url('admin.php?page=china-car-vin-requests&highlight=' . (int) $r->id), 'Открыть проверку в админке', '#1d2327', $intro), cci_mail_headers());
    return true;
}

/** Письмо менеджеру: клиент оставил заявку на проверку без онлайн-оплаты */
function cci_send_vin_unpaid_email($request_id) {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}vin_requests WHERE id = %d", $request_id));
    if (!$r) {
        return false;
    }
    $rows = [
        ['Запрос', '<strong>' . esc_html($r->query_value) . '</strong>'],
        ['Телефон', esc_html($r->client_phone)],
        ['E-mail', esc_html($r->client_email)],
        ['Стоимость', esc_html(number_format((float) $r->amount_rub, 0, '.', ' ')) . ' ₽'],
    ];
    $intro = '<p>Онлайн-оплата на сайте не подключена, поэтому клиент оставил заявку без оплаты. Свяжитесь с ним, выставьте счёт и отметьте проверку оплаченной в админке.</p>';
    return wp_mail(cci_notify_recipients(), 'Заявка на проверку #' . (int) $r->id . ' (без онлайн-оплаты)', cci_email_shell('Заявка на проверку авто', get_bloginfo('name'), $rows, admin_url('admin.php?page=china-car-vin-requests&highlight=' . (int) $r->id), 'Открыть в админке', '#e26f00', $intro), cci_mail_headers());
}

/** Отправка готового отчёта клиенту (ссылка и/или вложенный файл из медиатеки) */
function cci_send_vin_report_email($request_id, $report_url, $message = '') {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}vin_requests WHERE id = %d", $request_id));
    if (!$r || !is_email($r->client_email)) {
        return new WP_Error('no_email', 'У заявки не указан корректный e-mail клиента.');
    }
    $intro = '<p>Здравствуйте! Отчёт по вашей проверке готов.</p>';
    if ($message !== '') {
        $intro .= '<p>' . nl2br(esc_html($message)) . '</p>';
    }
    $rows = [['Проверка', '<strong>' . esc_html($r->query_value) . '</strong>']];

    $attachments = [];
    if ($report_url !== '') {
        $att_id = attachment_url_to_postid($report_url);
        $path   = $att_id ? get_attached_file($att_id) : '';
        if ($path && file_exists($path) && filesize($path) < 10 * 1024 * 1024) {
            $attachments[] = $path;
        }
    }
    $btn_url = $report_url;
    $btn_txt = $attachments ? 'Открыть отчёт онлайн' : 'Открыть отчёт';
    $ok = wp_mail($r->client_email, 'Отчёт по проверке готов: ' . $r->query_value, cci_email_shell('Ваш отчёт готов', get_bloginfo('name'), $rows, $btn_url, $btn_txt, '#2271b1', $intro), cci_mail_headers(), $attachments);
    return $ok ? true : new WP_Error('mail', 'Письмо не отправилось. Проверьте настройки почты на сервере.');
}
