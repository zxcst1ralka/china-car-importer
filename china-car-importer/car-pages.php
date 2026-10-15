<?php
/**
 * Страницы автомобилей и марок (адреса /auto/… и /marka/…), sitemap, микроразметка.
 * Нужны для поисковой выдачи: у каждого автомобиля и марки — своя индексируемая страница.
 */
if (!defined('ABSPATH')) {
    exit;
}

/* ---- Адреса и маршруты ---- */
add_action('init', function () {
    add_rewrite_rule('^auto/([^/]+)/?$', 'index.php?cci_car=$matches[1]', 'top');
    add_rewrite_rule('^marka/([^/]+)/?$', 'index.php?cci_brand=$matches[1]', 'top');
    add_rewrite_rule('^cci-sitemap\.xml$', 'index.php?cci_sitemap=1', 'top');
});

add_filter('query_vars', function ($v) {
    $v[] = 'cci_car';
    $v[] = 'cci_brand';
    $v[] = 'cci_sitemap';
    return $v;
});

// Обновление структуры БД и правил адресов после обновления плагина
add_action('init', 'cci_ensure_catalog_table', 1);
add_action('init', function () {
    if (get_option('cci_rewrite_ver') !== CCI_PLUGIN_VERSION) {
        flush_rewrite_rules(false);
        update_option('cci_rewrite_ver', CCI_PLUGIN_VERSION);
    }
}, 99);

function cci_make_car_slug($brand, $model, $year, $lot) {
    $base = strtolower(remove_accents($brand . ' ' . $model . ' ' . $year));
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base), '-');
    if ($base === '') {
        $base = 'avto';
    }
    return $base . '-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $lot));
}

function cci_backfill_car_slugs() {
    global $wpdb;
    $t = $wpdb->prefix . 'car_catalog';
    $rows = $wpdb->get_results("SELECT id, brand, model, year, lot_id FROM {$t} WHERE slug = ''");
    foreach ((array) $rows as $r) {
        $wpdb->update($t, ['slug' => cci_make_car_slug($r->brand, $r->model, $r->year, $r->lot_id)], ['id' => $r->id]);
    }
}

function cci_car_url($car) {
    $slug = !empty($car->slug) ? $car->slug : '';
    if ($slug === '') {
        return cci_page_url('catalog');
    }
    return get_option('permalink_structure') ? home_url('/auto/' . $slug . '/') : add_query_arg('cci_car', $slug, home_url('/'));
}

function cci_brand_slug($brand) {
    return strtolower(rawurldecode(sanitize_title(remove_accents((string) $brand))));
}

function cci_brand_url($brand) {
    $s = cci_brand_slug($brand);
    return get_option('permalink_structure') ? home_url('/marka/' . $s . '/') : add_query_arg('cci_brand', $s, home_url('/'));
}

function cci_get_car_by_slug($slug) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}car_catalog WHERE slug = %s LIMIT 1", $slug));
}

/** Что запрашивает посетитель: страницу автомобиля, марки или обычную страницу */
function cci_current_route() {
    static $rt = null;
    if (!did_action('wp')) {
        return ['type' => '', 'car' => null, 'brand' => ''];
    }
    if ($rt !== null) {
        return $rt;
    }
    $rt = ['type' => '', 'car' => null, 'brand' => ''];
    $slug = get_query_var('cci_car');
    if ($slug !== '' && $slug !== null) {
        $rt['type'] = 'car';
        $rt['car']  = cci_get_car_by_slug(sanitize_title($slug));
        return $rt;
    }
    $b = get_query_var('cci_brand');
    if ($b !== '' && $b !== null) {
        global $wpdb;
        $rt['type'] = 'brand';
        $want = strtolower(rawurldecode(sanitize_title($b)));
        foreach ((array) $wpdb->get_col("SELECT DISTINCT brand FROM {$wpdb->prefix}car_catalog WHERE status = 'active'") as $name) {
            if (cci_brand_slug($name) === $want) {
                $rt['brand'] = $name;
                break;
            }
        }
    }
    return $rt;
}

// Статус ответа: 404 для несуществующих страниц, 200 для найденных
add_action('template_redirect', function () {
    $rt = cci_current_route();
    if ($rt['type'] === '') {
        return;
    }
    global $wp_query;
    $found = ($rt['type'] === 'car' && $rt['car']) || ($rt['type'] === 'brand' && $rt['brand'] !== '');
    if ($found) {
        $wp_query->is_404 = false;
        status_header(200);
    } else {
        $wp_query->is_404 = true;
        status_header(404);
        nocache_headers();
    }
}, 5);

