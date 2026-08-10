<?php
/**
 * icons.php — набор иконок панели. Инлайновый SVG вместо FontAwesome.
 *
 * Зачем свой набор: FontAwesome — это шрифт на ~75 КБ и отдельный CSS ради
 * сотни глифов, из которых страница использует десяток. Инлайновый SVG не
 * делает запросов вовсе, красится currentColor вместе с текстом и не мигает
 * квадратами, пока грузится шрифт.
 *
 * Все иконки нарисованы в одной сетке 24×24, обводкой 1.7 без заливки —
 * поэтому в интерфейсе они выглядят одним семейством, а не набором с миру
 * по нитке.
 *
 * Использование:
 *     <?= ui_icon('star') ?>
 *     <?= ui_icon('trash', 18) ?>
 *
 * Неизвестное имя возвращает пустую строку и пишет предупреждение в лог: так
 * опечатка в имени не роняет страницу, но и не теряется молча.
 */

/**
 * Отдаёт SVG-разметку иконки.
 *
 * @param string $name имя из набора ниже
 * @param int    $size размер стороны в пикселях (иконки квадратные)
 * @return string готовый <svg> или пустая строка, если имени нет в наборе
 */
function ui_icon($name, $size = 16)
{
    static $paths = null;

    if ($paths === null) {
        $paths = array(
            // ── навигация и общее ──
            'logo'        => '<path d="M4 19V9M10 19V5M16 19v-6M4 19h16"/>',
            'arrow-left'  => '<path d="M20 12H5M11 6l-6 6 6 6"/>',
            'arrow-right' => '<path d="M4 12h15M13 6l6 6-6 6"/>',
            'chevron-down'=> '<path d="M6 9l6 6 6-6"/>',
            'chevron-right'=> '<path d="M9 6l6 6-6 6"/>',
            'external'    => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/>',
            'close'       => '<path d="M18 6L6 18M6 6l12 12"/>',
            'search'      => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
            'filter'      => '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/>',
            'more'        => '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',

            // ── сущности панели ──
            'star'        => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5z"/>',
            'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
            'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 5.6M17.5 14.5A6.5 6.5 0 0 1 21.5 20"/>',
            'database'    => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
            'table'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9.5h18M9 9.5V20"/>',
            'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
            'key'         => '<circle cx="8" cy="14" r="4"/><path d="M11 11l9-9M17.5 4.5l2 2M15 7l2 2"/>',
            'shield'      => '<path d="M12 3l7.5 3v5.5c0 4.5-3 8.3-7.5 9.5-4.5-1.2-7.5-5-7.5-9.5V6L12 3z"/>',
            'link'        => '<path d="M10 13a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 0 0-5.7-5.7L11.5 6"/><path d="M14 11a4 4 0 0 0-5.7 0L5.5 13.8a4 4 0 0 0 5.7 5.7L12.5 18"/>',

            // ── действия ──
            'trash'       => '<path d="M4 7h16M9.5 7V5h5v2M6.5 7l.8 12.2A2 2 0 0 0 9.3 21h5.4a2 2 0 0 0 2-1.8L17.5 7"/>',
            'restore'     => '<path d="M4 10a8 8 0 1 1 1.6 6.4"/><path d="M3.5 4.5V10H9"/>',
            'history'     => '<path d="M4 10a8 8 0 1 1 1.6 6.4"/><path d="M3.5 4.5V10H9"/><path d="M12 8v4.5l3 1.8"/>',
            'refresh'     => '<path d="M20 11a8 8 0 1 0-1.6 6.4"/><path d="M20.5 4.5V11H14"/>',
            'copy'        => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 6.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h.5"/>',
            'download'    => '<path d="M12 4v11M7.5 11.5L12 16l4.5-4.5"/><path d="M4.5 19.5h15"/>',
            'upload'      => '<path d="M12 20V9M7.5 12.5L12 8l4.5 4.5"/><path d="M4.5 4.5h15"/>',
            'plus'        => '<path d="M12 5v14M5 12h14"/>',
            'settings'    => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>',
            'logout'      => '<path d="M15 4.5h3.5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H15"/><path d="M10 16.5L14.5 12 10 7.5"/><path d="M14 12H3.5"/>',
            'eye'         => '<path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>',
            'eye-off'     => '<path d="M10.6 6.2A9.6 9.6 0 0 1 12 5.5c6.2 0 10 6.5 10 6.5a17 17 0 0 1-3.3 4M6.4 7.6A17.4 17.4 0 0 0 2 12s3.8 6.5 10 6.5c1.6 0 3-.4 4.3-1"/><path d="M3 3l18 18"/>',

            // ── состояния ──
            'check'       => '<path d="M20 6L9 17l-5-5"/>',
            'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.2"/>',
            'alert'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16.2v.2"/>',
            'clock'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5.2l3.2 1.9"/>',
            'file'        => '<path d="M14 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5L14 3.5z"/><path d="M13.8 3.8V9h5"/>',
            'moon'        => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        );
    }

    if (!isset($paths[$name])) {
        if (class_exists('Logger')) {
            Logger::warning('ui_icon: неизвестная иконка', array('name' => $name));
        }
        return '';
    }

    $size = (int) $size;

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

/**
 * Иконка со сплошной заливкой — нужна там, где состояние читается «залито или
 * нет» (звезда избранного). Обводка при этом не рисуется.
 *
 * @param string $name
 * @param int    $size
 * @return string
 */
function ui_icon_filled($name, $size = 16)
{
    $svg = ui_icon($name, $size);
    if ($svg === '') {
        return '';
    }
    return str_replace('fill="none"', 'fill="currentColor"', $svg);
}
