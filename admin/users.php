<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getConnection();
$message = $error = '';

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token inválido.';
    } else {
        if ($action === 'save') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role  = in_array($_POST['role'] ?? '', ['admin','student']) ? $_POST['role'] : 'student';
            $pass  = $_POST['password'] ?? '';
            $active= isset($_POST['active']) ? 1 : 0;

            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Nome e e-mail válidos são obrigatórios.';
            } else {
                if ($id) {
                    $updates = 'name=:name, email=:email, role=:role, active=:active';
                    $params  = [':name'=>$name,':email'=>$email,':role'=>$role,':active'=>$active,':id'=>$id];
                    if ($pass) {
                        $updates .= ', password=:password';
                        $params[':password'] = password_hash($pass, PASSWORD_BCRYPT);
                    }
                    $db->prepare("UPDATE users SET {$updates} WHERE id=:id")->execute($params);
                    $message = 'Usuário atualizado.';
                } else {
                    if (strlen($pass) < 6) { $error = 'Senha mínima de 6 caracteres.'; goto render; }
                    $db->prepare('INSERT INTO users (name,email,password,role,active,created_at) VALUES (?,?,?,?,?,NOW())')
                       ->execute([$name,$email,password_hash($pass,PASSWORD_BCRYPT),$role,$active]);
                    $message = 'Usuário criado com sucesso.';
                }
            }
        }
        if ($action === 'delete' && $id) {
            // Não exclui o próprio admin logado
            if ($id === (int)$_SESSION['user_id']) { $error = 'Você não pode excluir sua própria conta.'; }
            else {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                header('Location: users.php?msg=' . urlencode('Usuário excluído.'));
                exit;
            }
        }
    }
}

render:
if (!$message && !empty($_GET['msg'])) $message = htmlspecialchars($_GET['msg']);

if ($action === 'list' || (!$action)) {
    $users = $db->query('SELECT u.*, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS course_count FROM users u ORDER BY u.created_at DESC')->fetchAll();
    adminHeader('Alunos e Usuários', 'users');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h2>Usuários (<?= count($users) ?>)</h2>
        <a href="users.php?action=new" class="btn btn-primary">+ Novo usuário</a>
      </div>
      <table class="table">
        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Cursos</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge <?= $u['role']==='admin'?'badge-info':'badge-secondary' ?>"><?= $u['role'] ?></span></td>
            <td><?= $u['course_count'] ?></td>
            <td><span class="badge <?= $u['active']?'badge-success':'badge-danger' ?>"><?= $u['active']?'Ativo':'Inativo' ?></span></td>
            <td class="actions">
              <a href="users.php?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm">✏️ Editar</a>
              <form method="post" action="users.php?action=delete&id=<?= $u['id'] ?>" style="display:inline" onsubmit="return confirm('Excluir usuário?')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="btn btn-sm btn-danger">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php adminFooter(); exit;
}

if ($action === 'new' || $action === 'edit') {
    $user = $id ? $db->prepare('SELECT * FROM users WHERE id = ?') : null;
    if ($id) { $user->execute([$id]); $user = $user->fetch(); }
    adminHeader($id ? 'Editar Usuário' : 'Novo Usuário', 'users');
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><h2><?= $id ? 'Editar Usuário' : 'Novo Usuário' ?></h2></div>
      <div class="card-body">
        <form method="post" action="users.php?action=save<?= $id ? "&id={$id}" : '' ?>">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <div class="form-group"><label>Nome *</label><input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required class="form-control"></div>
          <div class="form-group"><label>E-mail *</label><input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required class="form-control"></div>
          <div class="form-group"><label><?= $id ? 'Nova senha (deixe vazio para manter)' : 'Senha (mín. 6 caracteres) *' ?></label><input type="password" name="password" <?= !$id ? 'required' : '' ?> class="form-control"></div>
          <div class="form-group"><label>Perfil</label>
            <select name="role" class="form-control">
              <option value="student" <?= ($user['role']??'')!=='admin'?'selected':'' ?>>Aluno</option>
              <option value="admin"   <?= ($user['role']??'')==='admin'?'selected':'' ?>>Administrador</option>
            </select></div>
          <div class="form-group"><label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= !isset($user) || $user['active'] ? 'checked' : '' ?>> Conta ativa</label></div>
          <button type="submit" class="btn btn-primary">💾 Salvar</button>
          <a href="users.php" class="btn">Cancelar</a>
        </form>
      </div>
    </div>
    <?php adminFooter();
}