/* ---- Метаданные страницы ---- */
function cci_car_title_str($car) {
    return trim($car->brand . ' ' . $car->model);
}

function cci_route_meta() {
    $rt   = cci_current_route();
    $name = get_bloginfo('name');
    if ($rt['type'] === 'car') {
        $car = $rt['car'];
        if (!$car) {
            return ['title' => 'Автомобиль не найден — ' . $name, 'desc' => '', 'url' => home_url(add_query_arg(null, null)), 'image' => '', 'noindex' => true];
        }
        $calc  = cci_calculate_car_turnkey_price($car);
        $total = !empty($calc['total_rub']) ? (float) $calc['total_rub'] : 0;
        $t     = cci_car_title_str($car);
        $ph    = cci_car_photos($car);
        $desc  = $t . ' ' . $car->year . ' г., пробег ' . cci_money($car->mileage) . ' км, ' . mb_strtolower(cci_engine_label($car->engine_type))
               . ($car->engine_cc ? ' ' . number_format($car->engine_cc / 1000, 1, '.', '') . ' л' : '') . '.'
               . ($total > 0 ? ' Цена под ключ во Владивостоке ≈ ' . cci_money($total) . ' ₽.' : '') . ' Заявка и проверка по VIN онлайн.';
        return [
            'title'   => $t . ' ' . $car->year . ' из Китая' . ($total > 0 ? ': цена под ключ ≈ ' . cci_money($total) . ' ₽' : '') . ' — ' . $name,
            'desc'    => $desc,
            'url'     => cci_car_url($car),
            'image'   => $ph ? $ph[0] : cci_asset_url('media/hero-poster.jpg'),
            'noindex' => $car->status !== 'active',
        ];
    }
    if ($rt['type'] === 'brand') {
        if ($rt['brand'] === '') {
            return ['title' => 'Марка не найдена — ' . $name, 'desc' => '', 'url' => home_url(add_query_arg(null, null)), 'image' => '', 'noindex' => true];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) c, MIN(price_cny) m FROM {$wpdb->prefix}car_catalog WHERE status = 'active' AND brand = %s", $rt['brand']));
        return [
            'title'   => $rt['brand'] . ' из Китая: каталог и цены под ключ — ' . $name,
            'desc'    => 'Автомобили ' . $rt['brand'] . ' из Китая в наличии: ' . (int) ($row ? $row->c : 0) . ' шт. Цена под ключ во Владивостоке, расчёт стоимости, проверка по VIN и доставка.',
            'url'     => cci_brand_url($rt['brand']),
            'image'   => cci_asset_url('media/hero-poster.jpg'),
            'noindex' => false,
        ];
    }
    return null;
}

function cci_render_jsonld() {
    $rt = cci_current_route();
    $data = null;
    if ($rt['type'] === 'car' && $rt['car']) {
        $car   = $rt['car'];
        $calc  = cci_calculate_car_turnkey_price($car);
        $total = !empty($calc['total_rub']) ? (float) $calc['total_rub'] : 0;
        $data  = [
            '@context' => 'https://schema.org', '@type' => 'Product',
            'name' => cci_car_title_str($car) . ' ' . $car->year, 'sku' => $car->lot_id,
            'brand' => ['@type' => 'Brand', 'name' => $car->brand],
            'image' => cci_car_photos($car),
            'description' => 'Пробег ' . cci_money($car->mileage) . ' км. ' . cci_engine_label($car->engine_type) . ', ' . $car->power_hp . ' л.с.',
        ];
        if ($total > 0) {
            $data['offers'] = [
                '@type' => 'Offer', 'priceCurrency' => 'RUB', 'price' => (string) round($total),
                'availability' => $car->status === 'active' ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
                'url' => cci_car_url($car),
            ];
        }
    } elseif (cci_page_kind() === 'landing') {
        $data = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/')];
        $phone = trim((string) get_option('cci_contact_phone', ''));
        $addr  = trim((string) get_option('cci_company_address', ''));
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        if ($addr !== '') {
            $data['address'] = $addr;
        }
    }
    if ($data) {
        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
    }
}

