<?php
/**
 * Админка: заявки клиентов (с каталога и с лендинга)
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Создание / обновление таблицы заявок */
function cci_ensure_leads_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'car_leads';
    $current = '1.4';
    if (get_option('cci_leads_db_ver') === $current) {
        return;
    }
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        car_lot_id varchar(64) NOT NULL DEFAULT '',
        car_name varchar(255) NOT NULL DEFAULT '',
        calculated_price decimal(12,2) NOT NULL DEFAULT 0.00,
        client_name varchar(150) NOT NULL DEFAULT '',
        client_phone varchar(50) NOT NULL DEFAULT '',
        contact_method varchar(30) NOT NULL DEFAULT 'phone',
        client_budget varchar(100) NOT NULL DEFAULT '',
        client_link varchar(500) NOT NULL DEFAULT '',
        utm varchar(255) NOT NULL DEFAULT '',
        client_comment text NULL,
        status varchar(30) NOT NULL DEFAULT 'new',
        email_sent tinyint(1) NOT NULL DEFAULT 0,
        form_token varchar(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY car_lot_id (car_lot_id),
        KEY status (status),
        KEY form_token (form_token)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Перенос бюджета из комментария в отдельный столбец (для старых заявок)
    $rows = $wpdb->get_results("SELECT id, client_comment FROM {$table} WHERE client_budget = '' AND client_comment LIKE '%Ориентировочный бюджет:%'");
    foreach ((array) $rows as $r) {
        if (preg_match('/^(.*?)\s*Ориентировочный бюджет:\s*(.*)$/su', (string) $r->client_comment, $m)) {
            $wpdb->update($table, ['client_budget' => trim($m[2]), 'client_comment' => trim($m[1])], ['id' => $r->id]);
        }
    }
    update_option('cci_leads_db_ver', $current);
}

function cci_lead_status_labels() {
    return [
        'new'         => ['Новая', 'new'],
        'in_progress' => ['В работе', 'work'],
        'completed'   => ['Закрыта', 'done'],
        'canceled'    => ['Отменена', 'off'],
    ];
}

/** Экспорт заявок в CSV (открывается в Excel) */
function cci_export_leads_csv() {
    if (!current_user_can('manage_options') || !check_admin_referer('cci_leads_export')) {
        wp_die('Нет доступа.');
    }
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}car_leads ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="zayavki-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['№', 'Дата', 'Автомобиль', 'Лот', 'Цена под ключ, ₽', 'Клиент', 'Телефон', 'Связь', 'Бюджет', 'Комментарий', 'Ссылка на авто', 'Источник', 'Статус'], ';');
    $st = cci_lead_status_labels();
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['car_name'], $r['car_lot_id'] === 'LANDING_SELECTION' ? '' : $r['car_lot_id'],
            (float) $r['calculated_price'] > 0 ? (int) $r['calculated_price'] : '', $r['client_name'], $r['client_phone'],
            $r['contact_method'], $r['client_budget'], $r['client_comment'], $r['client_link'], $r['utm'], isset($st[$r['status']]) ? $st[$r['status']][0] : $r['status'],
        ], ';');
    }
    fclose($out);
    exit;
}
add_action('admin_post_cci_export_leads', 'cci_export_leads_csv');

/** Обработка действий над заявками (выполняется до вывода страницы, чтобы работал редирект) */
function cci_leads_handle_actions() {
    if (!current_user_can('manage_options') || !isset($_GET['do'], $_GET['lead_id'])) {
        return;
    }
    global $wpdb;
    cci_ensure_leads_table();
    $table    = $wpdb->prefix . 'car_leads';
    $labels   = cci_lead_status_labels();
    $page_url = admin_url('admin.php?page=china-car-leads');
    $lead_id  = absint($_GET['lead_id']);
    check_admin_referer('cci_lead_' . $lead_id);
    $do = sanitize_key($_GET['do']);
    if (isset($labels[$do])) {
        $wpdb->update($table, ['status' => $do], ['id' => $lead_id]);
        cci_admin_flash('ok', 'Статус заявки №' . $lead_id . ' изменён на «' . $labels[$do][0] . '».');
    } elseif ($do === 'delete') {
        $wpdb->delete($table, ['id' => $lead_id]);
        cci_admin_flash('ok', 'Заявка №' . $lead_id . ' удалена.');
    }
    wp_safe_redirect($page_url);
    exit;
}

