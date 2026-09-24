<?php

declare(strict_types=1);

namespace PerfilEmDia;

final class Messages
{
    public static function welcome(string $name): string
    {
        if ($name === '') {
            return "Oi! Eu sou o Perfil em Dia. Você me manda a foto do serviço ou do produto, eu escrevo a legenda e posto no seu Instagram. Leva 1 minuto. Vamos configurar? Como quer que eu te chame?\n\nSe precisar, /ajuda lista os comandos.";
        }

        return "Oi, {$name}! Eu sou o Perfil em Dia. Você me manda a foto do serviço ou do produto, eu escrevo a legenda e posto no seu Instagram. Leva 1 minuto. Vamos configurar? Como quer que eu te chame?\n\nSe precisar, /ajuda lista os comandos.";
    }

    public static function welcomeBack(string $name): string
    {
        $who = $name !== '' ? $name : 'de novo';

        return "Bem-vindo de volta, {$who}. Me manda a foto com uma frase sobre ela, ou use /ajuda.";
    }

    public static function askProfession(): string
    {
        return 'O que você faz? Pode ser a profissão ou o produto. Por exemplo: eletricista, loja, guia, aplicativo.';
    }

    public static function askCity(): string
    {
        return 'Em que cidade você atende? Se for online, responda online.';
    }

    public static function askTone(): string
    {
        return 'Como você quer que suas legendas soem?';
    }

    public static function askCta(): string
    {
        return "O que entra na legenda, depois das hashtags? Ex.: 'Chama no WhatsApp (11) 99999-0000' ou o link do seu site.";
    }

    public static function askAbout(): string
    {
        return 'Em uma ou duas frases: o que você faz de melhor?';
    }

    public static function askInstagram(): string
    {
        return "Agora vamos conectar seu Instagram.\n\nSeu Instagram precisa ser conta profissional (é grátis e leva 1 minuto): Instagram, Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, escolha Empresa. Não precisa de CNPJ nem de Página do Facebook.";
    }

    public static function instagramHowTo(): string
    {
        return "1. Abra o Instagram e vá em Configurações e atividade.\n2. Toque em Tipo de conta e ferramentas.\n3. Toque em Mudar para conta profissional.\n4. Escolha Empresa. Não precisa de CNPJ nem de Página do Facebook.\nQuando terminar, toque em Já é profissional, conectar.";
    }

    public static function ready(string $name): string
    {
        $who = $name !== '' ? $name : 'Tudo pronto';

        return "{$who}! Me manda a foto com uma frase sobre ela.";
    }

    public static function repeat(): string
    {
        return 'Não entendi. Vamos repetir a pergunta.';
    }

    public static function novo(): string
    {
        return 'Me manda a foto do serviço ou do produto. Se quiser, escreva na mesma mensagem uma frase sobre ela. Para mais qualidade, envie a foto como arquivo.';
    }

    public static function ajuda(): string
    {
        return "Eu publico a foto do seu trabalho ou do seu produto no Instagram, só depois que você aprova.\n\n"
            . "/novo como mandar um post\n"
            . "/perfil ver e editar seus dados\n"
            . "/conectar ligar o Instagram\n"
            . "/status conta e limite do mês\n"
            . "/assinatura plano e validade\n"
            . "/cancelar descartar o post que está esperando\n"
            . "/start retomar de onde você parou\n"
            . "/excluirconta apagar seus dados e começar do zero\n"
            . "/chamado abrir ou ver um chamado\n/ideia ideia para o post de hoje\n/resultado alcance dos últimos dias\n/marca cor e estilo da marca\n"
            . "/ajuda esta lista\n\n"
            . "Dica: pode mandar um áudio no lugar de escrever. Mande a foto como arquivo para mais qualidade. /novo pergunta o tipo: foto única, carrossel, vídeo curto ou criado pela IA. Vídeo curto fica nos planos Profissional e Estúdio. O post criado pela IA é só do Estúdio.";
    }

    public static function status(string $ig, int $used, int $limit): string
    {
        $conta = $ig !== '' ? '@' . ltrim($ig, '@') : 'Instagram ainda não conectado';
        $rest = max(0, $limit - $used);

        return "Conta: {$conta}\nPosts neste mês: {$used} de {$limit}\nRestam {$rest}.";
    }