/* ---- sitemap и robots.txt ---- */
add_action('template_redirect', function () {
    if (!get_query_var('cci_sitemap')) {
        return;
    }
    global $wpdb;
    $urls = [home_url('/'), cci_page_url('catalog'), cci_page_url('vin-check')];
    foreach ((array) $wpdb->get_col("SELECT DISTINCT brand FROM {$wpdb->prefix}car_catalog WHERE status = 'active'") as $b) {
        $urls[] = cci_brand_url($b);
    }
    foreach ((array) $wpdb->get_results("SELECT slug FROM {$wpdb->prefix}car_catalog WHERE status = 'active' AND slug <> ''") as $r) {
        $urls[] = cci_car_url((object) ['slug' => $r->slug]);
    }
    nocache_headers();
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (array_unique($urls) as $u) {
        echo '<url><loc>' . esc_url($u) . '</loc></url>' . "\n";
    }
    echo '</urlset>';
    exit;
}, 1);

add_filter('robots_txt', function ($out) {
    return $out . "\nSitemap: " . home_url('/cci-sitemap.xml') . "\n";
});

/* ---- Страница автомобиля ---- */
function cci_render_notfound() {
    ob_start();
    ?>
    <section class="page-head"><div class="wrap">
        <span class="eyebrow">Ошибка 404</span>
        <h1 style="font-size:clamp(30px,5vw,64px)">Такой страницы нет</h1>
        <p class="lead">Возможно, автомобиль уже продан или ссылка устарела. Посмотрите актуальный каталог.</p>
        <div class="btns" style="margin-top:24px"><a class="btn btn-primary" href="<?php echo esc_url(cci_page_url('catalog')); ?>">В каталог <span class="arrow">→</span></a></div>
    </div></section>
    <?php
    return ob_get_clean();
}

