<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Billing\AppMaxGateway;
use PerfilEmDia\Billing\BillingFactory;
use PerfilEmDia\Billing\BrazilianDocument;
use PerfilEmDia\Billing\Card;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\CouponRejected;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\PaymentWebhook;
use PerfilEmDia\Billing\Phone;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\SandboxGateway;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Billing\Trial;
use PerfilEmDia\Growth\BenefitOrchestrator;
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
        if ($path === '/sitemap.xml') {
            $this->sitemap();
            return true;
        }
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
        if ($path === '/profissoes') {
            $this->professionHub();
            return true;
        }
        if (preg_match('#^/profissoes/([a-z0-9-]+)$#', $path, $profession) === 1) {
            $this->professionPage($profession[1]);
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
        if ($path === '/appmax/validar') {
            $this->appmaxValidate();
            return true;
        }

        return false;
    }

    private function home(): void
    {
        $plans = $this->planCards('mensal', true);
        $questions = [
            ['Minha conta do Instagram precisa ser profissional?', 'Sim. É grátis e leva um minuto: Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, Empresa. O bot mostra o passo a passo.'],
            ['Preciso de CNPJ ou de Página do Facebook?', 'Não. Autônomos com CPF usam normalmente, e quem cuida de um produto também. A conexão é feita direto com o Instagram.'],
            ['Vocês publicam sem eu aprovar?', 'Não. Cada post chega como prévia. Ele sai quando você toca em Publicar, ou na hora que você agendou.'],
            ['E se eu não gostar da legenda?', 'Toque em Ajustar e diga o que mudar, peça outra versão ou escreva o texto do seu jeito.'],
            ['Posso mandar mais de uma foto?', 'Pode. Mande um álbum com até 10 fotos e ele vira um carrossel.'],
            ['Posso publicar vídeo?', 'Nos planos Profissional e Estúdio, um vídeo de 3 a 90 segundos e até 20 MB. A legenda sai na prévia e só publica quando você aprova.'],
            ['O post pode ser criado pela IA?', 'No plano Estúdio. Você manda a ideia, ou toca em Surpreenda-me. Aí o Perfil em Dia monta a foto, o texto na imagem, a marca e a legenda. A prévia chega no Telegram e só publica quando você aprova.'],
            ['O bot sugere o que postar?', 'Sim. A partir das 8h, uma vez por dia, chega uma ideia ligada ao que você faz. Se passar de 1 dia sem publicar, o aviso lembra e já traz um pedido pronto. Dá para parar no botão da mensagem.'],
            ['Por que Telegram e não WhatsApp?', 'Porque o Telegram deixa o Perfil em Dia separado das suas conversas de clientes e família, e funciona do mesmo jeito que o WhatsApp.'],
        ];
        $faq = $this->faq($questions);
        $html = '<section class="hero"><div class="wrap"><div>'
            . '<h1>Seu Instagram em dia, mesmo sem tempo para postar.</h1>'
            . '<p class="lead">Você manda a foto e uma frase no Telegram. O Perfil em Dia escreve a legenda, mostra a prévia e publica no Instagram quando você aprova.</p>'
            . '<div class="ctas"><a class="btn btn-primary" href="' . Layout::e(Layout::url('planos')) . '">Ver planos</a>'
            . '<a class="btn btn-ghost" href="' . Layout::e(Layout::url('/#como')) . '">Como funciona</a></div>'
            . '<p class="fine">Para profissionais e para quem cuida do perfil de um produto. Sem agência e sem planilha de posts. <a href="' . Layout::e(Layout::url('manual')) . '">Veja o passo a passo</a>.</p>'
            . '</div>' . $this->phone() . '</div></section>';
        $html .= '<section class="sec" id="como"><div class="wrap"><h2>Três passos, quando sobrar um minuto.</h2>'
            . '<div class="steps">'
            . '<article class="step"><div class="n">1</div><h3>Manda a foto</h3><p>Uma foto real, do serviço ou do produto. Pode ser um álbum de até 10. Nada de banco de imagem.</p></article>'
            . '<article class="step"><div class="n">2</div><h3>Diz o tema</h3><p>Uma frase chega: o que foi feito, o que mudou no produto, ou o que você quer destacar.</p></article>'
            . '<article class="step"><div class="n">3</div><h3>Aprova e publica</h3><p>A legenda chega como prévia. Você publica, pede ajuste ou escreve do seu jeito.</p></article>'
            . '</div></div></section>';
        $html .= '<section class="sec sec-paper"><div class="wrap"><h2>Para o profissional e para o produto.</h2>'
            . '<p class="quote">Quem atende gente o dia inteiro, e quem tem um produto e não sobra tempo para o Instagram.</p>'
            . '<div class="why">'
            . '<div><h3>A foto é sua</h3><p>Do serviço que você entregou ou do produto que você cuida. Nada de imagem genérica.</p></div>'
            . '<div><h3>Nada sai sem você ver</h3><p>Todo post chega como prévia. Você publica, ajusta, escreve do seu jeito ou cancela.</p></div>'
            . '<div><h3>É uma conversa, não um app</h3><p>Funciona no Telegram, que é igual ao WhatsApp de usar. Nada novo para aprender.</p></div>'
            . '<div><h3>Não precisa de CNPJ</h3><p>Basta ter conta profissional no Instagram, que é grátis. O bot mostra como mudar em um minuto.</p></div>'
            . '</div></div></section>';
        $html .= $this->homeMiddle();
        $html .= '<section class="sec"><div class="wrap"><h2>Planos simples, sem fidelidade</h2><p class="intro">Cancele quando quiser, direto pelo bot.</p>' . $plans . '</div></section>';
        $html .= '<section class="sec sec-paper"><div class="wrap" style="max-width:860px"><h2>Perguntas frequentes</h2><div class="faq">' . $faq . '</div></div></section>';
        $html .= '<section class="band"><div class="wrap"><h2>O perfil do seu produto também pode ficar em dia.</h2><p>Assine, abra o bot no Telegram e mande a primeira foto hoje.</p><a class="btn" href="' . Layout::e(Layout::url('planos')) . '">Ver planos</a></div></section>';
        Layout::page('Perfil em Dia', $html, '', 200, '', $this->faqJson($questions));
    }


    private function homeMiddle(): string
    {
        $cards = '';
        foreach (BenefitOrchestrator::professions() as $slug => $job) {
            $cards .= '<a class="job" href="' . Layout::e(Layout::url('profissoes/' . $slug)) . '">'
                . $this->professionIcon($slug) . '<h3>'
                . Layout::e($job['name']) . '</h3><p>' . Layout::e($job['phrase']) . '</p></a>';
        }
        $jobs = '<section class="sec" id="profissoes" aria-roledescription="carrossel"><div class="wrap"><div class="job-head"><div><h2>'
            . Layout::e(BenefitOrchestrator::hubTitle()) . '</h2><p class="intro">'
            . Layout::e(BenefitOrchestrator::hubLead()) . '</p></div>'
            . '<div class="job-nav"><button type="button" data-job="prev" aria-label="Anterior">&#8249;</button>'
            . '<button type="button" data-job="next" aria-label="Pr&#243;xima">&#8250;</button></div></div>'
            . '<div class="jobs">' . $cards . '</div></div></section>';
        $items = [
            ['Ideia do dia', 'A partir das 8h, uma vez por dia, chega um pedido pronto, ligado ao que você faz. Se passar de 1 dia sem postar, o aviso lembra. Em data comemorativa, a sugestão já vem no tema. Dá para pedir na hora com /ideia, ou parar no botão.'],
            ['Publicar no story', 'Uma foto ou um vídeo pode ir para o story. Carrossel segue no feed.'],
            ['Cor e estilo', 'No plano Estúdio, descreva a marca com /marca. A imagem criada pela IA segue essa descrição.'],
            ['Resultado da semana', 'O comando /resultado mostra alcance e visualizações dos últimos 7 dias, quando o Instagram libera.'],
        ];
        $feats = '';
        foreach ($items as [$title, $text]) {
            $feats .= '<article class="feat"><h3>' . Layout::e($title) . '</h3><p>' . Layout::e($text) . '</p></article>';
        }
        $more = '<section class="sec sec-paper" id="recursos"><div class="wrap"><h2>Mais do que a legenda.</h2>'
            . '<p class="intro">Ideia, story, visual da marca e o resultado da semana. Tudo na mesma conversa do Telegram.</p>'
            . '<div class="feats">' . $feats . '</div></div></section>';

        return $jobs . $more;
    }

    private function professionIcon(string $slug): string
    {
        $paths = match ($slug) {
            'nutricionista' => '<path d="M12 19.5c-3.6 0-6.5-2.6-6.5-6.2 0-3.4 2.6-5.3 6.5-5.3s6.5 1.9 6.5 5.3c0 3.6-2.9 6.2-6.5 6.2z"/><path d="M12 8c.2-2 1.4-3.2 3.2-3.8"/><path d="M12.6 8.2c.8-1.2 2-1.6 3.2-1.4"/>',
            'advogado' => '<path d="M12 4v14"/><path d="M8 20h8"/><path d="M5 9h14"/><path d="M5 9c0 2 1.2 3.5 2.8 3.5S10.6 11 10.6 9"/><path d="M13.4 9c0 2 1.2 3.5 2.8 3.5S19 11 19 9"/>',
            'dentista' => '<path d="M8 4.5h8c.8 3.2 2 5.2 2 8 0 2.6-1.4 5.5-2.8 5.5-1.1 0-1.4-1.8-3.2-1.8s-2.1 1.8-3.2 1.8C7.4 18 6 15.1 6 12.5c0-2.8 1.2-4.8 2-8z"/>',
            'salao-de-beleza' => '<circle cx="6.5" cy="6.5" r="2.2"/><circle cx="6.5" cy="17.5" r="2.2"/><path d="M8.2 7.8L19 17"/><path d="M8.2 16.2L19 7"/>',
            'personal' => '<circle cx="14" cy="5" r="2"/><path d="M7 20l3.2-6.2 2.2 2.2 2.4-4.4"/><path d="M9.2 12.2l4.2-1.6 3.2 2.2"/>',
            'restaurante' => '<path d="M7 3v6a2 2 0 0 0 2 2v10"/><path d="M7 7h4"/><path d="M16 3c1.6 1.6 1.6 4 0 6"/><path d="M16 3v18"/>',
            'psicologo' => '<circle cx="12" cy="8" r="3"/><path d="M6.5 19.5c.8-3 2.8-4.5 5.5-4.5s4.7 1.5 5.5 4.5"/><path d="M16.5 6.2c.7.3 1.2.9 1.4 1.6"/>',
            'corretor-de-imoveis' => '<path d="M4 11.5L12 4l8 7.5"/><path d="M6.5 10.5V20h11V10.5"/><path d="M10 20v-5h4v5"/>',
            'loja' => '<path d="M6.5 8h11l-1 12h-9z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
            'estetica' => '<path d="M12 3.5l1.1 3.8 3.8 1.2-3.8 1.2L12 13.5l-1.1-3.8-3.8-1.2 3.8-1.2z"/><path d="M17.5 14.5l.6 1.8 1.8.6-1.8.6-.6 1.8-.6-1.8-1.8-.6 1.8-.6z"/>',
            'eletricista' => '<path d="M13 2.5L5.5 13H12l-1 8.5L18.5 10H12z"/>',
            'confeitaria' => '<path d="M5 14h14v4.5a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z"/><path d="M5 14c1.4-1.8 2.8-1.8 4.2 0s2.8 1.8 4.2 0 2.8-1.8 4.2 0"/><path d="M12 9.5V13"/><path d="M12 6.2c.7.7.7 1.4 0 2.2"/>',
            'pet-shop' => '<circle cx="7.5" cy="8" r="1.5"/><circle cx="12" cy="6.2" r="1.5"/><circle cx="16.5" cy="8" r="1.5"/><circle cx="9.2" cy="11" r="1.3"/><path d="M8.2 15.2c0-1.8 1.4-2.8 3.8-2.8s3.8 1 3.8 2.8-1.4 3.6-3.8 3.6-3.8-1.8-3.8-3.6z"/>',
            'academia' => '<path d="M4 10v4"/><path d="M7 8.5v7"/><path d="M7 12h10"/><path d="M17 8.5v7"/><path d="M20 10v4"/>',
            default => '<circle cx="12" cy="12" r="7"/>',
        };

        return '<svg class="job-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    private function plans(): void
    {
        $cycle = ($_GET['ciclo'] ?? 'mensal') === 'anual' ? 'anual' : 'mensal';
        $mensal = $cycle === 'mensal';
        $html = '<section class="page"><div class="wrap" style="max-width:980px"><h1>Planos e preços</h1>'
            . '<p class="meta">No mensal, os 3 primeiros dias são um teste, com o limite de posts na proporção do mês. Depois o plano renova sozinho, mês a mês, até o cancelamento. O anual cobra o equivalente a 10 meses e também renova sozinho.</p>'
            . '<div class="cycle">'
            . '<a href="' . Layout::e(Layout::url('planos?ciclo=mensal')) . '" aria-pressed="' . ($mensal ? 'true' : 'false') . '">Mensal</a>'
            . '<a href="' . Layout::e(Layout::url('planos?ciclo=anual')) . '" aria-pressed="' . ($mensal ? 'false' : 'true') . '">Anual</a>'
            . '</div>'
            . $this->planCards($cycle, true)
            . '</div></section>';
        Layout::page('Planos e preços', $html, 'planos', 200, $this->plansMeta($cycle));
    }


    private function telegramDownloads(?string $botUrl = null): string
    {
        $play = 'https://play.google.com/store/apps/details?id=org.telegram.messenger';
        $apple = 'https://apps.apple.com/app/telegram-messenger/id686449807';
        if ($botUrl === null || $botUrl === '') {
            $botUrl = 'https://t.me/' . rawurlencode(Config::get('TELEGRAM_BOT_USERNAME', 'PerfilEmDiaBot'));
        }

        return '<div class="box store-box"><h2>Ainda não tem o Telegram?</h2>'
            . '<p>O Perfil em Dia funciona nele. Baixe o aplicativo oficial e abra o bot por este link.</p>'
            . '<div class="store-row">'
            . '<a class="btn btn-primary" href="' . Layout::e($botUrl) . '" target="_blank" rel="noopener noreferrer">Abrir o bot</a>'
            . '<a class="btn btn-ghost" href="' . Layout::e($play) . '" target="_blank" rel="noopener noreferrer">Android na Play Store</a>'
            . '<a class="btn btn-ghost" href="' . Layout::e($apple) . '" target="_blank" rel="noopener noreferrer">iPhone na App Store</a>'
            . '</div></div>';
    }

    private function help(): void
    {
        $email = Settings::get('support_email', 'ajuda@perfilemdia.com.br');
        $questions = [
            ['Como mudo meu Instagram para conta profissional?', 'No Instagram, toque na sua foto de perfil, depois no menu de três linhas, Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, e escolha Empresa. É grátis e não pede CNPJ.'],
            ['O bot diz que minha conexão expirou. O que faço?', 'Por segurança, a conexão com o Instagram precisa ser renovada de tempos em tempos. Toque em Reconectar Instagram no bot ou envie /conectar.'],
            ['A foto ficou cortada. Por quê?', 'O Instagram só aceita fotos entre o formato retrato 4:5 e o paisagem 1,91:1. Fotos mais altas, como as de celular em pé, são ajustadas com um corte central.'],
            ['Posso usar em mais de um Instagram?', 'Por enquanto, cada assinatura conecta uma conta do Instagram.'],
            ['Como cancelo minha assinatura?', 'Envie /assinatura no bot e abra Minha conta. O cancelamento vale no fim do período já pago. Se precisar de ajuda, envie /chamado.'],
            ['Como apago meus dados?', 'Envie /excluirconta no bot ou use a página de exclusão de dados.'],
        ];
        $faq = $this->faq($questions);
        $html = '<section class="page"><div class="wrap"><h1>Dúvidas frequentes</h1><p class="meta"><a href="' . Layout::e(Layout::url('manual')) . '">Abra o passo a passo da área do cliente</a> se você já assinou ou está configurando o Instagram.</p><p class="meta">Respostas rápidas para as dúvidas mais comuns.</p>' . $this->telegramDownloads() . '<div class="faq">' . $faq . '</div>'
            . '<div class="box" style="margin-top:40px"><h2 style="font-size:22px;margin-bottom:8px">Não achou sua resposta?</h2>'
            . '<p style="color:var(--ink2)">Se a resposta não estiver aqui, envie /chamado no bot. Você também pode escrever para <a href="mailto:' . Layout::e($email) . '">' . Layout::e($email) . '</a>.</p></div>'
            . '</div></section>';
        Layout::page('Dúvidas frequentes', $html, 'ajuda', 200, '', $this->faqJson($questions));
    }

    private function privacy(): void
    {
        Layout::page('Privacidade', $this->legal(
            'Política de privacidade',
            '<h2>Quem trata os dados</h2><p>O responsável pelo tratamento é [RAZÃO SOCIAL], CNPJ [CNPJ]. O encarregado é [ENCARREGADO], pelo e-mail ' . Layout::e(Settings::get('support_email', 'ajuda@perfilemdia.com.br')) . '.</p>'
            . '<h2>O que coletamos</h2><ul><li>Nome, e-mail, celular e CPF ou CNPJ, para a contratação.</li><li>Identificador do Telegram e as fotos que você envia para publicar.</li><li>Token de acesso do Instagram, guardado criptografado, para publicar em seu nome.</li><li>Registros de pagamento: valor, status, bandeira e os 4 últimos dígitos. O número completo do cartão não fica aqui.</li></ul>'
            . '<h2>Para que usamos</h2><p>Para prestar o serviço, cobrar a assinatura, publicar no Instagram depois da sua aprovação, na hora ou no horário agendado, e cumprir a lei.</p>'
            . '<h2>Por quanto tempo</h2><p>As fotos públicas saem do ar em algumas horas. Os dados da conta ficam enquanto a assinatura existir. Pagamentos são guardados pelo prazo fiscal, mesmo depois da exclusão da conta.</p>'
            . '<h2>Seus direitos</h2><p>Você pode pedir acesso, correção ou exclusão na página de exclusão de dados, ou com o comando /excluirconta no bot.</p><h2 id="cookies">Cookies</h2><p>A sessão do site guarda o pagamento e o acesso à conta. Ela é necessária e não pede escolha.</p><p>Se você aceitar no aviso, o Google Analytics mede as visitas do site. Se recusar, essa medição não começa. Para mudar a escolha, limpe os dados deste site no navegador.</p>'
        ));
    }

    private function terms(): void
    {
        Layout::page('Termos de uso', $this->legal(
            'Termos de uso',
            '<h2>O serviço</h2><p>[RAZÃO SOCIAL], CNPJ [CNPJ], oferece um bot no Telegram que escreve a legenda de uma foto real, do seu trabalho ou do seu produto, e publica no Instagram depois que você aprova.</p>'
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
        $appmax = AppMaxGateway::configured();
        $method = (string) ($_POST['method'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf() && $method === 'ip') {
            $ip = trim((string) ($_POST['client_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $_SESSION['appmax_ip'] = $ip;
            }
            http_response_code(204);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf() && $method === 'cartao' && $appmax) {
            $token = trim((string) ($_POST['appmax_token'] ?? ''));
            $digits = Card::digits((string) ($_POST['number'] ?? $_POST['card-number'] ?? ''));
            $last4 = strlen($digits) >= 4 ? substr($digits, -4) : null;
            $brand = $digits !== '' ? Card::brand($digits) : null;
            $holder = trim((string) ($_POST['card-holder-name'] ?? ''));
            $month = (int) ($_POST['exp-month'] ?? 0);
            $year = (int) ($_POST['exp-year'] ?? 0);
            $cvv = Card::digits((string) ($_POST['cvv'] ?? ''));
            unset($_POST['number'], $_POST['card-number'], $_POST['cvv'], $_POST['exp-month'], $_POST['exp-year'], $_POST['card-holder-name']);
            if ($token === '' && $digits !== '') {
                if (!Card::luhn($digits)) {
                    $error = 'Número de cartão inválido.';
                } else {
                    try {
                        $token = (new AppMaxGateway($this->pdo))->tokenize($holder, $digits, $month, $year, $cvv);
                    } catch (Throwable) {
                        $error = 'Não foi possível ler o cartão. Tente de novo.';
                    }
                }
            }
            $digits = '';
            $cvv = '';
            if ($error === '' && $token === '') {
                $error = 'Não foi possível ler o cartão. Tente de novo.';
            } elseif ($error === '') {
                try {
                    $this->billing()->chargeCard($publicId, $token, $brand, $last4);
                    header('Location: ' . Layout::url('pronto/' . $publicId));
                    exit;
                } catch (PaymentRefused) {
                    $error = 'O banco recusou este cartão.';
                } catch (Throwable) {
                    $error = 'Não foi possível cobrar agora.';
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf() && $method === 'cartao') {
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
        $wantPix = $_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf() && $method === 'pix';
        $waitIp = $appmax && filter_var((string) ($_SESSION['appmax_ip'] ?? ''), FILTER_VALIDATE_IP) === false;
        if ((int) $checkout['amount_cents'] > 0 && $checkout['status'] === 'aberto') {
            try {
                if ($wantPix && !$waitIp) {
                    $pix = $this->billing()->startPix($publicId);
                } else {
                    $pix = $this->billing()->pendingPix($publicId);
                }
            } catch (Throwable) {
                if ($wantPix) {
                    $error = $error !== '' ? $error : 'Não foi possível gerar o Pix.';
                }
            }
        }
        $hasPix = is_array($pix) && (string) ($pix['pix_payload'] ?? '') !== '';
        $pick = '';
        if ($method === 'cartao') {
            $pick = 'cartao';
        }
        if ($hasPix || $method === 'pix' || ($wantPix && $waitIp)) {
            $pick = 'pix';
        }
        $sandbox = SandboxGateway::enabled();
        $html = '<section class="ck"><div class="wrap"><div>' . $this->steps(2) . '<div class="box"><h2>Pagamento</h2>';
        if ($error !== '') {
            $html .= '<p class="notice">' . Layout::e($error) . '</p>';
        }
        $html .= '<div class="pay">';
        $html .= '<p class="pay-lead">Escolha uma forma de pagamento.</p>';
        $html .= '<div class="pay-methods" role="radiogroup" aria-label="Forma de pagamento">';
        $html .= $this->payChoice('pix', 'Pix', 'Aprovação na hora', $pick === 'pix');
        $html .= $this->payChoice('cartao', 'Cartão de crédito', 'Cobrança na hora', $pick === 'cartao');
        $html .= '</div>';
        $html .= '<div class="pay-panel" data-for="pix">';
        if ($hasPix) {
            $qr = $this->pixImage($pix);
            $html .= '<div class="pix' . ($qr !== '' ? '' : ' pix-text') . '">' . $qr . '<div>'
                . '<p>Escaneie o QR ou copie o código. A confirmação chega pelo banco. O botão abaixo só consulta o status.</p>'
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
        } else {
            $html .= '<form method="post">'
                . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
                . '<input type="hidden" name="method" value="pix">';
            if ($wantPix && $waitIp) {
                $html .= '<p id="pix-wait">Preparando o Pix. O código aparece em seguida.</p>';
            } else {
                $html .= '<p>O QR Code aparece aqui quando você gerar o Pix.</p>';
            }
            $html .= '<button class="btn btn-primary" type="submit">Gerar Pix</button></form>';
        }
        $html .= '</div><div class="pay-panel" data-for="cartao">';
        if ($appmax) {
            $html .= $this->appmaxCard((string) $checkout['customer_name']);
        } else {
            $html .= '<form method="post">'
                . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
                . '<input type="hidden" name="method" value="cartao">'
                . $this->field('number', 'Número', 'text', '', '', 'Não guardamos o número. No sandbox, um final 0002 simula recusa.', 'card')
                . '<div class="grid2">' . $this->field('expiry', 'Validade', 'text', '', '', '', 'expiry')
                . $this->field('cvv', 'CVV', 'text', '', '') . '</div>'
                . '<p id="brand" class="hint"></p>'
                . '<button class="btn btn-primary" type="submit">Pagar com cartão</button></form>';
        }
        $html .= '</div></div></div></div>';
        $html .= $this->summaryPaid($checkout) . '</div></section>';
        Layout::page('Pagamento', $html);
    }


    private function payChoice(string $value, string $title, string $note, bool $on): string
    {
        return '<label class="pay-method"><input type="radio" name="forma" value="' . $value . '"'
            . ($on ? ' checked' : '') . '>'
            . '<span><strong>' . Layout::e($title) . '</strong><small>' . Layout::e($note) . '</small></span></label>';
    }
    /**
     * @param array<string, mixed> $pix
     */
    private function pixImage(array $pix): string
    {
        $raw = preg_replace('/\s+/', '', (string) ($pix['pix_qrcode'] ?? '')) ?? '';
        if (!str_starts_with($raw, 'iVBORw0KGgo') || preg_match('/^[A-Za-z0-9+\/=]+$/', $raw) !== 1) {
            return '';
        }

        return '<img class="qr" alt="QR Code do Pix" width="176" height="176" src="data:image/png;base64,' . $raw . '">';
    }
    private function status(string $publicId): void
    {
        if (AppMaxGateway::configured()) {
            try {
                $this->billing()->pullPix($publicId);
            } catch (Throwable) {
            }
        }
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
            . $this->telegramDownloads($bot)
            . '<ol class="next">'
            . '<li>Toque em Abrir o bot. O código já vai na conversa.</li>'
            . '<li>Responda as perguntas do perfil.</li>'
            . '<li>Mude o Instagram para conta profissional e conecte.</li>'
            . '<li>No bot, escolha o tipo do post e mande a foto, o carrossel, o vídeo ou a ideia. No Estúdio, Surpreenda-me monta o post e você só aprova.</li>'
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
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $token = (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? $_GET['token'] ?? '');
        $expected = Config::get('PAYMENT_WEBHOOK_TOKEN', '');
        $sandboxPost = ($_POST['sandbox'] ?? '') === '1' && SandboxGateway::enabled() && Layout::checkCsrf();
        if (!$sandboxPost && !PaymentWebhook::tokenMatches($expected, $token)) {
            http_response_code(401);
            echo 'unauthorized';
            return;
        }
        if (is_array($json) && isset($json['event']) && AppMaxGateway::configured()) {
            $this->appmaxWebhook($json, $raw);
            return;
        }
        if (!$sandboxPost && (!is_array($json) || !PaymentWebhook::provesPayment($json))) {
            http_response_code(422);
            echo 'unconfirmed';
            return;
        }
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
        $gateway = SandboxGateway::enabled()
            ? 'sandbox'
            : strtolower(Config::get('PAYMENT_GATEWAY', 'appmax'));
        $this->billing()->confirmExternal($gateway, $external, $raw !== '' ? $raw : $external);
        if ($sandboxPost) {
            $public = (string) ($_SESSION['checkout_id'] ?? '');
            header('Location: ' . Layout::url($public !== '' ? 'pronto/' . $public : 'planos'));
            exit;
        }
        http_response_code(200);
        echo 'ok';
    }

    private function appmaxWebhook(array $json, string $raw): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $event = (string) ($json['event'] ?? '');
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $orderId = (string) ($data['order_id'] ?? '');
        if ($orderId === '' && isset($data['order']['id'])) {
            $orderId = (string) $data['order']['id'];
        }
        if ($orderId === '' && isset($data['id'])) {
            $orderId = (string) $data['id'];
        }
        $gateway = new AppMaxGateway($this->pdo);
        $paid = ['order_paid_by_pix', 'order_approved', 'order_paid', 'order_integrated'];
        if (in_array($event, $paid, true) && $orderId !== '') {
            if (!$gateway->orderIsPaid($orderId)) {
                http_response_code(503);
                echo 'pending';
                return;
            }
            $this->billing()->confirmExternal('appmax', $orderId, $raw);
            http_response_code(200);
            echo 'ok';
            return;
        }
        $subId = (string) ($data['subscription_id'] ?? '');
        if ($event === 'subscription_charge_success' && $subId !== '' && $orderId !== '' && $gateway->orderIsPaid($orderId)) {
            $amount = (int) ($data['total'] ?? 0);
            $this->billing()->recordGatewayRenewal($subId, $orderId, $amount);
        }
        if ($event === 'subscription_charge_failed' && $subId !== '' && $orderId !== '' && $gateway->orderIsRefused($orderId)) {
            $this->billing()->markGatewayDelinquent($subId);
        }
        http_response_code(200);
        echo 'ok';
    }

    private function billing(): CheckoutService
    {
        return BillingFactory::service($this->pdo);
    }

    private function appmaxCard(string $holder): string
    {
        $html = '';
        $html .= '<form id="appmax-customer" data-appmax-customer hidden>'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '"></form>';
        $html .= '<p id="card-error" class="notice" hidden>Não deu para ler o cartão. Confira os dados e tente de novo.</p>';
        $html .= '<form id="card-form" method="post">'
            . '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">'
            . '<input type="hidden" name="method" value="cartao">'
            . '<div class="field"><label for="card-number">Número do cartão</label>'
            . '<input class="in" id="card-number" name="number" type="text" inputmode="numeric" autocomplete="cc-number" required></div>'
            . '<div class="field"><label for="card-holder-name">Nome no cartão</label>'
            . '<input class="in" id="card-holder-name" name="card-holder-name" type="text" autocomplete="cc-name" value="' . Layout::e($holder) . '" required></div>'
            . '<div class="grid2"><div class="field"><label for="exp-month">Mês</label>'
            . '<input class="in" id="exp-month" name="exp-month" type="text" inputmode="numeric" autocomplete="cc-exp-month" placeholder="MM" required></div>'
            . '<div class="field"><label for="exp-year">Ano</label>'
            . '<input class="in" id="exp-year" name="exp-year" type="text" inputmode="numeric" autocomplete="cc-exp-year" placeholder="AA" required></div></div>'
            . '<div class="field"><label for="cvv">CVV</label>'
            . '<input class="in" id="cvv" name="cvv" type="text" inputmode="numeric" autocomplete="cc-csc" required></div>'
            . '<button class="btn btn-primary" type="submit">Pagar com cartão</button></form>';
        $html .= '<script src="' . Layout::e(AppMaxGateway::scriptUrl()) . '"></script><script>'
            . '(function(){var form=document.getElementById("appmax-customer");'
            . 'function saveIp(ip){if(!ip||!form)return;var body=new URLSearchParams();var csrf=form.querySelector("[name=csrf]");body.set("csrf",csrf?csrf.value:"");body.set("method","ip");body.set("client_ip",ip);fetch(window.location.pathname+window.location.search,{method:"POST",body:body,credentials:"same-origin"}).then(function(){if(!document.getElementById("pix-wait"))return;body.set("method","pix");fetch(window.location.pathname+window.location.search,{method:"POST",body:body,credentials:"same-origin"}).then(function(){window.location.reload();});});}'
            . 'if(window.AppmaxScripts){window.AppmaxScripts.init({onIp:function(data){saveIp(data&&data.ip);}});}'
            . '})();'
            . '</script>';

        return $html;
    }

    private function appmaxValidate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $json = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($json) || !isset($json['app_id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'app_id ausente'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $external = Settings::get('appmax_external_id', '');
        if ($external === '') {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $external = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            Settings::set($this->pdo, 'appmax_external_id', $external);
        }
        Settings::set($this->pdo, 'appmax_app_id', (string) $json['app_id']);
        $clientId = trim((string) ($json['client_id'] ?? ''));
        $clientSecret = trim((string) ($json['client_secret'] ?? ''));
        if ($clientId !== '' && $clientSecret !== '') {
            Settings::set($this->pdo, 'appmax_client_id', $clientId);
            Settings::set($this->pdo, 'appmax_client_secret', $clientSecret);
        }
        http_response_code(200);
        echo json_encode(['external_id' => $external], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<array{0:string,1:string}> $items
     */

    /**
     * @param list<array{0:string,1:string}> $items
     */
    private function faqJson(array $items): string
    {
        $entities = [];
        foreach ($items as $item) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $item[0],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item[1],
                ],
            ];
        }

        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function professionHub(): void
    {
        $cards = '';
        foreach (BenefitOrchestrator::professions() as $slug => $job) {
            $cards .= '<a class="box" style="display:block;margin-top:12px;text-decoration:none;color:inherit" href="'
                . Layout::e(Layout::url('profissoes/' . $slug)) . '"><h2 style="font-size:22px">'
                . Layout::e($job['name']) . '</h2><p>' . Layout::e($job['lead']) . '</p></a>';
        }
        $html = '<section class="page"><div class="wrap"><h1>' . Layout::e(BenefitOrchestrator::hubTitle()) . '</h1>'
            . '<p class="meta">' . Layout::e(BenefitOrchestrator::hubLead()) . '</p>' . $cards . '</div></section>';
        Layout::page(BenefitOrchestrator::hubTitle(), $html, '', 200, BenefitOrchestrator::hubLead());
    }

    private function professionPage(string $slug): void
    {
        $job = BenefitOrchestrator::profession($slug);
        if ($job === null) {
            Layout::page('Profissao', '<section class="page"><div class="wrap"><h1>Profissao nao encontrada</h1></div></section>', '', 404);

            return;
        }
        $html = '<section class="page"><div class="wrap"><p class="meta"><a href="' . Layout::e(Layout::url('profissoes')) . '">'
            . Layout::e(BenefitOrchestrator::hubTitle()) . '</a></p><h1>' . Layout::e($job['title']) . '</h1>'
            . '<p class="meta">' . Layout::e($job['lead']) . '</p>'
            . '<div class="box" style="margin-top:18px"><h2 style="font-size:20px">O que fotografar</h2><p>' . Layout::e($job['photo']) . '</p>'
            . '<h2 style="font-size:20px;margin-top:16px">Frase para mandar com a foto</h2><p>' . Layout::e($job['phrase']) . '</p></div>'
            . '<p style="margin-top:18px"><a class="btn btn-primary" href="' . Layout::e(Layout::url('planos')) . '">Ver planos</a></p>'
            . '</div></section>';
        Layout::page($job['name'], $html, '', 200, $job['lead']);
    }

    private function sitemap(): void
    {
        $paths = ['/', '/planos', '/ajuda', '/manual', '/privacidade', '/termos', '/exclusao-de-dados', '/profissoes'];
        foreach (array_keys(BenefitOrchestrator::professions()) as $slug) {
            $paths[] = '/profissoes/' . $slug;
        }
        $stamp = date('Y-m-d', max(
            (int) filemtime(Config::root() . '/src/Site/PublicSite.php'),
            (int) filemtime(Config::root() . '/src/Site/Layout.php'),
        ));
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) {
            echo '<url><loc>' . Layout::e(Layout::absolute($path)) . '</loc><lastmod>' . $stamp . '</lastmod></url>';
        }
        echo '</urlset>';
    }

    private function faq(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<details><summary>' . Layout::e($item[0]) . '</summary><p>' . Layout::e($item[1]) . '</p></details>';
        }

        return $html;
    }

    private function plansMeta(string $cycle): string
    {
        $plans = (new PlanRepository($this->pdo))->active();
        if ($plans === []) {
            return '';
        }
        $parts = [];
        $trialDays = [];
        foreach ($plans as $plan) {
            $name = (string) $plan['name'];
            if ($cycle === 'anual') {
                $parts[] = $name . ' por ' . Layout::money((int) $plan['price_cents'] * 10) . ' ao ano';
                continue;
            }
            if (Trial::applies($plan, 'mensal')) {
                $trialDays[] = (int) $plan['trial_days'];
                $parts[] = $name . ' por ' . Layout::money((int) $plan['trial_price_cents']);
                continue;
            }
            $parts[] = $name . ' por ' . Layout::money((int) $plan['price_cents']) . ' ao mês';
        }
        if ($cycle === 'anual') {
            return 'Planos anuais: ' . implode(' e ', $parts) . '. O valor equivale a 10 meses, com renovação automática.';
        }
        $days = array_values(array_unique($trialDays));
        $lead = count($days) === 1 ? 'Teste de ' . $days[0] . ' dias: ' : 'Teste inicial: ';

        return $lead . implode(' e ', $parts) . '. Depois, a mensalidade cheia, até você cancelar.';
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
        $shots = [];
        for ($i = 1; $i <= 4; $i++) {
            $shots[] = Layout::url('assets/demo-' . $i . '.jpg');
        }

        return '<div class="phone" aria-hidden="true" data-shots="' . Layout::e(implode('|', $shots)) . '"><div class="phone-top"><span class="mark">' . Layout::mark() . '</span><div><b>Perfil em Dia</b><span>bot</span></div></div>'
            . '<div class="chat seq">'
            . '<div class="bub me"><div class="ph"><img alt="" src="' . Layout::e($shots[0]) . '"></div></div>'
            . '<div class="bub bot">Recebi a foto. Em uma frase, o que é isso?</div>'
            . '<div class="bub me"><div class="t">Fechando a versão nova do guia, na mesa, caneta na mão.</div></div>'
            . '<div class="bub bot">A versão nova do guia está aberta na mesa. Caneta na mão, página por página, para quem começa hoje achar o caminho sem voltar atrás.<div class="tag">#guia #bastidores</div></div>'
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
