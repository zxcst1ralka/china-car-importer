<?php
/**
 * Фронтенд 2.0: «холст» без темы, шапка/подвал, ассеты, карточка авто,
 * AJAX-калькулятор, автонастройка страниц.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('CCI_SITE_VERSION', '2.0.0');

/* ---------------------------------------------------------------------
 * Хелперы
 * ------------------------------------------------------------------- */
function cci_asset_url($path) {
    return plugins_url('assets/' . ltrim($path, '/'), __FILE__);
}

function cci_asset_ver($path) {
    $file = plugin_dir_path(__FILE__) . 'assets/' . ltrim($path, '/');
    return file_exists($file) ? filemtime($file) : CCI_SITE_VERSION;
}

function cci_money($n) {
    return number_format((float) $n, 0, '', "\u{00A0}");
}

function cci_page_url($slug) {
    $p = get_page_by_path($slug);
    return $p ? get_permalink($p) : home_url('/' . $slug . '/');
}

function cci_engine_label($t) {
    $m = ['petrol' => 'Бензин', 'diesel' => 'Дизель', 'hybrid' => 'Гибрид', 'ev' => 'Электро'];
    return isset($m[$t]) ? $m[$t] : strtoupper((string) $t);
}

function cci_car_photos($car) {
    $p = !empty($car->cdn_images) ? json_decode($car->cdn_images, true) : [];
    return is_array($p) ? array_values(array_filter($p)) : [];
}

function cci_consent_html() {
    $p   = get_page_by_path('privacy');
    $txt = 'Нажимая кнопку, вы соглашаетесь на обработку персональных данных';
    if ($p && $p->post_status === 'publish') {
        return esc_html($txt) . ' в соответствии с <a href="' . esc_url(get_permalink($p)) . '" target="_blank" rel="noopener" style="text-decoration:underline">политикой конфиденциальности</a>.';
    }
    return esc_html($txt) . '.';
}

/** Страница, на которой выводим наш «холст»? */
function cci_is_canvas() {
    static $r = null;
    if (!did_action('wp')) {
        return false;
    }
    if ($r !== null) {
        return $r;
    }
    $r = false;
    if (is_admin()) {
        return $r;
    }
    $rt = cci_current_route();
    if ($rt['type'] !== '' || get_query_var('cci_sitemap')) {
        $r = true;
        return $r;
    }
    if (!is_singular('page')) {
        return $r;
    }
    $p = get_queried_object();
    if ($p && !empty($p->post_content)) {
        foreach (['china_cars_landing', 'china_cars_catalog', 'china_cars_vin', 'china_cars_legal'] as $sc) {
            if (has_shortcode($p->post_content, $sc)) {
                $r = true;
                break;
            }
        }
    }
    return $r;
}

/* ---------------------------------------------------------------------
 * Подключение шаблона-«холста», ассетов, отключение лишнего
 * ------------------------------------------------------------------- */
add_filter('template_include', function ($tpl) {
    if (cci_is_canvas()) {
        $own = plugin_dir_path(__FILE__) . 'templates/canvas.php';
        if (file_exists($own)) {
            return $own;
        }
    }
    return $tpl;
}, 99);

// Админ-панель WP на наших страницах скрыта (вернуть: добавьте ?bar=1 к адресу)
add_filter('show_admin_bar', function ($show) {
    if (cci_is_canvas() && !isset($_GET['bar'])) {
        return false;
    }
    return $show;
});

