<?php
/**
 * Управляемый контент сайта: отзывы, кейсы, документы, вопросы-ответы.
 * Всё добавляется в админке в меню «Авто из Китая». Пустые блоки посетителям не показываются.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'cci_register_content_types');

function cci_register_content_types() {
    $defs = [
        'cci_review' => ['Отзывы', 'Отзыв', 'Добавить отзыв', 'dashicons-format-quote', ['title', 'editor', 'thumbnail']],
        'cci_case'   => ['Кейсы', 'Кейс', 'Добавить кейс', 'dashicons-awards', ['title', 'editor', 'thumbnail']],
        'cci_doc'    => ['Документы', 'Документ', 'Добавить документ', 'dashicons-media-document', ['title', 'editor', 'thumbnail']],
        'cci_faq'    => ['Вопросы и ответы', 'Вопрос', 'Добавить вопрос', 'dashicons-editor-help', ['title', 'editor', 'page-attributes']],
    ];
    foreach ($defs as $type => $d) {
        register_post_type($type, [
            'labels' => [
                'name' => $d[0], 'singular_name' => $d[1], 'menu_name' => $d[0], 'all_items' => $d[0],
                'add_new' => $d[2], 'add_new_item' => $d[2], 'new_item' => $d[2], 'edit_item' => 'Изменить',
                'search_items' => 'Найти', 'not_found' => 'Пока ничего нет. Нажмите «' . $d[2] . '».',
                'featured_image' => 'Фото', 'set_featured_image' => 'Выбрать фото', 'remove_featured_image' => 'Убрать фото', 'use_featured_image' => 'Использовать как фото',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'china-car-import', 'show_in_rest' => false,
            'exclude_from_search' => true, 'publicly_queryable' => false, 'has_archive' => false, 'rewrite' => false,
            'supports' => $d[4], 'hierarchical' => false,
        ]);
    }
}

// Фото в записях этих типов работают, даже если тема не поддерживает миниатюры
add_action('after_setup_theme', function () {
    if (!current_theme_supports('post-thumbnails')) {
        add_theme_support('post-thumbnails', ['cci_review', 'cci_case', 'cci_doc']);
    }
}, 20);

// Простой классический редактор вместо блочного
add_filter('use_block_editor_for_post_type', function ($use, $type) {
    return in_array($type, ['cci_review', 'cci_case', 'cci_doc', 'cci_faq'], true) ? false : $use;
}, 10, 2);

add_filter('enter_title_here', function ($text, $post) {
    $map = [
        'cci_review' => 'Имя клиента, например: Алексей',
        'cci_case'   => 'Автомобиль, например: Zeekr 001, 2023',
        'cci_doc'    => 'Название, например: Договор поставки',
        'cci_faq'    => 'Вопрос клиента',
    ];
    return isset($map[$post->post_type]) ? $map[$post->post_type] : $text;
}, 10, 2);

/* ---- Дополнительные поля ---- */
function cci_meta_fields($type) {
    $all = [
        'cci_review' => [
            '_cci_car'    => ['Автомобиль', 'text', 'Zeekr 001'],
            '_cci_city'   => ['Город клиента', 'text', 'Хабаровск'],
            '_cci_rating' => ['Оценка', 'rating', ''],
            '_cci_link'   => ['Ссылка на оригинал отзыва (необязательно)', 'url', 'https://…'],
        ],
        'cci_case' => [
            '_cci_price_cny' => ['Цена в Китае, ¥', 'number', '235000'],
            '_cci_total_rub' => ['Итого получилось, ₽', 'number', '5828642'],
            '_cci_days'      => ['Срок от выкупа до выдачи, дней', 'number', '21'],
            '_cci_city'      => ['Город клиента', 'text', 'Новосибирск'],
        ],
    ];
    return isset($all[$type]) ? $all[$type] : [];
}

add_action('add_meta_boxes', function () {
    foreach (['cci_review' => 'Подробности отзыва', 'cci_case' => 'Цифры кейса'] as $type => $title) {
        add_meta_box('cci_meta_' . $type, $title, 'cci_render_meta_box', $type, 'normal', 'high');
    }
});

