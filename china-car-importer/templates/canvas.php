<?php
/**
 * Шаблон-«холст»: полностью заменяет тему на страницах с нашими шорткодами.
 */
if (!defined('ABSPATH')) {
    exit;
}

remove_action('wp_head', '_wp_render_title_tag', 1);
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

$cci_post    = get_queried_object();
$cci_content = ($cci_post && isset($cci_post->post_content)) ? $cci_post->post_content : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070A12">
    <title><?php echo esc_html(wp_get_document_title()); ?></title>
    <?php cci_render_head_meta(); ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class('cci-canvas'); ?>>
<?php
wp_body_open();
cci_render_header();
echo '<main id="main">';
$cci_rt = cci_current_route();
if ($cci_rt['type'] === 'car') {
    echo $cci_rt['car'] ? cci_render_car_page($cci_rt['car']) : cci_render_notfound();
} elseif ($cci_rt['type'] === 'brand') {
    echo $cci_rt['brand'] !== '' ? cci_render_brand_page($cci_rt['brand']) : cci_render_notfound();
} else {
    echo do_shortcode($cci_content);
}
echo '</main>';
cci_render_footer();
cci_render_lead_modal();
cci_render_cookie_banner();
cci_render_toast();
wp_footer();
?>
</body>
</html>