add_action('wp_enqueue_scripts', function () {
    if (!cci_is_canvas()) {
        return;
    }
    // Шрифты: если положить manrope.woff2 и unbounded.woff2 в assets/fonts, сайт перестанет ходить за ними в Google
    $fd = plugin_dir_path(__FILE__) . 'assets/fonts/';
    if (file_exists($fd . 'manrope.woff2') && file_exists($fd . 'unbounded.woff2')) {
        wp_register_style('cci-fonts', false);
        wp_enqueue_style('cci-fonts');
        wp_add_inline_style('cci-fonts', "@font-face{font-family:'Manrope';font-style:normal;font-weight:200 800;font-display:swap;src:url(" . cci_asset_url('fonts/manrope.woff2') . ") format('woff2')}@font-face{font-family:'Unbounded';font-style:normal;font-weight:200 900;font-display:swap;src:url(" . cci_asset_url('fonts/unbounded.woff2') . ") format('woff2')}");
    } else {
        wp_enqueue_style('cci-fonts', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Unbounded:wght@500;600;700&display=swap', [], null);
    }
    wp_enqueue_style('cci-site', cci_asset_url('css/site.css'), [], cci_asset_ver('css/site.css'));
    wp_enqueue_script('cci-site-js', cci_asset_url('js/site.js'), [], cci_asset_ver('js/site.js'), true);
    wp_localize_script('cci-site-js', 'cciData', [
        'ajax' => admin_url('admin-ajax.php'),
        'ym'   => (int) preg_replace('/\\D/', '', (string) get_option('cci_metrika_id', '')),
    ]);
}, 5);

// Выкидываем стили/скрипты темы и блоков — наш дизайн самодостаточен
add_action('wp_enqueue_scripts', function () {
    if (!cci_is_canvas()) {
        return;
    }
    global $wp_styles, $wp_scripts;
    $keep_css = ['cci-fonts', 'cci-site', 'admin-bar', 'dashicons'];
    $keep_js  = ['cci-site-js', 'admin-bar', 'hoverintent-js'];
    if ($wp_styles instanceof WP_Styles) {
        foreach ((array) $wp_styles->queue as $h) {
            if (!in_array($h, $keep_css, true)) {
                wp_dequeue_style($h);
            }
        }
    }
    if ($wp_scripts instanceof WP_Scripts) {
        foreach ((array) $wp_scripts->queue as $h) {
            if (!in_array($h, $keep_js, true)) {
                wp_dequeue_script($h);
            }
        }
    }
}, 9999);

/* ---------------------------------------------------------------------
 * SEO: заголовок, описание, Open Graph, микроразметка, значок сайта
 * ------------------------------------------------------------------- */
function cci_page_kind() {
    $rt = cci_current_route();
    if ($rt['type'] !== '') {
        return $rt['type'];
    }
    $p = get_queried_object();
    $c = ($p && !empty($p->post_content)) ? $p->post_content : '';
    foreach (['landing', 'catalog', 'vin', 'legal'] as $k) {
        if (has_shortcode($c, 'china_cars_' . $k)) {
            return $k;
        }
    }
    return '';
}

add_filter('pre_get_document_title', function ($title) {
    if (!cci_is_canvas()) {
        return $title;
    }
    $m = cci_route_meta();
    if ($m) {
        return $m['title'];
    }
    $name = get_bloginfo('name');
    $map  = [
        'landing' => trim((string) get_option('cci_home_title', '')) !== '' ? trim((string) get_option('cci_home_title')) : 'Автомобили из Китая под ключ',
        'catalog' => 'Каталог автомобилей из Китая с ценой под ключ — ' . $name,
        'vin'     => 'Проверка автомобиля по VIN (отчёт Chaboshi) — ' . $name,
    ];
    $k = cci_page_kind();
    if (isset($map[$k])) {
        return $map[$k];
    }
    $p = get_queried_object();
    return ($p ? $p->post_title . ' — ' : '') . $name;
}, 20);

function cci_render_head_meta() {
    $name = get_bloginfo('name');
    $k    = cci_page_kind();
    $m    = cci_route_meta();
    $desc = [
        'landing' => 'Привоз автомобилей из Китая под ключ: выкуп у дилера, проверка по базам, доставка, таможня в Уссурийске и ЭПТС. Фиксированная комиссия и расчёт цены онлайн.',
        'catalog' => 'Каталог автомобилей из Китая в наличии. В каждой карточке полная стоимость под ключ во Владивостоке: авто, логистика, пошлина, сборы и комиссия.',
        'vin'     => 'Проверка автомобиля из Китая по VIN: реальный пробег, ДТП, утопление, пожары, история обслуживания. Отчёт на русском языке на e-mail.',
    ];
    $url   = $m ? $m['url'] : get_permalink();
    $image = $m && $m['image'] ? $m['image'] : cci_asset_url('media/hero-poster.jpg');
    $d     = $m ? $m['desc'] : (isset($desc[$k]) ? $desc[$k] : '');

    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    if ($d !== '') {
        echo '<meta name="description" content="' . esc_attr($d) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($d) . '">' . "\n";
    }
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($name) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr(wp_get_document_title()) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
    echo '<meta property="og:locale" content="ru_RU">' . "\n";
    if ($k === 'legal' || ($m && !empty($m['noindex']))) {
        echo '<meta name="robots" content="noindex,follow">' . "\n";
    }
    if ($k === 'landing') {
        echo '<link rel="preload" as="image" href="' . esc_url(cci_asset_url('media/hero-poster.jpg')) . '">' . "\n";
    }
    if (!has_site_icon()) {
        $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="#FF4B3A"/><path d="M10 38l5-13a5 5 0 0 1 5-3h24a5 5 0 0 1 5 3l5 13v10h-5a5 5 0 0 1-10 0H25a5 5 0 0 1-10 0h-5z" fill="#fff"/></svg>');
        echo '<link rel="icon" href="data:image/svg+xml,' . $svg . '">' . "\n";
    }
    cci_render_jsonld();
    cci_render_tracking_head();
}

/* ---------------------------------------------------------------------
 * Тосты (уведомления после отправки форм)
 * ------------------------------------------------------------------- */
function cci_collect_toast() {
    static $t = false;
    if ($t !== false) {
        return $t;
    }
    $t = null;

    if (!empty($_GET['cci_notice'])) {
        $id = sanitize_key($_GET['cci_notice']);
        $f  = get_transient('cci_lead_flash_' . $id);
        if (is_array($f)) {
            delete_transient('cci_lead_flash_' . $id);
            $lid = (int) ($f['lead_id'] ?? 0);
            $car = (string) ($f['car_title'] ?? '');
            $map = [
                'success'                => ['ok', 'Заявка' . ($lid ? ' №' . $lid : '') . ' принята: ' . $car . '. Менеджер свяжется с вами в течение 15 минут.'],
                'mail_failed'            => ['ok', 'Заявка №' . $lid . ' сохранена. Менеджер увидит её в панели и свяжется с вами.'],
                'invalid_nonce'          => ['err', 'Сессия устарела. Обновите страницу и отправьте заявку ещё раз.'],
                'invalid_name'           => ['err', 'Укажите имя (минимум 2 символа).'],
                'invalid_phone'          => ['err', 'Проверьте телефон: нужно не меньше 10 цифр.'],
                'invalid_contact_method' => ['err', 'Выберите удобный способ связи.'],
                'car_not_found'          => ['err', 'Этот лот уже недоступен. Выберите другой автомобиль.'],
                'db_error'               => ['err', 'Не удалось сохранить заявку. Попробуйте ещё раз.'],
            ];
            $s = (string) ($f['status'] ?? '');
            if (isset($map[$s])) {
                $t = ['type' => $map[$s][0], 'msg' => $map[$s][1]];
            }
        }
    }

    if (!$t && !empty($_GET['cci_flash'])) {
        $id = sanitize_key($_GET['cci_flash']);
        $f  = get_transient('cci_flash_' . $id);
        if (is_array($f)) {
            delete_transient('cci_flash_' . $id);
            $t = ['type' => (($f['type'] ?? '') === 'ok') ? 'ok' : 'err', 'msg' => (string) ($f['msg'] ?? '')];
        }
    }
    return $t;
}

function cci_render_toast() {
    $t = cci_collect_toast();
    if (!$t || $t['msg'] === '') {
        return;
    }
    echo '<div class="toast ' . ($t['type'] === 'err' ? 'err' : '') . '" data-toast role="status">' . esc_html($t['msg']) . '</div>';
}

/* ---------------------------------------------------------------------
 * Защита форм от ботов (скрытая «ловушка») и перенаправление с уведомлением
 * ------------------------------------------------------------------- */
function cci_is_bot() {
    return !empty($_POST['cci_hp']);
}

function cci_honeypot_field() {
    return '<div class="hp" aria-hidden="true"><label>Не заполняйте это поле<input type="text" name="cci_hp" value="" tabindex="-1" autocomplete="off"></label></div>';
}

/** Сохраняет уведомление и возвращает пользователя на страницу (PRG: защита от повторной отправки при F5) */
function cci_flash_redirect($type, $msg, $url = '') {
    $id = wp_generate_password(16, false);
    set_transient('cci_flash_' . $id, ['type' => $type, 'msg' => $msg], 120);
    if ($url === '') {
        $url = wp_get_referer();
    }
    if (!$url) {
        $url = home_url('/');
    }
    $url = remove_query_arg(['cci_flash', 'cci_notice'], $url);
    wp_safe_redirect(add_query_arg('cci_flash', $id, $url));
    exit;
}

/* ---------------------------------------------------------------------
 * Шапка
 * ------------------------------------------------------------------- */
function cci_render_header() {
    $home  = home_url('/');
    $links = [
        [cci_page_url('catalog'), 'Каталог'],
        [$home . '#calc', 'Калькулятор'],
        [cci_page_url('vin-check'), 'Проверка VIN'],
        [$home . '#journey', 'Как мы работаем'],
        [$home . '#faq', 'Вопросы'],
    ];
    $phone = trim((string) get_option('cci_contact_phone', ''));
    $name  = get_bloginfo('name');
    $cta   = $home . '#selection';
    ?>
    <header class="hdr">
        <div class="wrap hdr-in">
            <a class="logo" href="<?php echo esc_url($home); ?>" aria-label="<?php echo esc_attr($name); ?>">
                <span class="logo-mark"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M3 14l2-5a2 2 0 0 1 2-1.3h10A2 2 0 0 1 19 9l2 5v4h-2a2 2 0 0 1-4 0H9a2 2 0 0 1-4 0H3v-4zm4-4.3L5.6 13h12.8L17 9.7H7z"/></svg></span>
                <span><?php echo esc_html($name); ?></span>
            </a>
            <nav class="nav" aria-label="Основное меню">
                <?php foreach ($links as $l): ?>
                    <a href="<?php echo esc_url($l[0]); ?>"><?php echo esc_html($l[1]); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="hdr-cta">
                <?php if ($phone !== ''): ?>
                    <a class="hdr-phone" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm" href="<?php echo esc_url($cta); ?>">Подобрать авто</a>
                <button class="burger" type="button" aria-label="Меню"><i></i><i></i></button>
            </div>
        </div>
    </header>
    <div class="mobile-menu">
        <?php foreach ($links as $l): ?>
            <a href="<?php echo esc_url($l[0]); ?>"><?php echo esc_html($l[1]); ?></a>
        <?php endforeach; ?>
        <a class="btn btn-primary" href="<?php echo esc_url($cta); ?>">Подобрать авто</a>
    </div>
    <?php
}

/* ---------------------------------------------------------------------
 * Подвал
 * ------------------------------------------------------------------- */
function cci_render_footer() {
    $name  = get_bloginfo('name');
    $home  = home_url('/');
    $cny   = (float) get_option('cci_cny_rate', 13.50);
    $eur   = (float) get_option('cci_eur_rate', 102.00);
    $phone = trim((string) get_option('cci_contact_phone', ''));
    $tg    = trim((string) get_option('cci_contact_telegram', ''));
    $wa    = preg_replace('/\D/', '', (string) get_option('cci_contact_whatsapp', ''));
    $company = trim((string) get_option('cci_company_name', ''));
    $inn     = trim((string) get_option('cci_company_inn', ''));
    $addr    = trim((string) get_option('cci_company_address', ''));
    $priv    = get_page_by_path('privacy');
    ?>
    <footer class="ftr">
        <div class="wrap">
            <div class="ftr-grid">
                <div>
                    <a class="logo" href="<?php echo esc_url($home); ?>">
                        <span class="logo-mark"><svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M3 14l2-5a2 2 0 0 1 2-1.3h10A2 2 0 0 1 19 9l2 5v4h-2a2 2 0 0 1-4 0H9a2 2 0 0 1-4 0H3v-4zm4-4.3L5.6 13h12.8L17 9.7H7z"/></svg></span>
                        <span><?php echo esc_html($name); ?></span>
                    </a>
                    <p>Прямой импорт автомобилей из Китая: выкуп, проверка, доставка, таможня в Уссурийске и ЭПТС на ваше имя.</p>
                </div>
                <div>
                    <h4>Разделы</h4>
                    <ul>
                        <li><a href="<?php echo esc_url(cci_page_url('catalog')); ?>">Каталог</a></li>
                        <li><a href="<?php echo esc_url($home . '#calc'); ?>">Калькулятор</a></li>
                        <li><a href="<?php echo esc_url(cci_page_url('vin-check')); ?>">Проверка VIN</a></li>
                        <li><a href="<?php echo esc_url($home . '#faq'); ?>">Вопросы и ответы</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Курсы в расчётах</h4>
                    <ul>
                        <li>¥ 1 = <?php echo esc_html(number_format($cny, 2, ',', ' ')); ?> ₽</li>
                        <li>€ 1 = <?php echo esc_html(number_format($eur, 2, ',', ' ')); ?> ₽</li>
                        <li>Срок: 18–25 дней</li>
                    </ul>
                </div>
                <div>
                    <h4>Связь</h4>
                    <ul>
                        <li><a href="<?php echo esc_url($home . '#selection'); ?>">Оставить заявку</a></li>
                        <?php if ($phone !== ''): ?><li><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a></li><?php endif; ?>
                        <?php if ($tg !== ''): ?><li><a href="<?php echo esc_url('https://t.me/' . ltrim($tg, '@')); ?>" target="_blank" rel="noopener">Telegram</a></li><?php endif; ?>
                        <?php if ($wa !== ''): ?><li><a href="<?php echo esc_url('https://wa.me/' . $wa); ?>" target="_blank" rel="noopener">WhatsApp</a></li><?php endif; ?>
                    </ul>
                </div>
            </div>
            <?php
            global $wpdb;
            $fbrands = (array) $wpdb->get_col("SELECT DISTINCT brand FROM {$wpdb->prefix}car_catalog WHERE status = 'active' ORDER BY brand LIMIT 8");
            if ($fbrands): ?>
                <div class="ftr-brands"><span>Марки:</span><?php foreach ($fbrands as $fb): ?><a href="<?php echo esc_url(cci_brand_url($fb)); ?>"><?php echo esc_html($fb); ?></a><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="ftr-big" aria-hidden="true"><?php echo esc_html($name); ?></div>
            <div class="ftr-bot">
                <span>© <?php echo esc_html(date('Y')); ?> <?php echo esc_html($company !== '' ? $company : $name); ?><?php echo $inn !== '' ? ', ' . esc_html($inn) : ''; ?><?php echo $addr !== '' ? ', ' . esc_html($addr) : ''; ?></span>
                <span>Расчёты ориентировочные и не являются публичной офертой.<?php if ($priv && $priv->post_status === 'publish'): ?> <a href="<?php echo esc_url(get_permalink($priv)); ?>" style="text-decoration:underline">Политика конфиденциальности</a><?php endif; ?></span>
            </div>
        </div>
    </footer>
    <?php
}

/* ---------------------------------------------------------------------
 * Окно заявки на конкретный автомобиль (форма обрабатывается в public-catalog.php)
 * ------------------------------------------------------------------- */
function cci_render_lead_modal() {
    ?>
    <div class="modal" id="cciModal" aria-hidden="true">
        <div class="modal-bg" data-close-modal></div>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="mHead">
            <button class="modal-x" type="button" data-close-modal aria-label="Закрыть">×</button>
            <h3 id="mHead">Заявка на автомобиль</h3>
            <div class="mcar">
                <img id="mPhoto" src="" alt="">
                <div><b id="mTitle"></b><span>Ориентировочно под ключ</span><em id="mPrice"></em></div>
            </div>
            <form class="form" method="post" action="">
                <?php wp_nonce_field('cci_lead_action', 'cci_lead_nonce'); ?>
                <input type="hidden" name="cci_lead_submit" value="1">
                <input type="hidden" name="car_lot_id" id="mLot" value="">
                <?php echo cci_honeypot_field(); ?>
                <div class="f"><label for="m-name">Ваше имя</label><input id="m-name" type="text" name="client_name" required placeholder="Иван" autocomplete="name"></div>
                <div class="f"><label for="m-phone">Телефон</label><input id="m-phone" type="tel" name="client_phone" required placeholder="+7 (999) 000-00-00" autocomplete="tel"></div>
                <div class="f">
                    <label>Как связаться</label>
                    <div class="seg">
                        <input type="radio" name="contact_method" id="mm1" value="whatsapp" checked><label for="mm1">WhatsApp</label>
                        <input type="radio" name="contact_method" id="mm2" value="telegram"><label for="mm2">Telegram</label>
                        <input type="radio" name="contact_method" id="mm3" value="phone"><label for="mm3">Звонок</label>
                    </div>
                </div>
                <div class="f"><label for="m-c">Комментарий</label><textarea id="m-c" name="client_comment" rows="2" placeholder="Город доставки, пожелания по комплектации…"></textarea></div>
                <button class="btn btn-primary btn-block" type="submit">Отправить заявку</button>
                <div class="agree"><?php echo cci_consent_html(); ?></div>
            </form>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------
 * Карточка автомобиля
 * ------------------------------------------------------------------- */
function cci_render_car_card($car) {
    $ph    = cci_car_photos($car);
    $img   = isset($ph[0]) ? $ph[0] : '';
    $img2  = isset($ph[1]) ? $ph[1] : '';
    $calc  = function_exists('cci_calculate_car_turnkey_price') ? cci_calculate_car_turnkey_price($car) : [];
    $total = !empty($calc['total_rub']) ? (float) $calc['total_rub'] : 0;
    $title = $car->brand . ' ' . $car->model;
    $full  = $title . ' (' . $car->year . ' г.)';
    $price = $total > 0 ? '≈ ' . cci_money($total) . ' ₽' : 'По запросу';
    $etype = (string) $car->engine_type;

    $parts = [];
    if ($total > 0) {
        $parts = [
            ['Автомобиль в Китае', $calc['car_price_rub']],
            ['Логистика', $calc['logistics_rub']],
            ['Пошлина и налоги', $calc['duty_rub']],
            ['Утильсбор и тамож. сбор', $calc['util_fee_rub'] + $calc['customs_fee_rub']],
            ['СВХ, лаборатория, ЭПТС', $calc['ussuriysk_rub']],
            ['Комиссия', $calc['commission_rub']],
        ];
    }

    ob_start();
    ?>
    <article class="card">
        <div class="card-img">
            <a class="card-ov" href="<?php echo esc_url(cci_car_url($car)); ?>" aria-label="<?php echo esc_attr($title); ?>"></a>
            <div class="tag"><span><?php echo esc_html($car->year); ?></span><span class="<?php echo $etype === 'ev' ? 'ev' : ($etype === 'hybrid' ? 'hy' : ''); ?>"><?php echo esc_html(cci_engine_label($etype)); ?></span></div>
            <?php if ($img): ?><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy"><?php else: ?><div class="ph-fallback"><span><?php echo esc_html($car->brand); ?></span><small><?php echo esc_html($car->model); ?></small></div><?php endif; ?>
            <?php if ($img2): ?><img src="<?php echo esc_url($img2); ?>" alt="" loading="lazy"><?php endif; ?>
        </div>
        <div class="card-body">
            <h3 class="card-title"><a href="<?php echo esc_url(cci_car_url($car)); ?>"><?php echo esc_html($title); ?></a></h3>
            <div class="specs">
                <?php if (!empty($car->mileage)): ?><span><?php echo esc_html(cci_money($car->mileage)); ?> км</span><?php endif; ?>
                <?php if (!empty($car->engine_cc)): ?><span><?php echo esc_html(number_format($car->engine_cc / 1000, 1, '.', '')); ?> л</span><?php endif; ?>
                <?php if (!empty($car->power_hp)): ?><span><?php echo esc_html($car->power_hp); ?> л.с.</span><?php endif; ?>
                <?php if (!empty($car->drive_type)): ?><span><?php echo esc_html(strtoupper($car->drive_type)); ?></span><?php endif; ?>
            </div>
            <div class="price">
                <small>Под ключ во Владивостоке</small>
                <strong><?php echo esc_html($price); ?><?php if ($total > 0): ?><sup class="sup">1</sup><?php endif; ?></strong>
                <div class="cn">В Китае: <?php echo esc_html(cci_money($car->price_cny)); ?> ¥</div>
            </div>
            <?php if ($parts): ?>
                <details class="brk">
                    <summary>Из чего складывается цена</summary>
                    <div class="stack">
                        <?php foreach ($parts as $i => $p): ?>
                            <i class="c<?php echo (int) $i; ?>" style="width:<?php echo esc_attr(number_format($p[1] / $total * 100, 2, '.', '')); ?>%"></i>
                        <?php endforeach; ?>
                    </div>
                    <ul class="legend">
                        <?php foreach ($parts as $i => $p): ?>
                            <li class="c<?php echo (int) $i; ?>"><span><?php echo esc_html($p[0]); ?></span><b><?php echo esc_html(cci_money($p[1])); ?> ₽</b></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
            <button type="button" class="btn btn-dark btn-block" data-open-lead
                    data-lot="<?php echo esc_attr($car->lot_id); ?>"
                    data-title="<?php echo esc_attr($full); ?>"
                    data-price="<?php echo esc_attr($price); ?>"
                    data-photo="<?php echo esc_url($img); ?>">
                Оставить заявку <span class="arrow">→</span>
            </button>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function cci_price_footnote() {
    $cny = (float) get_option('cci_cny_rate', 13.50);
    $eur = (float) get_option('cci_eur_rate', 102.00);
    $upd = (int) get_option('cci_rates_updated', 0);
    $date = date_i18n('d.m.Y', $upd ? $upd : time());
    return '<p class="footnote"><sup>1</sup> Ориентировочная стоимость «под ключ» во Владивостоке. Расчёт актуален на ' . esc_html($date) . ': курс ¥ ' . esc_html(number_format($cny, 2, ',', ' ')) . ' ₽, курс € ' . esc_html(number_format($eur, 2, ',', ' ')) . ' ₽. Включает цену автомобиля, логистику, таможенную пошлину, утилизационный и таможенный сборы, расходы в Уссурийске (СВХ, лаборатория, ЭПТС) и фиксированную комиссию. Точная смета фиксируется в договоре. Не является публичной офертой.</p>';
}

/* ---------------------------------------------------------------------
 * AJAX: интерактивный калькулятор (считает той же функцией, что и каталог)
 * ------------------------------------------------------------------- */
add_action('wp_ajax_cci_calc', 'cci_ajax_calc');
add_action('wp_ajax_nopriv_cci_calc', 'cci_ajax_calc');

function cci_ajax_calc() {
    if (!function_exists('cci_calculate_car_turnkey_price')) {
        wp_send_json_error('no_calc');
    }
    $price = min(3000000, max(10000, (float) ($_POST['price_cny'] ?? 0)));
    $cc    = min(7000, max(0, (int) ($_POST['cc'] ?? 0)));
    $hp    = min(1500, max(0, (int) ($_POST['hp'] ?? 0)));
    $age   = sanitize_key($_POST['age'] ?? 'new');
    $eng   = sanitize_key($_POST['eng'] ?? 'petrol');
    if (!in_array($eng, ['petrol', 'diesel', 'hybrid', 'ev'], true)) {
        $eng = 'petrol';
    }
    $months = ['new' => 12, 'mid' => 48, 'old' => 84];
    $m      = isset($months[$age]) ? $months[$age] : 12;

    $car = (object) [
        'price_cny'   => $price,
        'reg_date'    => date('Y-m-d', strtotime('-' . $m . ' months')),
        'year'        => (int) date('Y') - intdiv($m, 12),
        'engine_cc'   => $eng === 'ev' ? 0 : max(600, $cc),
        'power_hp'    => $hp,
        'engine_type' => $eng,
    ];
    wp_send_json_success(cci_calculate_car_turnkey_price($car));
}

/* ---------------------------------------------------------------------
 * Автонастройка страниц: Главная (лендинг), Каталог, Проверка VIN
 * ------------------------------------------------------------------- */
function cci_setup_site_pages() {
    $notes = [];

    $ensure = function ($slug, $title, $shortcode) use (&$notes) {
        $content = '<!-- wp:shortcode -->[' . $shortcode . ']<!-- /wp:shortcode -->';
        $p = get_page_by_path($slug);
        if (!$p) {
            $id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
            $notes[] = 'Создана страница «' . $title . '».';
            return (int) $id;
        }
        $upd = [];
        if (strpos($p->post_content, '[' . $shortcode) === false) {
            $upd['post_content'] = $content;
            $notes[] = 'Страница «' . $p->post_title . '» теперь выводит новый дизайн.';
        }
        if ($p->post_status !== 'publish') {
            $upd['post_status'] = 'publish';
        }
        if ($upd) {
            $upd['ID'] = $p->ID;
            wp_update_post($upd);
        }
        return (int) $p->ID;
    };

    $ensure('catalog', 'Каталог автомобилей', 'china_cars_catalog');
    $ensure('vin-check', 'Проверка VIN', 'china_cars_vin');
    $ensure('privacy', 'Политика конфиденциальности', 'china_cars_legal doc="privacy"');

    // Главная: берём текущую статическую главную, либо «home-auto», либо страницу «Главная»
    $home = null;
    $front_id = (int) get_option('page_on_front');
    if ($front_id) {
        $home = get_post($front_id);
    }
    if (!$home || $home->post_type !== 'page') {
        $home = get_page_by_path('home-auto');
    }
    if (!$home) {
        $found = get_posts(['post_type' => 'page', 'title' => 'Главная', 'numberposts' => 1, 'post_status' => 'any']);
        $home  = $found ? $found[0] : null;
    }
    $content = '<!-- wp:shortcode -->[china_cars_landing]<!-- /wp:shortcode -->';
    if ($home) {
        $home_id = (int) $home->ID;
        if (strpos($home->post_content, '[china_cars_landing') === false) {
            wp_update_post(['ID' => $home_id, 'post_content' => $content, 'post_status' => 'publish']);
            $notes[] = 'Страница «Главная» теперь выводит лендинг.';
        }
    } else {
        $home_id = (int) wp_insert_post([
            'post_title'   => 'Главная',
            'post_name'    => 'home-auto',
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
        $notes[] = 'Создана страница «Главная».';
    }
    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);

    return $notes;
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options') || wp_doing_ajax()) {
        return;
    }
    if (get_option('cci_site_version') === CCI_SITE_VERSION) {
        return;
    }
    $notes = cci_setup_site_pages();
    update_option('cci_site_version', CCI_SITE_VERSION);
    set_transient('cci_setup_notes', $notes ?: ['Новый дизайн подключён.'], 120);
});

add_action('admin_notices', function () {
    $notes = get_transient('cci_setup_notes');
    if (!$notes) {
        return;
    }
    delete_transient('cci_setup_notes');
    echo '<div class="notice notice-success is-dismissible"><p><strong>China Car Importer 2.0:</strong> ' . esc_html(implode(' ', $notes)) . ' <a href="' . esc_url(home_url('/')) . '" target="_blank">Открыть сайт ↗</a></p></div>';
});
