<?php
/**
 * Админка: автомобили каталога — список, добавление, редактирование, архив, удаление
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Действия (выполняются до вывода страницы, чтобы работал редирект) */
function cci_cars_handle_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'car_catalog';
    $list  = admin_url('admin.php?page=china-car-cars');

    // Сохранение формы
    if (!empty($_POST['cci_save_car'])) {
        $id = absint($_POST['car_id'] ?? 0);
        check_admin_referer('cci_car_form_' . $id, 'cci_car_nonce');
        list($data, $errors) = cci_validate_car_input($_POST);
        if ($errors) {
            $GLOBALS['cci_car_form'] = ['id' => $id, 'data' => (object) $data, 'errors' => $errors, 'images' => json_decode($data['cdn_images'], true)];
            return;
        }
        $res = cci_save_car($id, $data);
        if (is_wp_error($res)) {
            $GLOBALS['cci_car_form'] = ['id' => $id, 'data' => (object) $data, 'errors' => [$res->get_error_message()], 'images' => json_decode($data['cdn_images'], true)];
            return;
        }
        $car  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $res));
        $calc = $car ? cci_calculate_car_turnkey_price($car) : [];
        $sum  = !empty($calc['total_rub']) ? ' Цена под ключ на сайте: ≈ ' . number_format($calc['total_rub'], 0, '', ' ') . ' ₽.' : '';
        cci_admin_flash('ok', ($id ? 'Автомобиль сохранён.' : 'Автомобиль добавлен на сайт.') . $sum);
        wp_safe_redirect($list);
        exit;
    }

    // Архив / возврат / удаление
    if (isset($_GET['do'], $_GET['car_id'])) {
        $id = absint($_GET['car_id']);
        check_admin_referer('cci_car_' . $id);
        $lot = $wpdb->get_var($wpdb->prepare("SELECT lot_id FROM {$table} WHERE id = %d", $id));
        $do  = sanitize_key($_GET['do']);
        if ($lot && $do === 'archive') {
            cci_archive_car($lot);
            cci_admin_flash('ok', 'Автомобиль убран с сайта (в архиве). Вернуть его можно в любой момент.');
        } elseif ($lot && $do === 'restore') {
            cci_restore_car($lot);
            cci_admin_flash('ok', 'Автомобиль снова виден на сайте.');
        } elseif ($lot && $do === 'delete') {
            cci_delete_car($id);
            cci_admin_flash('ok', 'Автомобиль удалён.');
        }
        wp_safe_redirect($list);
        exit;
    }
}

function cci_render_cars_page() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас недостаточно прав для доступа к этой странице.');
    }
    if (isset($_GET['add']) || isset($_GET['edit']) || !empty($GLOBALS['cci_car_form'])) {
        cci_render_car_form();
        return;
    }
    cci_render_cars_list();
}

