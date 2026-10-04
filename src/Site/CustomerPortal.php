<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use DateTimeImmutable;
use PerfilEmDia\Billing\AccountOrchestrator;
use PerfilEmDia\Billing\AppMaxGateway;
use PerfilEmDia\Billing\BillingFactory;
use PerfilEmDia\Billing\Card;
use PerfilEmDia\Billing\CustomerAccess;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\Phone;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PDO;
use RuntimeException;
use Throwable;

final class CustomerPortal
{
    private ?PortalOrchestrator $portal = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    private function portal(): PortalOrchestrator
    {
        if ($this->portal instanceof PortalOrchestrator) {
            return $this->portal;
        }
        $users = new UserRepository($this->pdo, Crypto::fromConfig());
        $accounts = new AccountOrchestrator(
            $this->pdo,
            BillingFactory::service($this->pdo),
            new CustomerAccess($this->pdo),
            new TicketService($this->pdo, $users),
            $users,
        );
        $this->portal = new PortalOrchestrator($this->pdo, $accounts);

        return $this->portal;
    }

    private function accounts(): AccountOrchestrator
    {
        return $this->portal()->billing();
    }

    public function dispatch(string $path): bool
    {
        if (!str_starts_with($path, '/minha-conta')) {
            return false;
        }
        if ($path === '/minha-conta/sair') {
            unset($_SESSION['account_customer_id']);
            header('Location: ' . Layout::url('planos'));
            exit;
        }
        if (preg_match('#^/minha-conta/acesso/([a-f0-9]{64})$#', $path, $match) === 1) {
            $customerId = $this->accounts()->openToken($match[1]);
            if ($customerId === null) {
                Layout::page(
                    'Link vencido',
                    '<section class="center-page"><div><h1>Esse link venceu</h1><p>No Telegram, envie /assinatura de novo e abra o link da mensagem mais recente. Cada link vale 12 horas.</p></div></section>',
                    '',
                    410,
                );

                return true;
            }
            session_regenerate_id(true);
            $_SESSION['account_customer_id'] = $customerId;
            header('Location: ' . Layout::url('minha-conta'));
            exit;
        }
        $customerId = $this->sessionCustomerId();
        if ($path === '/minha-conta/logo') {
            return $this->serveLogo($customerId);
        }
        if (preg_match('#^/minha-conta/extra/(\d+)$#', $path, $extraMatch) === 1) {
            return $this->servePromptExtraImage($customerId, (int) $extraMatch[1]);
        }
        if ($path === '/minha-conta/publicar/estado') {
            return $this->studioJson($customerId);
        }
        if ($path === '/minha-conta/publicar/acao') {
            return $this->studioActionJson($customerId);
        }
        $pages = [
            '/minha-conta' => 'billing',
            '/minha-conta/perfil' => 'profile',
            '/minha-conta/publicar' => 'studio',
        ];
        if (!isset($pages[$path])) {
            return false;
        }
        if ($customerId < 1) {
            Layout::page(
                'Minha conta',
                '<section class="center-page"><div><h1>Entre pelo Telegram</h1><p>Envie /assinatura no bot. A mensagem traz o link desta página.</p></div></section>',
                '',
                401,
            );

            return true;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $redirect = $this->post($customerId, $path);

            return is_string($redirect) ? $this->redirect($redirect) : true;
        }
        $bundle = $this->portal()->profileBundle($customerId);
        if ($bundle === null) {
            unset($_SESSION['account_customer_id']);
            Layout::page(
                'Minha conta',
                '<section class="center-page"><div><h1>Conta não encontrada</h1><p>Envie /assinatura no bot para gerar um link novo.</p></div></section>',
                '',
                404,
            );

            return true;
        }
        $tab = $pages[$path];
        if ($tab === 'studio') {
            $this->portal()->studioSeed($customerId);
        }
        $title = match ($tab) {
            'profile' => 'Perfil e preferências',
            'studio' => 'Publicar',
            default => 'Minha conta',
        };
        $body = match ($tab) {
            'profile' => $this->htmlProfile($bundle),
            'studio' => $this->htmlStudio($customerId, $bundle),
            default => $this->html($bundle),
        };
        Layout::page($title, $this->portalNav($tab) . $body, 'conta');

        return true;
    }

    private function sessionCustomerId(): int
    {
        return (int) ($_SESSION['account_customer_id'] ?? 0);
    }

    private function redirect(string $path): bool
    {
        header('Location: ' . Layout::url(ltrim($path, '/')));
        exit;
    }

    private function savePromptExtra(int $customerId): void
    {
        $extraId = $this->portal()->savePromptExtra($customerId, [
            'extra_id' => (string) ($_POST['extra_id'] ?? ''),
            'trigger_word' => (string) ($_POST['trigger_word'] ?? ''),
            'prompt_text' => (string) ($_POST['prompt_text'] ?? ''),
        ]);
        if (isset($_FILES['extra_image']) && is_array($_FILES['extra_image'])
            && ($_FILES['extra_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) ($_FILES['extra_image']['tmp_name'] ?? '');
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                $this->portal()->savePromptExtraImage($customerId, $extraId, $tmp);
            }
        }
    }

    private function uploadPromptExtraImage(int $customerId): void
    {
        if (!isset($_FILES['extra_image']) || !is_array($_FILES['extra_image'])) {
            throw new RuntimeException('Escolha uma imagem.');
        }
        $file = $_FILES['extra_image'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('N�o foi poss�vel receber a imagem.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload inv�lido.');
        }
        $this->portal()->savePromptExtraImage($customerId, (int) ($_POST['extra_id'] ?? 0), $tmp);
    }

    private function servePromptExtraImage(int $customerId, int $extraId): bool
    {
        if ($customerId < 1) {
            http_response_code(401);

            return true;
        }
        $path = $this->portal()->promptExtraImagePath($customerId, $extraId);
        if ($path === null) {
            http_response_code(404);

            return true;
        }
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=3600');
        readfile($path);

        return true;
    }

    private function serveLogo(int $customerId): bool
    {
        if ($customerId < 1) {
            http_response_code(401);

            return true;
        }
        $path = $this->portal()->logoPathForCustomer($customerId);
        if ($path === null) {
            http_response_code(404);

            return true;
        }
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=3600');
        readfile($path);

        return true;
    }

    private function studioJson(int $customerId): bool
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($customerId < 1) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);

