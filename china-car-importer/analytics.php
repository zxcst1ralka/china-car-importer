<?php
/**
 * Аналитика: Яндекс.Метрика, UTM-метки в заявках, уведомление о cookie
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Запоминаем источник посетителя на 30 дней (UTM-метки или внешний сайт-источник) */
add_action('template_redirect', 'cci_capture_utm', 1);

function cci_capture_utm() {
    if (is_admin() || wp_doing_ajax() || headers_sent()) {
        return;
    }
    $data = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'gclid'] as $k) {
        if (!empty($_GET[$k])) {
            $data[$k] = mb_substr(sanitize_text_field(wp_unslash($_GET[$k])), 0, 80);
        }
    }
    if (!$data) {
        if (isset($_COOKIE['cci_utm'])) {
            return;
        }
        $ref = isset($_SERVER['HTTP_REFERER']) ? wp_parse_url(wp_unslash($_SERVER['HTTP_REFERER']), PHP_URL_HOST) : '';
        $own = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$ref || $ref === $own) {
            return;
        }
        $data = ['ref' => mb_substr(sanitize_text_field($ref), 0, 80)];
    }
    setcookie('cci_utm', wp_json_encode($data, JSON_UNESCAPED_UNICODE), time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
}

/** Строка источника для заявки: «yandex / cpc / spring» */
function cci_utm_string() {
    if (empty($_COOKIE['cci_utm'])) {
        return 'прямой заход';
    }
    $d = json_decode(wp_unslash($_COOKIE['cci_utm']), true);
    if (!is_array($d) || !$d) {
        return 'прямой заход';
    }
    if (isset($d['ref'])) {
        return 'сайт: ' . $d['ref'];
    }
    $parts = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term'] as $k) {
        if (!empty($d[$k])) {
            $parts[] = $d[$k];
        }
    }
    if (!$parts) {
        $parts[] = isset($d['yclid']) ? 'Яндекс Директ' : (isset($d['gclid']) ? 'Google Ads' : 'метки');
    }
    return mb_substr(implode(' / ', $parts), 0, 250);
}

/** Счётчик Метрики и произвольный код — только для посетителей (администраторов не считаем) */
function cci_render_tracking_head() {
    if (current_user_can('manage_options') && !isset($_GET['track'])) {
        return;
    }
    $id = preg_replace('/\D/', '', (string) get_option('cci_metrika_id', ''));
    if ($id !== '') {
        echo '<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym(' . (int) $id . ',"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true});</script>' . "\n";
        echo '<noscript><div><img src="https://mc.yandex.ru/watch/' . (int) $id . '" style="position:absolute;left:-9999px;" alt=""></div></noscript>' . "\n";
    }
    $code = (string) get_option('cci_head_code', '');
    if ($code !== '') {
        echo $code . "\n"; // вводится только администратором в «Настройки → Сайт и аналитика»
    }
}

/** Уведомление об использовании cookie */
function cci_render_cookie_banner() {
    $priv = get_page_by_path('privacy');
    ?>
    <div class="cookie" data-cookie hidden>
        <p>Мы используем cookie и сервисы аналитики, чтобы сайт работал лучше. Продолжая пользоваться сайтом, вы соглашаетесь с этим.<?php if ($priv && $priv->post_status === 'publish'): ?> <a href="<?php echo esc_url(get_permalink($priv)); ?>">Подробнее</a><?php endif; ?></p>
        <button type="button" class="btn btn-primary btn-sm" data-cookie-ok>Понятно</button>
    </div>
    <?php
}
