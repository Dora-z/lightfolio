<?php
declare(strict_types=1);

require __DIR__ . '/lib/sqlite_store.php';
require __DIR__ . '/lib/auth.php';

$configFile = lightfolio_auth_config_file();
$installed = is_file($configFile);

if ($installed) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($installed) {
        $errors[] = '安装配置已存在。如需重新安装，请先删除服务器上的 lib/config.php。';
    }

    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) {
        $errors[] = '管理员用户名需为 3-40 位字母、数字、点、下划线或横线。';
    }

    if (strlen($password) < 8) {
        $errors[] = '管理员密码至少需要 8 位。';
    }

    if ($password !== $confirmPassword) {
        $errors[] = '两次输入的密码不一致。';
    }

    if (!$errors) {
        try {
            lightfolio_run_install($username, $password, $configFile);
            $installed = true;
            $messages[] = '安装完成。你现在可以进入后台登录。';
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$checks = lightfolio_install_checks($configFile);
$hasBlockingCheck = count(array_filter($checks, static fn (array $check): bool => !$check['ok'] && $check['required'])) > 0;

function lightfolio_run_install(string $username, string $password, string $configFile): void
{
    lightfolio_prepare_storage();
    lightfolio_db();
    lightfolio_prepare_uploads();

    $salt = bin2hex(random_bytes(16));
    $hash = hash_pbkdf2('sha256', $password, $salt, 120000, 64);

    $config = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'admin_user' => " . var_export($username, true) . ",\n"
        . "    'admin_password_salt' => " . var_export($salt, true) . ",\n"
        . "    'admin_password_pbkdf2' => " . var_export($hash, true) . ",\n"
        . "];\n";

    if (file_put_contents($configFile, $config, LOCK_EX) === false) {
        throw new RuntimeException('无法写入 lib/config.php，请检查 lib 目录权限。');
    }
}

function lightfolio_prepare_uploads(): void
{
    $directories = [
        __DIR__ . '/uploads',
        __DIR__ . '/uploads/previews',
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('无法创建目录：' . basename($directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException('目录不可写：' . basename($directory));
        }
    }
}

function lightfolio_install_checks(string $configFile): array
{
    $pdoSqlite = extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    $libWritable = is_writable(__DIR__ . '/lib');

    return [
        [
            'label' => 'PHP 版本',
            'detail' => PHP_VERSION,
            'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'required' => true,
        ],
        [
            'label' => 'PDO SQLite',
            'detail' => $pdoSqlite ? '已启用' : '未启用',
            'ok' => $pdoSqlite,
            'required' => true,
        ],
        [
            'label' => '配置目录',
            'detail' => $libWritable ? 'lib/ 可写' : 'lib/ 不可写',
            'ok' => $libWritable,
            'required' => true,
        ],
        [
            'label' => '存储目录',
            'detail' => lightfolio_path_status(lightfolio_storage_dir()),
            'ok' => lightfolio_parent_or_self_writable(lightfolio_storage_dir()),
            'required' => true,
        ],
        [
            'label' => '上传目录',
            'detail' => lightfolio_path_status(__DIR__ . '/uploads'),
            'ok' => lightfolio_parent_or_self_writable(__DIR__ . '/uploads'),
            'required' => true,
        ],
        [
            'label' => '安装配置',
            'detail' => is_file($configFile) ? 'lib/config.php 已存在' : '尚未生成',
            'ok' => true,
            'required' => false,
        ],
    ];
}

function lightfolio_path_status(string $path): string
{
    if (is_dir($path)) {
        return is_writable($path) ? '已存在且可写' : '已存在但不可写';
    }

    $parent = dirname($path);
    return is_writable($parent) ? '可创建' : '父目录不可写';
}

function lightfolio_parent_or_self_writable(string $path): bool
{
    if (is_dir($path)) {
        return is_writable($path);
    }

    return is_writable(dirname($path));
}

function lightfolio_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>安装 Lightfolio</title>
    <link rel="stylesheet" href="./styles.css?v=20260619-installer" />
  </head>
  <body class="install-page">
    <main class="install-shell">
      <section class="install-panel">
        <div class="brand">
          <span class="brand-mark"></span>
          <span>Lightfolio</span>
        </div>
        <header class="install-heading">
          <span>安装程序</span>
          <h1>准备你的画廊</h1>
        </header>

        <?php if ($messages): ?>
          <div class="install-alert is-success">
            <?php foreach ($messages as $message): ?>
              <p><?= lightfolio_escape($message) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="install-alert is-error">
            <?php foreach ($errors as $error): ?>
              <p><?= lightfolio_escape($error) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <section class="install-checks" aria-label="环境检查">
          <?php foreach ($checks as $check): ?>
            <article class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
              <span><?= $check['ok'] ? '通过' : '处理' ?></span>
              <strong><?= lightfolio_escape($check['label']) ?></strong>
              <small><?= lightfolio_escape($check['detail']) ?></small>
            </article>
          <?php endforeach; ?>
        </section>

        <?php if ($installed): ?>
          <div class="install-alert is-success">
            <p>当前站点已安装。如需重新安装，请先删除服务器上的 lib/config.php。</p>
          </div>
        <?php elseif (!$hasBlockingCheck): ?>
          <form class="install-form" method="post" action="./install.php">
            <label>
              <span>管理员用户名</span>
              <input name="username" type="text" value="admin" autocomplete="username" required />
            </label>
            <label>
              <span>管理员密码</span>
              <input name="password" type="password" autocomplete="new-password" minlength="8" required />
            </label>
            <label>
              <span>确认密码</span>
              <input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required />
            </label>
            <button class="solid-button" type="submit">开始安装</button>
          </form>
        <?php endif; ?>

        <nav class="install-links" aria-label="安装导航">
          <a href="./index.html">打开画廊</a>
          <a href="./login.php">进入后台</a>
        </nav>
      </section>
    </main>
  </body>
</html>