            return true;
        }
        echo json_encode(['ok' => true, 'state' => $this->enrichStudioState($customerId)], JSON_UNESCAPED_UNICODE);

        return true;
    }

    private function studioActionJson(int $customerId): bool
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);

            return true;
        }
        if ($customerId < 1) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);

            return true;
        }
        if (!Layout::checkCsrf()) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'A sess�o expirou. Recarregue a p�gina e tente de novo.',
                'state' => $this->enrichStudioState($customerId),
            ], JSON_UNESCAPED_UNICODE);

            return true;
        }
        $action = (string) ($_POST['action'] ?? '');
        $notice = '';
        try {
            match ($action) {
                'studio_msg' => $this->portal()->studioChat($customerId, (string) ($_POST['message'] ?? '')),
                'studio_cb' => $this->portal()->studioCallback($customerId, (string) ($_POST['callback'] ?? '')),
                'studio_upload' => $this->studioUpload($customerId),
                'studio_reset' => $this->portal()->studioReset($customerId),
                default => throw new RuntimeException('A��o desconhecida.'),
            };
            if ($action === 'studio_reset') {
                $notice = 'Conversa reiniciada.';
            }
            echo json_encode([
                'ok' => true,
                'notice' => $notice,
                'state' => $this->enrichStudioState($customerId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (RuntimeException $e) {
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'state' => $this->enrichStudioState($customerId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            echo json_encode([
                'ok' => false,
                'error' => 'N�o foi poss�vel concluir. Tente de novo.',
                'state' => $this->enrichStudioState($customerId),
            ], JSON_UNESCAPED_UNICODE);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichStudioState(int $customerId): array
    {
        $state = $this->portal()->studioState($customerId);
        $expect = (string) ($state['expecting'] ?? 'text');
        $state['expecting_label'] = $this->studioExpectingLabel($expect);
        $post = $state['post'] ?? null;
        $state['busy'] = $this->studioIsBusy(is_array($post) ? $post : null);
        if (is_array($post)) {
            $state['post'] = [
                'id' => (int) ($post['id'] ?? 0),
                'status' => (string) ($post['status'] ?? ''),
            ];
        }

        return $state;
    }

    private function studioExpectingLabel(string $expect): string
    {
        return match ($expect) {
            'theme' => 'Descreva o tema do post na caixa de mensagem.',
            'feedback' => 'Diga o que mudar na legenda ou na arte (mensagem escrita).',
            'caption' => 'Envie o texto final da legenda na caixa de mensagem.',
            'image_edit' => 'Descreva a mudan�a que quer na imagem (mensagem escrita).',
            'media' => 'Envie foto ou v�deo no painel ao lado (legenda opcional).',
            'idea' => 'Descreva a ideia na mensagem ou use os bot�es do assistente.',
            default => 'Comece com Novo post ou escreva /novo na mensagem escrita.',
        };
    }

    /**
     * @param array<string, mixed>|null $post
     */
    private function studioIsBusy(?array $post): bool
    {
        if ($post === null) {
            return false;
        }
        $status = (string) ($post['status'] ?? '');

        return in_array($status, ['GENERATING', 'COLLECTING', 'PUBLISHING', 'IMAGE_EDITING'], true);
    }

    private function portalNav(string $active): string
    {
        $link = static function (string $slug, string $label, string $key) use ($active): string {
            $current = $active === $key ? ' aria-current="page"' : '';

            return '<a class="portal-tab" href="' . Layout::e(Layout::url('minha-conta' . ($slug === '' ? '' : '/' . $slug))) . '"' . $current . '>' . Layout::e($label) . '</a>';
        };

        return '<nav class="portal-tabs wrap" aria-label="Área do cliente">'
            . $link('', 'Assinatura', 'billing')
            . $link('perfil', 'Perfil', 'profile')
            . $link('publicar', 'Publicar', 'studio')
            . '</nav>';
    }

    private function post(int $customerId, string $path): ?string
    {
        if (!Layout::checkCsrf()) {
            $this->flash('A página expirou. Abra de novo e tente outra vez.', true);

            return $this->redirectPath($path);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ip') {
            $ip = trim((string) ($_POST['client_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $_SESSION['appmax_ip'] = $ip;
            }

            return $this->redirectPath($path);
        }
        try {
            unset($_SESSION['account_error'], $_SESSION['account_notice']);
            match ($action) {
                'plano' => $this->accounts()->choosePlan($customerId, (int) ($_POST['plan_id'] ?? 0)),
                'ciclo' => $this->accounts()->chooseCycle($customerId, (string) ($_POST['cycle'] ?? '')),
                'cancelar' => $this->accounts()->cancel($customerId),
                'desfazer' => $this->accounts()->undoCancel($customerId),
                'contato' => $this->accounts()->saveContact($customerId, (string) ($_POST['email'] ?? ''), (string) ($_POST['phone'] ?? '')),
                'chamado' => $this->accounts()->openTicket($customerId, (string) ($_POST['body'] ?? '')),
                'pix' => $this->accounts()->beginPix($customerId),
                'verificar' => $this->accounts()->refreshPix($customerId),
                'pagar', 'cartao' => $this->card($customerId, $action),
                'conta_ig' => $this->portal()->setActiveAccount($customerId, (int) ($_POST['account_id'] ?? 0)),
                'perfil' => $this->portal()->saveProfile($customerId, $_POST),
                'prefs' => $this->portal()->savePreferences($customerId, array_merge($_POST, [
                    'idea_daily' => isset($_POST['idea_daily']) ? '1' : '0',
                ])),
                'logo' => $this->uploadLogo($customerId),
                'studio_msg' => $this->portal()->studioChat($customerId, (string) ($_POST['message'] ?? '')),
                'studio_cb' => $this->portal()->studioCallback($customerId, (string) ($_POST['callback'] ?? '')),
                'studio_upload' => $this->studioUpload($customerId),
                'studio_reset' => $this->portal()->studioReset($customerId),
                'extra_save' => $this->savePromptExtra($customerId),
                'extra_delete' => $this->portal()->deletePromptExtra($customerId, (int) ($_POST['extra_id'] ?? 0)),
                'extra_image' => $this->uploadPromptExtraImage($customerId),
                default => throw new RuntimeException('Ação desconhecida.'),
            };
            if (!isset($_SESSION['account_error'])) {
                $this->flash($this->doneMessage($action), false);
            }
        } catch (PaymentRefused $e) {
            $this->flash($e->getMessage(), true);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage(), true);
        } catch (Throwable) {
            $this->flash('Não foi possível concluir. Tente de novo.', true);
        }

        return $this->redirectPath($path, $action);
    }

    private function redirectPath(string $path, string $action = ''): string
    {
        if (str_starts_with($action, 'studio_')) {
            return '/minha-conta/publicar';
        }
        if (in_array($action, ['perfil', 'prefs', 'logo', 'conta_ig', 'extra_save', 'extra_delete', 'extra_image'], true)) {
            return '/minha-conta/perfil';
        }

        return $path === '' ? '/minha-conta' : $path;
    }

    private function uploadLogo(int $customerId): void
    {
        if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
            throw new RuntimeException('Escolha uma imagem.');
        }
        $file = $_FILES['logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não foi possível receber a logo.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload inválido.');
        }
        $this->portal()->saveLogo($customerId, $tmp);
    }

    private function studioUpload(int $customerId): void
    {
        if (!isset($_FILES['media']) || !is_array($_FILES['media'])) {
            throw new RuntimeException('Escolha uma foto ou vídeo.');
        }
        $caption = isset($_POST['caption']) ? (string) $_POST['caption'] : null;
        $this->portal()->studioUpload($customerId, $_FILES['media'], $caption);
    }

    private function card(int $customerId, string $action): void
    {
        $read = $this->readCard();
        if ($action === 'pagar') {
            $this->accounts()->payCard($customerId, $read['token'], $read['brand'], $read['last4']);

            return;
        }
        $this->accounts()->saveCard($customerId, $read['token'], $read['brand'], $read['last4']);
    }

    /**
     * @return array{token:string,brand:?string,last4:?string}
     */
    private function readCard(): array
    {
        $digits = Card::digits((string) ($_POST['number'] ?? ''));
        $last4 = strlen($digits) >= 4 ? substr($digits, -4) : null;
        $brand = $digits !== '' ? Card::brand($digits) : null;
        $holder = trim((string) ($_POST['card-holder-name'] ?? ''));
        $month = (int) ($_POST['exp-month'] ?? 0);
        $year = (int) ($_POST['exp-year'] ?? 0);
        $cvv = Card::digits((string) ($_POST['cvv'] ?? ''));
        unset($_POST['number'], $_POST['cvv'], $_POST['exp-month'], $_POST['exp-year'], $_POST['card-holder-name']);
        if (AppMaxGateway::configured()) {
            if (!Card::luhn($digits)) {
                throw new RuntimeException('Número de cartão inválido.');
            }
            $token = (new AppMaxGateway($this->pdo))->tokenize($holder, $digits, $month, $year, $cvv);

            return ['token' => $token, 'brand' => $brand, 'last4' => $last4];
        }
        if (!Card::luhn($digits)) {
            throw new RuntimeException('Número de cartão inválido.');
        }

        return ['token' => 'sandbox:' . $last4, 'brand' => $brand, 'last4' => $last4];
    }

    private function doneMessage(string $action): string
    {
        return match ($action) {
            'plano' => 'Plano atualizado.',
            'ciclo' => 'Ciclo atualizado.',
            'cancelar' => 'Cancelamento registrado.',
            'desfazer' => 'A assinatura volta a renovar.',
            'contato' => 'Dados atualizados.',
            'chamado' => 'Chamado aberto. A resposta chega no Telegram.',
            'pix' => 'Pix gerado.',
            'verificar' => 'Pagamento consultado.',
            'pagar' => 'Pagamento registrado.',
            'cartao' => 'Cartão guardado para a próxima cobrança.',
            'ip' => '',
            'conta_ig' => 'Conta Instagram ativa atualizada.',
            'perfil' => 'Perfil salvo.',
            'prefs' => 'Preferências salvas.',
            'logo' => 'Logo atualizada.',
            'studio_msg', 'studio_cb', 'studio_upload' => '',
            'studio_reset' => 'Conversa reiniciada.',
            'extra_save' => 'Extra salvo.',
            'extra_delete' => 'Extra removido.',
            'extra_image' => 'Imagem do extra atualizada.',
            default => 'Pronto.',
        };
    }

    private function flash(string $message, bool $error): void
    {
        if ($message === '') {
            return;
        }
        if ($error) {
            $_SESSION['account_error'] = $message;
            unset($_SESSION['account_notice']);

            return;
        }
        $_SESSION['account_notice'] = $message;
        unset($_SESSION['account_error']);
    }

    /**
     * @param array<string, mixed> $view
     */
    private function html(array $view): string
    {
        $notice = (string) ($_SESSION['account_notice'] ?? '');
        $error = (string) ($_SESSION['account_error'] ?? '');
        unset($_SESSION['account_notice'], $_SESSION['account_error']);
        $sub = is_array($view['subscription']) ? $view['subscription'] : null;
        $customer = $view['customer'];
        $html = '<section class="page"><div class="wrap">';
        $html .= '<h1>Minha conta</h1>';
        $html .= '<p class="meta">' . Layout::e((string) $customer['name']) . ' · <a href="' . Layout::e(Layout::url('minha-conta/sair')) . '">Sair</a></p>';
        if ($notice !== '') {
            $html .= '<p class="notice">' . Layout::e($notice) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="notice">' . Layout::e($error) . '</p>';
        }
        $html .= $this->summaryBox($view, $sub);
        $html .= $this->payBox($view);
        $html .= $this->planBox($view, $sub);
        $html .= $this->historyBox($view);
        $html .= $this->instagramBox($view);
        $html .= $this->contactBox($customer);
        $html .= $this->ticketBox($view);
        if ($view['appmax']) {
            $html .= $this->ipScript();
        }
        $html .= '</div></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, mixed>|null $sub
     */
    private function summaryBox(array $view, ?array $sub): string
    {
        $cycle = $sub === null ? '' : ((string) $sub['cycle'] === 'anual' ? 'anual' : 'mensal');
        $until = $sub === null ? '' : $this->when((string) ($sub['current_period_end'] ?? ''));
        $html = '<div class="box" style="margin-top:28px"><h2>Assinatura</h2>';
        $html .= '<div class="list">';
        $html .= $this->row('Situação', (string) $view['status_label']);
        $html .= $this->row('Plano', $sub === null ? 'Nenhum' : (string) $sub['plan_name']);
        $html .= $this->row('Ciclo', $cycle);
        $html .= $this->row('Posts', (string) $view['usage']);
        $exempt = $this->exemptionLabel($sub);
        if ($exempt !== '') {
            $html .= $this->row('Isenção', $exempt);
        }
        if ($until !== '' && (int) ($sub['comp_forever'] ?? 0) !== 1) {
            $html .= $this->row('Período até', $until);
        }
        if ($sub !== null && !empty($sub['cancel_at'])) {
            $html .= $this->row('Cancela em', $this->when((string) $sub['cancel_at']));
        } elseif ($view['due']) {
            $html .= $this->row('Em aberto', Layout::money((int) $view['amount_cents']));
        } elseif ($exempt === '' && $sub !== null && (string) $sub['status'] === 'ativa') {
            $html .= $this->row('Próxima cobrança', Layout::money((int) $view['amount_cents']));
        }
        $card = $this->cardLabel($sub);
        if ($card !== '') {
            $html .= $this->row('Cartão', $card);
        }
        $html .= '</div>';
        if ($view['mutable'] && $sub !== null && (string) $sub['status'] === 'ativa' && !empty($sub['cancel_at'])) {
            $html .= $this->form('desfazer', '<button class="btn btn-primary" type="submit">Desfazer cancelamento</button>');
        } elseif ($view['mutable']) {
            $html .= $this->form(
                'cancelar',
                '<button class="btn btn-ghost" type="submit">Cancelar assinatura</button>',
                'O acesso segue até o fim do período já pago. Se o pagamento estiver em aberto, a assinatura encerra agora.',
            );
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     */
    private function payBox(array $view): string
    {
        if (!$view['due'] && !$view['mutable']) {
            return '';
        }
        $html = '<div class="box" style="margin-top:20px"><h2>' . ($view['due'] ? 'Pagar agora' : 'Cartão da próxima cobrança') . '</h2>';
        if ($view['due']) {
            $html .= '<p class="meta">Valor: ' . Layout::e(Layout::money((int) $view['amount_cents'])) . '. No Pix, este período fica quitado e a renovação seguinte pede um cartão.</p>';
            $pix = is_array($view['pix']) ? $view['pix'] : null;
            if ($pix === null) {
                $html .= $this->form('pix', '<button class="btn btn-primary" type="submit">Gerar Pix</button>');
            } else {
                $html .= '<div class="copy" style="margin-top:12px"><input class="in" id="pix-code" readonly value="' . Layout::e((string) $pix['pix_payload']) . '">';
                $html .= '<button class="btn btn-ghost" type="button" data-act="copy" data-target="pix-code">Copiar</button></div>';
                $html .= '<p class="hint">Válido até ' . Layout::e($this->when((string) ($pix['pix_expires_at'] ?? ''))) . '.</p>';
                $html .= $this->form('verificar', '<button class="btn btn-primary" type="submit">Verificar pagamento</button>');
            }
        } else {
            $html .= '<p class="meta">O cartão novo entra na próxima cobrança. O número não fica gravado aqui.</p>';
        }
        $html .= $this->cardForm($view['due'] ? 'pagar' : 'cartao', (string) $view['customer']['name']);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, mixed>|null $sub
     */
    private function planBox(array $view, ?array $sub): string
    {
        if (!$view['mutable'] || $sub === null) {
            return '';
        }
        $html = '<div class="box portal-plan" style="margin-top:20px"><h2>Plano e ciclo</h2>';
        $html .= '<p class="meta">A troca de preço vale na próxima cobrança. Um plano com mais posts libera o limite na hora. Um plano com menos posts muda o limite só no próximo período. Estúdio inclui o post criado a partir de uma ideia, com Surpreenda-me. A ideia do dia chega a partir das 8h, e um lembrete se passar de 1 dia sem postar. Vídeo curto, texto na foto, tratamento da foto e a marca d\'água ficam no Profissional e no Estúdio. Agendar vale em todos os planos.</p>';
        $options = '';
        foreach ($view['plans'] as $plan) {
            $selected = (int) $plan['id'] === (int) $sub['plan_id'] ? ' selected' : '';
            $options .= '<option value="' . (int) $plan['id'] . '"' . $selected . '>' . Layout::e((string) $plan['name']) . ' ' . Layout::e(Layout::money((int) $plan['price_cents'])) . '/mês</option>';
        }
        $html .= '<div class="field"><label for="plan_id">Plano</label><select class="in" id="plan_id" name="plan_id" form="portal-plan-form">' . $options . '</select></div>';
        if (!empty($sub['next_plan_id'])) {
            $html .= '<p class="hint">Há um plano diferente marcado para a próxima cobrança.</p>';
        }
        $monthly = (string) $sub['cycle'] === 'mensal' ? 'btn-primary' : 'btn-ghost';
        $yearly = (string) $sub['cycle'] === 'anual' ? 'btn-primary' : 'btn-ghost';
        $html .= '<p class="portal-field-label">Ciclo de cobrança</p>';
        $html .= '<div class="account-actions portal-cycle-actions">';
        $html .= $this->form('ciclo', '<input type="hidden" name="cycle" value="mensal"><button class="btn ' . $monthly . '" type="submit">Mensal</button>', '', 'portal-inline-form');
        $html .= $this->form('ciclo', '<input type="hidden" name="cycle" value="anual"><button class="btn ' . $yearly . '" type="submit">Anual, 10 meses</button>', '', 'portal-inline-form');
        $html .= '</div>';
        if (!empty($sub['next_cycle'])) {
            $html .= '<p class="hint">Na próxima cobrança o ciclo passa para ' . Layout::e((string) $sub['next_cycle']) . '.</p>';
        }
        $html .= $this->form(
            'plano',
            '<div class="portal-form-actions"><button class="btn btn-primary" type="submit">Usar este plano</button></div>',
            '',
            '',
            'portal-plan-form',
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     */
    private function historyBox(array $view): string
    {
        $html = '<div class="box" style="margin-top:20px"><h2>Pagamentos</h2><div class="list">';
        if ($view['payments'] === []) {
            $html .= '<p class="meta">Nenhum pagamento ainda.</p>';
        }
        foreach ($view['payments'] as $payment) {
            $card = '';
            if ((string) ($payment['last4'] ?? '') !== '') {
                $card = ' ' . (string) ($payment['brand'] ?? '') . ' final ' . (string) $payment['last4'];
            }
            $html .= $this->row(
                $this->when((string) $payment['created_at']) . ' · ' . $this->methodLabel((string) $payment['method']) . $card,
                Layout::money((int) $payment['amount_cents']) . ' ' . $this->payLabel((string) $payment['status']),
            );
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     */
    private function instagramBox(array $view): string
    {
        $ig = $view['instagram'];
        $html = '<div class="box" style="margin-top:20px"><h2>Instagram</h2>';
        $accounts = is_array($view['instagram_accounts'] ?? null) ? $view['instagram_accounts'] : [];
        if ($accounts === []) {
            if ((string) $ig['username'] !== '' && (string) $ig['status'] === 'active') {
                $html .= '<p>Conectado como @' . Layout::e(ltrim((string) $ig['username'], '@')) . '.</p>';
            } else {
                $html .= '<p>Nenhuma conta profissional conectada.</p>';
            }
        } else {
            $active = (int) ($view['active_account_id'] ?? 0);
            $html .= '<p class="meta">Perfil, logo e posts usam a conta marcada como ativa. Edite em <a href="' . Layout::e(Layout::url('minha-conta/perfil')) . '">Perfil</a>.</p><div class="list">';
            foreach ($accounts as $row) {
                $username = ltrim((string) ($row['username'] ?? ''), '@');
                $mark = (int) ($row['id'] ?? 0) === $active ? ' (ativa)' : '';
                $status = (string) ($row['status'] ?? '');
                $html .= $this->row('@' . $username . $mark, $status === 'active' ? 'conectada' : $status);
            }
            $html .= '</div>';
        }
        if ((string) $ig['url'] !== '') {
            $html .= '<p style="margin-top:12px"><a class="btn btn-ghost" href="' . Layout::e((string) $ig['url']) . '">Conectar Instagram</a></p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function contactBox(array $customer): string
    {
        $html = '<div class="box" style="margin-top:20px"><h2>Dados</h2>';
        $html .= '<p class="hint">Nome, legenda e logo ficam em <a href="' . Layout::e(Layout::url('minha-conta/perfil')) . '">Perfil</a>. Aqui ficam e-mail e celular da assinatura. Documento: ' . Layout::e((string) $customer['document']) . '. Ele não muda por aqui.</p>';
        $html .= $this->form('contato', '<div class="field"><label for="email">E-mail</label><input class="in" id="email" name="email" type="email" value="' . Layout::e((string) $customer['email']) . '" required></div>'
            . '<div class="field"><label for="phone">Celular</label><input class="in" id="phone" name="phone" type="tel" value="' . Layout::e(Phone::format((string) $customer['phone'])) . '" required></div>'
            . '<button class="btn btn-primary" type="submit">Salvar</button>');
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $view
     */
    private function ticketBox(array $view): string
    {
        $html = '<div class="box" style="margin-top:20px"><h2>Chamados</h2>';
        if ((int) $view['customer']['user_id'] < 1) {
            $html .= '<p>Ative o código no Telegram para abrir um chamado.</p></div>';

            return $html;
        }
        foreach ($view['tickets'] as $ticket) {
            $html .= '<h3 style="font-size:18px;margin-top:16px">#' . (int) $ticket['id'] . ' ' . Layout::e((string) $ticket['subject']) . '</h3>';
            $html .= '<p class="hint">' . Layout::e((string) $ticket['label']) . '</p><div class="list">';
            foreach ($ticket['messages'] as $message) {
                $who = (string) $message['author'] === 'admin' ? 'Perfil em Dia' : 'Você';
                $html .= $this->row($who . ' ' . $this->when((string) $message['created_at']), (string) $message['body']);
            }
            $html .= '</div>';
        }
        $html .= $this->form('chamado', '<div class="field"><label for="body">Novo chamado</label><textarea class="in" id="body" name="body" required></textarea></div><button class="btn btn-primary" type="submit">Enviar</button>');
        $html .= '<p class="hint">A resposta chega na conversa do bot.</p></div>';

        return $html;
    }

    private function cardForm(string $action, string $holder): string
    {
        $label = $action === 'pagar' ? 'Pagar com cartão' : 'Guardar este cartão';
        $fields = '<div class="field"><label for="card-number">Número do cartão</label><input class="in" id="card-number" name="number" type="text" inputmode="numeric" autocomplete="cc-number" required></div>';
        if (AppMaxGateway::configured()) {
            $fields .= '<div class="field"><label for="card-holder-name">Nome no cartão</label><input class="in" id="card-holder-name" name="card-holder-name" type="text" autocomplete="cc-name" value="' . Layout::e($holder) . '" required></div>';
            $fields .= '<div class="grid2"><div class="field"><label for="exp-month">Mês</label><input class="in" id="exp-month" name="exp-month" inputmode="numeric" autocomplete="cc-exp-month" placeholder="MM" required></div>';
            $fields .= '<div class="field"><label for="exp-year">Ano</label><input class="in" id="exp-year" name="exp-year" inputmode="numeric" autocomplete="cc-exp-year" placeholder="AA" required></div></div>';
            $fields .= '<div class="field"><label for="cvv">CVV</label><input class="in" id="cvv" name="cvv" inputmode="numeric" autocomplete="cc-csc" required></div>';
        }
        $fields .= '<button class="btn btn-primary" type="submit">' . $label . '</button>';

        return $this->form($action, $fields);
    }

    private function form(string $action, string $inner, string $confirm = '', string $class = '', string $id = ''): string
    {
        $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES, 'UTF-8') . ')"' : '';
        $classAttr = $class !== '' ? ' class="' . Layout::e($class) . '"' : '';
        $idAttr = $id !== '' ? ' id="' . Layout::e($id) . '"' : '';

        return '<form method="post"' . $idAttr . $classAttr . $onsubmit . '>'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="action" value="' . Layout::e($action) . '">'
            . $inner
            . '</form>';
    }

    private function fileUploadField(string $id, string $name, string $accept, bool $required = false): string
    {
        $req = $required ? ' required' : '';

        return '<div class="portal-file">'
            . '<label class="portal-file-btn btn btn-ghost" for="' . Layout::e($id) . '">Escolher arquivo</label>'
            . '<input class="portal-file-input" type="file" id="' . Layout::e($id) . '" name="' . Layout::e($name) . '" accept="' . Layout::e($accept) . '" data-file-label' . $req . '>'
            . '<span class="portal-file-name">Nenhum arquivo escolhido</span>'
            . '</div>';
    }

    private function portalFileScript(): string
    {
        return '<script>(function(){document.querySelectorAll("[data-file-label]").forEach(function(input){var name=input.closest(".portal-file");if(!name)return;var out=name.querySelector(".portal-file-name");if(!out)return;function sync(){if(!input.files||!input.files[0]){out.textContent="Nenhum arquivo escolhido";return;}out.textContent=input.files[0].name;}input.addEventListener("change",sync);});})();</script>';
    }

    private function row(string $label, string $value): string
    {
        return '<div class="it"><span>' . Layout::e($label) . '</span><span>' . Layout::e($value) . '</span></div>';
    }

    /**
     * @param array<string, mixed>|null $sub
     */
    private function cardLabel(?array $sub): string
    {
        if ($sub === null || (string) ($sub['renew_last4'] ?? '') === '') {
            return '';
        }
        $brand = (string) ($sub['renew_brand'] ?? '');

        return trim($brand . ' final ' . (string) $sub['renew_last4']);
    }

    private function ipScript(): string
    {
        return '<script src="' . Layout::e(AppMaxGateway::scriptUrl()) . '"></script><script>'
            . '(function(){function saveIp(ip){if(!ip)return;var body=new URLSearchParams();var csrf=document.querySelector("input[name=csrf]");body.set("csrf",csrf?csrf.value:"");body.set("action","ip");body.set("client_ip",ip);fetch(window.location.pathname,{method:"POST",body:body,credentials:"same-origin"});}'
            . 'if(window.AppmaxScripts){window.AppmaxScripts.init({onIp:function(data){saveIp(data&&data.ip);}});}})();'
            . '</script>';
    }


    /**
     * @param array<string, mixed>|null $sub
     */
    private function exemptionLabel(?array $sub): string
    {
        if ($sub === null) {
            return '';
        }
        if ((int) ($sub['comp_forever'] ?? 0) === 1) {
            return 'Para sempre';
        }
        $until = (string) ($sub['comp_until'] ?? '');
        if ($until !== '' && $until >= date('Y-m-d H:i:s')) {
            return 'Até ' . Layout::when($until);
        }

        return '';
    }

    private function when(string $value): string
    {
        return Layout::when($value);
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'pix' => 'Pix',
            'cartao' => 'Cartão',
            'cupom' => 'Cupom',
            default => $method,
        };
    }

    private function payLabel(string $status): string
    {
        return match ($status) {
            'pago' => 'pago',
            'pendente' => 'aguardando',
            'recusado' => 'recusado',
            'estornado' => 'estornado',
            default => $status,
        };
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function htmlProfile(array $bundle): string
    {
        $notice = (string) ($_SESSION['account_notice'] ?? '');
        $error = (string) ($_SESSION['account_error'] ?? '');
        unset($_SESSION['account_notice'], $_SESSION['account_error']);
        $customer = $bundle['customer'];
        $profile = is_array($bundle['profile'] ?? null) ? $bundle['profile'] : [];
        $userId = (int) ($customer['user_id'] ?? 0);
        $html = '<section class="page"><div class="wrap">';
        $html .= '<h1>Perfil e preferências</h1>';
        $html .= '<p class="meta">' . Layout::e((string) $customer['name']) . ' · <a href="' . Layout::e(Layout::url('minha-conta/sair')) . '">Sair</a></p>';
        if ($notice !== '') {
            $html .= '<p class="notice">' . Layout::e($notice) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="notice">' . Layout::e($error) . '</p>';
        }
        if ($userId < 1) {
            $html .= '<p class="meta">Ative o código no Telegram para editar o perfil.</p></div></section>';

            return $html;
        }
        $accounts = is_array($bundle['instagram_accounts'] ?? null) ? $bundle['instagram_accounts'] : [];
        if ($accounts !== []) {
            $html .= '<div class="box" style="margin-top:20px"><h2>Conta Instagram ativa</h2><p class="meta">Ideias, legendas e publicação usam o @ selecionado.</p>';
            $options = '';
            $active = (int) ($bundle['active_account_id'] ?? 0);
            foreach ($accounts as $row) {
                $id = (int) ($row['id'] ?? 0);
                $username = ltrim((string) ($row['username'] ?? ''), '@');
                $sel = $id === $active ? ' selected' : '';
                $options .= '<option value="' . $id . '"' . $sel . '>@' . Layout::e($username) . '</option>';
            }
            $html .= $this->form('conta_ig', '<select class="in" name="account_id">' . $options . '</select><button class="btn btn-primary" type="submit">Usar esta conta</button>');
            $ig = $bundle['instagram'];
            if ((string) ($ig['url'] ?? '') !== '') {
                $html .= '<p style="margin-top:12px"><a class="btn btn-ghost" href="' . Layout::e((string) $ig['url']) . '">Conectar outra conta</a></p>';
            }
            $html .= '</div>';
        }
        $val = static fn (string $key): string => Layout::e((string) ($profile[$key] ?? ''));
        $html .= '<div class="box" style="margin-top:20px"><h2>Perfil da legenda</h2>';
        $html .= $this->form(
            'perfil',
            '<div class="field"><label for="display_name">Nome</label><input class="in" id="display_name" name="display_name" value="' . $val('display_name') . '" required></div>'
            . '<div class="field"><label for="profession">O que você faz</label><input class="in" id="profession" name="profession" value="' . $val('profession') . '" required></div>'
            . '<div class="field"><label for="city">Cidade</label><input class="in" id="city" name="city" value="' . $val('city') . '" required></div>'
            . '<div class="field"><label for="tone">Tom</label><select class="in" id="tone" name="tone">'
            . $this->toneOption('profissional', (string) ($profile['tone'] ?? ''))
            . $this->toneOption('descontraido', (string) ($profile['tone'] ?? ''))
            . $this->toneOption('tecnico', (string) ($profile['tone'] ?? ''))
            . $this->toneOption('acolhedor', (string) ($profile['tone'] ?? ''))
            . '</select></div>'
            . '<div class="field"><label for="contact_cta">Contato na legenda</label><input class="in" id="contact_cta" name="contact_cta" value="' . $val('contact_cta') . '"></div>'
            . '<div class="field"><label for="about">Sobre</label><textarea class="in" id="about" name="about" rows="4">' . $val('about') . '</textarea></div>'
            . '<div class="field"><label for="brand_style">Marca visual</label><input class="in" id="brand_style" name="brand_style" value="' . $val('brand_style') . '"></div>'
            . '<div class="field"><label for="fixed_hashtags">Hashtags fixas</label><input class="in" id="fixed_hashtags" name="fixed_hashtags" value="' . $val('fixed_hashtags') . '"></div>'
            . '<button class="btn btn-primary" type="submit">Salvar perfil</button>',
        );
        $html .= '</div>';
        $html .= $this->htmlPromptExtras($bundle);
        $logoUrl = (string) ($bundle['logo_url'] ?? '');
        $html .= '<div class="box" style="margin-top:20px"><h2>Logo</h2>';
        $html .= '<div class="portal-logo-row">';
        if ($logoUrl !== '') {
            $html .= '<img class="portal-logo" src="' . Layout::e($logoUrl) . '" alt="Logo atual">';
        }
        $html .= '<div class="portal-logo-upload">';
        $html .= '<p class="meta">PNG ou JPEG. Usada na marca d\'água e no texto na foto.</p>';
        $html .= '<form method="post" enctype="multipart/form-data" class="portal-upload-form">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="action" value="logo">'
            . $this->fileUploadField('logo-file', 'logo', 'image/*', true)
            . '<div class="portal-form-actions"><button class="btn btn-primary" type="submit">Enviar logo</button></div>'
            . '</form></div></div></div>';
        $ideaOn = !empty($bundle['idea_daily']);
        $html .= '<div class="box" style="margin-top:20px"><h2>Preferências de postagem</h2>';
        $html .= $this->form(
            'prefs',
            '<div class="portal-prefs-grid">'
            . '<div class="field"><label for="phrase_style">Estilo do texto na foto</label><select class="in" id="phrase_style" name="phrase_style">'
            . $this->selectOption('classica', 'Clássica', (string) ($profile['phrase_style'] ?? ''))
            . $this->selectOption('cursiva', 'Cursiva', (string) ($profile['phrase_style'] ?? ''))
            . $this->selectOption('limpa', 'Limpa', (string) ($profile['phrase_style'] ?? ''))
            . $this->selectOption('forte', 'Forte', (string) ($profile['phrase_style'] ?? ''))
            . $this->selectOption('balao', 'Balão', (string) ($profile['phrase_style'] ?? ''))
            . $this->selectOption('caixa', 'Caixa', (string) ($profile['phrase_style'] ?? ''))
            . '</select></div>'
            . '<div class="field"><label for="phrase_color">Cor do texto</label><select class="in" id="phrase_color" name="phrase_color">'
            . $this->selectOption('branco', 'Branco', (string) ($profile['phrase_color'] ?? ''))
            . $this->selectOption('preto', 'Preto', (string) ($profile['phrase_color'] ?? ''))
            . '</select></div>'
            . '<div class="field"><label for="phrase_place">Posição</label><select class="in" id="phrase_place" name="phrase_place">'
            . $this->selectOption('topo', 'Topo', (string) ($profile['phrase_place'] ?? ''))
            . $this->selectOption('meio', 'Meio', (string) ($profile['phrase_place'] ?? ''))
            . $this->selectOption('rodape', 'Rodapé', (string) ($profile['phrase_place'] ?? ''))
            . '</select></div>'
            . '<div class="field"><label for="phrase_size">Tamanho</label><select class="in" id="phrase_size" name="phrase_size">'
            . $this->selectOption('menor', 'Menor', (string) ($profile['phrase_size'] ?? ''))
            . $this->selectOption('normal', 'Normal', (string) ($profile['phrase_size'] ?? ''))
            . $this->selectOption('maior', 'Maior', (string) ($profile['phrase_size'] ?? ''))
            . '</select></div>'
            . '</div>'
            . '<label class="portal-check"><input type="checkbox" name="idea_daily" value="1"' . ($ideaOn ? ' checked' : '') . '><span>Ideia do dia no Telegram (8h)</span></label>'
            . '<div class="portal-form-actions"><button class="btn btn-primary" type="submit">Salvar preferências</button></div>',
        );
        $html .= '</div></div></section>';
        $html .= $this->portalFileScript();

        return $html;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function htmlPromptExtras(array $bundle): string
    {
        $extras = is_array($bundle['prompt_extras'] ?? null) ? $bundle['prompt_extras'] : [];
        $html = '<div class="box" style="margin-top:20px"><h2>Extras para a IA</h2>';
        $html .= '<p class="meta">Quando o tema ou a ideia citar a palavra-gatilho, o texto (e a imagem, se houver) entram no prompt da legenda e da foto por IA. Ex.: adesig, sigsistem.</p>';
        foreach ($extras as $row) {
            $id = (int) ($row['id'] ?? 0);
            $trigger = (string) ($row['trigger_word'] ?? '');
            $text = (string) ($row['prompt_text'] ?? '');
            $hasImage = trim((string) ($row['image_path'] ?? '')) !== '';
            $html .= '<div class="portal-extra-card">';
            $html .= '<form method="post" enctype="multipart/form-data" class="portal-upload-form">';
            $html .= '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
            $html .= '<input type="hidden" name="action" value="extra_save">';
            $html .= '<input type="hidden" name="extra_id" value="' . $id . '">';
            $html .= '<div class="portal-extra-head">';
            if ($hasImage) {
                $html .= '<img class="portal-extra-thumb" src="' . Layout::e(Layout::url('minha-conta/extra/' . $id)) . '" alt="">';
            }
            $html .= '<div class="field"><label for="trigger-' . $id . '">Gatilho</label>';
            $html .= '<input class="in" id="trigger-' . $id . '" name="trigger_word" value="' . Layout::e($trigger) . '" required></div>';
            $html .= '</div>';
            $html .= '<div class="field"><label for="prompt-' . $id . '">Texto para a IA</label>';
            $html .= '<textarea class="in" id="prompt-' . $id . '" name="prompt_text" rows="4" required>' . Layout::e($text) . '</textarea></div>';
            $html .= $this->fileUploadField('extra-file-' . $id, 'extra_image', 'image/*');
            $html .= '<p class="hint">Deixe o arquivo vazio para manter a imagem atual.</p>';
            $html .= '<div class="portal-form-actions portal-form-actions--split">';
            $html .= '<button class="btn btn-primary" type="submit">Salvar extra</button>';
            $html .= '</div></form>';
            $html .= $this->form(
                'extra_delete',
                '<input type="hidden" name="extra_id" value="' . $id . '"><button class="btn btn-ghost" type="submit">Remover</button>',
                'Remover este extra?',
                'portal-inline-form',
            );
            $html .= '</div>';
        }
        if (count($extras) < \PerfilEmDia\Domain\PromptExtras::MAX_PER_ACCOUNT) {
            $html .= '<div class="portal-extra-card portal-extra-card--new">';
            $html .= '<h3 class="portal-panel-title">Novo extra</h3>';
            $html .= '<form method="post" enctype="multipart/form-data" class="portal-upload-form">';
            $html .= '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
            $html .= '<input type="hidden" name="action" value="extra_save">';
            $html .= '<div class="field"><label for="trigger-new">Gatilho</label>';
            $html .= '<input class="in" id="trigger-new" name="trigger_word" placeholder="adesig" required></div>';
            $html .= '<div class="field"><label for="prompt-new">Texto para a IA</label>';
            $html .= '<textarea class="in" id="prompt-new" name="prompt_text" rows="4" placeholder="Marca, tom, o que mostrar na arte..." required></textarea></div>';
            $html .= $this->fileUploadField('extra-file-new', 'extra_image', 'image/*');
            $html .= '<div class="portal-form-actions"><button class="btn btn-primary" type="submit">Adicionar extra</button></div>';
            $html .= '</form></div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function toneOption(string $value, string $current): string
    {
        $label = ucfirst($value);
        $sel = $current === $value ? ' selected' : '';

        return '<option value="' . Layout::e($value) . '"' . $sel . '>' . Layout::e($label) . '</option>';
    }

    private function selectOption(string $value, string $label, string $current): string
    {
        $sel = $current === $value || ($current === '' && $value === 'classica') ? ' selected' : '';

        return '<option value="' . Layout::e($value) . '"' . $sel . '>' . Layout::e($label) . '</option>';
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function htmlStudio(int $customerId, array $bundle): string
    {
        $notice = (string) ($_SESSION['account_notice'] ?? '');
        $error = (string) ($_SESSION['account_error'] ?? '');
        unset($_SESSION['account_notice'], $_SESSION['account_error']);
        $customer = $bundle['customer'];
        $state = $this->enrichStudioState($customerId);
        $expect = (string) ($state['expecting'] ?? 'text');
        $expectLabel = (string) ($state['expecting_label'] ?? '');
        $html = '<section class="page"><div class="wrap">';
        $html .= '<h1>Publicar</h1>';
        $html .= '<p class="meta">' . Layout::e((string) $customer['name']) . ' � mesmo fluxo do bot � <a href="' . Layout::e(Layout::url('minha-conta/sair')) . '">Sair</a></p>';
        if ((int) ($customer['user_id'] ?? 0) < 1) {
            $html .= '<p class="meta">Ative a conta no Telegram para publicar da web.</p></div></section>';

            return $html;
        }
        $html .= '<div class="portal-studio" id="portal-studio"'
            . ' data-poll="' . Layout::e(Layout::url('minha-conta/publicar/estado')) . '"'
            . ' data-action="' . Layout::e(Layout::url('minha-conta/publicar/acao')) . '"'
            . ' data-csrf="' . Layout::e(Layout::csrf()) . '"'
            . ' data-expecting="' . Layout::e($expect) . '">';
        $html .= '<p class="portal-studio-lead">Leia o assistente acima e responda em <strong>mensagem escrita</strong> ou <strong>foto/v�deo</strong>, conforme a dica abaixo. A publica��o final vai para o Instagram conectado.</p>';
        $html .= '<div id="portal-studio-alert" class="portal-studio-alert" hidden role="status"></div>';
        if ($notice !== '') {
            $html .= '<p class="notice portal-studio-notice">' . Layout::e($notice) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="notice portal-studio-notice portal-studio-notice--err">' . Layout::e($error) . '</p>';
        }
        $html .= '<div class="portal-chat" id="portal-chat" aria-live="polite" aria-relevant="additions">';
        foreach ($state['messages'] as $message) {
            $html .= $this->chatBubble($message);
        }
        $html .= '</div>';
        $html .= '<div class="portal-status" id="portal-studio-status" data-busy="' . (!empty($state['busy']) ? '1' : '0') . '">';
        $html .= '<span class="portal-status-dot" aria-hidden="true"></span>';
        $html .= '<p class="portal-status-text" id="portal-status-text">' . Layout::e($expectLabel) . '</p>';
        $html .= '</div>';
        $html .= '<div class="portal-compose-split">';
        $textActive = $expect !== 'media' ? ' portal-compose-card--active' : '';
        $mediaActive = $expect === 'media' ? ' portal-compose-card--active' : '';
        $html .= '<section class="portal-compose-card portal-compose-card--text' . $textActive . '" data-for="text">';
        $html .= '<h2 class="portal-compose-title">Mensagem escrita</h2>';
        $html .= '<p class="portal-compose-desc">Tema do post, ajustes pedidos pelo bot, legenda quando solicitada, ou comandos como <code>/novo</code> e <code>/cancelar</code>.</p>';
        $html .= '<form method="post" class="portal-msg-form" id="portal-form-text" data-studio-form="text">';
        $html .= '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
        $html .= '<input type="hidden" name="action" value="studio_msg">';
        $html .= '<label class="sr" for="studio-message">Mensagem</label>';
        $html .= '<textarea class="in portal-input" id="studio-message" name="message" rows="3" placeholder="Ex.: post sobre consultoria em Maring�, ou /novo"></textarea>';
        $html .= '<div class="portal-form-actions portal-form-actions--split">';
        $html .= '<button class="btn btn-primary" type="submit" data-studio-submit>Enviar mensagem</button>';
        $html .= '</div></form></section>';
        $html .= '<section class="portal-compose-card portal-compose-card--media' . $mediaActive . '" data-for="media">';
        $html .= '<h2 class="portal-compose-title">Foto ou v�deo</h2>';
        $html .= '<p class="portal-compose-desc">Use quando o assistente pedir m�dia. A legenda abaixo vira tema ou texto que acompanha o arquivo.</p>';
        $html .= '<form method="post" enctype="multipart/form-data" class="portal-upload-form" id="portal-form-media" data-studio-form="media">';
        $html .= '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
        $html .= '<input type="hidden" name="action" value="studio_upload">';
        $html .= $this->fileUploadField('studio-media', 'media', 'image/*,video/mp4');
        $html .= '<div class="field"><label for="studio-caption">Legenda (opcional)</label>';
        $html .= '<input class="in" id="studio-caption" name="caption" placeholder="Tema ou legenda que vai com a m�dia"></div>';
        $html .= '<div class="portal-form-actions"><button class="btn btn-primary" type="submit" data-studio-submit>Enviar foto ou v�deo</button>';
        $html .= '</div></form></section>';
        $html .= '</div>';
        $html .= '<div class="portal-compose-foot">';
        $html .= '<button class="btn btn-ghost" type="button" id="portal-studio-reset" data-confirm="Limpar toda a conversa e recome�ar?">Limpar conversa</button>';
        $html .= '<p class="hint">D�vida? No Telegram o fluxo � o mesmo; aqui voc� s� escolhe texto ou arquivo conforme a dica.</p>';
        $html .= '</div></div></div></section>';
        $html .= $this->portalFileScript();
        $html .= '<script src="' . Layout::e($this->versionedPublicAsset('assets/portal-studio.js')) . '" defer></script>';

        return $html;
    }

    private function versionedPublicAsset(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/public/' . ltrim($relative, '/');
        $version = is_file($path) ? (string) filemtime($path) : '1';

        return Layout::url($relative) . '?v=' . $version;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function chatBubble(array $message): string
    {
        $role = (string) ($message['role'] ?? 'bot');
        $class = $role === 'user' ? 'portal-msg portal-msg-user' : 'portal-msg portal-msg-bot';
        $inner = '';
        $type = (string) ($message['type'] ?? 'text');
        if ($type === 'photo' || $type === 'video') {
            $url = (string) ($message['url'] ?? '');
            if ($url !== '') {
                if ($type === 'video') {
                    $inner .= '<video controls src="' . Layout::e($url) . '"></video>';
                } else {
                    $inner .= '<img src="' . Layout::e($url) . '" alt="">';
                }
            }
            $cap = (string) ($message['caption'] ?? '');
            if ($cap !== '') {
                $inner .= '<p>' . nl2br(Layout::e($cap)) . '</p>';
            }
        } elseif ($type === 'album') {
            foreach ($message['urls'] ?? [] as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $inner .= '<img src="' . Layout::e($url) . '" alt="">';
            }
        } else {
            $inner .= '<p>' . nl2br(Layout::e((string) ($message['text'] ?? ''))) . '</p>';
        }
        $buttons = $message['buttons'] ?? [];
        if (is_array($buttons) && $buttons !== []) {
            $inner .= '<div class="portal-actions">';
            foreach ($buttons as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $button) {
                    if (!is_array($button)) {
                        continue;
                    }
                    $text = (string) ($button['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    if (!empty($button['url'])) {
                        $inner .= '<a class="btn btn-ghost btn-sm" href="' . Layout::e((string) $button['url']) . '">' . Layout::e($text) . '</a>';
                        continue;
                    }
                    $data = (string) ($button['data'] ?? '');
                    if ($data === '') {
                        continue;
                    }
                    $inner .= '<button class="btn btn-ghost btn-sm" type="button" data-studio-callback="' . Layout::e($data) . '">' . Layout::e($text) . '</button>';
                }
            }
            $inner .= '</div>';
        }

        return '<article class="' . $class . '">' . $inner . '</article>';
    }

}