function cci_render_car_page($car) {
    global $wpdb;
    $t      = $wpdb->prefix . 'car_catalog';
    $photos = cci_car_photos($car);
    $calc   = cci_calculate_car_turnkey_price($car);
    $total  = !empty($calc['total_rub']) ? (float) $calc['total_rub'] : 0;
    $title  = cci_car_title_str($car);
    $full   = $title . ' (' . $car->year . ' г.)';
    $active = $car->status === 'active';
    $price  = $total > 0 ? '≈ ' . cci_money($total) . ' ₽' : 'По запросу';
    $vin    = strtoupper((string) $car->vin);
    $vin_m  = strlen($vin) >= 12 ? substr($vin, 0, 4) . str_repeat('•', strlen($vin) - 8) . substr($vin, -4) : '';
    $tg     = trim((string) get_option('cci_contact_telegram', ''));
    $wa     = preg_replace('/\D/', '', (string) get_option('cci_contact_whatsapp', ''));
    $parts  = [];
    if ($total > 0) {
        $parts = [
            ['Автомобиль в Китае', $calc['car_price_rub']], ['Логистика', $calc['logistics_rub']], ['Пошлина и налоги', $calc['duty_rub']],
            ['Утильсбор и тамож. сбор', $calc['util_fee_rub'] + $calc['customs_fee_rub']], ['СВХ, лаборатория, ЭПТС', $calc['ussuriysk_rub']], ['Комиссия', $calc['commission_rub']],
        ];
    }
    $similar = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status = 'active' AND id <> %d ORDER BY (brand = %s) DESC, id DESC LIMIT 3", $car->id, $car->brand));
    $desc = trim((string) $car->description);
    if ($desc === '') {
        $desc = $title . ', ' . $car->year . ' года выпуска, пробег ' . cci_money($car->mileage) . ' км. Двигатель: ' . mb_strtolower(cci_engine_label($car->engine_type))
              . ($car->engine_cc ? ', ' . number_format($car->engine_cc / 1000, 1, '.', '') . ' л' : '') . ', ' . (int) $car->power_hp . ' л.с., привод ' . strtoupper($car->drive_type)
              . '. Стоимость под ключ включает автомобиль, логистику, таможенные платежи и сборы, расходы в Уссурийске и комиссию. Перед выкупом можно проверить историю по VIN.';
    }
    $vin_link = cci_page_url('vin-check') . ($vin !== '' ? '?q=' . rawurlencode($vin) : '');

    ob_start();
    ?>
    <section class="car-page wrap">
        <nav class="crumbs" aria-label="Навигация">
            <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a><i>›</i>
            <a href="<?php echo esc_url(cci_page_url('catalog')); ?>">Каталог</a><i>›</i>
            <a href="<?php echo esc_url(cci_brand_url($car->brand)); ?>"><?php echo esc_html($car->brand); ?></a><i>›</i>
            <span><?php echo esc_html($car->model); ?></span>
        </nav>

        <?php if (!$active): ?>
            <div class="sold">Этот автомобиль уже продан или снят с продажи. Ниже похожие предложения, а по кнопке «Подобрать такой же» мы найдём аналог.</div>
        <?php endif; ?>

        <div class="car-top">
            <div class="gal" data-gallery>
                <div class="gal-main">
                    <?php if ($photos): ?><img src="<?php echo esc_url($photos[0]); ?>" alt="<?php echo esc_attr($full); ?>" data-gal-main><?php else: ?><div class="ph-fallback"><span><?php echo esc_html($car->brand); ?></span><small><?php echo esc_html($car->model); ?></small></div><?php endif; ?>
                </div>
                <?php if (count($photos) > 1): ?>
                    <div class="gal-thumbs">
                        <?php foreach ($photos as $i => $u): ?>
                            <button type="button" data-gal-thumb data-src="<?php echo esc_url($u); ?>" class="<?php echo $i === 0 ? 'on' : ''; ?>" aria-label="Фото <?php echo (int) ($i + 1); ?>"><img src="<?php echo esc_url($u); ?>" alt="" loading="lazy"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="car-info">
                <span class="eyebrow"><?php echo $active ? 'В наличии в Китае' : 'Снят с продажи'; ?></span>
                <h1 class="car-h1"><?php echo esc_html($title . ' ' . $car->year); ?></h1>
                <div class="specs">
                    <?php if (!empty($car->mileage)): ?><span><?php echo esc_html(cci_money($car->mileage)); ?> км</span><?php endif; ?>
                    <span><?php echo esc_html(cci_engine_label($car->engine_type)); ?></span>
                    <?php if (!empty($car->engine_cc)): ?><span><?php echo esc_html(number_format($car->engine_cc / 1000, 1, '.', '')); ?> л</span><?php endif; ?>
                    <?php if (!empty($car->power_hp)): ?><span><?php echo (int) $car->power_hp; ?> л.с.</span><?php endif; ?>
                    <span><?php echo esc_html(strtoupper($car->drive_type)); ?></span>
                </div>

                <div class="price big">
                    <small>Под ключ во Владивостоке</small>
                    <strong><?php echo esc_html($price); ?><?php if ($total > 0): ?><sup class="sup">1</sup><?php endif; ?></strong>
                    <div class="cn">Цена в Китае: <?php echo esc_html(cci_money($car->price_cny)); ?> ¥</div>
                </div>

                <?php if ($parts): ?>
                    <div class="stack">
                        <?php foreach ($parts as $i => $p): ?><i class="c<?php echo (int) $i; ?>" style="width:<?php echo esc_attr(number_format($p[1] / $total * 100, 2, '.', '')); ?>%"></i><?php endforeach; ?>
                    </div>
                    <ul class="legend">
                        <?php foreach ($parts as $i => $p): ?><li class="c<?php echo (int) $i; ?>"><span><?php echo esc_html($p[0]); ?></span><b><?php echo esc_html(cci_money($p[1])); ?> ₽</b></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="car-cta">
                    <button type="button" class="btn btn-primary" data-open-lead
                            data-lot="<?php echo esc_attr($car->lot_id); ?>" data-title="<?php echo esc_attr($full); ?>"
                            data-price="<?php echo esc_attr($price); ?>" data-photo="<?php echo esc_url($photos ? $photos[0] : ''); ?>">
                        <?php echo $active ? 'Оставить заявку' : 'Подобрать такой же'; ?> <span class="arrow">→</span>
                    </button>
                    <a class="btn btn-ghost" href="<?php echo esc_url($vin_link); ?>">Проверить по VIN</a>
                </div>
                <?php if ($tg !== '' || $wa !== ''): ?>
                    <div class="msgr">Или напишите нам:
                        <?php if ($tg !== ''): ?><a href="<?php echo esc_url('https://t.me/' . ltrim($tg, '@')); ?>" target="_blank" rel="noopener" data-goal="tg_click">Telegram</a><?php endif; ?>
                        <?php if ($wa !== ''): ?><a href="<?php echo esc_url('https://wa.me/' . $wa); ?>" target="_blank" rel="noopener" data-goal="wa_click">WhatsApp</a><?php endif; ?>
                    </div>
                <?php endif; ?>
                <ul class="mini-trust">
                    <li>Итоговая цена фиксируется в договоре</li>
                    <li>Перед выкупом проверяем историю по базам Китая</li>
                    <li>ЭПТС оформляется на ваше имя</li>
                </ul>
            </div>
        </div>

        <div class="car-cols">
            <div>
                <h2 class="h3">Характеристики</h2>
                <table class="spec-table">
                    <tr><th>Год выпуска</th><td><?php echo esc_html($car->year); ?></td></tr>
                    <?php if (!empty($car->reg_date)): ?><tr><th>Первая регистрация</th><td><?php echo esc_html(date_i18n('d.m.Y', strtotime($car->reg_date))); ?></td></tr><?php endif; ?>
                    <tr><th>Пробег</th><td><?php echo esc_html(cci_money($car->mileage)); ?> км</td></tr>
                    <tr><th>Двигатель</th><td><?php echo esc_html(cci_engine_label($car->engine_type)); ?><?php echo !empty($car->engine_cc) ? ', ' . esc_html(number_format($car->engine_cc / 1000, 1, '.', '')) . ' л' : ''; ?></td></tr>
                    <tr><th>Мощность</th><td><?php echo (int) $car->power_hp; ?> л.с.</td></tr>
                    <tr><th>Привод</th><td><?php echo esc_html(strtoupper($car->drive_type)); ?></td></tr>
                    <?php if ($vin_m !== ''): ?><tr><th>VIN</th><td><code><?php echo esc_html($vin_m); ?></code> <a href="<?php echo esc_url($vin_link); ?>" class="link-arrow">проверить</a></td></tr><?php endif; ?>
                    <tr><th>Номер лота</th><td><?php echo esc_html($car->lot_id); ?></td></tr>
                </table>
            </div>
            <div>
                <h2 class="h3">Об автомобиле</h2>
                <div class="car-desc"><?php echo nl2br(esc_html($desc)); ?></div>
            </div>
        </div>

        <?php if ($similar): ?>
            <h2 class="h3" style="margin-top:56px">Похожие предложения</h2>
            <div class="grid" style="margin-top:20px">
                <?php foreach ($similar as $s) { echo cci_render_car_card($s); } ?>
            </div>
        <?php endif; ?>
        <?php echo cci_price_footnote(); ?>
    </section>
    <?php
    return ob_get_clean();
}