function cci_render_meta_box($post) {
    wp_nonce_field('cci_meta_' . $post->ID, 'cci_meta_nonce');
    echo '<table class="form-table" role="presentation">';
    foreach (cci_meta_fields($post->post_type) as $key => $f) {
        $val = get_post_meta($post->ID, $key, true);
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($f[0]) . '</label></th><td>';
        if ($f[1] === 'rating') {
            $val = $val ? (int) $val : 5;
            echo '<select name="' . esc_attr($key) . '" id="' . esc_attr($key) . '">';
            for ($i = 5; $i >= 1; $i--) {
                echo '<option value="' . $i . '"' . selected($val, $i, false) . '>' . str_repeat('★', $i) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="' . esc_attr($f[1]) . '" class="regular-text" name="' . esc_attr($key) . '" id="' . esc_attr($key) . '" value="' . esc_attr($val) . '" placeholder="' . esc_attr($f[2]) . '">';
        }
        echo '</td></tr>';
    }
    echo '</table>';
    if ($post->post_type === 'cci_review') {
        echo '<p class="description">Текст отзыва пишите в поле выше. «Фото» справа (необязательно) — снимок клиента с автомобилем.</p>';
    } else {
        echo '<p class="description">Расскажите историю в поле выше. «Фото» справа — главное фото выданного автомобиля. Цифры показываются на карточке, если заполнены.</p>';
    }
}

add_action('save_post', function ($post_id, $post) {
    if (!in_array($post->post_type, ['cci_review', 'cci_case'], true)) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['cci_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cci_meta_nonce'])), 'cci_meta_' . $post_id)) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (cci_meta_fields($post->post_type) as $key => $f) {
        $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        if ($f[1] === 'url') {
            $val = esc_url_raw($raw);
        } elseif ($f[1] === 'number') {
            $val = $raw === '' ? '' : (string) max(0, (int) preg_replace('/\D/', '', $raw));
        } elseif ($f[1] === 'rating') {
            $val = (string) min(5, max(1, (int) $raw));
        } else {
            $val = sanitize_text_field($raw);
        }
        update_post_meta($post_id, $key, $val);
    }
}, 10, 2);

/* ---- Получение данных ---- */
function cci_get_items($type, $limit = 12, $ordered = false) {
    return get_posts([
        'post_type' => $type, 'post_status' => 'publish', 'numberposts' => $limit,
        'orderby' => $ordered ? 'menu_order date' : 'date', 'order' => $ordered ? 'ASC' : 'DESC',
        'suppress_filters' => true,
    ]);
}

function cci_count_items($type) {
    $c = wp_count_posts($type);
    return $c && isset($c->publish) ? (int) $c->publish : 0;
}

/** Подсказка для администратора на месте пустого блока (посетители её не видят) */
function cci_admin_hint($text, $path) {
    if (!current_user_can('manage_options')) {
        return '';
    }
    return '<section style="padding:18px 0"><div class="wrap"><div class="admin-hint">' . esc_html($text)
        . ' <a href="' . esc_url(admin_url($path)) . '">Настроить →</a><small>Эту подсказку видите только вы. Пока блок пуст, посетители его не видят.</small></div></div></section>';
}

