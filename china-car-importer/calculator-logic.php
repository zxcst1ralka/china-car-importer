<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Точный расчет стоимости авто «под ключ в Уссурийске»
 */
function cci_calculate_car_turnkey_price($car) {
    $cny_rate = (float) get_option('cci_cny_rate', 13.50);
    $eur_rate = (float) get_option('cci_eur_rate', 102.00);
    $logistics_cny = (float) get_option('cci_logistics_cny', 14000);
    $ussuriysk_rub = (float) get_option('cci_ussuriysk_expenses_rub', 55000);
    $commission_rub = (float) get_option('cci_commission_rub', 50000);

    $car_price_cny = (float) $car->price_cny;
    $car_price_rub = $car_price_cny * $cny_rate;
    $logistics_rub = $logistics_cny * $cny_rate;
    $car_price_eur = $eur_rate > 0 ? ($car_price_rub / $eur_rate) : 0;

    // 1. ТОЧНЫЙ расчет возраста (в годах и месяцах) по дате регистрации
    $reg_date_str = !empty($car->reg_date) ? $car->reg_date : ($car->year . '-07-01');
    try {
        $reg_date = new DateTime($reg_date_str);
    } catch (Exception $e) {
        $reg_date = new DateTime(((int) $car->year ?: (int) date('Y')) . '-07-01');
    }
    $today = new DateTime();
    $diff = $today->diff($reg_date);
    
    $age_months = ($diff->y * 12) + $diff->m;

    $engine_cc = (int) $car->engine_cc;
    $power_hp = (int) $car->power_hp;
    $engine_type = strtolower($car->engine_type);

    $duty_rub = 0;
    $util_fee_rub = 0;

    // 2. РАСЧЕТ ПОШЛИНЫ И НАЛОГОВ
    if ($engine_type === 'ev') {
        $duty_base_rub = $car_price_rub * 0.15;
        
        if ($power_hp <= 90)        $excise_rate = 0;
        elseif ($power_hp <= 150)   $excise_rate = 61;
        elseif ($power_hp <= 200)   $excise_rate = 583;
        elseif ($power_hp <= 300)   $excise_rate = 955;
        elseif ($power_hp <= 400)   $excise_rate = 1628;
        elseif ($power_hp <= 500)   $excise_rate = 1684;
        else                        $excise_rate = 1740;

        $excise_rub = $power_hp * $excise_rate;
        $vat_rub = ($car_price_rub + $duty_base_rub + $excise_rub) * 0.20;

        $duty_rub = $duty_base_rub + $excise_rub + $vat_rub;
        $util_fee_rub = ($age_months < 36) ? 3400 : 5200;

    } elseif ($age_months >= 36 && $age_months <= 60) {
        if ($engine_cc <= 1000)       $rate = 1.5;
        elseif ($engine_cc <= 1500)   $rate = 1.7;
        elseif ($engine_cc <= 1800)   $rate = 2.5;
        elseif ($engine_cc <= 2300)   $rate = 2.7;
        elseif ($engine_cc <= 3000)   $rate = 3.0;
        else                          $rate = 3.6;

        $duty_eur = $engine_cc * $rate;
        $duty_rub = $duty_eur * $eur_rate;
        $util_fee_rub = ($engine_cc <= 3000) ? 5200 : 1875000;

    } elseif ($age_months < 36) {
        if ($car_price_eur <= 8500) {
            $duty_eur = max($car_price_eur * 0.54, $engine_cc * 2.5);
        } elseif ($car_price_eur <= 16700) {
            $duty_eur = max($car_price_eur * 0.48, $engine_cc * 3.5);
        } elseif ($car_price_eur <= 42300) {
            $duty_eur = max($car_price_eur * 0.48, $engine_cc * 5.5);
        } elseif ($car_price_eur <= 84500) {
            $duty_eur = max($car_price_eur * 0.48, $engine_cc * 7.5);
        } elseif ($car_price_eur <= 169000) {
            $duty_eur = max($car_price_eur * 0.48, $engine_cc * 15.0);
        } else {
            $duty_eur = max($car_price_eur * 0.48, $engine_cc * 20.0);
        }

        $duty_rub = $duty_eur * $eur_rate;
        $util_fee_rub = ($engine_cc <= 3000) ? 3400 : 1562000;

    } else {
        if ($engine_cc <= 1000)       $rate = 3.0;
        elseif ($engine_cc <= 1500)   $rate = 3.2;
        elseif ($engine_cc <= 1800)   $rate = 3.5;
        elseif ($engine_cc <= 2300)   $rate = 4.8;
        elseif ($engine_cc <= 3000)   $rate = 5.0;
        else                          $rate = 5.7;

        $duty_eur = $engine_cc * $rate;
        $duty_rub = $duty_eur * $eur_rate;
        $util_fee_rub = ($engine_cc <= 3000) ? 5200 : 2500000;
    }

    // 3. Таможенный сбор за оформление
    if ($car_price_rub <= 200000)        $customs_fee_rub = 1067;
    elseif ($car_price_rub <= 450000)    $customs_fee_rub = 1552;
    elseif ($car_price_rub <= 1200000)   $customs_fee_rub = 4656;
    elseif ($car_price_rub <= 2700000)   $customs_fee_rub = 11640;
    elseif ($car_price_rub <= 4200000)   $customs_fee_rub = 15520;
    elseif ($car_price_rub <= 5500000)   $customs_fee_rub = 23280;
    else                                 $customs_fee_rub = 31040;

    // 4. Итоговая сумма под ключ
    $total_rub = $car_price_rub + $logistics_rub + $duty_rub + $customs_fee_rub + $util_fee_rub + $ussuriysk_rub + $commission_rub;

    return [
        'car_price_rub'    => round($car_price_rub),
        'logistics_rub'    => round($logistics_rub),
        'duty_rub'         => round($duty_rub),
        'customs_fee_rub'  => round($customs_fee_rub),
        'util_fee_rub'     => round($util_fee_rub),
        'ussuriysk_rub'    => round($ussuriysk_rub),
        'commission_rub'   => round($commission_rub),
        'total_rub'        => round($total_rub),
        'age_months'       => $age_months
    ];
}

/**
 * Алиас для обратной совместимости вызовов
 */
if (!function_exists('cci_calculate_turnkey_price')) {
    function cci_calculate_turnkey_price($car) {
        return cci_calculate_car_turnkey_price($car);
    }
}
