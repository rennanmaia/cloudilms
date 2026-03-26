<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/trail.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$db         = Database::getConnection();
$trailModel = new TrailModel();
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
                    header('Location: users.php?action=edit&id=' . $id . '&msg=' . urlencode('Usuário atualizado com sucesso.'));
                    exit;
                } else {
                    if (strlen($pass) < 6) { $error = 'Senha mínima de 6 caracteres.'; goto render; }
                    $db->prepare('INSERT INTO users (name,email,password,role,active,created_at) VALUES (?,?,?,?,?,NOW())')
                       ->execute([$name,$email,password_hash($pass,PASSWORD_BCRYPT),$role,$active]);
                    header('Location: users.php?msg=' . urlencode('Usuário criado com sucesso.'));
                    exit;
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

        // ── Trilhas ──────────────────────────────────────────────────────────
        if ($action === 'assign_trail' && $id) {
            $trailId = (int)($_POST['trail_id'] ?? 0);
            $status  = ($_POST['trail_status'] ?? 'unlocked') === 'locked' ? 'locked' : 'unlocked';
            if ($trailId) $trailModel->assignTrail($id, $trailId, $status, (int)$_SESSION['user_id']);
            header('Location: users.php?action=edit&id=' . $id . '&msg=' . urlencode('Trilha atribuída.'));
            exit;
        }
        if ($action === 'remove_trail' && $id) {
            $trailId = (int)($_POST['trail_id'] ?? 0);
            if ($trailId) $trailModel->removeUserTrail($id, $trailId);
            header('Location: users.php?action=edit&id=' . $id . '&msg=' . urlencode('Trilha removida.'));
            exit;
        }
        if ($action === 'toggle_trail' && $id) {
            $trailId = (int)($_POST['trail_id'] ?? 0);
            $newStatus = $trailId ? $trailModel->toggleTrailStatus($id, $trailId) : null;
            header('Content-Type: application/json');
            echo json_encode(['ok' => (bool)$newStatus, 'status' => $newStatus]);
            exit;
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
              <a href="audit.php?user=<?= $u['id'] ?>" class="btn btn-sm btn-secondary">🔎 Auditoria</a>
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
    $message = !$message && !empty($_GET['msg']) ? htmlspecialchars($_GET['msg']) : $message;
    adminHeader($id ? 'Editar Usuário' : 'Novo Usuário', 'users');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
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

    <?php if ($id):
      $userTrails     = $trailModel->getUserTrails($id);
      $availableTrails = $trailModel->getAvailableTrailsForUser($id);
    ?>
    <div class="card mt-2">
      <div class="card-header">
        <h2>🗺️ Trilhas do Usuário (<?= count($userTrails) ?>)</h2>
      </div>
      <?php if ($userTrails): ?>
      <table class="table">
        <thead>
          <tr><th>Trilha</th><th>Cursos</th><th>Status</th><th>Atribuída em</th><th>Ações</th></tr>
        </thead>
        <tbody>
          <?php foreach ($userTrails as $ut): ?>
          <tr>
            <td><strong><?= htmlspecialchars($ut['title']) ?></strong></td>
            <td><?= $ut['course_count'] ?></td>
            <td>
              <button class="btn btn-sm trail-status-btn <?= $ut['status'] === 'unlocked' ? 'trail-unlocked' : 'trail-locked' ?>"
                      data-trail="<?= $ut['id'] ?>"
                      onclick="toggleTrail(this, <?= $id ?>)"
                      title="Clique para alternar">
                <?= $ut['status'] === 'unlocked' ? '🟢 Liberada' : '🔴 Bloqueada' ?>
              </button>
            </td>
            <td style="font-size:.8rem;color:var(--text3)"><?= date('d/m/Y', strtotime($ut['assigned_at'])) ?></td>
            <td>
              <form method="post" action="users.php?action=remove_trail&id=<?= $id ?>" style="display:inline"
                    onsubmit="return confirm('Remover a trilha &quot;<?= htmlspecialchars(addslashes($ut['title'])) ?>&quot; deste usuário?')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="trail_id" value="<?= $ut['id'] ?>">
                <button class="btn btn-sm btn-danger">✕ Remover</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="card-body" style="color:var(--text3)">Nenhuma trilha atribuída a este usuário.</div>
      <?php endif; ?>

      <?php if ($availableTrails): ?>
      <div class="card-body" style="border-top:1px solid var(--bg3);padding-top:1rem">
        <strong style="font-size:.9rem">Atribuir trilha</strong>
        <form method="post" action="users.php?action=assign_trail&id=<?= $id ?>" style="display:flex;gap:.75rem;align-items:flex-end;margin-top:.75rem;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <div class="form-group" style="margin:0;flex:1;min-width:200px">
            <label>Trilha</label>
            <select name="trail_id" class="form-control topic-assign-select" required>
              <option value="">— selecione —</option>
              <?php foreach ($availableTrails as $t): ?>
              <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label>Status inicial</label>
            <select name="trail_status" class="form-control topic-assign-select">
              <option value="unlocked">🟢 Liberada</option>
              <option value="locked">🔴 Bloqueada</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">+ Atribuir</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <script>
    const CSRF_UT = <?= json_encode($csrf) ?>;
    function toggleTrail(btn, userId) {
        const trailId = btn.dataset.trail;
        const form = new FormData();
        form.append('csrf',     CSRF_UT);
        form.append('trail_id', trailId);
        fetch(`users.php?action=toggle_trail&id=${userId}`, {method:'POST', body:form})
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                if (d.status === 'unlocked') {
                    btn.textContent = '🟢 Liberada';
                    btn.className = 'btn btn-sm trail-status-btn trail-unlocked';
                } else {
                    btn.textContent = '🔴 Bloqueada';
                    btn.className = 'btn btn-sm trail-status-btn trail-locked';
                }
            });
    }
    </script>
    <?php endif; ?>

    <?php adminFooter();
}
