<?php
/**
 * Тесты второго пароля входа в панель ({@see PanelPassword} + auth.php).
 *
 * Почему это покрыто тестом: здесь решается, кого пускать в панель. Ошибка в
 * одну сторону — посторонний с чужой строкой подключения входит без пароля;
 * в другую — не входит никто, включая владельца. Инварианты:
 *   1. Нет файла с хэшем / файл битый → вход закрыт всем (а не открыт всем).
 *   2. Сессия, открытая до появления пароля или под ПРЕЖНИМ паролем, больше не
 *      считается входом. Этим «разлогинить всех» = сменить пароль.
 *   3. Хэш переживает запись в файл и чтение обратно, включая символы `$`.
 *
 * Запуск: php tests/test_panel_password.php   (код выхода 0 = успех)
 */

// На проде error_reporting=E_ALL, и Warning в шаблоне обрывает рендер.
// Поэтому в тесте любой Warning/Notice — это падение.
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// auth.php поднимает сессию. В CLI она живёт в памяти процесса, cookie не
// отправляются; сохраняем файлы сессии во временный каталог теста.
$tmpDir = sys_get_temp_dir() . '/panel_password_test_' . getmypid();
@mkdir($tmpDir, 0700, true);
ini_set('session.save_path', $tmpDir);

require_once __DIR__ . '/../includes/PanelPassword.php';
require_once __DIR__ . '/../auth.php';

// В CLI любой echo считается «заголовки уже отправлены», и session_regenerate_id()
// внутри authenticate()/forgetStaleAuthentication() падает Warning'ом. На странице
// логина эти функции зовутся до вывода, поэтому придерживаем вывод и в тесте.
ob_start();

$failures = 0;
$checks   = 0;

function check($condition, $message) {
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  ✓ $message\n";
    } else {
        echo "  ✗ $message\n";
        $failures++;
    }
}

/** Записать файл с хэшем так же, как это делает деплой, и вернуть путь. */
function writeHashFile($dir, $name, $hash) {
    $path = $dir . '/' . $name;
    file_put_contents($path, PanelPassword::renderFile($hash));
    return $path;
}

// Хэш считаем один раз: bcrypt намеренно медленный.
$hashA = password_hash('14044_bd', PASSWORD_DEFAULT);
$hashB = password_hash('совсем-другой-пароль', PASSWORD_DEFAULT);

echo "── Нет файла → пароль не настроен, вход закрыт\n";
PanelPassword::setFile($tmpDir . '/нет-такого-файла.php');
check(PanelPassword::loadHash() === null, 'loadHash() → null без файла');
check(PanelPassword::verify('14044_bd', PanelPassword::loadHash()) === false, 'verify() без хэша → false, а не true');

echo "── Запись файла и чтение обратно\n";
$fileA = writeHashFile($tmpDir, 'a.php', $hashA);
PanelPassword::setFile($fileA);
check(PanelPassword::loadHash() === $hashA, 'хэш с символами $ читается побайтово тем же');
check(PanelPassword::verify('14044_bd', PanelPassword::loadHash()) === true, 'верный пароль принимается');
check(PanelPassword::verify('14044_BD', PanelPassword::loadHash()) === false, 'регистр имеет значение');
check(PanelPassword::verify('14044_bd ', PanelPassword::loadHash()) === false, 'лишний пробел — уже неверный пароль');
check(PanelPassword::verify('', PanelPassword::loadHash()) === false, 'пустой пароль не принимается');

echo "── Битый файл = пароль не настроен (вход закрыт, а не открыт)\n";
file_put_contents($tmpDir . '/garbage.php', "<?php\nreturn 'просто-строка';\n");
PanelPassword::setFile($tmpDir . '/garbage.php');
check(PanelPassword::loadHash() === null, 'строка, не похожая на хэш пароля, → null');
file_put_contents($tmpDir . '/array.php', "<?php\nreturn ['x'];\n");
PanelPassword::setFile($tmpDir . '/array.php');
check(PanelPassword::loadHash() === null, 'файл вернул не строку → null');
file_put_contents($tmpDir . '/empty.php', '');
PanelPassword::setFile($tmpDir . '/empty.php');
check(PanelPassword::loadHash() === null, 'пустой файл → null');

echo "── isValidHash\n";
check(PanelPassword::isValidHash($hashA) === true, 'bcrypt-хэш признаётся');
check(PanelPassword::isValidHash('14044_bd') === false, 'сам пароль вместо хэша — не хэш');
check(PanelPassword::isValidHash('') === false, 'пустая строка — не хэш');