    public static function perfil(array $user): string
    {
        $tone = (string) ($user['tone'] ?? 'não definido');

        return "Seu perfil:\nNome: " . self::show($user['display_name'] ?? null)
            . "\nO que faz: " . self::show($user['profession'] ?? null)
            . "\nCidade: " . self::show($user['city'] ?? null)
            . "\nTom: {$tone}\nContato: " . self::show($user['contact_cta'] ?? null)
            . "\nSobre: " . self::show($user['about'] ?? null)
            . "\nMarca: " . self::show($user['brand_style'] ?? null)
            . "\n\nToque no que quiser editar. O contato entra na legenda, depois das hashtags.";
    }

    public static function received(): string
    {
        return 'Recebi! Preparando sua legenda!';
    }

    public static function askTheme(): string
    {
        return 'Legal! Me conta em uma frase o que essa foto mostra. Pode escrever ou mandar um áudio.';
    }

    public static function captionFailed(): string
    {
        return "Não consegui escrever a legenda agora. Manda a foto de novo, com a frase na mesma mensagem.";
    }

    public static function askFeedback(): string
    {
        return "O que quer mudar na legenda? Pode ser um trecho ou o texto inteiro. Ex.: 'mais curto', 'tira os emojis', 'reescreve o final'.";
    }

    public static function askManual(): string
    {
        return 'Manda o texto do jeito que você quer que saia.';
    }

    public static function cancelled(): string
    {
        return 'Cancelado. Quando quiser, é só mandar outra foto.';
    }

    public static function publishing(): string
    {
        return 'Publicando!';
    }

    public static function published(): string
    {
        return 'Tá postado!';
    }

    public static function replacePending(): string
    {
        return 'Você tem um post esperando aprovação. Quer descartar ele e começar este?';
    }

    public static function alreadyProcessed(): string
    {
        return 'Esse post já foi processado.';
    }

    public static function regenLimit(): string
    {
        return 'Chegamos no limite de versões para este post. Você pode publicar ou escrever do seu jeito.';
    }

    public static function needOnboarding(): string
    {
        return 'Antes de postar, preciso terminar de te conhecer. Vamos continuar de onde paramos.';
    }

    public static function needInstagram(): string
    {
        return 'Seu Instagram ainda não está conectado. Use /conectar.';
    }

    public static function monthLimit(int $limit): string
    {
        return "Você chegou no limite de {$limit} posts neste mês.";
    }

    public static function trialLimit(int $limit, int $days): string
    {
        return "Você chegou no limite de {$limit} posts nestes {$days} dias de teste.";
    }

    public static function trialStatus(string $ig, int $used, int $limit, int $days): string
    {
        $conta = $ig !== '' ? '@' . ltrim($ig, '@') : 'Instagram ainda não conectado';
        $rest = max(0, $limit - $used);

        return "Conta: {$conta}\nPosts no teste de {$days} dias: {$used} de {$limit}\nRestam {$rest}.";
    }

    public static function renewalRefused(string $plan, string $accountUrl = ''): string
    {
        $text = "Não consegui renovar o {$plan}. A publicação fica pausada até a cobrança passar.";
        if ($accountUrl !== '') {
            $text .= "\nAbra sua conta para pagar, trocar o cartão ou cancelar: {$accountUrl}";
        }

        return $text;
    }

    public static function periodEnded(string $plan, int $cents, int $days, string $kind, string $accountUrl = ''): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;
        $price = 'R$ ' . number_format($cents / 100, $decimals, ',', '.');
        $link = $accountUrl !== '' ? "\nPara pagar ou cancelar: {$accountUrl}" : '';
        if ($kind === 'teste') {
            return "Os {$days} dias de teste do {$plan} terminaram. Para continuar, o plano fica {$price} por mês.{$link}";
        }

