<?php
/**
 * Админка: проверки VIN / ссылок — оплаты, холды, отправка отчёта клиенту
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Создание / обновление таблицы проверок */
function cci_ensure_vin_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'vin_requests';
    $current = '1.1';
    if (get_option('cci_vin_db_ver') === $current) {
        return;
    }
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL DEFAULT 'vin',
        query_value text NOT NULL,
        client_phone varchar(50) NOT NULL DEFAULT '',
        client_email varchar(100) NOT NULL DEFAULT '',
        amount_rub decimal(10,2) NOT NULL DEFAULT 0.00,
        payment_id varchar(100) NOT NULL DEFAULT '',
        payment_status varchar(50) NOT NULL DEFAULT 'pending',
        report_url text NULL,
        report_sent_at datetime NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY payment_id (payment_id),
        KEY payment_status (payment_status)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('cci_vin_db_ver', $current);
}

function cci_vin_status_labels() {
    return [
        'succeeded'           => ['Оплачено', 'done'],
        'waiting_for_capture' => ['Деньги заморожены', 'work'],
        'unpaid'              => ['Без онлайн-оплаты', 'new'],
        'pending'             => ['Ожидает оплаты', 'off'],
        'canceled'            => ['Отменено', 'off'],
    ];
}

/** Действия: списать/отменить холд, отметить оплаченной, отправить отчёт, удалить */
function cci_vin_handle_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    cci_ensure_vin_table();
    $table = $wpdb->prefix . 'vin_requests';
    $back  = admin_url('admin.php?page=china-car-vin-requests');

    // Отправка отчёта клиенту (форма)
    if (!empty($_POST['cci_send_report'])) {
        $id = absint($_POST['req_id'] ?? 0);
        check_admin_referer('cci_report_' . $id, 'cci_report_nonce');
        $url = esc_url_raw(trim(wp_unslash($_POST['report_url'] ?? '')));
        $msg = sanitize_textarea_field(wp_unslash($_POST['report_message'] ?? ''));
        if ($url === '') {
            cci_admin_flash('err', 'Укажите ссылку на отчёт или загрузите файл кнопкой «Выбрать файл».');
        } else {
            $res = cci_send_vin_report_email($id, $url, $msg);
            if (is_wp_error($res)) {
                cci_admin_flash('err', $res->get_error_message());
            } else {
                $wpdb->update($table, ['report_url' => $url, 'report_sent_at' => current_time('mysql')], ['id' => $id]);
                cci_admin_flash('ok', 'Отчёт отправлен клиенту на e-mail.');
            }
        }
        wp_safe_redirect(add_query_arg('highlight', $id, $back));
        exit;
    }

    if (!isset($_GET['do'], $_GET['req_id'])) {
        return;
    }
    $id = absint($_GET['req_id']);
    check_admin_referer('cci_vin_' . $id);
    $do = sanitize_key($_GET['do']);

    if ($do === 'capture') {
        $res = cci_capture_held_payment($id);
        if (is_wp_error($res)) {
            cci_admin_flash('err', $res->get_error_message());
        } else {
            cci_send_vin_payment_emails($id);
            cci_admin_flash('ok', 'Деньги списаны. Клиенту и менеджеру отправлены письма. Теперь подготовьте отчёт и отправьте его клиенту.');
        }
    } elseif ($do === 'cancel') {
        $res = cci_cancel_held_payment($id);
        cci_admin_flash(is_wp_error($res) ? 'err' : 'ok', is_wp_error($res) ? $res->get_error_message() : 'Холд отменён: деньги разморожены на карте клиента.');
    } elseif ($do === 'mark_paid') {
        $wpdb->update($table, ['payment_status' => 'succeeded'], ['id' => $id]);
        cci_admin_flash('ok', 'Проверка отмечена оплаченной. Можно отправлять отчёт.');
    } elseif ($do === 'delete') {
        $wpdb->delete($table, ['id' => $id]);
        cci_admin_flash('ok', 'Запись удалена.');
    }
    wp_safe_redirect(add_query_arg('highlight', $id, $back));
    exit;
}

