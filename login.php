<?php
require __DIR__ . '/lib/auth.php';

$error = '';

if (is_logged_in()) {
    redirect('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (verify_admin_credentials($username, $password)) {
        session_regenerate_id(true);
        $_SESSION['lightfolio_admin'] = true;
        redirect('admin.php');
    }

    $error = '账号或密码不正确';
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Lightfolio | 登录</title>
    <link rel="stylesheet" href="./styles.css" />
  </head>
  <body class="login-page">
    <main class="login-shell">
      <form class="login-panel" method="post" action="./login.php">
        <a class="brand" href="./index.html" aria-label="返回画廊">
          <span class="brand-dot"></span>
          <span>Lightfolio</span>
        </a>
        <h1>后台登录</h1>
        <?php if ($error): ?>
          <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <label>
          账号
          <input name="username" type="text" autocomplete="username" required autofocus />
        </label>
        <label>
          密码
          <input name="password" type="password" autocomplete="current-password" required />
        </label>
        <button class="text-button is-dark" type="submit">登录</button>
      </form>
    </main>
  </body>
</html>
