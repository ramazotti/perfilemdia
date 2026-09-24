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
    private ?AccountOrchestrator $accounts = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    private function accounts(): AccountOrchestrator
    {
        if ($this->accounts instanceof AccountOrchestrator) {
            return $this->accounts;
        }
        $users = new UserRepository($this->pdo, Crypto::fromConfig());
        $this->accounts = new AccountOrchestrator(
            $this->pdo,
            BillingFactory::service($this->pdo),
            new CustomerAccess($this->pdo),
            new TicketService($this->pdo, $users),
            $users,
        );

        return $this->accounts;
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
                    '<section class="center-page"><div><h1>Esse link venceu</h1><p>No Telegram, envie /assinatura para receber um link novo. Ele vale 12 horas.</p></div></section>',
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
        if ($path !== '/minha-conta') {
            return false;
        }
        $customerId = (int) ($_SESSION['account_customer_id'] ?? 0);
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
            $this->post($customerId);
            header('Location: ' . Layout::url('minha-conta'));
            exit;
        }
        $view = $this->accounts()->view($customerId);
        if ($view === null) {
            unset($_SESSION['account_customer_id']);
            Layout::page(
                'Minha conta',
                '<section class="center-page"><div><h1>Conta não encontrada</h1><p>Envie /assinatura no bot para gerar um link novo.</p></div></section>',
                '',
                404,
            );

            return true;
        }
        Layout::page('Minha conta', $this->html($view), '');

        return true;
    }

    private function post(int $customerId): void
    {
        if (!Layout::checkCsrf()) {
            $this->flash('A página expirou. Abra de novo e tente outra vez.', true);

            return;
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ip') {
            $ip = trim((string) ($_POST['client_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $_SESSION['appmax_ip'] = $ip;
            }

            return;
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
        $html = '<div class="box" style="margin-top:20px"><h2>Plano e ciclo</h2>';
        $html .= '<p class="meta">A troca de preço vale na próxima cobrança. Um plano com mais posts libera o limite na hora. Um plano com menos posts muda o limite só no próximo período. Estúdio inclui o post criado a partir de uma ideia. Vídeo curto, texto na foto, tratamento da foto e a marca d\'água ficam no Profissional e no Estúdio. Agendar vale em todos os planos.</p>';
        $options = '';
        foreach ($view['plans'] as $plan) {
            $selected = (int) $plan['id'] === (int) $sub['plan_id'] ? ' selected' : '';
            $options .= '<option value="' . (int) $plan['id'] . '"' . $selected . '>' . Layout::e((string) $plan['name']) . ' ' . Layout::e(Layout::money((int) $plan['price_cents'])) . '/mês</option>';
        }
        $html .= $this->form('plano', '<select class="in" name="plan_id">' . $options . '</select><button class="btn btn-primary" type="submit">Usar este plano</button>');
        if (!empty($sub['next_plan_id'])) {
            $html .= '<p class="hint">Há um plano diferente marcado para a próxima cobrança.</p>';
        }
        $monthly = (string) $sub['cycle'] === 'mensal' ? 'btn-primary' : 'btn-ghost';
        $yearly = (string) $sub['cycle'] === 'anual' ? 'btn-primary' : 'btn-ghost';
        $html .= '<div class="account-actions">';
        $html .= $this->form('ciclo', '<input type="hidden" name="cycle" value="mensal"><button class="btn ' . $monthly . '" type="submit">Mensal</button>');
        $html .= $this->form('ciclo', '<input type="hidden" name="cycle" value="anual"><button class="btn ' . $yearly . '" type="submit">Anual, 10 meses</button>');
        $html .= '</div>';
        if (!empty($sub['next_cycle'])) {
            $html .= '<p class="hint">Na próxima cobrança o ciclo passa para ' . Layout::e((string) $sub['next_cycle']) . '.</p>';
        }
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
        if ((string) $ig['username'] !== '' && (string) $ig['status'] === 'active') {
            $html .= '<p>Conectado como @' . Layout::e(ltrim((string) $ig['username'], '@')) . '.</p>';
        } else {
            $html .= '<p>Nenhuma conta profissional conectada.</p>';
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
        $html .= '<p class="hint">O contato da legenda se edita no bot, com /perfil. Aqui ficam o e-mail e o celular da assinatura. Documento: ' . Layout::e((string) $customer['document']) . '. Ele não muda por aqui.</p>';
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

    private function form(string $action, string $inner, string $confirm = ''): string
    {
        $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES, 'UTF-8') . ')"' : '';

        return '<form method="post"' . $onsubmit . '>'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="action" value="' . Layout::e($action) . '">'
            . $inner
            . '</form>';
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
}