/* ---- Страница марки ---- */
function cci_render_brand_page($brand) {
    global $wpdb;
    $t    = $wpdb->prefix . 'car_catalog';
    $cars = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status = 'active' AND brand = %s ORDER BY id DESC LIMIT 60", $brand));
    $min  = null;
    $max  = null;
    foreach ($cars as $c) {
        $calc = cci_calculate_car_turnkey_price($c);
        $tt   = (float) ($calc['total_rub'] ?? 0);
        if ($tt > 0) {
            $min = $min === null ? $tt : min($min, $tt);
            $max = $max === null ? $tt : max($max, $tt);
        }
    }
    $others = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT brand FROM {$t} WHERE status = 'active' AND brand <> %s ORDER BY brand", $brand));

    ob_start();
    ?>
    <section class="page-head">
        <div class="wrap">
            <nav class="crumbs"><a href="<?php echo esc_url(home_url('/')); ?>">Главная</a><i>›</i><a href="<?php echo esc_url(cci_page_url('catalog')); ?>">Каталог</a><i>›</i><span><?php echo esc_html($brand); ?></span></nav>
            <h1 style="margin-top:14px"><?php echo esc_html($brand); ?> из Китая<br><em>под ключ</em></h1>
            <p class="lead">В каталоге <?php echo (int) count($cars); ?> авто <?php echo esc_html($brand); ?><?php echo $min !== null ? ': цена под ключ от ' . esc_html(cci_money($min)) . ' ₽' . ($max !== null && $max > $min ? ' до ' . esc_html(cci_money($max)) . ' ₽' : '') : ''; ?>. В цену входят автомобиль, логистика, пошлина, сборы и комиссия.</p>
        </div>
    </section>
    <section class="wrap" style="padding-bottom:clamp(70px,9vw,120px)">
        <div class="grid">
            <?php foreach ($cars as $car) { echo cci_render_car_card($car); } ?>
        </div>
        <?php echo cci_price_footnote(); ?>
        <?php if ($others): ?>
            <div class="chips" style="margin-top:40px"><span class="lbl">Другие марки</span>
                <?php foreach ($others as $o): ?><a class="chip" href="<?php echo esc_url(cci_brand_url($o)); ?>"><?php echo esc_html($o); ?></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}
