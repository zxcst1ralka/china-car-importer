<?php
/**
 * Лендинг (Главная). Шорткод: [china_cars_landing]
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Обработка заявки на подбор авто с лендинга
add_action('template_redirect', 'cci_handle_landing_form_post');

function cci_handle_landing_form_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cci_landing_lead_submit'])) {
        return;
    }

    $thanks = 'Спасибо за заявку! Менеджер свяжется с вами в течение 15 минут и пришлёт варианты с точной сметой.';

    if (cci_is_bot()) {
        cci_flash_redirect('ok', $thanks); // бот: делаем вид, что всё хорошо, но ничего не сохраняем
    }
    if (!isset($_POST['cci_landing_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cci_landing_nonce'])), 'cci_landing_lead_action')) {
        cci_flash_redirect('err', 'Сессия устарела. Обновите страницу и отправьте заявку ещё раз.');
    }

    $name    = isset($_POST['client_name']) ? trim(sanitize_text_field(wp_unslash($_POST['client_name']))) : '';
    $phone   = isset($_POST['client_phone']) ? trim(sanitize_text_field(wp_unslash($_POST['client_phone']))) : '';
    $budget  = isset($_POST['client_budget']) ? trim(sanitize_text_field(wp_unslash($_POST['client_budget']))) : '';
    $wishes  = isset($_POST['client_wishes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['client_wishes']))) : '';
    $link    = isset($_POST['client_link']) ? trim(wp_unslash($_POST['client_link'])) : '';
    $link    = $link !== '' ? esc_url_raw((preg_match('~^https?://~i', $link) ? '' : 'https://') . $link) : '';
    $body    = isset($_POST['quiz_body']) ? trim(sanitize_text_field(wp_unslash($_POST['quiz_body']))) : '';
    $fuel    = isset($_POST['quiz_fuel']) ? trim(sanitize_text_field(wp_unslash($_POST['quiz_fuel']))) : '';

    // Ответы квиза добавляем в комментарий, чтобы менеджер видел всё в одном месте
    $quiz = [];
    if ($body !== '') { $quiz[] = 'Кузов: ' . mb_substr($body, 0, 60); }
    if ($fuel !== '') { $quiz[] = 'Топливо: ' . mb_substr($fuel, 0, 60); }
    if ($quiz) {
        $wishes = trim(implode('. ', $quiz) . ($wishes !== '' ? "\n" . $wishes : ''));
    }
    $raw_m   = isset($_POST['contact_method']) ? trim(sanitize_text_field(wp_unslash($_POST['contact_method']))) : '';

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        cci_flash_redirect('err', 'Укажите имя (минимум 2 символа).');
    }
    if (strlen(preg_replace('/\D/', '', $phone)) < 10 || strlen(preg_replace('/\D/', '', $phone)) > 15) {
        cci_flash_redirect('err', 'Проверьте телефон: нужно не меньше 10 цифр.');
    }
    $methods = [
        'whatsapp' => 'whatsapp', 'WhatsApp' => 'whatsapp', 'telegram' => 'telegram', 'Telegram' => 'telegram',
        'phone' => 'phone', 'Звонок' => 'phone', 'Телефонный звонок' => 'phone',
    ];
    $method = isset($methods[$raw_m]) ? $methods[$raw_m] : 'phone';

    global $wpdb;
    cci_ensure_leads_table();
    $inserted = $wpdb->insert($wpdb->prefix . 'car_leads', [
        'car_lot_id'       => 'LANDING_SELECTION',
        'car_name'         => 'Подбор авто из Китая',
        'calculated_price' => 0.00,
        'client_name'      => $name,
        'client_phone'     => $phone,
        'contact_method'   => $method,
        'client_budget'    => mb_substr($budget, 0, 100),
        'client_link'      => mb_substr($link, 0, 500),
        'utm'              => cci_utm_string(),
        'client_comment'   => $wishes,
        'status'           => 'new',
        'email_sent'       => 0,
        'form_token'       => wp_generate_password(24, false),
        'created_at'       => current_time('mysql'),
    ]);
    $lead_id = (int) $wpdb->insert_id;

    if (!$inserted || !$lead_id) {
        cci_flash_redirect('err', 'Не удалось сохранить заявку. Пожалуйста, свяжитесь с нами напрямую.');
    }
    if (cci_send_lead_email($lead_id)) {
        $wpdb->update($wpdb->prefix . 'car_leads', ['email_sent' => 1], ['id' => $lead_id]);
    }
    cci_flash_redirect('ok', $thanks);
}

// 2. Регистрация шорткода [china_cars_landing]
add_shortcode('china_cars_landing', 'cci_render_landing_page_shortcode');

function cci_render_landing_page_shortcode($atts) {
    global $wpdb;
    $table_cars = $wpdb->prefix . 'car_catalog';

    $catalog_url = cci_page_url('catalog');
    $vin_url     = cci_page_url('vin-check');

    $cars  = $wpdb->get_results("SELECT * FROM {$table_cars} WHERE status = 'active' ORDER BY id DESC LIMIT 8");
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_cars} WHERE status = 'active'");
    $min_price = 0;
    foreach ((array) $wpdb->get_results("SELECT * FROM {$table_cars} WHERE status = 'active' LIMIT 200") as $c_all) {
        $c_calc = cci_calculate_car_turnkey_price($c_all);
        if (!empty($c_calc['total_rub']) && ($min_price === 0 || $c_calc['total_rub'] < $min_price)) {
            $min_price = (float) $c_calc['total_rub'];
        }
    }
    $db_brands = $wpdb->get_col("SELECT DISTINCT brand FROM {$table_cars} WHERE status = 'active' ORDER BY brand ASC");

    $cny        = (float) get_option('cci_cny_rate', 13.50);
    $eur        = (float) get_option('cci_eur_rate', 102.00);
    $commission = (float) get_option('cci_commission_rub', 50000);
    $vin_price  = (int) get_option('cci_vin_price_rub', 1500);

    $video  = plugin_dir_path(__FILE__) . 'assets/media/hero.mp4';
    $has_v  = file_exists($video);
    $poster = cci_asset_url('media/hero-poster.jpg');

    $brands = array_values(array_unique(array_merge($db_brands, ['Zeekr', 'Li Auto', 'Geely', 'Voyah', 'BYD', 'Changan', 'Chery', 'Haval'])));

    $faq = [
        ['Из чего складывается итоговая цена?', 'Цена автомобиля в Китае по курсу юаня, логистика до Уссурийска, таможенная пошлина и налоги, утилизационный и таможенный сборы, расходы в Уссурийске (СВХ, лаборатория, СБКТС, ЭПТС) и наша фиксированная комиссия. Всё это видно в калькуляторе и в смете до подписания договора.'],
        ['Как вы проверяете автомобиль перед покупкой?', 'По VIN запрашиваем официальный отчёт Chaboshi: реальный пробег по базам дилеров, ДТП, утопление, пожары, история ТО и страховых случаев. Для лота без VIN анализируем объявление вручную.'],
        ['Сколько времени занимает доставка?', 'В среднем 18–25 дней с момента выкупа: экспорт и перевозка до Уссурийска, таможенное оформление, получение ЭПТС. Сроки зависят от загрузки границы и таможни.'],
        ['Как происходит оплата?', 'Оплата поэтапная и прописана в договоре. Комиссия фиксированная и не зависит от курса. Проверку по VIN можно оплатить онлайн картой.'],
        ['Что с гарантиями и документами?', 'Вы получаете полный пакет: договор, ЭПТС на ваше имя, СБКТС, документы об уплате таможенных платежей. Подробные условия обсуждаем при подборе.'],
        ['Можно ли доставить автомобиль в мой город?', 'Да. Передача возможна во Владивостоке, либо организуем доставку автовозом или железной дорогой в любой город России.'],
    ];

    $faq = cci_get_faq_items($faq);

    ob_start();
    ?>
    <!-- HERO: видео «листается» скроллом -->
    <section class="hero" data-hero>
        <div class="hero-pin">
            <div class="hero-poster" style="background-image:url('<?php echo esc_url($poster); ?>')"></div>
            <?php if ($has_v): ?>
                <video class="hero-video" muted playsinline preload="none" poster="<?php echo esc_url($poster); ?>">
                    <source src="<?php echo esc_url(cci_asset_url('media/hero.mp4')); ?>?v=<?php echo esc_attr(cci_asset_ver('media/hero.mp4')); ?>" type="video/mp4">
                    <?php if (file_exists(plugin_dir_path(__FILE__) . 'assets/media/hero.webm')): ?>
                        <source src="<?php echo esc_url(cci_asset_url('media/hero.webm')); ?>?v=<?php echo esc_attr(cci_asset_ver('media/hero.webm')); ?>" type="video/webm">
                    <?php endif; ?>
                </video>
            <?php endif; ?>
            <div class="hero-grain"></div>
            <div class="hero-shade"></div>

            <div class="hero-copy">
                <div class="wrap" style="position:relative;min-height:340px">
                    <div class="stage on">
                        <span class="k">Прямой импорт из Китая</span>
                        <h1>Автомобили из Китая под ключ</h1>
                        <p>Выкупаем у дилеров КНР, проверяем по базам, растаможиваем в Уссурийске и оформляем ЭПТС на вас. Комиссия — фиксированная.</p>
                        <div class="hero-facts">
                            <span>18–25 дней</span>
                            <span>Комиссия <?php echo esc_html(cci_money($commission)); ?> ₽ фикс.</span>
                            <span>ЭПТС на ваше имя</span>
                            <?php if ($min_price > 0): ?><span>Авто от <?php echo esc_html(cci_money($min_price)); ?> ₽</span><?php endif; ?>
                        </div>
                        <div class="acts">
                            <a class="btn btn-primary" href="<?php echo esc_url($catalog_url); ?>">Смотреть каталог <span class="arrow">→</span></a>
                            <a class="btn btn-ghost" href="#calc">Рассчитать стоимость</a>
                        </div>
                    </div>
                    <div class="stage">
                        <span class="k">01 · Подбор</span>
                        <h2>Находим и выкупаем у дилера</h2>
                        <p>Работаем напрямую с дилерскими центрами Китая — без перекупов и накруток на этапе покупки.</p>
                    </div>
                    <div class="stage">
                        <span class="k">02 · Проверка</span>
                        <h2>Читаем историю по базам Китая</h2>
                        <p>Отчёт Chaboshi: реальный пробег, ДТП, ремонты, утопление и пожары. До оплаты авто.</p>
                        <div class="acts"><a class="btn btn-ghost" href="<?php echo esc_url($vin_url); ?>">Проверить VIN</a></div>
                    </div>
                    <div class="stage">
                        <span class="k">03 · Таможня</span>
                        <h2>Везём и растамаживаем в Уссурийске</h2>
                        <p>Пошлина, утильсбор, лаборатория, СБКТС и ЭПТС — берём на себя.</p>
                    </div>
                    <div class="stage">
                        <span class="k">04 · Выдача</span>
                        <h2>Ключи — у вас</h2>
                        <p>Передаём во Владивостоке или отправляем автовозом в ваш город.</p>
                        <div class="acts"><a class="btn btn-primary" href="#selection">Подобрать авто <span class="arrow">→</span></a></div>
                    </div>
                </div>
            </div>

            <div class="hero-hud">
                <div class="wrap">
                    <div class="route"><span>Китай</span><div class="route-line"><i></i><b></b></div><span>Ваш город · <span class="d" data-day>день 0</span></span></div>
                </div>
            </div>
            <div class="scroll-hint">Листайте</div>
        </div>
    </section>

    <!-- БЕГУЩАЯ СТРОКА -->
    <div class="ticker" aria-hidden="true">
        <div class="ticker-track">
            <?php for ($k = 0; $k < 2; $k++): ?>
                <span class="hl">¥ <?php echo esc_html(number_format($cny, 2, ',', ' ')); ?> ₽</span>
                <span class="hl">€ <?php echo esc_html(number_format($eur, 2, ',', ' ')); ?> ₽</span>
                <?php foreach ($brands as $b): ?><span><?php echo esc_html($b); ?></span><?php endforeach; ?>
            <?php endfor; ?>
        </div>
    </div>

    <!-- ВИТРИНА -->
    <section class="sec" id="cars">
        <div class="wrap">
            <div class="sec-head row rv">
                <div>
                    <span class="eyebrow">В наличии в Китае</span>
                    <h2 class="h2">Свежие лоты</h2>
                </div>
                <a class="link-arrow" href="<?php echo esc_url($catalog_url); ?>">Весь каталог <span class="arrow">→</span></a>
            </div>
        </div>
        <div class="wrap">
            <?php if ($cars): ?>
                <div class="showcase rv">
                    <?php foreach ($cars as $car) { echo cci_render_car_card($car); } ?>
                </div>
                <div class="sc-bar"><i></i></div>
                <?php echo cci_price_footnote(); ?>
            <?php else: ?>
                <div class="empty"><h3>Каталог скоро наполнится</h3><p>Оставьте заявку — подберём автомобиль индивидуально.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ЦИФРЫ -->
    <section class="sec" style="padding-top:0">
        <div class="wrap">
            <?php
            $delivered = (int) get_option('cci_stat_delivered', 0);
            $since     = (int) get_option('cci_stat_since', 0);
            ?>
            <div class="facts rv">
                <div class="fact"><b data-count="<?php echo (int) $total; ?>">0</b><span>лотов в каталоге прямо сейчас</span></div>
                <div class="fact"><b>18–25<small>дн.</small></b><span>в среднем от выкупа до выдачи</span></div>
                <div class="fact"><b data-count="<?php echo (int) $commission; ?>">0</b><span>₽ — фиксированная комиссия, прописана в договоре</span></div>
                <div class="fact"><b>ЭПТС</b><span>оформляется на ваше имя</span></div>
                <?php if ($delivered > 0): ?><div class="fact"><b data-count="<?php echo $delivered; ?>">0</b><span>автомобилей уже доставлено клиентам</span></div><?php endif; ?>
                <?php if ($since > 1990 && $since <= (int) date('Y')): ?><div class="fact"><b><?php echo max(1, (int) date('Y') - $since); ?><small>+ лет</small></b><span>работаем с <?php echo $since; ?> года</span></div><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- КАЛЬКУЛЯТОР -->
    <section class="sec" id="calc" style="background:linear-gradient(var(--bg),var(--bg2) 20%,var(--bg2) 80%,var(--bg))">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">Калькулятор</span>
                <h2 class="h2">Сколько стоит авто из Китая<br>в рублях — за 10 секунд</h2>
                <p class="lead">Считаем тем же алгоритмом, что и в каталоге: пошлина зависит от возраста, объёма и мощности, а курсы берутся из актуальных настроек.</p>
            </div>
            <div class="calc rv" data-calc>
                <div class="glass">
                    <div class="fld"><label for="c-price">Цена в Китае <output data-o-price></output></label><input id="c-price" type="range" min="40000" max="800000" step="5000" value="150000"></div>
                    <div class="fld">
                        <div class="lbl">Возраст автомобиля</div>
                        <div class="seg">
                            <input type="radio" name="age" id="a1" value="new" checked><label for="a1">до 3 лет</label>
                            <input type="radio" name="age" id="a2" value="mid"><label for="a2">3–5 лет</label>
                            <input type="radio" name="age" id="a3" value="old"><label for="a3">старше 5</label>
                        </div>
                    </div>
                    <div class="fld">
                        <div class="lbl">Двигатель</div>
                        <div class="seg">
                            <input type="radio" name="eng" id="e1" value="petrol" checked><label for="e1">Бензин</label>
                            <input type="radio" name="eng" id="e2" value="diesel"><label for="e2">Дизель</label>
                            <input type="radio" name="eng" id="e3" value="hybrid"><label for="e3">Гибрид</label>
                            <input type="radio" name="eng" id="e4" value="ev"><label for="e4">Электро</label>
                        </div>
                    </div>
                    <div class="fld"><label for="c-cc">Объём <output data-o-cc></output></label><input id="c-cc" type="range" min="600" max="6000" step="100" value="2000"></div>
                    <div class="fld" style="margin-bottom:0"><label for="c-hp">Мощность <output data-o-hp></output></label><input id="c-hp" type="range" min="50" max="800" step="10" value="200"></div>
                </div>
                <div class="glass res">
                    <div>
                        <span class="eyebrow">Под ключ во Владивостоке</span>
                        <div class="big" data-total>—</div>
                        <div class="sub">ориентировочно, включая комиссию</div>
                        <div class="stack" data-stack></div>
                        <ul class="legend" data-legend></ul>
                    </div>
                    <div class="acts">
                        <button type="button" class="btn btn-primary" data-calc-fix>Зафиксировать расчёт <span class="arrow">→</span></button>
                        <div class="calc-note">Для гибридов используется расчёт по объёму двигателя. Точную смету подтвердит менеджер.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- МАРШРУТ -->
    <section class="journey" id="journey" data-journey>
      <div class="journey-track"><div class="journey-pin">
        <div class="wrap">
            <div class="sec-head rv">
                <span class="eyebrow">Как мы работаем</span>
                <h2 class="h2">Путь автомобиля<br>от дилера до ваших ключей</h2>
            </div>
            <div class="jmap-wrap">
                <svg class="jmap" viewBox="0 0 1200 240" role="img" aria-label="Маршрут доставки">
                    <defs><linearGradient id="jg" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="1200" y2="0"><stop offset="0" stop-color="#FFC857"/><stop offset="1" stop-color="#FF4B3A"/></linearGradient></defs>
                    <path class="bg" d="M90 150 C230 30 330 40 430 110 S640 200 760 120 S990 30 1110 120"/>
                    <path class="fg" d="M90 150 C230 30 330 40 430 110 S640 200 760 120 S990 30 1110 120"/>
                    <?php foreach ([0, .25, .5, .75, 1] as $at): ?><circle class="node" r="9" data-at="<?php echo esc_attr($at); ?>" cx="0" cy="0"/><?php endforeach; ?>
                    <?php foreach (['Дилер в Китае', 'Проверка', 'Граница', 'Уссурийск', 'Ваш город'] as $lab): ?><text class="nl" x="0" y="0"><?php echo esc_html($lab); ?></text><?php endforeach; ?>
                    <g class="jcar"><path d="M-12 -8 L12 0 L-12 8 L-6 0 Z"/></g>
                </svg>
            </div>
            <div class="steps">
                <div class="step"><em>01</em><h3>Подбор и смета</h3><p>Выбираем лот, считаем стоимость под ключ и фиксируем условия в договоре.</p></div>
                <div class="step"><em>02</em><h3>Проверка отчёта</h3><p>Запрашиваем отчёт Chaboshi. Если есть скрытые проблемы — предупреждаем до выкупа.</p></div>
                <div class="step"><em>03</em><h3>Выкуп и экспорт</h3><p>Оплачиваем дилеру, оформляем экспорт и отправляем автомобиль к границе.</p></div>
                <div class="step"><em>04</em><h3>Таможня</h3><p>В Уссурийске проходим СВХ, лабораторию, СБКТС и получаем ЭПТС.</p></div>
                <div class="step"><em>05</em><h3>Выдача</h3><p>Передаём ключи во Владивостоке или отправляем в ваш город.</p></div>
            </div>
        </div>
      </div></div>
    </section>

    <?php
    echo cci_render_terms_section();
    echo cci_render_cases_section();
    echo cci_render_reviews_section();
    echo cci_render_docs_section();
    ?>

    <!-- VIN -->
    <section class="sec" style="padding-top:0">
        <div class="wrap">
            <div class="vin-band rv">
                <div>
                    <span class="eyebrow">Проверка по VIN</span>
                    <h2 class="h2">Узнайте историю<br>до того, как заплатите</h2>
                    <p class="lead">Официальный отчёт Chaboshi (查博士) на русском языке: реальный пробег, ДТП, утопление, пожары, обращения к дилерам. Стоимость — <?php echo esc_html(cci_money($vin_price)); ?> ₽.</p>
                    <form class="vin-mini" action="<?php echo esc_url($vin_url); ?>" method="get">
                        <input type="text" name="q" maxlength="300" placeholder="Введите VIN или вставьте ссылку на лот" autocomplete="off">
                        <button class="btn btn-primary" type="submit">Проверить <span class="arrow">→</span></button>
                    </form>
                </div>
                <div class="report" aria-hidden="true">
                    <div class="report-top"><i>Chaboshi · 查博士</i><span>Пример отчёта</span></div>
                    <h4>Geely Monjaro 2.0T 4WD</h4>
                    <div class="vn">VIN LSVF•••••••••9821</div>
                    <div class="checks"><div>Без тяжких ДТП</div><div>Не утопленник</div><div>Без пожаров</div></div>
                    <div class="odo"><span>Пробег по базам 4S</span><b>14 200 км</b></div>
                    <div class="stamp">Скрутки нет</div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="sec" id="faq" style="padding-top:0">
        <div class="wrap">
            <div class="sec-head rv"><span class="eyebrow">Вопросы</span><h2 class="h2">Отвечаем заранее</h2></div>
            <div class="faq rv">
                <?php foreach ($faq as $i => $q): ?>
                    <details<?php echo $i === 0 ? ' open' : ''; ?>>
                        <summary><?php echo esc_html($q[0]); ?><i></i></summary>
                        <div class="ans"><?php echo wp_kses_post($q[1]); ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ЗАЯВКА НА ПОДБОР (квиз из 3 шагов) -->
    <?php
    $tg_u = trim((string) get_option('cci_contact_telegram', ''));
    $wa_n = preg_replace('/\D/', '', (string) get_option('cci_contact_whatsapp', ''));
    $ph_n = trim((string) get_option('cci_contact_phone', ''));
    $bodies  = ['Седан', 'Кроссовер', 'Внедорожник', 'Минивэн', 'Пока не знаю'];
    $fuels   = ['Бензин', 'Гибрид', 'Электро', 'Неважно'];
    $budgets = ['До 2 млн ₽', '2–3 млн ₽', '3–5 млн ₽', 'Более 5 млн ₽', 'Пока не знаю'];
    ?>
    <section class="sec" id="selection" style="background:linear-gradient(var(--bg),var(--bg2))">
        <div class="wrap lead-wrap">
            <div class="rv">
                <span class="eyebrow">Подбор под ваш бюджет</span>
                <h2 class="h2">Ответьте на 3 вопроса — пришлём варианты с точной сметой</h2>
                <p class="lead">Нашли автомобиль на Dongchedi, Che168 или Autohome? Пришлите ссылку на втором шаге — посчитаем «под ключ» бесплатно.</p>
                <ul class="checklist">
                    <li>Отвечаем в течение 15 минут в рабочее время</li>
                    <li>Смета без скрытых платежей, комиссия фиксирована</li>
                    <li>Отчёт Chaboshi по выбранному лоту до выкупа</li>
                </ul>
                <?php if ($tg_u !== '' || $wa_n !== '' || $ph_n !== ''): ?>
                    <div class="contact-btns">
                        <span>Удобнее написать?</span>
                        <?php if ($tg_u !== ''): ?><a class="btn btn-ghost btn-sm" href="<?php echo esc_url('https://t.me/' . ltrim($tg_u, '@')); ?>" target="_blank" rel="noopener" data-goal="tg_click">Telegram</a><?php endif; ?>
                        <?php if ($wa_n !== ''): ?><a class="btn btn-ghost btn-sm" href="<?php echo esc_url('https://wa.me/' . $wa_n); ?>" target="_blank" rel="noopener" data-goal="wa_click">WhatsApp</a><?php endif; ?>
                        <?php if ($ph_n !== ''): ?><a class="btn btn-ghost btn-sm" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $ph_n)); ?>" data-goal="phone_click"><?php echo esc_html($ph_n); ?></a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="glass rv" style="--d:.12s">
                <form class="form quiz" method="post" action="" data-quiz>
                    <?php wp_nonce_field('cci_landing_lead_action', 'cci_landing_nonce'); ?>
                    <input type="hidden" name="cci_landing_lead_submit" value="1">
                    <?php echo cci_honeypot_field(); ?>
                    <div class="quiz-progress" aria-hidden="true"><i></i></div>

                    <div class="quiz-step on" data-step="1">
                        <div class="qh"><em>1 из 3</em> Какой автомобиль нужен?</div>
                        <div class="f"><label>Тип кузова</label>
                            <div class="seg"><?php foreach ($bodies as $i => $b): ?><input type="radio" name="quiz_body" id="qb<?php echo (int) $i; ?>" value="<?php echo esc_attr($b); ?>"><label for="qb<?php echo (int) $i; ?>"><?php echo esc_html($b); ?></label><?php endforeach; ?></div></div>
                        <div class="f"><label>Двигатель</label>
                            <div class="seg"><?php foreach ($fuels as $i => $b): ?><input type="radio" name="quiz_fuel" id="qf<?php echo (int) $i; ?>" value="<?php echo esc_attr($b); ?>"><label for="qf<?php echo (int) $i; ?>"><?php echo esc_html($b); ?></label><?php endforeach; ?></div></div>
                        <div class="qnav"><button type="button" class="btn btn-primary" data-next>Далее <span class="arrow">→</span></button></div>
                    </div>

                    <div class="quiz-step" data-step="2">
                        <div class="qh"><em>2 из 3</em> Бюджет «под ключ»</div>
                        <div class="f"><div class="seg"><?php foreach ($budgets as $i => $b): ?><input type="radio" name="client_budget" id="qg<?php echo (int) $i; ?>" value="<?php echo esc_attr($b); ?>"><label for="qg<?php echo (int) $i; ?>"><?php echo esc_html($b); ?></label><?php endforeach; ?></div></div>
                        <div class="f"><label for="s-link">Есть ссылка на конкретное авто? (необязательно)</label><input id="s-link" type="text" name="client_link" placeholder="dongchedi.com/…, che168.com/…" autocomplete="off"></div>
                        <div class="qnav"><button type="button" class="btn btn-ghost" data-prev>Назад</button><button type="button" class="btn btn-primary" data-next>Далее <span class="arrow">→</span></button></div>
                    </div>

                    <div class="quiz-step" data-step="3">
                        <div class="qh"><em>3 из 3</em> Куда отправить варианты?</div>
                        <div class="f"><label for="s-name">Имя</label><input id="s-name" type="text" name="client_name" required placeholder="Иван" autocomplete="name"></div>
                        <div class="f"><label for="s-phone">Телефон</label><input id="s-phone" type="tel" name="client_phone" required placeholder="+7 (999) 000-00-00" autocomplete="tel"></div>
                        <div class="f"><label>Как связаться</label>
                            <div class="seg">
                                <input type="radio" name="contact_method" id="sm1" value="WhatsApp" checked><label for="sm1">WhatsApp</label>
                                <input type="radio" name="contact_method" id="sm2" value="Telegram"><label for="sm2">Telegram</label>
                                <input type="radio" name="contact_method" id="sm3" value="Телефонный звонок"><label for="sm3">Звонок</label>
                            </div></div>
                        <div class="f"><label for="sel-wishes">Пожелания (необязательно)</label><textarea id="sel-wishes" name="client_wishes" rows="3" placeholder="Марка, комплектация, цвет, город доставки…"></textarea></div>
                        <div class="qnav"><button type="button" class="btn btn-ghost" data-prev>Назад</button><button class="btn btn-primary" type="submit">Получить подборку <span class="arrow">→</span></button></div>
                        <div class="agree"><?php echo cci_consent_html(); ?></div>
                    </div>
                </form>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