echo "── sessionMatches: чья сессия считается входом\n";
check(PanelPassword::sessionMatches([], $hashA) === false, 'пустая сессия — не вход');
$sessA = [PanelPassword::SESSION_KEY => PanelPassword::stampFor($hashA)];
check(PanelPassword::sessionMatches($sessA, $hashA) === true, 'сессия под текущим паролем — вход');
check(PanelPassword::sessionMatches($sessA, $hashB) === false, 'пароль сменили → прежние сессии больше не вход');
check(PanelPassword::sessionMatches($sessA, null) === false, 'пароль не настроен → никакая сессия не вход');
check(PanelPassword::sessionMatches([PanelPassword::SESSION_KEY => ['x']], $hashA) === false, 'мусор вместо отпечатка — не вход');
check(PanelPassword::stampFor($hashA) !== $hashA, 'в сессию кладётся отпечаток, а не сам хэш');

echo "── isAuthenticated(): старые сессии выкидываются\n";
PanelPassword::setFile($fileA);
$_SESSION = [
    'user_authenticated' => true,
    'username'           => 'user@host',
    'db_config'          => ['host' => 'h', 'user' => 'u', 'password' => 'p', 'database' => 'd'],
];
check(isAuthenticated() === false, 'сессия, открытая ДО появления пароля, — уже не вход');

$_SESSION[PanelPassword::SESSION_KEY] = PanelPassword::stampFor($hashA);
check(isAuthenticated() === true, 'та же сессия с отпечатком текущего пароля — вход');

$fileB = writeHashFile($tmpDir, 'b.php', $hashB);
PanelPassword::setFile($fileB);
check(isAuthenticated() === false, 'сменили пароль → вошедшие под старым выкинуты');

PanelPassword::setFile($tmpDir . '/нет-такого-файла.php');
check(isAuthenticated() === false, 'файл с паролем пропал → вход закрыт всем');

echo "── forgetStaleAuthentication(): из старой сессии стирается строка подключения\n";
PanelPassword::setFile($fileB);
$_SESSION = [
    'user_authenticated' => true,
    'db_config'          => ['host' => 'h', 'user' => 'u', 'password' => 'секрет', 'database' => 'd'],
    PanelPassword::SESSION_KEY => PanelPassword::stampFor($hashA),
    'dashboard_theme'    => 'dark',
];
forgetStaleAuthentication();
check(!isset($_SESSION['db_config']), 'сохранённая строка подключения к БД стёрта');
check(!isset($_SESSION['user_authenticated']), 'признак входа стёрт');

$_SESSION = [
    'user_authenticated' => true,
    'db_config'          => ['host' => 'h', 'user' => 'u', 'password' => 'секрет', 'database' => 'd'],
    PanelPassword::SESSION_KEY => PanelPassword::stampFor($hashB),
];
forgetStaleAuthentication();
check(isset($_SESSION['db_config']) && isAuthenticated(), 'действующий вход не трогается');

$_SESSION = ['csrf_token' => 'abc'];
forgetStaleAuthentication();
check($_SESSION === ['csrf_token' => 'abc'], 'сессию без входа (страница логина) не трогаем');

echo "── authenticate() ставит отпечаток текущего пароля\n";
PanelPassword::setFile($fileA);
$_SESSION = [];
$ok = authenticate(['host' => 'h', 'port' => 3306, 'user' => 'u', 'password' => 'p', 'database' => 'd', 'charset' => 'utf8mb4'], false);
check($ok === true, 'authenticate() → true');
check(isAuthenticated() === true, 'сразу после входа isAuthenticated() → true');

PanelPassword::setFile($tmpDir . '/нет-такого-файла.php');
$_SESSION = [];
$ok = authenticate(['host' => 'h', 'port' => 3306, 'user' => 'u', 'password' => 'p', 'database' => 'd', 'charset' => 'utf8mb4'], false);
check($ok === false, 'пароль не настроен → authenticate() отказывает');
check(empty($_SESSION['user_authenticated']), 'и признак входа не ставится');

// Уборка: сперва закрываем сессию, иначе PHP допишет её файл в удалённый каталог.
session_write_close();
PanelPassword::setFile(null);
array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

echo "\n$checks проверок, провалено: $failures\n";
exit($failures === 0 ? 0 : 1);
