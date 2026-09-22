<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PDO;

final class AdminSite
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function dispatch(string $path): bool
    {
        if (!str_starts_with($path, '/admin')) {
            return false;
        }
        if ($path === '/admin/sair') {
            unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
            header('Location: ' . Layout::url('admin'));
            exit;
        }
        if ($path === '/admin/login' || ($path === '/admin' && !$this->logged())) {
            $this->login();
            return true;
        }
        if (!$this->logged()) {
            header('Location: ' . Layout::url('admin'));
            exit;
        }
        $_SESSION['admin_seen'] = time();

        return match ($path) {
            '/admin' => $this->dashboard() || true,
            '/admin/clientes' => $this->customers() || true,
            '/admin/posts' => $this->posts() || true,
            '/admin/pagamentos' => $this->payments() || true,
            '/admin/planos' => $this->plans() || true,
            '/admin/cupons' => $this->coupons() || true,
            '/admin/ia' => $this->ai() || true,
            '/admin/eventos' => $this->events() || true,
            '/admin/config' => $this->config() || true,
            '/admin/manual' => $this->manualPage() || true,
            default => $this->customerDetail($path),
        };
    }

    private function logged(): bool
    {
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        $seen = (int) ($_SESSION['admin_seen'] ?? 0);
        if ($id === 0 || $seen === 0 || (time() - $seen) > 7200) {
            unset($_SESSION['admin_id'], $_SESSION['admin_seen']);

            return false;
        }

        return true;
    }

    private function login(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
            );
            $count->execute([$ip]);
            if ((int) $count->fetchColumn() >= 5) {
                $error = 'Muitas tentativas. Espere 15 minutos.';
            } elseif (!Layout::checkCsrf()) {
                $error = 'Recarregue a página e tente de novo.';
            } else {
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                $stmt = $this->pdo->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();
                if ($admin === false || !password_verify($password, (string) $admin['password_hash'])) {
                    $this->pdo->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())')->execute([$ip]);
                    $error = 'E-mail ou senha incorretos.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = (int) $admin['id'];
                    $_SESSION['admin_seen'] = time();
                    header('Location: ' . Layout::url('admin'));
                    exit;
                }
            }
        }
        $html = '<section class="center-page"><div class="box" style="text-align:left;width:min(420px,100%)"><h1 style="font-size:32px">Área administrativa</h1>'
            . ($error !== '' ? '<p class="notice">' . Layout::e($error) . '</p>' : '')
            . '<form method="post" action="' . Layout::e(Layout::url('admin')) . '">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<div class="field"><label for="email">E-mail</label><input class="in" id="email" name="email" type="email" required></div>'
            . '<div class="field"><label for="password">Senha</label><input class="in" id="password" name="password" type="password" required></div>'
            . '<button class="btn btn-primary" type="submit">Entrar</button></form>'
            . '<p class="hint" style="margin-top:16px">O acesso é criado no servidor com php bin/admin.php create-admin.</p>'
            . '</div></section>';
        Layout::page('Entrar', $html);
    }

    private function dashboard(): bool
    {
        $customers = (int) $this->pdo->query('SELECT COUNT(*) FROM customers WHERE status <> "excluido"')->fetchColumn();
        $waiting = (int) $this->pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'aguardando_ativacao'")->fetchColumn();
        $paid = (int) $this->pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status = 'pago' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
        $failed = (int) $this->pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'FAILED'")->fetchColumn();
        $days = $this->pdo->query(
            "SELECT DATE(created_at) d, COUNT(*) c FROM posts WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)"
        )->fetchAll();
        $map = [];
        foreach ($days as $day) {
            $map[(string) $day['d']] = (int) $day['c'];
        }
        $bars = '';
        $max = 1;
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $key = date('Y-m-d', strtotime('-' . $i . ' days'));
            $value = $map[$key] ?? 0;
            $max = max($max, $value);
            $series[] = [$key, $value];
        }
        foreach ($series as [$key, $value]) {
            $h = (int) round(($value / $max) * 140);
            $bars .= '<div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:6px" title="' . Layout::e($key . ': ' . $value) . '"><div style="height:' . $h . 'px;width:100%;background:var(--accent);border-radius:6px 6px 0 0"></div><span style="font-size:11px;color:var(--muted)">' . Layout::e(substr($key, 8)) . '</span></div>';
        }
        $alerts = '';
        $expiring = $this->pdo->query(
            "SELECT username, token_expires_at FROM instagram_accounts WHERE status = 'active' AND token_expires_at IS NOT NULL AND token_expires_at < DATE_ADD(NOW(), INTERVAL 10 DAY) ORDER BY token_expires_at ASC LIMIT 8"
        )->fetchAll();
        foreach ($expiring as $row) {
            $alerts .= '<div class="it"><span>@' . Layout::e((string) $row['username']) . '</span><span>conexão até ' . Layout::e((string) $row['token_expires_at']) . '</span></div>';
        }
        $fails = $this->pdo->query("SELECT id, error_message, created_at FROM posts WHERE status = 'FAILED' ORDER BY id DESC LIMIT 8")->fetchAll();
        $failHtml = '';
        foreach ($fails as $row) {
            $failHtml .= '<div class="it"><span>Post ' . (int) $row['id'] . '</span><span>' . Layout::e((string) ($row['error_message'] ?: 'falha')) . '</span></div>';
        }
        $html = '<div class="kpis">'
            . $this->kpi('Clientes', (string) $customers, 'exceto excluídos')
            . $this->kpi('Aguardando ativação', (string) $waiting, 'pagou e ainda não abriu o bot')
            . $this->kpi('Recebido no mês', Layout::money($paid), 'pagamentos confirmados')
            . $this->kpi('Posts com falha', (string) $failed, 'status FAILED')
            . '</div><div class="panel" style="margin-top:16px"><h2>Posts nos últimos 14 dias</h2><div style="display:flex;gap:8px;align-items:flex-end;height:180px">' . $bars . '</div></div>'
            . '<div class="cols"><div class="panel"><h2>Conexões vencendo</h2><div class="list">' . ($alerts !== '' ? $alerts : '<p class="empty">Nenhuma nos próximos 10 dias.</p>') . '</div></div>'
            . '<div class="panel"><h2>Publicações com falha</h2><div class="list">' . ($failHtml !== '' ? $failHtml : '<p class="empty">Nenhuma falha.</p>') . '</div></div></div>';
        $this->render('Painel', $html, 'painel');

        return true;
    }

    private function customers(): bool
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT * FROM customers WHERE 1=1';
        $args = [];
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR email LIKE ? OR document LIKE ?)';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like];
        }
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr data-href="' . Layout::e(Layout::url('admin/clientes/' . $row['id'])) . '"><td>' . Layout::e((string) $row['name']) . '<span class="sub">' . Layout::e((string) $row['email']) . '</span></td><td>' . Layout::e((string) $row['document']) . '</td><td>' . $this->pill((string) $row['status']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="empty">Nenhum cliente.</td></tr>';
        }
        $html = '<form class="filters" method="get"><input class="in" name="q" value="' . Layout::e($q) . '" placeholder="Nome, e-mail ou documento">'
            . '<select class="in" name="status"><option value="">Todos</option>'
            . $this->option('aguardando_ativacao', 'Aguardando ativação', $status)
            . $this->option('ativo', 'Ativo', $status)
            . $this->option('inadimplente', 'Inadimplente', $status)
            . $this->option('cancelado', 'Cancelado', $status)
            . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Cliente</th><th>Documento</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<script>document.querySelectorAll("tr[data-href]").forEach(function(tr){tr.addEventListener("click",function(){location.href=tr.dataset.href;});});</script>';
        $this->render('Clientes', $html, 'clientes');

        return true;
    }

    private function customerDetail(string $path): bool
    {
        if (!preg_match('#^/admin/clientes/(\d+)$#', $path, $m)) {
            return false;
        }
        $id = (int) $m[1];
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
        if ($customer === false) {
            return false;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf()) {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'bloquear') {
                $this->pdo->prepare("UPDATE customers SET status = 'cancelado', updated_at = NOW() WHERE id = ?")->execute([$id]);
            }
            if ($action === 'cancelar') {
                $this->pdo->prepare("UPDATE subscriptions SET status = 'cancelada', updated_at = NOW() WHERE customer_id = ? AND status IN ('ativa','inadimplente','pendente')")->execute([$id]);
                $this->pdo->prepare("UPDATE customers SET status = 'cancelado', updated_at = NOW() WHERE id = ?")->execute([$id]);
            }
            if ($action === 'plano') {
                $planId = (int) ($_POST['plan_id'] ?? 0);
                $this->pdo->prepare(
                    "UPDATE subscriptions SET plan_id = ?, updated_at = NOW() WHERE customer_id = ? AND status = 'ativa'"
                )->execute([$planId, $id]);
            }
            if ($action === 'excluir') {
                $this->erase($customer);
            }
            header('Location: ' . Layout::url('admin/clientes/' . $id));
            exit;
        }
        $stmt->execute([$id]);
        $customer = $stmt->fetch() ?: $customer;
        $subs = $this->pdo->prepare('SELECT s.*, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.customer_id = ? ORDER BY s.id DESC');
        $subs->execute([$id]);
        $subHtml = '';
        foreach ($subs->fetchAll() as $sub) {
            $subHtml .= '<div class="it"><span>' . Layout::e((string) $sub['name']) . ' ' . Layout::e((string) $sub['cycle']) . '</span><span>' . Layout::e((string) $sub['status']) . '</span></div>';
        }
        $plans = '';
        foreach ((new PlanRepository($this->pdo))->all() as $plan) {
            $plans .= '<option value="' . (int) $plan['id'] . '">' . Layout::e((string) $plan['name']) . '</option>';
        }
        $connect = Layout::url('conectar.php');
        $html = '<div class="panel"><dl class="dl">'
            . '<dt>Nome</dt><dd>' . Layout::e((string) $customer['name']) . '</dd>'
            . '<dt>E-mail</dt><dd>' . Layout::e((string) $customer['email']) . '</dd>'
            . '<dt>Celular</dt><dd>' . Layout::e(\PerfilEmDia\Billing\Phone::format((string) $customer['phone'])) . '</dd>'
            . '<dt>Documento</dt><dd>' . Layout::e((string) $customer['document']) . '</dd>'
            . '<dt>Status</dt><dd>' . $this->pill((string) $customer['status']) . '</dd>'
            . '</dl><div class="list">' . $subHtml . '</div>'
            . '<div class="actions">'
            . $this->actionForm('bloquear', 'Bloquear')
            . '<a class="btn btn-ghost btn-sm" href="' . Layout::e($connect) . '">Link de conexão</a>'
            . '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="plano"><select class="in" name="plan_id">' . $plans . '</select><button class="btn btn-ghost btn-sm" type="submit">Mudar plano</button></form>'
            . $this->actionForm('cancelar', 'Cancelar assinatura')
            . $this->actionForm('excluir', 'Excluir dados')
            . '</div></div>';
        $this->render((string) $customer['name'], $html, 'clientes');

        return true;
    }

    private function posts(): bool
    {
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT id, user_id, status, error_message, created_at FROM posts';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . (int) $row['id'] . '</td><td>' . (int) $row['user_id'] . '</td><td>' . $this->pill((string) $row['status']) . '</td><td>' . Layout::e((string) $row['created_at']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Nenhum post.</td></tr>';
        }
        $html = '<form class="filters" method="get"><input class="in" name="status" value="' . Layout::e($status) . '" placeholder="Status, por exemplo FAILED"><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Post</th><th>Usuário</th><th>Status</th><th>Quando</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $this->render('Posts', $html, 'posts');

        return true;
    }

    private function payments(): bool
    {
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT id, method, status, amount_cents, brand, last4, created_at FROM payments';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $card = $row['last4'] ? Layout::e((string) $row['brand']) . ' ???? ' . Layout::e((string) $row['last4']) : Layout::e((string) $row['method']);
            $rows .= '<tr><td>' . (int) $row['id'] . '</td><td>' . $card . '</td><td>' . Layout::e(Layout::money((int) $row['amount_cents'])) . '</td><td>' . $this->pill((string) $row['status']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Nenhum pagamento.</td></tr>';
        }
        $html = '<form class="filters" method="get"><select class="in" name="status"><option value="">Todos</option>'
            . $this->option('pago', 'Pago', $status) . $this->option('pendente', 'Pendente', $status) . $this->option('recusado', 'Recusado', $status)
            . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>#</th><th>Meio</th><th>Valor</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $this->render('Pagamentos', $html, 'pagamentos');

        return true;
    }


    private function coupons(): bool
    {
        $this->render('Cupons', (new CouponAdmin($this->pdo))->html(), 'cupons');

        return true;
    }

    private function plans(): bool
    {
        $repo = new PlanRepository($this->pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf()) {
            $id = (int) ($_POST['id'] ?? 0);
            $reais = (float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'));
            $trial = (float) str_replace(',', '.', (string) ($_POST['trial_price'] ?? '0'));
            $repo->update(
                $id,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['description'] ?? '')),
                (int) round($reais * 100),
                (int) ($_POST['posts_limit'] ?? 0),
                (int) round($trial * 100),
                (int) ($_POST['trial_days'] ?? 0),
                trim((string) ($_POST['features'] ?? '')),
                isset($_POST['highlighted']),
                isset($_POST['active']),
            );
            header('Location: ' . Layout::url('admin/planos'));
            exit;
        }
        $html = '<div class="plan-edit">';
        foreach ($repo->all() as $plan) {
            $html .= '<form class="box" method="post">' . $this->csrf()
                . '<input type="hidden" name="id" value="' . (int) $plan['id'] . '">'
                . '<div class="field"><label>Nome</label><input class="in" name="name" value="' . Layout::e((string) $plan['name']) . '"></div>'
                . '<div class="field"><label>Descrição</label><input class="in" name="description" value="' . Layout::e((string) $plan['description']) . '"></div>'
                . '<div class="field"><label>Preço mensal (R$)</label><input class="in" name="price" value="' . Layout::e(number_format(((int) $plan['price_cents']) / 100, 2, ',', '')) . '"></div>'
                . '<div class="field"><label>Preço do teste (R$)</label><input class="in" name="trial_price" value="' . Layout::e(number_format(((int) ($plan['trial_price_cents'] ?? 0)) / 100, 2, ',', '')) . '"><span class="hint">Vazio ou zero tira o teste. Na primeira mensalidade, cobra este valor pelos dias abaixo.</span></div>'
                . '<div class="field"><label>Dias de teste</label><input class="in" name="trial_days" value="' . (int) ($plan['trial_days'] ?? 0) . '"><span class="hint">O limite de posts desses dias é a fração do mês de 30 dias, arredondada, no mínimo 1.</span></div>'
                . '<div class="field"><label>Posts por mês</label><input class="in" name="posts_limit" value="' . (int) $plan['posts_limit'] . '"></div>'
                . '<div class="field"><label>Itens, um por linha</label><textarea name="features">' . Layout::e((string) $plan['features']) . '</textarea></div>'
                . '<label class="check"><input type="checkbox" name="highlighted"' . ((int) $plan['highlighted'] ? ' checked' : '') . '> Destaque</label>'
                . '<label class="check"><input type="checkbox" name="active"' . ((int) $plan['active'] ? ' checked' : '') . '> Ativo na página pública</label>'
                . '<button class="btn btn-primary" type="submit">Salvar</button></form>';
        }
        $html .= '</div>';
        $this->render('Planos', $html, 'planos');

        return true;
    }

    private function ai(): bool
    {
        $in = (float) Settings::get('ai_price_in_usd', '0');
        $out = (float) Settings::get('ai_price_out_usd', '0');
        $fx = (float) Settings::get('usd_brl', '0');
        $rows = $this->pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') mes, SUM(input_tokens) tin, SUM(output_tokens) tout
             FROM ai_usage GROUP BY mes ORDER BY mes DESC LIMIT 12"
        )->fetchAll();
        $html = '';
        foreach ($rows as $row) {
            $usd = ((int) $row['tin'] / 1000000) * $in + ((int) $row['tout'] / 1000000) * $out;
            $html .= '<tr><td>' . Layout::e((string) $row['mes']) . '</td><td class="num">' . (int) $row['tin'] . '</td><td class="num">' . (int) $row['tout'] . '</td><td class="num">R$ ' . number_format($usd * $fx, 2, ',', '.') . '</td></tr>';
        }
        if ($html === '') {
            $html = '<tr><td colspan="4" class="empty">Sem uso registrado.</td></tr>';
        }
        $byCustomer = $this->pdo->query(
            'SELECT c.name, SUM(a.input_tokens) tin, SUM(a.output_tokens) tout
             FROM ai_usage a JOIN users u ON u.id = a.user_id LEFT JOIN customers c ON c.user_id = u.id
             GROUP BY a.user_id ORDER BY tout DESC LIMIT 20'
        )->fetchAll();
        $people = '';
        foreach ($byCustomer as $row) {
            $usd = ((int) $row['tin'] / 1000000) * $in + ((int) $row['tout'] / 1000000) * $out;
            $people .= '<tr><td>' . Layout::e((string) ($row['name'] ?: 'Sem cliente ligado')) . '</td><td class="num">R$ ' . number_format($usd * $fx, 2, ',', '.') . '</td></tr>';
        }
        if ($people === '') {
            $people = '<tr><td colspan="2" class="empty">Sem uso por cliente.</td></tr>';
        }
        $body = '<div class="tbl-wrap"><table><thead><tr><th>Mês</th><th>Entrada</th><th>Saída</th><th>Custo</th></tr></thead><tbody>' . $html . '</tbody></table></div>'
            . '<div class="tbl-wrap" style="margin-top:16px"><table><thead><tr><th>Cliente</th><th>Custo</th></tr></thead><tbody>' . $people . '</tbody></table></div>'
            . '<p class="hint" style="margin-top:12px">O custo usa os preços por milhão de tokens definidos em Configurações.</p>';
        $this->render('Uso de IA', $body, 'ia');

        return true;
    }

    private function events(): bool
    {
        $type = trim((string) ($_GET['tipo'] ?? ''));
        $sql = 'SELECT id, type, user_id, post_id, created_at FROM events';
        $args = [];
        if ($type !== '') {
            $sql .= ' WHERE type LIKE ?';
            $args[] = '%' . $type . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . Layout::e((string) $row['type']) . '</td><td>' . (int) $row['user_id'] . '</td><td>' . (int) $row['post_id'] . '</td><td>' . Layout::e((string) $row['created_at']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Nenhum evento.</td></tr>';
        }
        $html = '<form class="filters" method="get"><input class="in" name="tipo" value="' . Layout::e($type) . '" placeholder="Tipo do evento"><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Tipo</th><th>Usuário</th><th>Post</th><th>Quando</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $this->render('Erros e eventos', $html, 'eventos');

        return true;
    }

    private function config(): bool
    {
        $keys = [
            'support_email' => 'E-mail de suporte',
            'regen_limit' => 'Versões por post',
            'media_ttl_hours' => 'Horas com a foto pública',
            'ai_model' => 'Modelo de IA',
            'ai_model_fallback' => 'Modelo reserva',
            'ai_price_in_usd' => 'USD por milhão de tokens de entrada',
            'ai_price_out_usd' => 'USD por milhão de tokens de saída',
            'usd_brl' => 'Câmbio USD/BRL',
            'instagram_mode' => 'Modo do Instagram',
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf()) {
            foreach ($keys as $key => $label) {
                Settings::set($this->pdo, $key, trim((string) ($_POST[$key] ?? '')));
            }
            Settings::set($this->pdo, 'ai_system_prompt', trim((string) ($_POST['ai_system_prompt'] ?? '')));
            header('Location: ' . Layout::url('admin/config'));
            exit;
        }
        $html = '<form method="post" class="box" style="max-width:720px">' . $this->csrf();
        foreach ($keys as $key => $label) {
            $html .= '<div class="field"><label>' . Layout::e($label) . '</label><input class="in" name="' . Layout::e($key) . '" value="' . Layout::e(Settings::get($key)) . '"></div>';
        }
        $html .= '<div class="field"><label>Prompt da IA</label><textarea name="ai_system_prompt">' . Layout::e(Settings::get('ai_system_prompt')) . '</textarea></div>';
        $html .= '<button class="btn btn-primary" type="submit">Salvar</button></form>';
        $html .= '<div class="panel" style="margin-top:16px"><h2>Integrações</h2><div class="list">'
            . '<div class="it"><span>Telegram</span><span>' . (ConfigReady('TELEGRAM_BOT_TOKEN') ? 'configurado' : 'sem token') . '</span></div>'
            . '<div class="it"><span>Instagram</span><span>' . (ConfigReady('INSTAGRAM_APP_ID') ? 'configurado' : 'sem app') . '</span></div>'
            . '<div class="it"><span>OpenRouter</span><span>' . (ConfigReady('OPENROUTER_API_KEY') ? 'configurado' : 'sem chave') . '</span></div>'
            . '<div class="it"><span>Pagamento</span><span>' . Layout::e(\PerfilEmDia\Config::get('PAYMENT_GATEWAY', 'sandbox')) . '</span></div>'
            . '</div><p class="hint">Os segredos não aparecem nesta tela.</p></div>';
        $this->render('Configurações', $html, 'config');

        return true;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function erase(array $customer): void
    {
        $id = (int) $customer['id'];
        if (!empty($customer['user_id'])) {
            try {
                $users = new UserRepository($this->pdo, Crypto::fromConfig());
                $users->deleteAccount((int) $customer['user_id']);
            } catch (\Throwable) {
            }
        }
        $subs = $this->pdo->prepare('SELECT id FROM subscriptions WHERE customer_id = ?');
        $subs->execute([$id]);
        foreach ($subs->fetchAll() as $sub) {
            $this->pdo->prepare('UPDATE payments SET subscription_id = NULL WHERE subscription_id = ?')->execute([(int) $sub['id']]);
        }
        $this->pdo->prepare(
            "UPDATE customers SET name = 'Excluído', email = '', phone = '', document = ?, status = 'excluido', user_id = NULL, updated_at = NOW() WHERE id = ?"
        )->execute(['excluido-' . $id, $id]);
        $this->pdo->prepare(
            "INSERT INTO events (user_id, post_id, type, data, created_at) VALUES (NULL, NULL, 'cliente_excluido', ?, NOW())"
        )->execute([json_encode(['customer_id' => $id], JSON_UNESCAPED_UNICODE)]);
    }

    private function kpi(string $label, string $value, string $sub): string
    {
        return '<article class="kpi"><div class="l">' . Layout::e($label) . '</div><div class="v">' . Layout::e($value) . '</div><div class="s">' . Layout::e($sub) . '</div></article>';
    }

    private function pill(string $status): string
    {
        $class = match ($status) {
            'ativo', 'ativa', 'pago', 'PUBLISHED' => 'ok',
            'aguardando_ativacao', 'pendente', 'inadimplente' => 'warn',
            'cancelado', 'cancelada', 'recusado', 'FAILED', 'excluido' => 'bad',
            default => 'mut',
        };

        return '<span class="pill ' . $class . '">' . Layout::e($status) . '</span>';
    }

    private function option(string $value, string $label, string $current): string
    {
        return '<option value="' . Layout::e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . Layout::e($label) . '</option>';
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
    }

    private function actionForm(string $action, string $label): string
    {
        return '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="' . Layout::e($action) . '"><button class="btn btn-ghost btn-sm" type="submit">' . Layout::e($label) . '</button></form>';
    }

    private function render(string $title, string $main, string $active): void
    {
        $draw = [Layout::class, 'admin'];
        $draw($title, $this->hint($active) . $main, $active);
    }

    private function hint(string $active): string
    {
        $hints = [
            'painel' => 'Os números do dia: quem pagou e ainda não abriu o bot, conexão perto de vencer e post que falhou.',
            'clientes' => 'Aguardando ativação significa que o pagamento existe e o código ainda não foi enviado no Telegram.',
            'posts' => 'Aqui está o que o bot tentou publicar. Uma falha não publica sozinha: o cliente tenta de novo no Telegram.',
            'pagamentos' => 'Valor, status, bandeira e os 4 últimos dígitos. O número do cartão não fica nesta lista.',
            'planos' => 'O preço do teste vale na primeira mensalidade. Depois cobra o preço mensal. O anual continua em 10 vezes o mensal, sem teste.',
            'cupons' => 'Cada cupom tem desconto, quantidade de usos e data de validade. Vazio na quantidade ou na data significa sem limite.',
            'ia' => 'Custo estimado das legendas. A chave do OpenRouter não aparece.',
            'eventos' => 'O que o sistema registrou. Filtre pelo tipo quando alguém disser que um dado sumiu.',
            'config' => 'Limites, modelo e prompt. Os cupons ficam na tela Cupons. Segredos ficam só no servidor.',
            'manual' => 'Roteiro completo. As outras telas repetem um resumo e apontam para a seção daqui.',
        ];
        $text = $hints[$active] ?? $hints['manual'];
        $href = Layout::url('admin/manual#' . $active);

        return '<aside class="mini-help"><p>' . Layout::e($text) . '</p><a href="' . Layout::e($href) . '">Abrir no manual</a></aside>';
    }

    private function manualPage(): bool
    {
        $steps = \PerfilEmDia\Site\Guides::adminChapters();
        $html = '<p class="meta">Use este roteiro para operar o dia a dia sem abrir chamado.</p>'
            . \PerfilEmDia\Site\Guides::toc($steps)
            . \PerfilEmDia\Site\Guides::steps($steps, false);
        $this->render('Manual', $html, 'manual');

        return true;
    }

}

function ConfigReady(string $key): bool
{
    try {
        return \PerfilEmDia\Config::get($key, '') !== '';
    } catch (\Throwable) {
        return false;
    }
}