function cci_render_leads_page() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас недостаточно прав для доступа к этой странице.');
    }
    global $wpdb;
    cci_ensure_leads_table();
    $table  = $wpdb->prefix . 'car_leads';
    $labels = cci_lead_status_labels();
    $page_url = admin_url('admin.php?page=china-car-leads');

    $filter = isset($_GET['status_filter']) ? sanitize_key($_GET['status_filter']) : 'all';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $hl     = isset($_GET['highlight']) ? absint($_GET['highlight']) : 0;

    $where = ['1=1'];
    $args  = [];
    if (isset($labels[$filter])) {
        $where[] = 'status = %s';
        $args[]  = $filter;
    }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(client_name LIKE %s OR client_phone LIKE %s OR car_name LIKE %s OR client_comment LIKE %s)';
        array_push($args, $like, $like, $like, $like);
    }
    $sql   = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200';
    $leads = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

    $counts = ['all' => 0];
    foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) c FROM {$table} GROUP BY status") as $r) {
        $counts[$r->status] = (int) $r->c;
        $counts['all'] += (int) $r->c;
    }

    $methods = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'phone' => 'Звонок'];
    ?>
    <div class="wrap cci-admin">
        <h1 class="wp-heading-inline">Заявки клиентов</h1>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cci_export_leads'), 'cci_leads_export')); ?>" class="page-title-action">Скачать в Excel (CSV)</a>
        <hr class="wp-header-end">

        <p class="cci-help">Сюда попадают все заявки с сайта: «Оставить заявку» на карточке авто и форма подбора на главной. О каждой новой заявке приходит письмо. Порядок работы: <b>В работу</b> → связались с клиентом → <b>Закрыть</b> (сделка состоялась) или <b>Отмена</b>.</p>

        <ul class="subsubsub">
            <?php
            $tabs = ['all' => 'Все', 'new' => 'Новые', 'in_progress' => 'В работе', 'completed' => 'Закрытые', 'canceled' => 'Отменённые'];
            $i = 0;
            foreach ($tabs as $key => $name):
                $i++;
                $url = $key === 'all' ? $page_url : add_query_arg('status_filter', $key, $page_url);
                ?>
                <li><a href="<?php echo esc_url($url); ?>" class="<?php echo $filter === $key || ($key === 'all' && !isset($labels[$filter])) ? 'current' : ''; ?>"><?php echo esc_html($name); ?> <span class="count">(<?php echo (int) ($counts[$key] ?? 0); ?>)</span></a><?php echo $i < count($tabs) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="get" class="search-box">
            <input type="hidden" name="page" value="china-car-leads">
            <?php if (isset($labels[$filter])): ?><input type="hidden" name="status_filter" value="<?php echo esc_attr($filter); ?>"><?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Имя, телефон, авто…">
            <input type="submit" class="button" value="Найти">
        </form>

        <table class="wp-list-table widefat striped cci-table">
            <thead>
                <tr>
                    <th style="width:48px">№</th>
                    <th style="width:110px">Дата</th>
                    <th>Автомобиль</th>
                    <th>Клиент</th>
                    <th style="width:95px">Связь</th>
                    <th style="width:130px">Бюджет</th>
                    <th>Комментарий</th>
                    <th style="width:110px">Цена под ключ</th>
                    <th style="width:100px">Статус</th>
                    <th style="width:190px">Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$leads): ?>
                <tr><td colspan="10" class="cci-empty">Заявок пока нет. Когда клиент отправит форму на сайте, она появится здесь.</td></tr>
            <?php endif; ?>
            <?php foreach ($leads as $l):
                $st    = isset($labels[$l->status]) ? $labels[$l->status] : [$l->status, 'off'];
                $nonce = wp_create_nonce('cci_lead_' . $l->id);
                $act   = function ($do) use ($l, $nonce, $page_url) {
                    return add_query_arg(['do' => $do, 'lead_id' => $l->id, '_wpnonce' => $nonce], $page_url);
                };
                $is_landing = $l->car_lot_id === 'LANDING_SELECTION';
                $tel = preg_replace('/[^0-9+]/', '', $l->client_phone);
                ?>
                <tr class="<?php echo $hl === (int) $l->id ? 'cci-hl' : ''; ?>">
                    <td><strong>#<?php echo (int) $l->id; ?></strong></td>
                    <td><?php $t = strtotime($l->created_at); echo esc_html($t ? date_i18n('d.m.Y H:i', $t) : $l->created_at); ?></td>
                    <td>
                        <strong><?php echo esc_html($l->car_name !== '' ? $l->car_name : '—'); ?></strong>
                        <?php if ($is_landing): ?><br><small class="cci-muted">Заявка на подбор (с главной)</small>
                        <?php elseif ($l->car_lot_id !== ''): ?><br><small class="cci-muted">Лот <code><?php echo esc_html($l->car_lot_id); ?></code></small><?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($l->client_name); ?></strong><br>
                        <a href="tel:<?php echo esc_attr($tel); ?>"><?php echo esc_html($l->client_phone); ?></a>
                    </td>
                    <td><?php echo esc_html(isset($methods[$l->contact_method]) ? $methods[$l->contact_method] : $l->contact_method); ?></td>
                    <td><?php echo $l->client_budget !== '' ? '<strong>' . esc_html($l->client_budget) . '</strong>' : '<span class="cci-muted">—</span>'; ?></td>
                    <td>
                        <?php echo !empty($l->client_comment) ? nl2br(esc_html($l->client_comment)) : '<span class="cci-muted">—</span>'; ?>
                        <?php if (!empty($l->client_link)): ?><br><a href="<?php echo esc_url($l->client_link); ?>" target="_blank" rel="noopener nofollow">ссылка на авто ↗</a><?php endif; ?>
                        <?php if (!empty($l->utm)): ?><br><small class="cci-muted">Источник: <?php echo esc_html($l->utm); ?></small><?php endif; ?>
                    </td>
                    <td><?php echo (float) $l->calculated_price > 0 ? '<strong>' . esc_html(number_format((float) $l->calculated_price, 0, '', ' ')) . '&nbsp;₽</strong>' : '<span class="cci-muted">—</span>'; ?></td>
                    <td>
                        <span class="cci-badge cci-b-<?php echo esc_attr($st[1]); ?>"><?php echo esc_html($st[0]); ?></span>
                        <?php if (!(int) $l->email_sent): ?><br><small class="cci-warn" title="Письмо менеджеру не ушло. Заявка сохранена здесь. Проверьте настройки почты.">письмо не ушло</small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l->status === 'new'): ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url($act('in_progress')); ?>">В работу</a>
                            <a class="button button-small" href="<?php echo esc_url($act('completed')); ?>">Закрыть</a>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('canceled')); ?>" onclick="return confirm('Отменить заявку?');">Отмена</a>
                        <?php elseif ($l->status === 'in_progress'): ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url($act('completed')); ?>">Закрыть</a>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('canceled')); ?>" onclick="return confirm('Отменить заявку?');">Отмена</a>
                        <?php else: ?>
                            <a class="button button-small" href="<?php echo esc_url($act('new')); ?>">Вернуть</a>
                            <a class="button button-small cci-danger" href="<?php echo esc_url($act('delete')); ?>" onclick="return confirm('Удалить заявку навсегда?');">Удалить</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
