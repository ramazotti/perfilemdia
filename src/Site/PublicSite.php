<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Billing\BrazilianDocument;
use PerfilEmDia\Billing\Card;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\CouponRejected;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\Phone;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\SandboxGateway;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Billing\Trial;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PDO;
use Throwable;

final class PublicSite
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function dispatch(string $path): bool
    {
        if ($path === '/') {
            $this->home();
            return true;
        }
        if ($path === '/planos') {
            $this->plans();
            return true;
        }
        if ($path === '/ajuda') {
            $this->help();
            return true;
        }
        if ($path === '/manual' || $path === '/conta') {
            $this->clientArea();
            return true;
        }
        if ($path === '/privacidade') {
            $this->privacy();
            return true;
        }
        if ($path === '/termos') {
            $this->terms();
            return true;
        }
        if ($path === '/exclusao-de-dados') {
            $this->deletion();
            return true;
        }
        if ($path === '/contratar') {
            $this->checkout();
            return true;
        }
        if ($path === '/contratar/pagamento') {
            $this->payment();
            return true;
        }
        if (preg_match('#^/contratar/status/([a-f0-9]{32})$#', $path, $m)) {
            $this->status($m[1]);
            return true;
        }
        if (preg_match('#^/pronto/([a-f0-9]{32})$#', $path, $m)) {
            $this->done($m[1]);
            return true;
        }
        if ($path === '/conectado') {
            $this->connected();
            return true;
        }
        if ($path === '/erro-conexao') {
            $this->connectionError();
            return true;
        }
        if ($path === '/webhooks/pagamento') {
            $this->webhook();
            return true;
        }

        return false;
    }

    private function home(): void
    {
        $plans = $this->planCards('mensal', true);
        $faq = $this->faq([
            ['Minha conta do Instagram precisa ser profissional?', 'Sim. É grátis e leva um minuto: Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, Empresa. O bot mostra o passo a passo.'],
            ['Preciso de CNPJ ou de Página do Facebook?', 'Não. Autônomos com CPF usam normalmente, e a conexão é feita direto com o Instagram.'],
            ['Vocês publicam sem eu aprovar?', 'Nunca. Cada post chega como prévia no Telegram e só vai para o Instagram quando você toca em Publicar.'],
            ['E se eu não gostar da legenda?', 'Toque em Ajustar e diga o que mudar, peça outra versão ou escreva o texto do seu jeito.'],
            ['Posso mandar mais de uma foto?', 'Pode. Mande um álbum com até 10 fotos e ele vira um carrossel.'],
            ['Por que Telegram e não WhatsApp?', 'Porque o Telegram deixa o Perfil em Dia separado das suas conversas de clientes e família, e funciona do mesmo jeito que o WhatsApp.'],
        ]);
        $html = '<section class="hero"><div class="wrap"><div>'
            . '<h1>Seu Instagram em dia com a foto do seu trabalho.</h1>'
            . '<p class="lead">Você manda a foto e um tema curto no Telegram. O Perfil em Dia escreve a legenda, mostra a prévia e publica no Instagram quando você aprova.</p>'
            . '<div class="ctas"><a class="btn btn-primary" href="' . Layout::e(Layout::url('planos')) . '">Ver planos</a>'
            . '<a class="btn btn-ghost" href="' . Layout::e(Layout::url('/#como')) . '">Como funciona</a></div>'
            . '<p class="fine">Para autônomos. Sem agência e sem planilha de posts. <a href="' . Layout::e(Layout::url('manual')) . '">Veja o passo a passo</a>.</p>'
            . '</div>' . $this->phone() . '</div></section>';
        $html .= '<section class="sec" id="como"><div class="wrap"><h2>Três passos, no meio do expediente.</h2>'
            . '<div class="steps">'
            . '<article class="step"><div class="n">1</div><h3>Manda a foto</h3><p>Uma foto real do serviço, ou um álbum de até 10. Nada de banco de imagem.</p></article>'
            . '<article class="step"><div class="n">2</div><h3>Diz o tema</h3><p>Uma frase chega: o que foi feito, para quem, em qual bairro.</p></article>'
            . '<article class="step"><div class="n">3</div><h3>Aprova e publica</h3><p>A legenda chega como prévia. Você publica, pede ajuste ou escreve do seu jeito.</p></article>'
            . '</div></div></section>';
        $html .= '<section class="sec sec-paper"><div class="wrap"><h2>Feito para quem trabalha com as mãos.</h2>'
            . '<p class="quote">Eletricista, personal, marceneiro, nutricionista, cabeleireira. <span class="q2">Quem vive de indicação e quer que o Instagram mostre o serviço de verdade.</span></p>'
            . '<div class="profs"><span>Eletricista</span><span>Personal</span><span>Marceneiro</span><span>Nutricionista</span><span>Cabeleireira</span><span>Pintor</span><span>Manicure</span><span>Fotógrafa</span></div>'
            . '<div class="why">'
            . '<div><h3>A foto é do seu trabalho</h3><p>Nada de imagem genérica. Quem te contrata quer ver o serviço que você entrega.</p></div>'
            . '<div><h3>Nada sai sem você ver</h3><p>Todo post chega como prévia. Você publica, ajusta, escreve do seu jeito ou cancela.</p></div>'
            . '<div><h3>É uma conversa, não um app</h3><p>Funciona no Telegram, que é igual ao WhatsApp de usar. Nada novo para aprender.</p></div>'
            . '<div><h3>Não precisa de CNPJ</h3><p>Basta ter conta profissional no Instagram, que é grátis. O bot mostra como mudar em um minuto.</p></div>'
            . '</div></div></section>';
        $html .= '<section class="sec"><div class="wrap"><h2>Planos simples, sem fidelidade</h2><p class="intro">Cancele quando quiser, direto pelo bot.</p>' . $plans . '</div></section>';
        $html .= '<section class="sec sec-paper"><div class="wrap" style="max-width:860px"><h2>Perguntas frequentes</h2><div class="faq">' . $faq . '</div></div></section>';
        $html .= '<section class="band"><div class="wrap"><h2>Seu próximo trabalho pode ser seu próximo post.</h2><p>Assine, abra o bot no Telegram e mande a primeira foto hoje.</p><a class="btn" href="' . Layout::e(Layout::url('planos')) . '">Ver planos</a></div></section>';
        Layout::page('Perfil em Dia', $html);
    }

    private function plans(): void
    {
        $cycle = ($_GET['ciclo'] ?? 'mensal') === 'anual' ? 'anual' : 'mensal';
        $mensal = $cycle === 'mensal';
        $html = '<section class="page"><div class="wrap" style="max-width:980px"><h1>Planos</h1>'
            . '<p class="meta">No mensal, os 3 primeiros dias são um teste, com o limite de posts na proporção do mês. Depois o plano renova sozinho, mês a mês, até o cancelamento. O anual cobra o equivalente a 10 meses e também renova sozinho.</p>'
            . '<div class="cycle">'
            . '<a href="' . Layout::e(Layout::url('planos?ciclo=mensal')) . '" aria-pressed="' . ($mensal ? 'true' : 'false') . '">Mensal</a>'
            . '<a href="' . Layout::e(Layout::url('planos?ciclo=anual')) . '" aria-pressed="' . ($mensal ? 'false' : 'true') . '">Anual</a>'
            . '</div>'
            . $this->planCards($cycle, true)
            . '</div></section>';
        Layout::page('Planos', $html, 'planos');
    }

    private function help(): void
    {
        $email = Settings::get('support_email', 'ajuda@perfilemdia.com.br');
        $faq = $this->faq([
            ['Como mudo meu Instagram para conta profissional?', 'No Instagram, toque na sua foto de perfil, depois no menu de três linhas, Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, e escolha Empresa. É grátis e não pede CNPJ.'],
            ['O bot diz que minha conexão expirou. O que faço?', 'Por segurança, a conexão com o Instagram precisa ser renovada de tempos em tempos. Toque em Reconectar Instagram no bot ou envie /conectar.'],
            ['A foto ficou cortada. Por quê?', 'O Instagram só aceita fotos entre o formato retrato 4:5 e o paisagem 1,91:1. Fotos mais altas, como as de celular em pé, são ajustadas com um corte central.'],
            ['Posso usar em mais de um Instagram?', 'Por enquanto, cada assinatura conecta uma conta do Instagram.'],
            ['Como cancelo minha assinatura?', 'Envie /assinatura no bot e toque em Cancelar assinatura, ou fale com o suporte. Você usa até o fim do período pago.'],
            ['Como apago meus dados?', 'Envie /excluirconta no bot ou use a página de exclusão de dados.'],
        ]);
        $html = '<section class="page"><div class="wrap"><h1>Ajuda</h1><p class="meta"><a href="' . Layout::e(Layout::url('manual')) . '">Abra o passo a passo da área do cliente</a> se você já assinou ou está configurando o Instagram.</p><p class="meta">Respostas rápidas para as dúvidas mais comuns.</p><div class="faq">' . $faq . '</div>'
            . '<div class="box" style="margin-top:40px"><h2 style="font-size:22px;margin-bottom:8px">Não achou sua resposta?</h2>'
            . '<p style="color:var(--ink2)">Fale com a gente pelo bot (comando /ajuda) ou pelo e-mail <a href="mailto:' . Layout::e($email) . '">' . Layout::e($email) . '</a>.</p></div>'
            . '</div></section>';
        Layout::page('Ajuda', $html, 'ajuda');
    }

    private function privacy(): void
    {
        Layout::page('Privacidade', $this->legal(
            'Política de privacidade',
            '<h2>Quem trata os dados</h2><p>O responsável pelo tratamento é [RAZÃO SOCIAL], CNPJ [CNPJ]. O encarregado é [ENCARREGADO], pelo e-mail ' . Layout::e(Settings::get('support_email', 'ajuda@perfilemdia.com.br')) . '.</p>'
            . '<h2>O que coletamos</h2><ul><li>Nome, e-mail, celular e CPF ou CNPJ, para a contratação.</li><li>Identificador do Telegram e as fotos que você envia para publicar.</li><li>Token de acesso do Instagram, guardado criptografado, para publicar em seu nome.</li><li>Registros de pagamento: valor, status, bandeira e os 4 últimos dígitos. O número completo do cartão não fica aqui.</li></ul>'
            . '<h2>Para que usamos</h2><p>Para prestar o serviço, cobrar a assinatura, publicar no Instagram depois da sua aprovação e cumprir a lei.</p>'
            . '<h2>Por quanto tempo</h2><p>As fotos públicas saem do ar em algumas horas. Os dados da conta ficam enquanto a assinatura existir. Pagamentos são guardados pelo prazo fiscal, mesmo depois da exclusão da conta.</p>'
            . '<h2>Seus direitos</h2><p>Você pode pedir acesso, correção ou exclusão na página de exclusão de dados, ou com o comando /excluirconta no bot.</p>'
        ));
    }

    private function terms(): void
    {
        Layout::page('Termos de uso', $this->legal(
            'Termos de uso',
            '<h2>O serviço</h2><p>[RAZÃO SOCIAL], CNPJ [CNPJ], oferece um bot no Telegram que escreve a legenda de uma foto real do seu trabalho e publica no Instagram depois que você aprova.</p>'
            . '<h2>Sua conta</h2><p>Você precisa de uma conta profissional do Instagram e autorizar a publicação. A conexão expira e pode ser renovada pelo bot.</p>'
            . '<h2>Assinatura</h2><p>Os planos e os limites de posts estão na página de planos. O plano anual cobra o valor de 10 meses. Não há fidelidade: o cancelamento vale até o fim do período já pago.</p>'
            . '<h2>O que não fazemos</h2><p>Não publicamos sem a sua aprovação e não inventamos fatos que não estejam na foto ou no tema que você escreveu.</p>'
            . '<h2>Foro</h2><p>Fica eleito o foro de [FORO].</p>'
        ));
    }

    private function deletion(): void
    {
        $protocol = isset($_GET['protocolo']) ? (string) $_GET['protocolo'] : '';
        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Layout::checkCsrf()) {
                $errors['form'] = 'Recarregue a página e tente de novo.';
            } else {
                $email = trim((string) ($_POST['email'] ?? ''));
                $document = BrazilianDocument::canonical((string) ($_POST['document'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Informe um e-mail válido.';
                }
                if ($errors === []) {
                    $protocol = $this->newProtocol();
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO deletion_requests (protocol, email, document, status, created_at) VALUES (?, ?, ?, ?, NOW())'
                    );
                    $stmt->execute([$protocol, $email, $document !== '' ? $document : null, 'aberto']);
                    header('Location: ' . Layout::url('exclusao-de-dados?protocolo=' . rawurlencode($protocol)));
                    exit;
                }
            }
        }
        $body = '<h2>Pedir exclusão</h2><p>Use este formulário, o comando /excluirconta no bot, ou o retorno da Meta quando você remove o app no Instagram. O protocolo serve para acompanhar o pedido.</p>';
        if ($protocol !== '' && preg_match('/^EX[A-Z0-9]{8}$/', $protocol)) {
            $body .= '<p class="notice">Protocolo ' . Layout::e($protocol) . '. Guarde este código.</p>';
        }
        $body .= '<form method="post" class="box" style="margin-top:24px">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . $this->field('email', 'E-mail', 'email', (string) ($_POST['email'] ?? ''), $errors['email'] ?? '')
            . $this->field('document', 'CPF ou CNPJ', 'text', (string) ($_POST['document'] ?? ''), $errors['document'] ?? '', 'Opcional. Ajuda a localizar a conta.')
            . '<button class="btn btn-primary" type="submit">Gerar protocolo</button></form>';
        Layout::page('Exclusão de dados', $this->legal('Exclusão de dados', $body));
    }

    private function checkout(): void
    {
        $plans = new PlanRepository($this->pdo);
        $slug = (string) ($_GET['plano'] ?? $_POST['plano'] ?? 'profissional');
        $cycle = (($_GET['ciclo'] ?? $_POST['ciclo'] ?? 'mensal') === 'anual') ? 'anual' : 'mensal';
        $plan = $plans->findBySlug($slug);
        if ($plan === null || !(int) $plan['active']) {
            $plan = $plans->active()[0] ?? null;
        }
        if ($plan === null) {
            Layout::page('Contratar', '<section class="page"><div class="wrap"><h1>Nenhum plano disponível</h1></div></section>', '', 404);
            return;
        }
        $errors = [];
        $values = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'document' => trim((string) ($_POST['document'] ?? '')),
            'coupon' => strtoupper(trim((string) ($_POST['coupon'] ?? ''))),
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Layout::checkCsrf()) {
                $errors['form'] = 'Recarregue a página e tente de novo.';
            }
            if (mb_strlen($values['name']) < 3) {
                $errors['name'] = 'Informe seu nome.';
            }
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Informe um e-mail válido.';
            }
            $phone = Phone::normalize($values['phone']);
            if ($phone === null) {
                $errors['phone'] = 'Informe um celular com DDD. Exemplo: (44) 99999-9999.';
            }
            if (BrazilianDocument::type($values['document']) === null) {
                $errors['document'] = 'CPF ou CNPJ inválido.';
            }
            if ($errors === []) {
                try {
                    $checkout = $this->billing()->open($plan, $cycle, [
                        'name' => $values['name'],
                        'email' => $values['email'],
                        'phone' => $phone,
                        'document' => $values['document'],
                    ], $values['coupon']);
                    $_SESSION['checkout_id'] = $checkout['public_id'];
                    $next = (int) $checkout['amount_cents'] === 0
                        ? 'pronto/' . $checkout['public_id']
                        : 'contratar/pagamento';
                    header('Location: ' . Layout::url($next));
                    exit;
                } catch (CouponRejected $e) {
                    $errors['coupon'] = $e->getMessage();
                } catch (Throwable $e) {
                    $errors['form'] = 'Não foi possível abrir o checkout. Tente de novo.';
                }
            }
        }
        $intro = Trial::applies($plan, $cycle);
        $price = $cycle === 'anual' ? (int) $plan['price_cents'] * 10 : ($intro ? (int) $plan['trial_price_cents'] : (int) $plan['price_cents']);
        $after = null;
        if ($intro) {
            $trialPosts = Trial::posts((int) $plan['posts_limit'], (int) $plan['trial_days']);
            $after = (int) $plan['trial_days'] . ' dias de teste, com até ' . $trialPosts . ' posts. Depois, ' . Layout::money((int) $plan['price_cents']) . ' por mês, renovando sozinho até você cancelar.';
        }
        $html = '<section class="ck"><div class="wrap"><div>' . $this->steps(1) . '<div class="box"><h2>Seus dados</h2>';
        if (isset($errors['form'])) {
            $html .= '<p class="notice">' . Layout::e($errors['form']) . '</p>';
        }
        $html .= '<form method="post">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="plano" value="' . Layout::e((string) $plan['slug']) . '">'
            . '<input type="hidden" name="ciclo" value="' . Layout::e($cycle) . '">'
            . $this->field('name', 'Nome', 'text', $values['name'], $errors['name'] ?? '')
            . $this->field('email', 'E-mail', 'email', $values['email'], $errors['email'] ?? '')
            . $this->field('phone', 'Celular', 'tel', $values['phone'], $errors['phone'] ?? '', 'O número que você usa no WhatsApp ou no Telegram.', 'phone')
            . $this->field('document', 'CPF ou CNPJ', 'text', $values['document'], $errors['document'] ?? '', 'Pode ser o CNPJ alfanumérico.', 'document')
            . $this->field('coupon', 'Cupom', 'text', $values['coupon'], $errors['coupon'] ?? '', 'Se tiver um código, ele vale na primeira mensalidade.')
            . '<button class="btn btn-primary" type="submit">Continuar para o pagamento</button></form></div></div>'
            . $this->summary($plan, $cycle, $price, $after) . '</div></section>';
        Layout::page('Contratar', $html);
    }

    private function payment(): void
    {
        $publicId = (string) ($_SESSION['checkout_id'] ?? '');
        $checkout = $publicId !== '' ? $this->billing()->findByPublicId($publicId) : null;
        if ($checkout === null) {
            header('Location: ' . Layout::url('planos'));
            exit;
        }
        if ($checkout['status'] === 'pago') {
            header('Location: ' . Layout::url('pronto/' . $publicId));
            exit;
        }
        $error = '';
        $method = (string) ($_POST['method'] ?? 'pix');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf() && $method === 'cartao') {
            $digits = Card::digits((string) ($_POST['number'] ?? ''));
            $last4 = strlen($digits) >= 4 ? substr($digits, -4) : '';
            $brand = Card::brand($digits);
            $ok = Card::luhn($digits);
            unset($_POST['number'], $digits);
            if (!$ok) {
                $error = 'Número de cartão inválido.';
            } else {
                try {
                    $this->billing()->chargeCard($publicId, 'sandbox:' . $last4, $brand, $last4);
                    header('Location: ' . Layout::url('pronto/' . $publicId));
                    exit;
                } catch (PaymentRefused) {
                    $error = 'O banco recusou este cartão.';
                } catch (Throwable) {
                    $error = 'Não foi possível cobrar agora.';
                }
            }
        }
        $pix = null;
        if ((int) $checkout['amount_cents'] > 0 && $checkout['status'] === 'aberto') {
            try {
                $pix = $this->billing()->startPix($publicId);
            } catch (Throwable) {
                $error = $error !== '' ? $error : 'Não foi possível gerar o Pix.';
            }
        }
        $sandbox = SandboxGateway::enabled();
        $html = '<section class="ck"><div class="wrap"><div>' . $this->steps(2) . '<div class="box"><h2>Pagamento</h2>';
        if ($error !== '') {
            $html .= '<p class="notice">' . Layout::e($error) . '</p>';
        }
        if (is_array($pix)) {
            $html .= '<div class="pix"><div class="qr" aria-hidden="true"><svg viewBox="0 0 100 100"><rect width="100" height="100" fill="#fff"/><path d="M8 8h28v28H8zM64 8h28v28H64zM8 64h28v28H8z" fill="#1B1B18"/></svg></div><div>'
                . '<p>Pix copia e cola. A confirmação chega pelo banco. O botão abaixo só consulta o status.</p>'
                . '<div class="copy" style="margin-top:12px"><input class="in" id="pix-code" readonly value="' . Layout::e((string) $pix['pix_payload']) . '">'
                . '<button class="btn btn-ghost" type="button" data-act="copy" data-target="pix-code">Copiar</button></div>'
                . '<p class="timer" style="margin-top:12px" data-expires="' . Layout::e((string) $pix['pix_expires_at']) . '">Aguardando</p>'
                . '<button class="btn btn-primary" type="button" style="margin-top:16px" data-act="poll" data-status="' . Layout::e(Layout::url('contratar/status/' . $publicId)) . '" data-next="' . Layout::e(Layout::url('pronto/' . $publicId)) . '">Verificar pagamento</button>';
            if ($sandbox) {
                $html .= '<form method="post" action="' . Layout::e(Layout::url('webhooks/pagamento')) . '" style="margin-top:12px">'
                    . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
                    . '<input type="hidden" name="sandbox" value="1">'
                    . '<input type="hidden" name="external_id" value="' . Layout::e((string) $pix['external_id']) . '">'
                    . '<button class="btn btn-ghost" type="submit">Confirmar no sandbox</button></form>';
            }
            $html .= '</div></div>';
        }
        $html .= '<h3 style="margin-top:28px;font-size:20px">Cartão</h3><form method="post">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="method" value="cartao">'
            . $this->field('number', 'Número', 'text', '', '', 'Não guardamos o número. No sandbox, um final 0002 simula recusa.', 'card')
            . '<div class="grid2">' . $this->field('expiry', 'Validade', 'text', '', '', '', 'expiry')
            . $this->field('cvv', 'CVV', 'text', '', '') . '</div>'
            . '<p id="brand" class="hint"></p>'
            . '<button class="btn btn-primary" type="submit">Pagar com cartão</button></form></div></div>'
            . $this->summaryPaid($checkout) . '</div></section>';
        Layout::page('Pagamento', $html);
    }

    private function status(string $publicId): void
    {
        $checkout = $this->billing()->findByPublicId($publicId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $checkout['status'] ?? 'inexistente',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function done(string $publicId): void
    {
        $checkout = $this->billing()->findByPublicId($publicId);
        if ($checkout === null || $checkout['status'] !== 'pago' || empty($checkout['activation_code'])) {
            Layout::page('Pagamento em aberto', '<section class="center-page"><div><h1>Pagamento ainda não confirmado</h1><p>Quando o banco confirmar, esta página mostra o código de ativação.</p><a class="btn btn-primary" href="' . Layout::e(Layout::url('contratar/pagamento')) . '">Voltar ao pagamento</a></div></section>', '', 402);
            return;
        }
        $code = (string) $checkout['activation_code'];
        $bot = 'https://t.me/' . rawurlencode(Config::get('TELEGRAM_BOT_USERNAME', 'PerfilEmDiaBot')) . '?start=' . rawurlencode($code);
        $html = '<section class="center-page"><div><div class="ic" style="background:var(--accent-soft);color:var(--accent-text)">' . Layout::check() . '</div>'
            . '<h1>Pronto. Ative no Telegram.</h1>'
            . '<p>Toque no botão para ligar este pagamento à sua conversa. O código também pode ser digitado no bot.</p>'
            . '<div class="code-big">' . Layout::e($code) . '</div>'
            . '<ol class="next">'
            . '<li>Toque em Abrir o bot. O código já vai na conversa.</li>'
            . '<li>Responda as perguntas do perfil.</li>'
            . '<li>Mude o Instagram para conta profissional e conecte.</li>'
            . '<li>Mande a foto de um trabalho com uma frase.</li>'
            . '</ol>'
            . '<p><a href="' . Layout::e(Layout::url('manual')) . '">Ver o passo a passo completo</a></p>'
            . '<p><a class="btn btn-primary" href="' . Layout::e($bot) . '">Abrir o bot</a></p></div></section>';
        Layout::page('Assinatura pronta', $html);
    }

    private function connected(): void
    {
        $account = ltrim((string) ($_GET['conta'] ?? ''), '@');
        $who = $account !== '' ? '@' . $account : 'sua conta';
        $html = '<section class="center-page"><div><div class="ic" style="background:var(--accent-soft);color:var(--accent-text)">' . Layout::check() . '</div>'
            . '<h1>Instagram conectado</h1><p>' . Layout::e($who) . ' já pode receber os posts que você aprovar no Telegram.</p>'
            . '<a class="btn btn-primary" href="https://t.me/PerfilEmDiaBot">Voltar ao Telegram</a></div></section>';
        Layout::page('Instagram conectado', $html);
    }

    private function connectionError(): void
    {
        $reason = (string) ($_GET['motivo'] ?? 'negado');
        $text = match ($reason) {
            'pessoal' => 'A conta ainda é pessoal. No Instagram, mude para conta profissional (Empresa). É grátis e não pede CNPJ. Depois volte ao bot e conecte de novo.',
            'expirado' => 'O link de conexão expirou. Volte ao Telegram e peça um novo em /conectar.',
            default => 'A autorização foi cancelada ou recusada. Nada foi publicado. Você pode tentar de novo pelo bot.',
        };
        $html = '<section class="center-page"><div><h1>Não foi possível conectar</h1><p>' . Layout::e($text) . '</p>'
            . '<a class="btn btn-primary" href="https://t.me/PerfilEmDiaBot">Voltar ao Telegram</a></div></section>';
        Layout::page('Conexão não concluída', $html);
    }

    private function webhook(): void
    {
        $token = (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_GET['token'] ?? '');
        $expected = Config::get('PAYMENT_WEBHOOK_TOKEN', '');
        $sandboxPost = ($_POST['sandbox'] ?? '') === '1' && SandboxGateway::enabled() && Layout::checkCsrf();
        if (!$sandboxPost && ($expected === '' || !hash_equals($expected, $token))) {
            http_response_code(401);
            echo 'unauthorized';
            return;
        }
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $external = (string) ($_POST['external_id'] ?? '');
        if (is_array($json)) {
            $external = (string) ($json['payment']['id'] ?? $json['external_id'] ?? $external);
            $raw = $raw !== '' ? $raw : json_encode($json);
        }
        if ($external === '') {
            http_response_code(422);
            echo 'missing';
            return;
        }
        $gateway = SandboxGateway::enabled() ? 'sandbox' : 'asaas';
        $this->billing()->confirmExternal($gateway, $external, $raw !== '' ? $raw : $external);
        if ($sandboxPost) {
            $public = (string) ($_SESSION['checkout_id'] ?? '');
            header('Location: ' . Layout::url($public !== '' ? 'pronto/' . $public : 'planos'));
            exit;
        }
        http_response_code(200);
        echo 'ok';
    }

    private function billing(): CheckoutService
    {
        $gateway = SandboxGateway::enabled()
            ? new SandboxGateway()
            : new \PerfilEmDia\Billing\AsaasGateway();

        return new CheckoutService($this->pdo, $gateway);
    }

    /**
     * @param list<array{0:string,1:string}> $items
     */
    private function faq(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<details><summary>' . Layout::e($item[0]) . '</summary><p>' . Layout::e($item[1]) . '</p></details>';
        }

        return $html;
    }

    private function planCards(string $cycle, bool $cta): string
    {
        $plans = (new PlanRepository($this->pdo))->active();
        if ($plans === []) {
            return '<p class="intro">Nenhum plano disponível no momento.</p>';
        }
        $html = '<div class="plans">';
        foreach ($plans as $plan) {
            $intro = Trial::applies($plan, $cycle);
            $trialPosts = $intro ? Trial::posts((int) $plan['posts_limit'], (int) $plan['trial_days']) : 0;
            $price = $cycle === 'anual' ? (int) $plan['price_cents'] * 10 : ($intro ? (int) $plan['trial_price_cents'] : (int) $plan['price_cents']);
            $html .= '<article class="plan' . ((int) $plan['highlighted'] ? ' hi' : '') . '">';
            if ((int) $plan['highlighted']) {
                $html .= '<span class="badge">Mais escolhido</span>';
            }
            $html .= '<div><h3>' . Layout::e((string) $plan['name']) . '</h3><p style="color:var(--ink2);margin-top:8px">' . Layout::e((string) $plan['description']) . '</p></div>';
            $period = $intro ? ((int) $plan['trial_days'] . ' dias') : ($cycle === 'anual' ? 'ano' : 'mês');
            $html .= '<p style="font-family:var(--display);font-size:40px;font-weight:800">' . Layout::e(Layout::money($price)) . '<span style="font-size:16px;font-weight:600;color:var(--muted)"> / ' . $period . '</span></p>';
            if ($intro) {
                $html .= '<p class="hint">Depois, ' . Layout::e(Layout::money((int) $plan['price_cents'])) . ' por mês, com renovação automática. Nestes ' . (int) $plan['trial_days'] . ' dias, até ' . $trialPosts . ' posts. No mês, até ' . (int) $plan['posts_limit'] . '.</p>';
            }
            $html .= '<ul>';
            foreach (preg_split('/\n/', (string) $plan['features']) ?: [] as $feature) {
                if (trim($feature) === '') {
                    continue;
                }
                $html .= '<li>' . Layout::check() . '<span>' . Layout::e(trim($feature)) . '</span></li>';
            }
            $html .= '</ul>';
            if ($cta) {
                $html .= '<a class="btn btn-primary" href="' . Layout::e(Layout::url('contratar?plano=' . rawurlencode((string) $plan['slug']) . '&ciclo=' . $cycle)) . '">Assinar ' . Layout::e((string) $plan['name']) . '</a>';
            }
            $html .= '</article>';
        }
        $html .= '</div>';

        return $html;
    }

    private function phone(): string
    {
        return '<div class="phone" aria-hidden="true"><div class="phone-top"><span class="mark">' . Layout::mark() . '</span><div><b>Perfil em Dia</b><span>bot</span></div></div>'
            . '<div class="chat seq">'
            . '<div class="bub me"><div class="ph">foto do quadro</div></div>'
            . '<div class="bub bot">Recebi a foto. Em uma frase, o que foi esse serviço?</div>'
            . '<div class="bub me"><div class="t">quadro de automação num apê em Moema</div></div>'
            . '<div class="bub bot">Quadro de distribuição novo e identificado. Segurança para a casa toda.<div class="tag">#eletricista #moema</div></div>'
            . '<div class="keys"><span>Ajustar</span><span>Outra versão</span><span class="p">Publicar</span></div>'
            . '</div></div>';
    }

    private function legal(string $title, string $body): string
    {
        return '<section class="page"><div class="wrap"><h1>' . Layout::e($title) . '</h1>'
            . '<p class="notice">Modelo para revisão jurídica. Faltam a razão social, o CNPJ, o encarregado e o foro. Um advogado precisa revisar antes de publicar como texto definitivo.</p>'
            . '<div class="prose">' . $body . '</div></div></section>';
    }

    private function field(string $name, string $label, string $type, string $value, string $error, string $hint = '', string $mask = ''): string
    {
        $class = 'field' . ($error !== '' ? ' bad' : '');
        $maskAttr = $mask !== '' ? ' data-mask="' . Layout::e($mask) . '"' : '';

        return '<div class="' . $class . '"><label for="' . Layout::e($name) . '">' . Layout::e($label) . '</label>'
            . '<input class="in" id="' . Layout::e($name) . '" name="' . Layout::e($name) . '" type="' . Layout::e($type) . '" value="' . Layout::e($value) . '"' . $maskAttr . '>'
            . ($hint !== '' ? '<span class="hint">' . Layout::e($hint) . '</span>' : '')
            . '<span class="err">' . Layout::e($error !== '' ? $error : 'Confira este campo.') . '</span></div>';
    }

    private function steps(int $current): string
    {
        $labels = [1 => 'Seus dados', 2 => 'Pagamento', 3 => 'Pronto'];
        $html = '<div class="ck-steps">';
        foreach ($labels as $n => $label) {
            $state = $n === $current ? ' aria-current="step"' : ($n < $current ? ' class="done"' : '');
            $html .= '<span' . $state . '>' . $n . '. ' . $label . '</span>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function summary(array $plan, string $cycle, int $price, ?string $after = null): string
    {
        $hint = $after ?? 'O cupom, se valer, aparece na etapa seguinte.';

        return '<aside class="box sum"><h3>Resumo</h3>'
            . '<div class="row"><span>' . Layout::e((string) $plan['name']) . '</span><span>' . Layout::e($cycle === 'anual' ? 'Anual' : 'Mensal') . '</span></div>'
            . '<div class="row tot"><span>Total agora</span><span>' . Layout::e(Layout::money($price)) . '</span></div>'
            . '<p class="hint">' . Layout::e($hint) . '</p></aside>';
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function summaryPaid(array $checkout): string
    {
        return '<aside class="box sum"><h3>Resumo</h3>'
            . '<div class="row"><span>' . Layout::e((string) $checkout['plan_name']) . '</span><span>' . Layout::e((string) $checkout['cycle']) . '</span></div>'
            . '<div class="row tot"><span>Total agora</span><span>' . Layout::e(Layout::money((int) $checkout['amount_cents'])) . '</span></div></aside>';
    }

    private function newProtocol(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = 'EX';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function clientArea(): void
    {
        $steps = \PerfilEmDia\Site\Guides::clientSteps();
        $html = '<section class="page"><div class="wrap"><h1>Área do cliente</h1>'
            . '<p class="meta">Oito passos, do pagamento até o primeiro post. Cada um diz como saber que deu certo.</p>'
            . \PerfilEmDia\Site\Guides::toc($steps)
            . \PerfilEmDia\Site\Guides::steps($steps)
            . '</div></section>';
        Layout::page('Área do cliente', $html, 'manual');
    }

}