        return "O período do {$plan} terminou. Para continuar, o plano fica {$price} por mês.{$link}";
    }

    public static function instagramConnected(string $username): string
    {
        return 'Conectado como @' . ltrim($username, '@') . '.';
    }

    public static function instagramDenied(): string
    {
        return 'Não consegui conectar o Instagram. Toque para tentar de novo.';
    }

    public static function tokenExpired(): string
    {
        return 'A conexão com o Instagram expirou. Toque em Reconectar Instagram.';
    }

    public static function rateLimited(): string
    {
        return 'O Instagram limitou publicações hoje. Tente amanhã.';
    }

    public static function publishRetry(): string
    {
        return 'Não consegui publicar agora. Toque em Publicar de novo em alguns minutos.';
    }

    public static function publishFailed(string $reason): string
    {
        return 'Não consegui publicar este post.
' . 'Motivo: ' . $reason;
    }

    public static function reasonMediaNotReady(): string
    {
        return 'O Instagram ainda não tinha a mídia pronta.';
    }

    public static function reasonMediaRejected(): string
    {
        return 'O Instagram recusou o arquivo.';
    }

    public static function reasonRateLimit(): string
    {
        return 'O Instagram pediu para esperar antes de publicar de novo.';
    }

    public static function reasonNetwork(): string
    {
        return 'A conexão com o Instagram falhou.';
    }

    public static function reasonInstagram(string $detail): string
    {
        return 'O Instagram respondeu: ' . $detail;
    }

    public static function reasonUnknown(): string
    {
        return 'O Instagram não aceitou a publicação.';
    }

    public static function inappropriate(): string
    {
        return 'Não posso publicar esse conteúdo.';
    }

    public static function heicUnsupported(): string
    {
        return 'Não consegui ler essa foto. Manda em JPG ou PNG.';
    }

    public static function carouselOverflow(): string
    {
        return 'O Instagram aceita até 10 fotos. As extras foram ignoradas.';
    }

    public static function deleteConfirm(): string
    {
        return 'Isso apaga seu perfil, a conexão do Instagram, os posts e as fotos guardadas aqui. Confirma?';
    }

    public static function deleted(): string
    {
        return 'Pronto. Seus dados foram apagados.';
    }

    public static function callbackHelp(): string
    {
        return 'Use os botões da última mensagem.';
    }

    public static function activated(): string
    {
        return 'Código aceito. Sua assinatura está ligada a esta conversa. Vamos seguir a configuração.';
    }

    public static function activationInvalid(): string
    {
        return 'Não encontrei esse código. Confira na página pronta do site e envie de novo, começando com PD.';
    }

    public static function noSubscription(string $plansUrl): string
    {
        return "Ainda não há assinatura nesta conversa. Se você já pagou, envie o código PD da página pronta. Planos: {$plansUrl}";
    }

    public static function subscription(string $plan, string $cycle, string $status, ?string $until, string $accountUrl = '', string $note = ''): string
    {
        $when = $until !== null && $until !== '' ? "\nVálido até {$until}." : '';
        $extra = $note !== '' ? "\n{$note}" : '';
        $link = $accountUrl !== ''
            ? "\nAbra sua conta para ver pagamentos, trocar o cartão ou cancelar: {$accountUrl}"
            : "\nRenova sozinho até você cancelar. O período já pago segue até o fim.";

        return "Assinatura: {$plan} ({$cycle}).\nSituação: {$status}.{$when}{$extra}{$link}";
    }




    public static function askSchedule(): string
    {
        return 'Quando quer publicar?';
    }

    public static function askScheduleText(): string
    {
        return 'Pode ser só a hora, ou o dia e a hora. Exemplo: 18h30, amanhã 10h, ou 12/10 18:30.';
    }

    public static function schedulePast(): string
    {
        return 'Esse horário já passou. Escolha outro.';
    }

    public static function scheduleFar(): string
    {
        return 'Dá para agendar até 30 dias. Escolha uma data mais perto.';
    }

    public static function scheduleInvalid(): string
    {
        return 'Não entendi o horário. Exemplo: amanhã 10h, ou 12/10 18:30.';
    }

    public static function scheduled(string $when): string
    {
        return sprintf('Agendado para %s. Vou publicar nesse horário. Para mudar a foto ou a legenda, cancele o agendamento.', $when);
    }


    public static function stillScheduled(string $when): string
    {
        return sprintf('Continua agendado para %s. O menu segue aqui até publicar.', $when);
    }

    public static function scheduleCancelled(): string
    {
        return 'Tirei o agendamento. A prévia segue valendo.';
    }

    public static function askMarkPlace(): string
    {
        return 'Onde quer a marca? Ela entra redonda, com uma borda, como no Instagram.';
    }

    public static function markPlan(): string
    {
        return 'A marca d\'água faz parte dos planos Profissional e Estúdio.';
    }

    public static function markNeedsPhoto(): string
    {
        return 'A marca entra na foto. Este post é um vídeo.';
    }

    public static function markNeedsInstagram(): string
    {
        return 'Conecte o Instagram para usar a foto do perfil como marca.';
    }

    public static function markFailed(): string
    {
        return 'Não consegui buscar a foto do perfil no Instagram. Tente de novo daqui a pouco.';
    }

    public static function askPhotoPhrase(): string
    {
        return 'Qual texto quer na foto? Pode ser curto.
Exemplo: Novidade do dia!';
    }

    public static function askPhotoEdit(bool $album): string
    {
        $extra = $album ? " No \u{00e1}lbum, trato s\u{00f3} a primeira foto." : '';

        return "Descreva o tratamento. Se quiser uma frase em cima da foto, coloque ela entre aspas.\nExemplo: mais luz.{$extra}";
    }

    public static function photoEditing(): string
    {
        return 'Tratando a foto...';
    }

    public static function photoEditFailed(): string
    {
        return "N\u{00e3}o consegui tratar essa foto. Mande o pedido de novo, em uma frase.";
    }

    public static function photoEditPlan(): string
    {
        return "Tratar a foto, com frase em cima, faz parte dos planos Profissional e Estúdio.";
    }

    public static function photoEditLimit(): string
    {
        return 'Este post j\u{00e1} usou os tratamentos da foto. A legenda ainda pode mudar.';
    }

    public static function ticketList(string $lines): string
    {
        if ($lines === '') {
            return "Você ainda não tem chamado.\n\nToque em Abrir chamado e conte o problema em uma mensagem. A resposta chega aqui.";
        }

        return "Seus chamados:\n\n{$lines}\n\nToque em um chamado aberto ou em Abrir chamado.";
    }

    public static function ticketAskNew(): string
    {
        return 'Escreva o problema em uma mensagem. Pode ser curto.';
    }

    public static function ticketAskReply(int $id): string
    {
        return "Escreva a mensagem para o chamado #{$id}.";
    }

    public static function ticketNeedText(): string
    {
        return 'Para o chamado, escreva em texto. A foto continua valendo para o post, fora daqui.';
    }

    public static function ticketOpened(int $id): string
    {
        return "Chamado #{$id} aberto. Status: Em análise.\n\nQuando eu responder, a mensagem chega aqui. Para ver ou escrever de novo, envie /chamado.";
    }

    public static function ticketNoted(int $id): string
    {
        return "Mensagem adicionada ao chamado #{$id}. Eu aviso aqui quando houver resposta.";
    }

    public static function ticketClosed(int $id): string
    {
        return "O chamado #{$id} está encerrado. Se ainda precisar, abra outro.";
    }

    public static function ticketView(int $id, string $label, string $thread): string
    {
        return "Chamado #{$id}\nStatus: {$label}\n\n{$thread}";
    }

    public static function ticketReply(int $id, string $label, string $body): string
    {
        return "Resposta no chamado #{$id} ({$label}):\n\n{$body}\n\nPara continuar, envie /chamado.";
    }

    public static function ticketClosedNotice(int $id): string
    {
        return "Chamado #{$id} encerrado.\n\nSe ainda precisar, envie /chamado e abra outro.";
    }

    public static function ticketMissing(): string
    {
        return 'Não encontrei esse chamado. Envie /chamado para ver os seus.';
    }

    public static function ticketDraftCancelled(): string
    {
        return 'Ok, não abri o chamado.';
    }



    public static function askPostKind(): string
    {
        return 'O que você quer postar?';
    }

    public static function kindFoto(): string
    {
        return 'Manda uma foto e, na mesma mensagem, uma frase sobre ela.';
    }

    public static function kindAlbum(): string
    {
        return 'Manda o álbum, com as fotos juntas, e uma frase na mesma mensagem.';
    }

    public static function kindVideo(): string
    {
        return 'Manda um vídeo de 3 a 90 segundos e, na mesma mensagem, uma frase.';
    }

    public static function kindIa(): string
    {
        return 'Manda a ideia em uma mensagem. Esse texto é o pedido: a imagem e a legenda saem a partir dele. Se quiser, manda uma foto junto, só como referência.';
    }

    public static function kindIaNeedText(): string
    {
        return 'Para criar pela IA, escreva a ideia. A foto, se for junto, é só referência.';
    }

    public static function kindUseAlbum(): string
    {
        return 'Você escolheu foto única. Para várias fotos, escolha Carrossel.';
    }

    public static function kindNotVideo(): string
    {
        return 'Esse envio é um vídeo. Escolha Vídeo curto, ou mande uma foto.';
    }

    public static function aiPlan(): string
    {
        return 'Post criado pela IA faz parte do plano Estúdio.';
    }

    public static function ideaImageLimit(): string
    {
        return 'A foto criada pela IA deste post já teve as duas versões novas. A legenda ainda pode mudar.';
    }

    public static function ideaImageRetryFailed(): string
    {
        return 'Não consegui criar outra foto agora. A legenda segue para uma nova versão.';
    }

    public static function ideaFailed(): string
    {
        return 'Não consegui criar essa imagem agora. Manda a ideia de novo.';
    }

    public static function videoPlan(): string
    {
        return 'Vídeo curto faz parte do plano Profissional.';
    }

    public static function videoTooLong(): string
    {
        return 'Esse vídeo passa de 90 segundos. Manda um trecho mais curto.';
    }

    public static function videoTooShort(): string
    {
        return 'O Instagram pede um vídeo de pelo menos 3 segundos.';
    }

    public static function videoTooBig(): string
    {
        return 'Esse vídeo é grande demais. Manda um de até 20 MB.';
    }

    public static function videoDurationUnknown(): string
    {
        return 'Não consegui ver a duração desse vídeo. Manda ele pela galeria, não como arquivo.';
    }

    public static function videoAlone(): string
    {
        return 'O vídeo vai sozinho. Manda o clipe em uma mensagem, com a frase.';
    }

    public static function videoProcessing(): string
    {
        return 'O Instagram ainda está preparando o vídeo. Eu aviso quando publicar.';
    }

    public static function ticketDraftDropped(): string
    {
        return 'Ok, a mensagem não foi enviada.';
    }


    public static function askBrand(): string
    {
        return 'Qual é a cor e o estilo da marca? Ex.: verde e bege, visual limpo.';
    }

    public static function storyNeedsOne(): string
    {
        return 'O story aceita uma foto ou um vídeo. O carrossel continua no feed.';
    }

    public static function ideaDailyOn(): string
    {
        return 'Certo. Uma ideia chega por dia, neste chat.';
    }

    public static function ideaDailyOff(): string
    {
        return 'Parei as ideias do dia. Quando quiser uma, envie /ideia.';
    }

    public static function reportDenied(): string
    {
        return 'O Instagram ainda não libera o relatório nesta conexão. O alcance aparece quando a permissão estiver ativa.';
    }

    public static function reportFailed(): string
    {
        return 'Não consegui buscar o resultado agora. Tente de novo daqui a pouco.';
    }

    public static function audioTooLong(): string
    {
        return 'Esse áudio passou de 3 minutos, ou não deu para medir a duração. Manda um mais curto, ou escreve.';
    }

    public static function audioFailed(): string
    {
        return 'Não consegui entender esse áudio agora. Tenta de novo, ou escreve.';
    }

    public static function audioEmpty(): string
    {
        return 'Não ouvi fala nesse áudio. Tenta de novo, ou escreve.';
    }

    public static function audioHeard(string $text): string
    {
        return 'Ouvi: ' . $text;
    }

    private static function show(mixed $value): string
    {
        $text = trim((string) $value);

        return $text === '' ? 'não informado' : $text;
    }
}