/* ---- Разделы лендинга ---- */
function cci_render_cases_section() {
    $items = cci_get_items('cci_case', 6);
    if (!$items) {
        return cci_admin_hint('Блок «Реальные поставки»: добавьте кейсы выданных автомобилей с фото и цифрами.', 'post-new.php?post_type=cci_case');
    }
    ob_start();
    ?>
    <section class="sec" id="cases">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">Реальные поставки</span>
                <h2 class="h2">Привезли: вот как это было</h2>
                <p class="lead">Цена в Китае, итоговая цена в России и срок, без округлений.</p>
            </div>
            <div class="grid">
                <?php foreach ($items as $p):
                    $img   = get_the_post_thumbnail_url($p, 'large');
                    $cny   = (int) get_post_meta($p->ID, '_cci_price_cny', true);
                    $total = (int) get_post_meta($p->ID, '_cci_total_rub', true);
                    $days  = (int) get_post_meta($p->ID, '_cci_days', true);
                    $city  = (string) get_post_meta($p->ID, '_cci_city', true);
                    ?>
                    <article class="card case rv">
                        <div class="card-img"><?php if ($img): ?><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($p->post_title); ?>" loading="lazy"><?php else: ?><div class="ph-fallback"><span><?php echo esc_html($p->post_title); ?></span></div><?php endif; ?></div>
                        <div class="card-body">
                            <h3 class="card-title"><?php echo esc_html($p->post_title); ?></h3>
                            <?php if ($city): ?><div class="specs"><span>Доставили в г. <?php echo esc_html($city); ?></span></div><?php endif; ?>
                            <?php if ($cny || $total || $days): ?>
                                <div class="metrics">
                                    <?php if ($cny): ?><div><small>В Китае</small><b><?php echo esc_html(cci_money($cny)); ?> ¥</b></div><?php endif; ?>
                                    <?php if ($total): ?><div><small>Итого</small><b><?php echo esc_html(cci_money($total)); ?> ₽</b></div><?php endif; ?>
                                    <?php if ($days): ?><div><small>Срок</small><b><?php echo (int) $days; ?> дн.</b></div><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php $story = wp_trim_words(wp_strip_all_tags($p->post_content), 30); if ($story): ?><p class="story"><?php echo esc_html($story); ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function cci_render_reviews_section() {
    $items = cci_get_items('cci_review', 9);
    if (!$items) {
        return cci_admin_hint('Блок «Отзывы клиентов»: добавьте настоящие отзывы (имя, текст, при желании фото).', 'post-new.php?post_type=cci_review');
    }
    ob_start();
    ?>
    <section class="sec" id="reviews" style="padding-top:0">
        <div class="wrap">
            <div class="sec-head rv"><span class="eyebrow">Отзывы</span><h2 class="h2">Что говорят клиенты</h2></div>
            <div class="grid">
                <?php foreach ($items as $p):
                    $rating = max(1, min(5, (int) (get_post_meta($p->ID, '_cci_rating', true) ?: 5)));
                    $car    = (string) get_post_meta($p->ID, '_cci_car', true);
                    $city   = (string) get_post_meta($p->ID, '_cci_city', true);
                    $link   = (string) get_post_meta($p->ID, '_cci_link', true);
                    $img    = get_the_post_thumbnail_url($p, 'thumbnail');
                    ?>
                    <article class="review rv">
                        <div class="stars" aria-label="Оценка <?php echo (int) $rating; ?> из 5"><?php echo str_repeat('★', $rating) . '<i>' . str_repeat('★', 5 - $rating) . '</i>'; ?></div>
                        <div class="rtext"><?php echo wp_kses_post(wpautop($p->post_content)); ?></div>
                        <div class="rby">
                            <?php if ($img): ?><img src="<?php echo esc_url($img); ?>" alt="" loading="lazy"><?php endif; ?>
                            <div><b><?php echo esc_html($p->post_title); ?></b><span><?php echo esc_html(trim($car . ($car && $city ? ' · ' : '') . $city)); ?></span></div>
                            <?php if ($link): ?><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener nofollow">оригинал ↗</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function cci_render_docs_section() {
    $items = cci_get_items('cci_doc', 8);
    if (!$items) {
        return cci_admin_hint('Блок «Документы и гарантии»: добавьте образец договора, ЭПТС, СБКТС, свидетельство, реквизиты (фото или сканы).', 'post-new.php?post_type=cci_doc');
    }
    ob_start();
    ?>
    <section class="sec" id="docs" style="padding-top:0">
        <div class="wrap">
            <div class="sec-head rv"><span class="eyebrow">Документы</span><h2 class="h2">Всё оформляется официально</h2><p class="lead">Покажем документы до заключения договора.</p></div>
            <div class="docs">
                <?php foreach ($items as $p):
                    $full = get_the_post_thumbnail_url($p, 'full');
                    $img  = get_the_post_thumbnail_url($p, 'medium_large');
                    ?>
                    <a class="doc rv" <?php echo $full ? 'href="' . esc_url($full) . '" target="_blank" rel="noopener"' : ''; ?>>
                        <?php if ($img): ?><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($p->post_title); ?>" loading="lazy"><?php endif; ?>
                        <b><?php echo esc_html($p->post_title); ?></b>
                        <?php $d = wp_trim_words(wp_strip_all_tags($p->post_content), 18); if ($d): ?><span><?php echo esc_html($d); ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function cci_render_terms_section() {
    $terms = [
        'Предоплата'                     => get_option('cci_terms_prepay', ''),
        'Этапы оплаты'                   => get_option('cci_terms_stages', ''),
        'Гарантии'                       => get_option('cci_terms_warranty', ''),
        'Если автомобиль не подойдёт'    => get_option('cci_terms_return', ''),
    ];
    $terms = array_filter(array_map('trim', $terms));
    if (!$terms) {
        return cci_admin_hint('Блок «Условия сделки»: опишите предоплату, этапы оплаты, гарантии и что будет, если автомобиль не подойдёт.', 'admin.php?page=china-car-settings&tab=site');
    }
    ob_start();
    ?>
    <section class="sec" id="terms" style="padding-top:0">
        <div class="wrap">
            <div class="sec-head rv"><span class="eyebrow">Условия сделки</span><h2 class="h2">Всё прозрачно<br>до подписания договора</h2></div>
            <div class="terms">
                <?php foreach ($terms as $name => $text): ?>
                    <div class="term rv"><h3><?php echo esc_html($name); ?></h3><p><?php echo nl2br(esc_html($text)); ?></p></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/** Вопросы-ответы: из админки, а если их там нет — стандартный набор */
function cci_get_faq_items($defaults) {
    $out = [];
    foreach (cci_get_items('cci_faq', 30, true) as $p) {
        $out[] = [$p->post_title, wp_kses_post(wpautop($p->post_content))];
    }
    return $out ? $out : $defaults;
}
