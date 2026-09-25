<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Billing\AppMaxGateway;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Channel\TelegramChannel;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Telegram\TelegramClient;
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

        if (preg_match('#^/admin/chamados/(\d+)$#', $path, $ticketMatch) === 1) {
            return $this->ticketDetail((int) $ticketMatch[1]);
        }

        return match ($path) {
            '/admin' => $this->dashboard() || true,
            '/admin/clientes' => $this->customers() || true,
            '/admin/chamados' => $this->tickets() || true,
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
        $this->syncAppMaxNets();
        $paid = (int) $this->pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status = 'pago' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
        $appmax = $this->pdo->query(
            "SELECT COALESCE(SUM(amount_cents),0) AS gross, COALESCE(SUM(net_cents),0) AS net,
                    SUM(net_cents IS NULL) AS missing
             FROM payments
             WHERE gateway = 'appmax' AND status = 'pago' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        )->fetch() ?: ['gross' => 0, 'net' => 0, 'missing' => 0];
        $gross = (int) $appmax['gross'];
        $net = (int) $appmax['net'];
        $missing = (int) $appmax['missing'];
        $fee = max(0, $gross - $net);
        $netHint = $missing > 0
            ? 'falta o repasse de ' . $missing . ' pagamento' . ($missing === 1 ? '' : 's')
            : 'a AppMax repassa. Taxa de ' . Layout::money($fee);
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
            $bars .= '<div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:6px" title="' . Layout::e(Layout::when($key) . ': ' . $value) . '"><div style="height:' . $h . 'px;width:100%;background:var(--accent);border-radius:6px 6px 0 0"></div><span style="font-size:11px;color:var(--muted)">' . Layout::e(substr($key, 8)) . '</span></div>';
        }
        $alerts = '';
        $expiring = $this->pdo->query(
            "SELECT username, token_expires_at FROM instagram_accounts WHERE status = 'active' AND token_expires_at IS NOT NULL AND token_expires_at < DATE_ADD(NOW(), INTERVAL 10 DAY) ORDER BY token_expires_at ASC LIMIT 8"
        )->fetchAll();
        foreach ($expiring as $row) {
            $alerts .= '<div class="it"><span>@' . Layout::e((string) $row['username']) . '</span><span>conexão até ' . Layout::e(Layout::when((string) $row['token_expires_at'])) . '</span></div>';
        }
        $fails = $this->pdo->query("SELECT id, error_message, created_at FROM posts WHERE status = 'FAILED' ORDER BY id DESC LIMIT 8")->fetchAll();
        $failHtml = '';
        foreach ($fails as $row) {
            $failHtml .= '<div class="it"><span>Post ' . (int) $row['id'] . '</span><span>' . Layout::e((string) ($row['error_message'] ?: 'falha')) . '</span></div>';
        }
        $html = '<div class="kpis">'
            . $this->kpi('Clientes', (string) $customers, 'exceto excluídos')
            . $this->kpi('Aguardando ativação', (string) $waiting, 'pagou e ainda não abriu o bot')
            . $this->kpi('Cobrado no mês', Layout::money($paid), 'o que os clientes pagaram')
            . $this->kpi('Líquido AppMax', Layout::money($net), $netHint)
            . $this->kpi('Posts com falha', (string) $failed, 'que não publicaram')
            . $this->kpi('Chamados abertos', (string) $this->pdo->query("SELECT COUNT(*) FROM tickets WHERE status <> 'encerrado'")->fetchColumn(), 'novos e em conversa')
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
        $sql = 'SELECT c.*, p.name AS plan_name, s.cycle AS plan_cycle, s.price_cents AS plan_price,
                s.posts_limit AS plan_posts, s.current_period_end, s.comp_forever, s.comp_until, s.period_kind,
                ig.username AS ig_username, ig.status AS ig_status
            FROM customers c
            LEFT JOIN subscriptions s ON s.id = (
                SELECT s2.id FROM subscriptions s2
                WHERE s2.customer_id = c.id
                ORDER BY CASE s2.status
                    WHEN \'ativa\' THEN 0
                    WHEN \'inadimplente\' THEN 1
                    WHEN \'pendente\' THEN 2
                    ELSE 3
                END, s2.id DESC
                LIMIT 1
            )
            LEFT JOIN plans p ON p.id = s.plan_id
            LEFT JOIN instagram_accounts ig ON ig.user_id = c.user_id
            WHERE 1=1';
        $args = [];
        if ($q !== '') {
            $sql .= ' AND (c.name LIKE ? OR c.email LIKE ? OR c.document LIKE ?)';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like];
        }
        if ($status !== '') {
            $sql .= ' AND c.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $phone = \PerfilEmDia\Billing\Phone::format((string) $row['phone']);
            $rows .= '<tr data-href="' . Layout::e(Layout::url('admin/clientes/' . $row['id'])) . '">'
                . '<td>' . Layout::e((string) $row['name'])
                . '<span class="sub">' . Layout::e((string) $row['email']) . '</span>'
                . ($phone !== '' ? '<span class="sub">' . Layout::e($phone) . '</span>' : '')
                . '</td>'
                . '<td class="keep">' . $this->customerPlanCell($row) . '</td>'
                . '<td class="keep">' . $this->customerIgCell($row) . '</td>'
                . '<td class="keep">' . $this->customerWhenCell($row) . '</td>'
                . '<td class="keep">' . Layout::e((string) $row['document']) . '</td>'
                . '<td class="keep">' . $this->pill((string) $row['status']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">Nenhum cliente.</td></tr>';
        }
        $html = '<form class="filters" method="get"><input class="in" name="q" value="' . Layout::e($q) . '" placeholder="Nome, e-mail ou documento">'
            . '<select class="in" name="status"><option value="">Todos</option>'
            . $this->option('aguardando_ativacao', 'Aguardando ativação', $status)
            . $this->option('ativo', 'Ativo', $status)
            . $this->option('inadimplente', 'Inadimplente', $status)
            . $this->option('cancelado', 'Cancelado', $status)
            . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Cliente</th><th>Plano</th><th>Instagram</th><th>Vigência</th><th>Documento</th><th>Status</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
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
            if ($action === 'isentar') {
                $this->grantExemption($id);
            }
            if ($action === 'isentar_fim') {
                $this->revokeExemption($id);
            }
            if ($action === 'ai_video') {
                $on = (string) ($_POST['ai_video'] ?? '') === '1' ? 1 : 0;
                $this->pdo->prepare('UPDATE customers SET ai_video = ?, updated_at = NOW() WHERE id = ?')->execute([$on, $id]);
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
            $subHtml .= '<div class="it"><span>' . Layout::e((string) $sub['name']) . ' ' . Layout::e((string) $sub['cycle']) . '</span><span>' . $this->pill((string) $sub['status']) . '</span></div>';
        }
        $ticketHtml = '';
        if (!empty($customer['user_id'])) {
            $ticketStmt = $this->pdo->prepare('SELECT id, subject, status FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 8');
            $ticketStmt->execute([(int) $customer['user_id']]);
            $ticketRows = '';
            foreach ($ticketStmt->fetchAll() as $ticketRow) {
                $ticketRows .= '<div class="it"><a href="' . Layout::e(Layout::url('admin/chamados/' . $ticketRow['id'])) . '">#' . (int) $ticketRow['id'] . ' ' . Layout::e((string) $ticketRow['subject']) . '</a><span>' . Layout::e(TicketService::label((string) $ticketRow['status'])) . '</span></div>';
            }
            if ($ticketRows !== '') {
                $ticketHtml = '<h2 style="font-size:18px;margin:16px 0 8px">Chamados</h2><div class="list">' . $ticketRows . '</div>';
            }
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
            . '</dl><div class="list">' . $subHtml . '</div>' . $ticketHtml
            . '<div class="actions">'
            . $this->actionForm('bloquear', 'Bloquear')
            . '<a class="btn btn-ghost btn-sm" href="' . Layout::e($connect) . '">Link de conexão</a>'
            . '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="plano"><select class="in" name="plan_id">' . $plans . '</select><button class="btn btn-ghost btn-sm" type="submit">Mudar plano</button></form>'
            . $this->actionForm('cancelar', 'Cancelar assinatura')
            . $this->actionForm('excluir', 'Excluir dados', 'Excluir os dados deste cliente? Isso não volta atrás.')
            . '</div>' . $this->exemptionBox($id, $customer, $plans) . $this->aiVideoBox($id, $customer) . '</div>';
        $this->render((string) $customer['name'], $html, 'clientes');

        return true;
    }

    private function posts(): bool
    {
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT p.id, p.user_id, p.status, p.created_at,
                c.id AS customer_id, c.name AS customer_name, u.display_name, ig.username AS ig_username
            FROM posts p
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN customers c ON c.id = (
                SELECT c2.id FROM customers c2
                WHERE c2.user_id = p.user_id AND c2.status <> \'excluido\'
                ORDER BY c2.id DESC
                LIMIT 1
            )
            LEFT JOIN instagram_accounts ig ON ig.user_id = p.user_id';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE p.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . (int) $row['id'] . '</td>'
                . '<td>' . $this->postClientCell($row) . '</td>'
                . '<td class="keep">' . $this->instagramLink((string) ($row['ig_username'] ?? '')) . '</td>'
                . '<td>' . $this->pill((string) $row['status']) . '</td>'
                . '<td class="keep">' . Layout::e(Layout::when((string) $row['created_at'])) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty">Nenhum post.</td></tr>';
        }
        $postFilters = '';
        foreach (['FAILED', 'AWAITING_APPROVAL', 'AWAITING_FEEDBACK', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'CANCELLED', 'AWAITING_MANUAL_EDIT', 'AWAITING_IMAGE_EDIT', 'IMAGE_EDITING', 'GENERATING', 'COLLECTING', 'AWAITING_THEME'] as $code) {
            $postFilters .= $this->option($code, $this->statusLabel($code), $status);
        }
        $html = '<form class="filters" method="get"><select class="in" name="status"><option value="">Todos</option>' . $postFilters . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Post</th><th>Cliente</th><th>Instagram</th><th>Status</th><th>Quando</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $this->render('Posts', $html, 'posts');

        return true;
    }

    private function payments(): bool
    {
        $this->syncAppMaxNets();
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT pay.id, pay.method, pay.status, pay.amount_cents, pay.net_cents, pay.brand, pay.last4,
                COALESCE(cu.id, cu2.id) AS customer_id,
                COALESCE(cu.name, cu2.name) AS customer_name,
                COALESCE(cu.email, cu2.email) AS customer_email,
                COALESCE(cu.document, cu2.document) AS customer_document
            FROM payments pay
            LEFT JOIN checkouts ch ON ch.id = pay.checkout_id
            LEFT JOIN customers cu ON cu.id = ch.customer_id
            LEFT JOIN subscriptions s ON s.id = pay.subscription_id
            LEFT JOIN customers cu2 ON cu2.id = s.customer_id';
        $args = [];
        if ($status !== '') {
            $sql .= ' WHERE pay.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY pay.id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $card = $row['last4'] ? Layout::e((string) $row['brand']) . ' ???? ' . Layout::e((string) $row['last4']) : Layout::e((string) $row['method']);
            $rows .= '<tr><td>' . (int) $row['id'] . '</td>'
                . '<td>' . $this->paymentClientCell($row) . '</td>'
                . '<td class="keep">' . $card . '</td>'
                . '<td class="keep">' . Layout::e(Layout::money((int) $row['amount_cents'])) . '</td>'
                . '<td class="keep">' . ($row['net_cents'] === null ? '' : Layout::e(Layout::money((int) $row['net_cents']))) . '</td>'
                . '<td>' . $this->pill((string) $row['status']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">Nenhum pagamento.</td></tr>';
        }
        $html = '<form class="filters" method="get"><select class="in" name="status"><option value="">Todos</option>'
            . $this->option('pago', 'Pago', $status) . $this->option('pendente', 'Pendente', $status) . $this->option('recusado', 'Recusado', $status)
            . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>#</th><th>Cliente</th><th>Meio</th><th>Cobrado</th><th>Líquido</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $this->render('Pagamentos', $html, 'pagamentos');

        return true;
    }

    private function syncAppMaxNets(): void
    {
        if (!AppMaxGateway::configured()) {
            return;
        }
        $pending = $this->pdo->query(
            "SELECT id, external_id FROM payments
             WHERE gateway = 'appmax' AND status = 'pago' AND net_cents IS NULL
               AND external_id IS NOT NULL
             ORDER BY id DESC LIMIT 30"
        )->fetchAll();
        if ($pending === []) {
            return;
        }
        $gateway = new AppMaxGateway($this->pdo);
        $update = $this->pdo->prepare('UPDATE payments SET net_cents = ? WHERE id = ? AND net_cents IS NULL');
        foreach ($pending as $row) {
            try {
                $settlement = $gateway->settlement((string) $row['external_id']);
            } catch (\Throwable) {
                continue;
            }
            if ($settlement === null) {
                continue;
            }
            $update->execute([$settlement['net_cents'], (int) $row['id']]);
        }
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
        $spend = (new \PerfilEmDia\Ai\OpenRouterAccount())->spend();
        $fx = (float) Settings::get('usd_brl', '0');
        $summary = $spend === null
            ? '<p class="hint">Não consegui ler o custo no OpenRouter agora.</p>'
            : '<div class="kpis">'
                . $this->kpi('Este mês', $this->usd($spend['monthly']), $this->moneyNote($spend['monthly'], $fx, 'cobrado na chave'))
                . $this->kpi('Hoje', $this->usd($spend['daily']), $this->openRouterLimitNote($spend))
                . $this->kpi('Total da chave', $this->usd($spend['usage']), $this->moneyNote($spend['usage'], $fx, 'desde que a chave existe'))
                . '</div>';
        $rows = $this->pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') mes, SUM(input_tokens) tin, SUM(output_tokens) tout
             FROM ai_usage GROUP BY mes ORDER BY mes DESC LIMIT 12"
        )->fetchAll();
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . Layout::e((string) $row['mes']) . '</td><td class="num">' . (int) $row['tin'] . '</td><td class="num">' . (int) $row['tout'] . '</td></tr>';
        }
        if ($html === '') {
            $html = '<tr><td colspan="3" class="empty">Sem uso registrado.</td></tr>';
        }
        $byCustomer = $this->pdo->query(
            "SELECT MAX(c.id) AS customer_id, MAX(c.name) AS name, SUM(a.input_tokens) tin, SUM(a.output_tokens) tout
             FROM ai_usage a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN customers c ON c.user_id = u.id AND c.status <> 'excluido'
             GROUP BY a.user_id
             ORDER BY tout DESC
             LIMIT 20"
        )->fetchAll();
        $people = '';
        foreach ($byCustomer as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = 'Sem cliente ligado';
            }
            $label = Layout::e($name);
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId > 0) {
                $label = '<a href="' . Layout::e(Layout::url('admin/clientes/' . $customerId)) . '">' . $label . '</a>';
            }
            $people .= '<tr><td>' . $label . '</td><td class="num">' . (int) $row['tin'] . '</td><td class="num">' . (int) $row['tout'] . '</td></tr>';
        }
        if ($people === '') {
            $people = '<tr><td colspan="3" class="empty">Sem uso por cliente.</td></tr>';
        }
        $body = $summary
            . '<div class="tbl-wrap" style="margin-top:16px"><table><thead><tr><th>Mês</th><th>Entrada</th><th>Saída</th></tr></thead><tbody>' . $html . '</tbody></table></div>'
            . '<div class="tbl-wrap" style="margin-top:16px"><table><thead><tr><th>Cliente</th><th>Entrada</th><th>Saída</th></tr></thead><tbody>' . $people . '</tbody></table></div>'
            . '<p class="hint" style="margin-top:12px">O custo vem da chave no OpenRouter. As tabelas contam só os tokens das legendas. A imagem do post criado pela IA entra no custo da chave e não entra nessa contagem.</p>';
        $this->render('Uso de IA', $body, 'ia');

        return true;
    }

    private function events(): bool
    {
        $type = trim((string) ($_GET['tipo'] ?? ''));
        $sql = 'SELECT e.type, e.user_id, e.post_id, e.created_at,
                c.id AS customer_id, c.name AS customer_name, u.display_name, ig.username AS ig_username
            FROM events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN customers c ON c.id = (
                SELECT c2.id FROM customers c2
                WHERE c2.user_id = e.user_id AND c2.status <> \'excluido\'
                ORDER BY c2.id DESC
                LIMIT 1
            )
            LEFT JOIN instagram_accounts ig ON ig.user_id = e.user_id';
        $args = [];
        if ($type !== '') {
            $sql .= ' WHERE e.type LIKE ?';
            $args[] = '%' . $type . '%';
        }
        $sql .= ' ORDER BY e.id DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            $client = (int) ($row['user_id'] ?? 0) === 0 && trim((string) ($row['customer_name'] ?? '')) === ''
                ? '<span class="sub">Sem cliente</span>'
                : $this->postClientCell($row);
            $rows .= '<tr><td class="keep">' . Layout::e($this->statusLabel((string) $row['type'])) . '</td>'
                . '<td>' . $client . '</td>'
                . '<td class="keep">' . $this->instagramLink((string) ($row['ig_username'] ?? '')) . '</td>'
                . '<td>' . ($postId > 0 ? (string) $postId : '') . '</td>'
                . '<td class="keep">' . Layout::e(Layout::when((string) $row['created_at'])) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty">Nenhum evento.</td></tr>';
        }
        $html = '<form class="filters" method="get"><input class="in" name="tipo" value="' . Layout::e($type) . '" placeholder="Tipo do evento"><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>Tipo</th><th>Cliente</th><th>Instagram</th><th>Post</th><th>Quando</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
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
            . '<div class="it"><span>AppMax</span><span>' . (\PerfilEmDia\Billing\AppMaxGateway::configured() ? 'API v3' : 'sem token') . '</span></div>'
            . '</div><p class="hint">URL de validação: ' . Layout::e(Layout::absolute('appmax/validar')) . '</p>'
            . '<p class="hint">Webhook: ' . Layout::e(Layout::absolute('webhooks/pagamento')) . '</p>'
            . '<p class="hint">Os segredos não aparecem nesta tela.</p></div>';
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

    private function tickets(): bool
    {
        $filter = (string) ($_GET['status'] ?? 'abertos');
        $allowed = ['abertos', TicketService::ANALISE, TicketService::ANDAMENTO, TicketService::ENCERRADO, 'todos'];
        if (!in_array($filter, $allowed, true)) {
            $filter = 'abertos';
        }
        $rows = '';
        foreach ($this->ticketService()->adminList($filter) as $row) {
            $who = trim((string) ($row['customer_name'] ?: $row['display_name'] ?: ''));
            if ($who === '' && !empty($row['telegram_username'])) {
                $who = '@' . $row['telegram_username'];
            }
            if ($who === '') {
                $who = 'Usuário #' . (int) $row['user_id'];
            }
            $rows .= '<tr data-href="' . Layout::e(Layout::url('admin/chamados/' . $row['id'])) . '"><td>#' . (int) $row['id'] . '</td><td>' . Layout::e($who) . '<span class="sub">' . Layout::e((string) $row['subject']) . '</span></td><td>' . $this->ticketPill((string) $row['status']) . '</td><td>' . Layout::e(Layout::when((string) $row['updated_at'])) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Nenhum chamado.</td></tr>';
        }
        $html = '<form class="filters" method="get"><select class="in" name="status">'
            . $this->option('abertos', 'Abertos', $filter)
            . $this->option(TicketService::ANALISE, TicketService::label(TicketService::ANALISE), $filter)
            . $this->option(TicketService::ANDAMENTO, TicketService::label(TicketService::ANDAMENTO), $filter)
            . $this->option(TicketService::ENCERRADO, TicketService::label(TicketService::ENCERRADO), $filter)
            . $this->option('todos', 'Todos', $filter)
            . '</select><button class="btn btn-primary btn-sm" type="submit">Filtrar</button></form>'
            . '<div class="tbl-wrap"><table><thead><tr><th>#</th><th>Cliente</th><th>Status</th><th>Atualizado</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<script>document.querySelectorAll("tr[data-href]").forEach(function(tr){tr.addEventListener("click",function(){location.href=tr.dataset.href;});});</script>';
        $this->render('Chamados', $html, 'chamados');

        return true;
    }

    private function ticketDetail(int $id): bool
    {
        $service = $this->ticketService();
        $ticket = $service->find($id);
        if ($ticket === null) {
            $this->render('Chamado', '<p class="empty">Chamado não encontrado.</p>', 'chamados');

            return true;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf()) {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'status') {
                $status = (string) ($_POST['status'] ?? '');
                $previous = (string) $ticket['status'];
                if ($service->setStatus($id, $status)) {
                    if ($status === TicketService::ENCERRADO && $previous !== TicketService::ENCERRADO) {
                        $sent = $this->notifyTicket((int) $ticket['telegram_chat_id'], Messages::ticketClosedNotice($id));
                        $_SESSION['admin_notice'] = $sent
                            ? 'Chamado encerrado. A pessoa foi avisada no Telegram.'
                            : 'Chamado encerrado. O Telegram não entregou o aviso.';
                    }
                }
            }
            if ($action === 'responder') {
                $body = trim((string) ($_POST['body'] ?? ''));
                $fresh = $body === '' ? null : $service->addAdminMessage($id, $body);
                if ($fresh === null) {
                    $_SESSION['admin_notice'] = 'Escreva a resposta antes de enviar.';
                } else {
                    $sent = $this->notifyTicket(
                        (int) $fresh['telegram_chat_id'],
                        Messages::ticketReply($id, TicketService::label((string) $fresh['status']), $body)
                    );
                    $_SESSION['admin_notice'] = $sent
                        ? 'Resposta enviada no Telegram.'
                        : 'A resposta foi salva, mas o Telegram não entregou.';
                }
            }
            header('Location: ' . Layout::url('admin/chamados/' . $id));
            exit;
        }

        $who = trim((string) ($ticket['display_name'] ?: ''));
        if ($who === '' && !empty($ticket['telegram_username'])) {
            $who = '@' . $ticket['telegram_username'];
        }
        $customerStmt = $this->pdo->prepare("SELECT id, name, email FROM customers WHERE user_id = ? AND status <> 'excluido' ORDER BY id DESC LIMIT 1");
        $customerStmt->execute([(int) $ticket['user_id']]);
        $customer = $customerStmt->fetch();
        if ($customer !== false && $who === '') {
            $who = (string) $customer['name'];
        }
        $whoHtml = Layout::e($who !== '' ? $who : 'Usuário #' . (int) $ticket['user_id']);
        if ($customer !== false) {
            $whoHtml .= ' <span class="sub"><a href="' . Layout::e(Layout::url('admin/clientes/' . $customer['id'])) . '">' . Layout::e((string) $customer['email']) . '</a></span>';
        }

        $thread = '';
        foreach ($service->messages($id) as $message) {
            $author = (string) $message['author'] === 'admin' ? 'Suporte' : 'Cliente';
            $thread .= '<div class="it" style="align-items:flex-start"><span>' . $author . '<span class="sub">' . Layout::e(Layout::when((string) $message['created_at'])) . '</span></span><span>' . nl2br(Layout::e((string) $message['body'])) . '</span></div>';
        }

        $statusButtons = '';
        foreach ([TicketService::ANALISE, TicketService::ANDAMENTO, TicketService::ENCERRADO] as $status) {
            $class = $status === (string) $ticket['status'] ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm';
            $statusButtons .= '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="status"><input type="hidden" name="status" value="' . Layout::e($status) . '"><button class="' . $class . '" type="submit">' . Layout::e(TicketService::label($status)) . '</button></form>';
        }

        $notice = (string) ($_SESSION['admin_notice'] ?? '');
        unset($_SESSION['admin_notice']);
        $noticeHtml = $notice !== '' ? '<p class="hint">' . Layout::e($notice) . '</p>' : '';

        $html = $noticeHtml
            . '<div class="panel"><p>' . $whoHtml . '</p><p>' . $this->ticketPill((string) $ticket['status']) . '</p><p>' . Layout::e((string) $ticket['subject']) . '</p>'
            . '<div class="actions">' . $statusButtons . '</div>'
            . '<div class="list" style="margin-top:16px">' . $thread . '</div>'
            . '<form method="post" class="box" style="margin-top:16px;max-width:720px">' . $this->csrf()
            . '<input type="hidden" name="action" value="responder"><div class="field"><label>Resposta</label><textarea name="body"></textarea></div>'
            . '<button class="btn btn-primary" type="submit">Enviar resposta</button></form></div>';
        $this->render('Chamado #' . $id, $html, 'chamados');

        return true;
    }

    private function ticketService(): TicketService
    {
        return new TicketService($this->pdo, new UserRepository($this->pdo, Crypto::fromConfig()));
    }

    private function notifyTicket(int $chatId, string $text): bool
    {
        if ($chatId <= 0 || Config::get('TELEGRAM_BOT_TOKEN', '') === '') {
            return false;
        }
        try {
            (new TelegramChannel(new TelegramClient()))->sendText($chatId, $text);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ticketPill(string $status): string
    {
        $class = match ($status) {
            TicketService::ANDAMENTO => 'ok',
            TicketService::ANALISE => 'warn',
            default => 'mut',
        };

        return '<span class="pill ' . $class . '">' . Layout::e(TicketService::label($status)) . '</span>';
    }

    private function kpi(string $label, string $value, string $sub): string
    {
        return '<article class="kpi"><div class="l">' . Layout::e($label) . '</div><div class="v">' . Layout::e($value) . '</div><div class="s">' . Layout::e($sub) . '</div></article>';
    }


    /**
     * @param array<string, mixed> $customer
     */
    /**
     * @param array<string, mixed> $customer
     */
    private function aiVideoBox(int $customerId, array $customer): string
    {
        $on = (int) ($customer['ai_video'] ?? 0) === 1;
        $state = $on ? 'Ligado. O bot mostra Vídeo com IA.' : 'Desligado.';
        $pending = empty($customer['user_id']) ? ' Vale quando a pessoa abrir o bot.' : '';
        $next = $on ? '0' : '1';
        $label = $on ? 'Desligar' : 'Ligar';

        return '<div class="box" style="margin-top:18px"><h2 style="font-size:18px;margin:0 0 8px">Vídeo com IA</h2>'
            . '<p>' . Layout::e($state . $pending) . ' Não entra no plano. Cada vídeo tem 5, 8 ou 15 segundos e gera custo na OpenRouter.</p>'
            . '<form method="post">' . $this->csrf()
            . '<input type="hidden" name="action" value="ai_video">'
            . '<input type="hidden" name="ai_video" value="' . $next . '">'
            . '<button class="btn btn-primary btn-sm" type="submit">' . $label . '</button></form></div>';
    }

    private function exemptionBox(int $customerId, array $customer, string $plans): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT comp_forever, comp_until FROM subscriptions WHERE customer_id = ? AND status = 'ativa' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        $status = 'Sem isenção.';
        if (is_array($row) && (int) ($row['comp_forever'] ?? 0) === 1) {
            $status = 'Isento para sempre.';
        } elseif (is_array($row) && !empty($row['comp_until'])) {
            $status = 'Isento até ' . Layout::when((string) $row['comp_until']) . '.';
        }
        $pending = empty($customer['user_id']) ? ' A isenção vale quando a pessoa enviar o código no Telegram.' : '';

        return '<div class="box" style="margin-top:18px"><h2 style="font-size:18px;margin:0 0 8px">' . 'Isenção' . '</h2>'
            . '<p>' . Layout::e($status . $pending) . '</p>'
            . '<form method="post" class="exempt">' . $this->csrf()
            . '<input type="hidden" name="action" value="isentar"><div class="modes">'
            . '<label><input type="radio" name="modo" value="sempre" checked> ' . 'Para sempre' . '</label>'
            . '<label><input type="radio" name="modo" value="ate"> ' . 'Até uma data' . '</label>'
            . '</div><input class="in" type="date" name="ate">'
            . '<select class="in" name="plan_id">' . $plans . '</select>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . 'Salvar isenção' . '</button></form>'
            . $this->actionForm('isentar_fim', 'Tirar isenção', 'Tirar a isenção? A cobrança volta no dia seguinte.')
            . '</div>';
    }

    private function grantExemption(int $customerId): void
    {
        $forever = (string) ($_POST['modo'] ?? '') === 'sempre';
        $until = null;
        if (!$forever) {
            $ate = (string) ($_POST['ate'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
                return;
            }
            $until = $ate . ' 23:59:59';
            if ($until < date('Y-m-d H:i:s')) {
                return;
            }
        }
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $plan = $this->pdo->prepare('SELECT id, price_cents, posts_limit FROM plans WHERE id = ? AND active = 1');
        $plan->execute([$planId]);
        $planRow = $plan->fetch();
        if ($planRow === false) {
            return;
        }
        $end = $forever
            ? (new \DateTimeImmutable('+10 years'))->format('Y-m-d H:i:s')
            : (string) $until;
        $find = $this->pdo->prepare(
            "SELECT id FROM subscriptions WHERE customer_id = ? AND status IN ('ativa','inadimplente','pendente') ORDER BY id DESC LIMIT 1"
        );
        $find->execute([$customerId]);
        $subId = $find->fetchColumn();
        if ($subId === false) {
            $this->pdo->prepare(
                'INSERT INTO subscriptions (customer_id, plan_id, cycle, period_kind, status, price_cents, posts_limit, period_days, comp_forever, comp_until, current_period_end, period_started_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW(), NOW(), NOW())'
            )->execute([
                $customerId,
                $planId,
                'mensal',
                'cheio',
                'ativa',
                (int) $planRow['price_cents'],
                (int) $planRow['posts_limit'],
                $forever ? 1 : 0,
                $until,
                $end,
            ]);
        } else {
            $this->pdo->prepare(
                "UPDATE subscriptions SET plan_id = ?, status = 'ativa', period_kind = 'cheio', price_cents = ?, posts_limit = ?, comp_forever = ?, comp_until = ?, current_period_end = ?, period_started_at = COALESCE(period_started_at, NOW()), updated_at = NOW() WHERE id = ?"
            )->execute([
                $planId,
                (int) $planRow['price_cents'],
                (int) $planRow['posts_limit'],
                $forever ? 1 : 0,
                $until,
                $end,
                (int) $subId,
            ]);
        }
        $this->pdo->prepare("UPDATE customers SET status = 'ativo', updated_at = NOW() WHERE id = ? AND status <> 'excluido'")
            ->execute([$customerId]);
    }

    private function revokeExemption(int $customerId): void
    {
        $this->pdo->prepare(
            "UPDATE subscriptions SET comp_forever = 0, comp_until = NULL, current_period_end = DATE_ADD(NOW(), INTERVAL 1 DAY), updated_at = NOW()
             WHERE customer_id = ? AND status = 'ativa' AND (comp_forever = 1 OR comp_until IS NOT NULL)"
        )->execute([$customerId]);
    }
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'aguardando_ativacao' => 'Aguardando ativação',
            'ativo' => 'Ativo',
            'ativa' => 'Ativa',
            'inadimplente' => 'Inadimplente',
            'cancelado', 'CANCELLED' => 'Cancelado',
            'cancelada' => 'Cancelada',
            'excluido' => 'Excluído',
            'pendente' => 'Pendente',
            'pago' => 'Pago',
            'recusado' => 'Recusado',
            'estornado' => 'Estornado',
            'COLLECTING' => 'Recebendo o material',
            'AWAITING_THEME' => 'Esperando o tema',
            'GENERATING' => 'Escrevendo a legenda',
            'AWAITING_APPROVAL' => 'Na prévia',
            'AWAITING_FEEDBACK' => 'Ajustando a legenda',
            'AWAITING_MANUAL_EDIT' => 'Esperando o texto',
            'AWAITING_IMAGE_EDIT' => 'Esperando o tratamento',
            'IMAGE_EDITING' => 'Tratando a foto',
            'SCHEDULED' => 'Agendado',
            'PUBLISHING' => 'Publicando',
            'PUBLISHED' => 'Publicado',
            'FAILED' => 'Falhou',
            'photo_edit' => 'Foto tratada',
            'post_published' => 'Post publicado',
            'cliente_excluido' => 'Cliente excluído',
            default => $status,
        };
    }
    private function pill(string $status): string
    {
        $class = match ($status) {
            'ativo', 'ativa', 'pago', 'PUBLISHED' => 'ok',
            'aguardando_ativacao', 'pendente', 'inadimplente' => 'warn',
            'cancelado', 'cancelada', 'recusado', 'estornado', 'FAILED', 'CANCELLED', 'excluido' => 'bad',
            'SCHEDULED', 'PUBLISHING', 'COLLECTING', 'GENERATING', 'IMAGE_EDITING', 'AWAITING_THEME', 'AWAITING_APPROVAL', 'AWAITING_FEEDBACK', 'AWAITING_MANUAL_EDIT', 'AWAITING_IMAGE_EDIT' => 'warn',
            default => 'mut',
        };

        return '<span class="pill ' . $class . '">' . Layout::e($this->statusLabel($status)) . '</span>';
    }

    private function option(string $value, string $label, string $current): string
    {
        return '<option value="' . Layout::e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . Layout::e($label) . '</option>';
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
    }

    private function actionForm(string $action, string $label, string $confirm = ''): string
    {
        $ask = $confirm !== ''
            ? ' onclick="return confirm(' . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')"'
            : '';

        return '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="' . Layout::e($action) . '"><button class="btn btn-ghost btn-sm" type="submit"' . $ask . '>' . Layout::e($label) . '</button></form>';
    }

    private function render(string $title, string $main, string $active): void
    {
        $draw = [Layout::class, 'admin'];
        $draw($title, $this->hint($active) . $main, $active);
    }

    private function hint(string $active): string
    {
        $hints = [
            'painel' => 'Os números do dia: quem pagou e ainda não abriu o bot, o líquido que a AppMax repassa, conexão perto de vencer e post que falhou.',
            'clientes' => 'Aguardando ativação significa que o pagamento existe e o código ainda não foi enviado no Telegram. No detalhe do cliente, Isenção libera o plano para sempre ou até uma data, sem cobrança. A lista mostra o plano, a vigência, o Instagram e o celular.',
            'chamados' => 'A pessoa abre com /chamado no Telegram. Responder avisa na conversa. Encerrado fecha o chamado.',
            'posts' => 'Aqui está o que o bot tentou publicar. Uma falha não publica sozinha: o cliente tenta de novo no Telegram. A lista mostra o cliente e o @ do Instagram.',
            'pagamentos' => 'Valor, status, bandeira e os 4 últimos dígitos. O número do cartão não fica nesta lista. A lista mostra de quem é o pagamento.',
            'planos' => 'O preço do teste vale na primeira mensalidade. Depois cobra o preço mensal. O anual continua em 10 vezes o mensal, sem teste.',
            'cupons' => 'Cada cupom tem desconto, quantidade de usos e data de validade. Vazio na quantidade ou na data significa sem limite.',
            'ia' => 'O custo do mês, do dia e o total vêm da chave no OpenRouter. A chave não aparece.',
            'eventos' => 'O que o sistema registrou. Filtre pelo tipo quando alguém disser que um dado sumiu. A lista mostra o cliente e o @ do Instagram.',
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

    /**
     * @param array<string, mixed> $row
     */
    private function customerPlanCell(array $row): string
    {
        $name = trim((string) ($row['plan_name'] ?? ''));
        if ($name === '') {
            return '<span class="sub">Sem plano</span>';
        }
        $cycle = (string) ($row['plan_cycle'] ?? '');
        $cycleLabel = match ($cycle) {
            'anual' => 'anual',
            'mensal' => 'mensal',
            default => $cycle,
        };
        $bits = [];
        if ($cycleLabel !== '') {
            $bits[] = $cycleLabel;
        }
        $price = (int) ($row['plan_price'] ?? 0);
        if ($price > 0) {
            $bits[] = Layout::money($price);
        }
        $limit = (int) ($row['plan_posts'] ?? 0);
        if ($limit > 0) {
            $bits[] = $limit . ' posts';
        }
        $html = Layout::e($name);
        if ($bits !== []) {
            $html .= '<span class="sub">' . Layout::e(implode(' · ', $bits)) . '</span>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function customerIgCell(array $row): string
    {
        $user = trim((string) ($row['ig_username'] ?? ''));
        if ($user === '') {
            return '<span class="sub">Não conectou</span>';
        }
        $html = Layout::e('@' . ltrim($user, '@'));
        $status = (string) ($row['ig_status'] ?? '');
        $note = match ($status) {
            'expired' => 'expirou',
            'revoked' => 'revogado',
            'error' => 'erro',
            default => '',
        };
        if ($note !== '') {
            $html .= '<span class="sub">' . Layout::e($note) . '</span>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function customerWhenCell(array $row): string
    {
        if ((int) ($row['comp_forever'] ?? 0) === 1) {
            return Layout::e('Sem cobrança') . '<span class="sub">isento</span>';
        }
        $end = trim((string) ($row['current_period_end'] ?? ''));
        if ($end === '') {
            return '<span class="sub">Sem data</span>';
        }
        $html = Layout::e($this->shortDate($end));
        if (!empty($row['comp_until'])) {
            $html .= '<span class="sub">isento</span>';
        } elseif ((string) ($row['period_kind'] ?? '') === 'teste') {
            $html .= '<span class="sub">teste</span>';
        }

        return $html;
    }

    private function shortDate(string $value): string
    {
        return Layout::when($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function postClientCell(array $row): string
    {
        $name = trim((string) ($row['customer_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['display_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Usuário ' . (int) $row['user_id'];
        }
        $label = Layout::e($name);
        $id = (int) ($row['customer_id'] ?? 0);
        if ($id > 0) {
            return '<a href="' . Layout::e(Layout::url('admin/clientes/' . $id)) . '">' . $label . '</a>';
        }

        return $label;
    }

    private function instagramLink(string $username): string
    {
        $user = ltrim(trim($username), '@');
        if ($user === '') {
            return '<span class="sub">Não conectou</span>';
        }
        $label = Layout::e('@' . $user);
        if (preg_match('/^[A-Za-z0-9._]+$/', $user) !== 1) {
            return $label;
        }
        $href = 'https://www.instagram.com/' . rawurlencode($user) . '/';

        return '<a href="' . Layout::e($href) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function paymentClientCell(array $row): string
    {
        $name = trim((string) ($row['customer_name'] ?? ''));
        $id = (int) ($row['customer_id'] ?? 0);
        if ($name === '' || $id === 0) {
            return '<span class="sub">Sem cliente</span>';
        }
        $html = '<a href="' . Layout::e(Layout::url('admin/clientes/' . $id)) . '">' . Layout::e($name) . '</a>';
        $email = trim((string) ($row['customer_email'] ?? ''));
        $document = trim((string) ($row['customer_document'] ?? ''));
        if ($email !== '') {
            $html .= '<span class="sub">' . Layout::e($email) . '</span>';
        }
        if ($document !== '') {
            $html .= '<span class="sub">' . Layout::e($document) . '</span>';
        }

        return $html;
    }


    private function moneyNote(float $amount, float $fx, string $note): string
    {
        if ($fx <= 0) {
            return $note;
        }

        return 'R$ ' . number_format($amount * $fx, 2, ',', '.') . ', ' . $note;
    }
    private function usd(float $amount, float $fx = 0): string
    {
        $decimals = ($amount > 0 && $amount < 0.01) ? 4 : 2;
        $text = 'US$ ' . number_format($amount, $decimals, ',', '.');
        if ($fx > 0) {
            $text .= ' (R$ ' . number_format($amount * $fx, 2, ',', '.') . ')';
        }

        return $text;
    }

    /**
     * @param array{usage:float,daily:float,weekly:float,monthly:float,limit:?float,remaining:?float,reset:?string} $spend
     */
    private function openRouterLimitNote(array $spend): string
    {
        if ($spend['limit'] === null) {
            return 'sem teto na chave';
        }
        $when = match ($spend['reset']) {
            'daily' => 'do dia',
            'weekly' => 'da semana',
            'monthly' => 'do mês',
            default => 'da chave',
        };
        $note = 'limite ' . $when . ' ' . $this->usd((float) $spend['limit']);
        if ($spend['remaining'] !== null) {
            $note .= ', restam ' . $this->usd((float) $spend['remaining']);
        }

        return $note;
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