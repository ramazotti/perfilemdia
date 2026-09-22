<?php

declare(strict_types=1);

namespace PerfilEmDia;

final class Messages
{
    public static function welcome(string $name): string
    {
        if ($name === '') {
            return 'Oi! Eu sou o Perfil em Dia. Você me manda a foto do seu trabalho, eu escrevo a legenda e posto no seu Instagram. Leva 1 minuto. Vamos configurar? Como quer que eu te chame?';
        }

        return "Oi, {$name}! Eu sou o Perfil em Dia. Você me manda a foto do seu trabalho, eu escrevo a legenda e posto no seu Instagram. Leva 1 minuto. Vamos configurar? Como quer que eu te chame?";
    }

    public static function welcomeBack(string $name): string
    {
        $who = $name !== '' ? $name : 'de novo';

        return "Bem-vindo de volta, {$who}. Me manda a foto de um trabalho com uma frase sobre ele, ou use /ajuda.";
    }

    public static function askProfession(): string
    {
        return 'Qual é a sua profissão? Por exemplo: personal trainer, eletricista, cabeleireira.';
    }

    public static function askCity(): string
    {
        return 'Em que cidade ou bairro você atende?';
    }

    public static function askTone(): string
    {
        return 'Como você quer que suas legendas soem?';
    }

    public static function askCta(): string
    {
        return "Como o cliente fala com você? Ex.: 'Chama no WhatsApp (11) 99999-0000' ou 'Link na bio'.";
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

        return "{$who}! Me manda a foto de um trabalho com uma frase sobre ele.";
    }

    public static function repeat(): string
    {
        return 'Não entendi. Vamos repetir a pergunta.';
    }

    public static function novo(): string
    {
        return 'Me manda a foto do trabalho. Se quiser, escreva na mesma mensagem uma frase sobre o que foi feito. Para mais qualidade, envie a foto como arquivo.';
    }

    public static function ajuda(): string
    {
        return "Eu publico a foto real do seu trabalho no Instagram.\n\n/novo explica como mandar um post\n/perfil mostra e edita seus dados\n/conectar liga o Instagram\n/status mostra a conta e o limite do mês\n/assinatura mostra o plano e a validade\n/cancelar descarta o post que está esperando\n/excluirconta apaga seus dados\n\nDica: mande a foto como arquivo para mais qualidade.";
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
            . "\nProfissão: " . self::show($user['profession'] ?? null)
            . "\nCidade: " . self::show($user['city'] ?? null)
            . "\nTom: {$tone}\nContato: " . self::show($user['contact_cta'] ?? null)
            . "\nSobre: " . self::show($user['about'] ?? null)
            . "\n\nToque no que quiser editar.";
    }

    public static function received(): string
    {
        return 'Recebi! Preparando sua legenda?';
    }

    public static function askTheme(): string
    {
        return 'Legal! Me conta em uma frase o que foi esse trabalho.';
    }

    public static function askFeedback(): string
    {
        return "O que você quer mudar? Ex.: 'mais curto', 'tira os emojis', 'fala que foi em Moema'.";
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
        return 'Publicando?';
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

    public static function renewalRefused(string $plan): string
    {
        return "Não consegui renovar o {$plan}. A publicação fica pausada até a cobrança passar.";
    }

    public static function periodEnded(string $plan, int $cents, int $days, string $kind): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;
        $price = 'R$ ' . number_format($cents / 100, $decimals, ',', '.');
        if ($kind === 'teste') {
            return "Os {$days} dias de teste do {$plan} terminaram. Para continuar, o plano fica {$price} por mês.";
        }

        return "O período do {$plan} terminou. Para continuar, o plano fica {$price} por mês.";
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

    public static function publishFailed(): string
    {
        return 'Não consegui publicar este post. Quando quiser, mande outra foto.';
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

    public static function subscription(string $plan, string $cycle, string $status, ?string $until): string
    {
        $when = $until !== null && $until !== '' ? "\nVálido até {$until}." : '';

        return "Assinatura: {$plan} ({$cycle}).\nSituação: {$status}.{$when}\nRenova sozinho até você cancelar. O período já pago segue até o fim.";
    }

    private static function show(mixed $value): string
    {
        $text = trim((string) $value);

        return $text === '' ? 'não informado' : $text;
    }
}