function cci_render_vin_requests_page() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас недостаточно прав для доступа к этой странице.');
    }
    global $wpdb;
    cci_ensure_vin_table();
    $table    = $wpdb->prefix . 'vin_requests';
    $labels   = cci_vin_status_labels();
    $back     = admin_url('admin.php?page=china-car-vin-requests');
    $hl       = isset($_GET['highlight']) ? absint($_GET['highlight']) : 0;
    $requests = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100");
    $has_pay  = get_option('cci_yk_shop_id') && get_option('cci_yk_secret_key');
    ?>
    <div class="wrap cci-admin">
        <h1>Проверки автомобилей (VIN и ссылки)</h1>

        <div class="cci-help">
            <b>Как это работает.</b> Клиент вводит VIN или ссылку на объявление на странице «Проверка VIN» и оплачивает. Вы получаете письмо.
            Дальше отчёт по базам Китая (например, Chaboshi) вы получаете сами и отправляете клиенту отсюда кнопкой <b>«Отправить отчёт»</b>: загрузите PDF или вставьте ссылку.
            Сайт сам отчёт не формирует.
            <?php if (!$has_pay): ?><br><b style="color:#b32d2e">Онлайн-оплата не подключена</b> (не заполнены ключи ЮKassa в «Настройки → Оплата»). Заявки приходят без оплаты: свяжитесь с клиентом, выставите счёт и нажмите «Отметить оплаченной».<?php endif; ?>
            <br><b>Ссылка на лот:</b> деньги только замораживаются. Проверьте, что отчёт возможен, и нажмите «Списать». Если нет — «Отменить», деньги вернутся клиенту.
        </div>

        <table class="wp-list-table widefat striped cci-table">
            <thead>
                <tr>
                    <th style="width:48px">№</th>
                    <th style="width:110px">Дата</th>
                    <th>Что проверяем</th>
                    <th>Клиент</th>
                    <th style="width:90px">Сумма</th>
                    <th style="width:150px">Оплата</th>
                    <th style="width:330px">Отчёт / действие</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$requests): ?>
                <tr><td colspan="7" class="cci-empty">Пока нет проверок. Они появятся здесь, когда клиент отправит форму на странице «Проверка VIN».</td></tr>
            <?php endif; ?>
            <?php foreach ($requests as $r):
                $st    = isset($labels[$r->payment_status]) ? $labels[$r->payment_status] : [$r->payment_status, 'off'];
                $nonce = wp_create_nonce('cci_vin_' . $r->id);
                $act   = function ($do) use ($r, $nonce, $back) {
                    return add_query_arg(['do' => $do, 'req_id' => $r->id, '_wpnonce' => $nonce], $back);
                };
                ?>
                <tr class="<?php echo $hl === (int) $r->id ? 'cci-hl' : ''; ?>">
                    <td><strong>#<?php echo (int) $r->id; ?></strong></td>
                    <td><?php $t = strtotime($r->created_at); echo esc_html($t ? date_i18n('d.m.Y H:i', $t) : $r->created_at); ?></td>
                    <td>
                        <span class="cci-badge <?php echo $r->type === 'vin' ? 'cci-b-work' : 'cci-b-new'; ?>"><?php echo $r->type === 'vin' ? 'VIN' : 'ССЫЛКА'; ?></span><br>
                        <?php if ($r->type === 'url'): ?>
                            <a href="<?php echo esc_url($r->query_value); ?>" target="_blank" rel="noopener" style="word-break:break-all"><?php echo esc_html($r->query_value); ?></a>
                        <?php else: ?>
                            <code style="font-size:14px"><?php echo esc_html($r->query_value); ?></code>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($r->client_phone); ?><br><small class="cci-muted"><?php echo esc_html($r->client_email); ?></small></td>
                    <td><strong><?php echo esc_html(number_format((float) $r->amount_rub, 0, '.', ' ')); ?>&nbsp;₽</strong></td>
                    <td><span class="cci-badge cci-b-<?php echo esc_attr($st[1]); ?>"><?php echo esc_html($st[0]); ?></span></td>
                    <td>
                        <?php if ($r->payment_status === 'waiting_for_capture'): ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url($act('capture')); ?>" onclick="return confirm('Списать замороженные деньги с карты клиента?');">Списать деньги</a>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('cancel')); ?>" onclick="return confirm('Отменить и вернуть деньги клиенту?');">Отменить и вернуть</a>
                        <?php elseif ($r->payment_status === 'unpaid'): ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url($act('mark_paid')); ?>" onclick="return confirm('Клиент оплатил? Отметить проверку оплаченной?');">Отметить оплаченной</a>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('delete')); ?>" onclick="return confirm('Удалить запись?');">Удалить</a>
                        <?php elseif ($r->payment_status === 'succeeded'): ?>
                            <?php if (!empty($r->report_sent_at)): ?>
                                <span class="cci-badge cci-b-done">Отчёт отправлен <?php echo esc_html(date_i18n('d.m H:i', strtotime($r->report_sent_at))); ?></span>
                                <?php if (!empty($r->report_url)): ?><a href="<?php echo esc_url($r->report_url); ?>" target="_blank" rel="noopener">открыть</a><?php endif; ?>
                            <?php endif; ?>
                            <details class="cci-details" <?php echo (empty($r->report_sent_at) && $hl === (int) $r->id) ? 'open' : ''; ?>>
                                <summary class="button button-small <?php echo empty($r->report_sent_at) ? 'button-primary' : ''; ?>"><?php echo empty($r->report_sent_at) ? 'Отправить отчёт' : 'Отправить ещё раз'; ?></summary>
                                <form method="post" action="<?php echo esc_url($back); ?>" class="cci-mini-form">
                                    <?php wp_nonce_field('cci_report_' . $r->id, 'cci_report_nonce'); ?>
                                    <input type="hidden" name="cci_send_report" value="1">
                                    <input type="hidden" name="req_id" value="<?php echo (int) $r->id; ?>">
                                    <label>Отчёт (ссылка или файл)</label>
                                    <div class="cci-row">
                                        <input type="url" name="report_url" id="rep-<?php echo (int) $r->id; ?>" value="<?php echo esc_attr((string) $r->report_url); ?>" placeholder="https://…" class="regular-text">
                                        <button type="button" class="button cci-pick-file" data-target="#rep-<?php echo (int) $r->id; ?>">Выбрать файл</button>
                                    </div>
                                    <label>Сообщение клиенту (необязательно)</label>
                                    <textarea name="report_message" rows="2" class="large-text" placeholder="Например: по автомобилю замечаний нет, можно покупать."></textarea>
                                    <button type="submit" class="button button-primary">Отправить клиенту на <?php echo esc_html($r->client_email); ?></button>
                                </form>
                            </details>
                        <?php elseif (in_array($r->payment_status, ['pending', 'canceled'], true)): ?>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('delete')); ?>" onclick="return confirm('Удалить запись?');">Удалить</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