function cci_render_cars_list() {
    global $wpdb;
    $table  = $wpdb->prefix . 'car_catalog';
    $base   = admin_url('admin.php?page=china-car-cars');
    $filter = isset($_GET['f']) ? sanitize_key($_GET['f']) : 'active';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged  = max(1, absint($_GET['paged'] ?? 1));
    $per    = 30;

    $where = ['1=1'];
    $args  = [];
    if ($filter === 'active') { $where[] = "status = 'active'"; }
    if ($filter === 'archive') { $where[] = "status <> 'active'"; }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(brand LIKE %s OR model LIKE %s OR vin LIKE %s OR lot_id LIKE %s)';
        array_push($args, $like, $like, $like, $like);
    }
    $w = implode(' AND ', $where);
    $total = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$w}", $args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$w}"));
    $q = "SELECT * FROM {$table} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
    $cars = $wpdb->get_results($wpdb->prepare($q, array_merge($args, [$per, ($paged - 1) * $per])));

    $c_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $c_act = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
    ?>
    <div class="wrap cci-admin">
        <h1 class="wp-heading-inline">Автомобили в каталоге</h1>
        <a href="<?php echo esc_url(add_query_arg('add', 1, $base)); ?>" class="page-title-action">Добавить автомобиль</a>
        <hr class="wp-header-end">

        <p class="cci-help">Здесь вы управляете каталогом на сайте. <b>Добавить автомобиль</b> — заполните карточку, и она сразу появится на сайте с расчётом цены «под ключ». Проданную или неактуальную машину лучше отправить <b>в архив</b> (её можно вернуть), а не удалять.</p>

        <ul class="subsubsub">
            <li><a href="<?php echo esc_url($base); ?>" class="<?php echo $filter === 'active' ? 'current' : ''; ?>">На сайте <span class="count">(<?php echo $c_act; ?>)</span></a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('f', 'archive', $base)); ?>" class="<?php echo $filter === 'archive' ? 'current' : ''; ?>">В архиве <span class="count">(<?php echo $c_all - $c_act; ?>)</span></a> |</li>
            <li><a href="<?php echo esc_url(add_query_arg('f', 'all', $base)); ?>" class="<?php echo $filter === 'all' ? 'current' : ''; ?>">Все <span class="count">(<?php echo $c_all; ?>)</span></a></li>
        </ul>
        <form method="get" class="search-box">
            <input type="hidden" name="page" value="china-car-cars">
            <input type="hidden" name="f" value="<?php echo esc_attr($filter); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Марка, модель, VIN…">
            <input type="submit" class="button" value="Найти">
        </form>

        <table class="wp-list-table widefat striped cci-table">
            <thead>
                <tr>
                    <th style="width:96px">Фото</th>
                    <th>Автомобиль</th>
                    <th>Параметры</th>
                    <th style="width:120px">Цена в Китае</th>
                    <th style="width:150px">Цена под ключ</th>
                    <th style="width:90px">Статус</th>
                    <th style="width:230px">Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$cars): ?>
                <tr><td colspan="7" class="cci-empty">
                    <?php echo $search !== '' ? 'Ничего не найдено.' : 'Автомобилей пока нет. Нажмите «Добавить автомобиль», чтобы создать первую карточку.'; ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($cars as $car):
                $photos = cci_car_photos($car);
                $calc   = cci_calculate_car_turnkey_price($car);
                $nonce  = wp_create_nonce('cci_car_' . $car->id);
                $act    = function ($do) use ($car, $nonce, $base) {
                    return add_query_arg(['do' => $do, 'car_id' => $car->id, '_wpnonce' => $nonce], $base);
                };
                $active = $car->status === 'active';
                ?>
                <tr>
                    <td><?php if ($photos): ?><img src="<?php echo esc_url($photos[0]); ?>" alt="" class="cci-thumb" loading="lazy"><?php else: ?><span class="cci-muted">нет фото</span><?php endif; ?></td>
                    <td>
                        <strong><a href="<?php echo esc_url(add_query_arg('edit', $car->id, $base)); ?>"><?php echo esc_html($car->brand . ' ' . $car->model); ?></a></strong>
                        <br><small class="cci-muted">Лот <code><?php echo esc_html($car->lot_id); ?></code></small>
                        <?php if ($car->vin): ?><br><small class="cci-muted">VIN <code><?php echo esc_html($car->vin); ?></code></small><?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($car->year); ?> г., <?php echo esc_html(number_format((int) $car->mileage, 0, '', ' ')); ?> км<br>
                        <?php echo esc_html(cci_engine_label($car->engine_type)); ?><?php echo $car->engine_cc ? ', ' . esc_html(number_format($car->engine_cc / 1000, 1, '.', '')) . ' л' : ''; ?>, <?php echo esc_html($car->power_hp); ?> л.с., <?php echo esc_html(strtoupper($car->drive_type)); ?>
                    </td>
                    <td><strong><?php echo esc_html(number_format((float) $car->price_cny, 0, '', ' ')); ?> ¥</strong><br><small class="cci-muted">≈ <?php echo esc_html(number_format($calc['car_price_rub'] ?? 0, 0, '', ' ')); ?> ₽</small></td>
                    <td>
                        <strong class="cci-price"><?php echo esc_html(number_format($calc['total_rub'] ?? 0, 0, '', ' ')); ?> ₽</strong>
                        <details class="cci-details"><summary>Из чего состоит</summary>
                            <div class="cci-estimate">
                                Авто: <?php echo esc_html(number_format($calc['car_price_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                Логистика: <?php echo esc_html(number_format($calc['logistics_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                Пошлина и налоги: <?php echo esc_html(number_format($calc['duty_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                Утильсбор: <?php echo esc_html(number_format($calc['util_fee_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                Таможенный сбор: <?php echo esc_html(number_format($calc['customs_fee_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                СВХ, лаборатория, ЭПТС: <?php echo esc_html(number_format($calc['ussuriysk_rub'] ?? 0, 0, '', ' ')); ?> ₽<br>
                                Комиссия: <?php echo esc_html(number_format($calc['commission_rub'] ?? 0, 0, '', ' ')); ?> ₽
                            </div>
                        </details>
                    </td>
                    <td><span class="cci-badge <?php echo $active ? 'cci-b-done' : 'cci-b-off'; ?>"><?php echo $active ? 'На сайте' : 'Архив'; ?></span></td>
                    <td>
                        <a class="button button-small button-primary" href="<?php echo esc_url(add_query_arg('edit', $car->id, $base)); ?>">Изменить</a>
                        <?php if ($active && !empty($car->slug)): ?><a class="button button-small" href="<?php echo esc_url(cci_car_url($car)); ?>" target="_blank" rel="noopener">На сайте ↗</a><?php endif; ?>
                        <?php if ($active): ?>
                            <a class="button button-small" href="<?php echo esc_url($act('archive')); ?>" onclick="return confirm('Убрать автомобиль с сайта? Его можно будет вернуть из архива.');">В архив</a>
                        <?php else: ?>
                            <a class="button button-small" href="<?php echo esc_url($act('restore')); ?>">Вернуть</a>
                        <?php endif; ?>
                        <a class="button button-small cci-danger" href="<?php echo esc_url($act('delete')); ?>" onclick="return confirm('Удалить автомобиль навсегда? Это нельзя отменить. Чтобы просто убрать с сайта — используйте «В архив».');">Удалить</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $pages = (int) ceil($total / $per);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%', $base), 'format' => '', 'current' => $paged, 'total' => $pages,
            ])) . '</div></div>';
        }
        ?>
    </div>
    <?php
}

function cci_render_car_form() {
    global $wpdb;
    $table = $wpdb->prefix . 'car_catalog';
    $base  = admin_url('admin.php?page=china-car-cars');
    $form  = isset($GLOBALS['cci_car_form']) ? $GLOBALS['cci_car_form'] : null;

    $id = $form ? (int) $form['id'] : absint($_GET['edit'] ?? 0);
    if ($form) {
        $car    = $form['data'];
        $images = is_array($form['images']) ? $form['images'] : [];
        $errors = $form['errors'];
    } elseif ($id) {
        $car = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$car) {
            wp_die('Автомобиль не найден.');
        }
        $images = cci_car_photos($car);
        $errors = [];
    } else {
        $car = (object) [
            'brand' => '', 'model' => '', 'year' => (int) date('Y'), 'reg_date' => '', 'mileage' => 0, 'engine_type' => 'petrol',
            'engine_cc' => 2000, 'power_hp' => 0, 'drive_type' => 'fwd', 'price_cny' => '', 'vin' => '', 'status' => 'active', 'lot_id' => '', 'description' => '',
        ];
        $images = [];
        $errors = [];
    }
    $v = function ($k) use ($car) {
        return isset($car->$k) ? $car->$k : '';
    };
    ?>
    <div class="wrap cci-admin">
        <h1><?php echo $id ? 'Изменить автомобиль' : 'Добавить автомобиль'; ?></h1>
        <p><a href="<?php echo esc_url($base); ?>">← К списку автомобилей</a></p>

        <?php if ($errors): ?>
            <div class="notice notice-error"><p><strong>Не удалось сохранить:</strong></p><ul style="list-style:disc;margin-left:20px">
                <?php foreach ($errors as $e): ?><li><?php echo esc_html($e); ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($id ? add_query_arg('edit', $id, $base) : add_query_arg('add', 1, $base)); ?>" class="cci-form">
            <?php wp_nonce_field('cci_car_form_' . $id, 'cci_car_nonce'); ?>
            <input type="hidden" name="cci_save_car" value="1">
            <input type="hidden" name="car_id" value="<?php echo (int) $id; ?>">

            <div class="cci-cols">
                <div class="cci-box">
                    <h2>Основное</h2>
                    <div class="cci-grid2">
                        <p><label>Марка *</label><input type="text" name="brand" value="<?php echo esc_attr($v('brand')); ?>" placeholder="Zeekr" required></p>
                        <p><label>Модель *</label><input type="text" name="model" value="<?php echo esc_attr($v('model')); ?>" placeholder="001" required></p>
                        <p><label>Год выпуска *</label><input type="number" name="year" value="<?php echo esc_attr($v('year')); ?>" min="2000" max="<?php echo (int) date('Y') + 1; ?>" required></p>
                        <p><label>Пробег, км</label><input type="number" name="mileage" value="<?php echo esc_attr($v('mileage')); ?>" min="0"></p>
                        <p><label>Цена в Китае, ¥ *</label><input type="number" name="price_cny" value="<?php echo esc_attr($v('price_cny') !== '' ? (float) $v('price_cny') : ''); ?>" min="1" step="1" required>
                            <span class="description">Цена автомобиля в юанях, как у дилера. Цена «под ключ» рассчитается сама.</span></p>
                        <p><label>VIN (необязательно)</label><input type="text" name="vin" value="<?php echo esc_attr($v('vin')); ?>" maxlength="30" style="text-transform:uppercase"></p>
                    </div>
                </div>

                <div class="cci-box">
                    <h2>Двигатель и расчёт пошлины</h2>
                    <div class="cci-grid2">
                        <p><label>Тип двигателя *</label>
                            <select name="engine_type" id="cci-engine">
                                <?php foreach (['petrol' => 'Бензин', 'diesel' => 'Дизель', 'hybrid' => 'Гибрид', 'ev' => 'Электро'] as $k => $n): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($v('engine_type'), $k); ?>><?php echo esc_html($n); ?></option>
                                <?php endforeach; ?>
                            </select></p>
                        <p id="cci-cc-wrap"><label>Объём, см³ *</label><input type="number" name="engine_cc" value="<?php echo esc_attr($v('engine_cc')); ?>" min="0" max="8000">
                            <span class="description">Например, 1998 для 2.0 л</span></p>
                        <p><label>Мощность, л.с.</label><input type="number" name="power_hp" value="<?php echo esc_attr($v('power_hp')); ?>" min="0"></p>
                        <p><label>Привод</label>
                            <select name="drive_type">
                                <?php foreach (['fwd' => 'Передний (FWD)', 'rwd' => 'Задний (RWD)', '4wd' => 'Полный (4WD)'] as $k => $n): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($v('drive_type'), $k); ?>><?php echo esc_html($n); ?></option>
                                <?php endforeach; ?>
                            </select></p>
                        <p style="grid-column:1/-1"><label>Дата первой регистрации</label><input type="date" name="reg_date" value="<?php echo esc_attr($v('reg_date')); ?>">
                            <span class="description">От возраста автомобиля зависит пошлина (до 3 лет, 3–5 лет, старше). Если дата неизвестна, оставьте пустой: возьмём 1 июля года выпуска.</span></p>
                    </div>
                </div>
            </div>

            <div class="cci-box">
                <h2>Фотографии</h2>
                <p class="description">Первое фото — главное, оно показывается на карточке. Второе появляется при наведении курсора.</p>
                <input type="hidden" name="images" id="cci-images" value="<?php echo esc_attr(wp_json_encode($images, JSON_UNESCAPED_SLASHES)); ?>">
                <div id="cci-photos" class="cci-photos"></div>
                <p>
                    <button type="button" class="button button-primary" id="cci-add-photos">Выбрать фото из медиатеки</button>
                    <span class="cci-muted"> или вставьте ссылку: </span>
                    <input type="url" id="cci-photo-url" placeholder="https://…" class="regular-text">
                    <button type="button" class="button" id="cci-add-photo-url">Добавить</button>
                </p>
            </div>

            <div class="cci-box">
                <h2>Описание</h2>
                <p class="description">Необязательно. Показывается на странице автомобиля: комплектация, состояние, особенности. Если оставить пустым, текст сформируется автоматически из характеристик.</p>
                <textarea name="description" rows="5" class="large-text" placeholder="Например: полный привод, панорамная крыша, адаптивный круиз, одна хозяйка…"><?php echo esc_textarea((string) $v('description')); ?></textarea>
            </div>

            <div class="cci-box">
                <h2>Публикация</h2>
                <p><label><input type="radio" name="status" value="active" <?php checked($v('status') !== 'archive'); ?>> Показывать на сайте</label><br>
                   <label><input type="radio" name="status" value="archive" <?php checked($v('status') === 'archive'); ?>> Не показывать (архив)</label></p>
                <?php if ($id && $v('lot_id')): ?><p class="cci-muted">Номер лота: <code><?php echo esc_html($v('lot_id')); ?></code> (присваивается автоматически)</p><?php endif; ?>
            </div>

            <p>
                <button type="submit" class="button button-primary button-hero"><?php echo $id ? 'Сохранить изменения' : 'Добавить на сайт'; ?></button>
                <a class="button button-hero" href="<?php echo esc_url($base); ?>">Отмена</a>
            </p>
        </form>
    </div>
    <?php
}
