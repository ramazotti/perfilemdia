<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

final class Guides
{
    /**
     * @return list<array{id:string,title:string,body:string,when:string}>
     */
    public static function clientSteps(): array
    {
        return [
            [
                'id' => 'pagar',
                'title' => 'Escolha o plano e pague',
                'body' => 'Em Planos, escolha mensal ou anual. O anual cobra o valor de 10 meses. Informe nome, e-mail, o celular que você usa no WhatsApp ou no Telegram, e CPF ou CNPJ. No mensal, a primeira compra é um teste de poucos dias, com menos posts. No fim do teste a mensalidade entra sozinha, e segue renovando até o cancelamento. No Pix, a página só libera o código quando o banco confirma. No cartão, a confirmação é na hora. Se houver um cupom, ele entra nesse passo e vale conforme a quantidade e a validade cadastradas.',
                'when' => 'A página mostra um código que começa com PD e um botão para abrir o Telegram.',
            ],
            [
                'id' => 'telegram',
                'title' => 'Abra o bot com esse código',
                'body' => 'Toque em Abrir o bot. O Telegram abre a conversa já com o código. Se o aplicativo não abrir, copie o código e envie para @PerfilEmDiaBot, exatamente como está na tela.',
                'when' => 'O bot responde e pergunta como você quer ser chamado.',
            ],
            [
                'id' => 'perfil',
                'title' => 'Conte quem você é',
                'body' => 'Responda nome, profissão, cidade e o tom da legenda. Depois, como o cliente fala com você e o que você faz de melhor. Para corrigir qualquer resposta mais tarde, envie /perfil e toque no campo.',
                'when' => 'O bot pede para conectar o Instagram.',
            ],
            [
                'id' => 'profissional',
                'title' => 'Passe o Instagram para conta profissional',
                'body' => 'É grátis e não pede CNPJ. No Instagram, toque na sua foto, abra o menu, Configurações, Tipo de conta e ferramentas, Mudar para conta profissional, e escolha Empresa.',
                'when' => 'O Instagram mostra a conta como profissional ou comercial.',
            ],
            [
                'id' => 'conectar',
                'title' => 'Autorize a publicação',
                'body' => 'No bot, toque em Conectar Instagram e entre na conta certa. Se a página disser que a conta ainda é pessoal, volte ao passo anterior. Se o link expirar ou você cancelar, envie /conectar e peça um link novo.',
                'when' => 'A página diz que o Instagram conectou e o bot confirma o @.',
            ],
            [
                'id' => 'foto',
                'title' => 'Mande a foto e uma frase',
                'body' => 'Envie uma foto real do serviço, ou um álbum de até 10 fotos. Na mesma mensagem, uma frase curta: o que foi feito e onde. A legenda só pode usar o que está na foto e nessa frase.',
                'when' => 'Chega uma prévia com a legenda, as hashtags e os botões Publicar, Ajustar e Outra versão.',
            ],
            [
                'id' => 'aprovar',
                'title' => 'Publique só se a prévia estiver boa',
                'body' => 'Publicar manda para o Instagram. Ajustar serve para dizer o que mudar. Outra versão pede um texto novo. Você também pode escrever a legenda do seu jeito. Nada sai sem esse toque.',
                'when' => 'O bot devolve o link do post publicado.',
            ],
            [
                'id' => 'sozinho',
                'title' => 'Se travar, resolva na conversa',
                'body' => 'Conexão expirada: /conectar. Foto cortada: o Instagram só aceita do retrato 4:5 ao paisagem 1,91:1, e o corte é no centro. Limite do mês: /status. Cancelar a assinatura: /assinatura. Apagar os dados: /excluirconta, ou a página Exclusão de dados, que gera um protocolo.',
                'when' => 'O bot confirma a ação. Se a dúvida for de plano ou de pagamento, /assinatura mostra o estado atual.',
            ],
        ];
    }

    /**
     * @return list<array{id:string,title:string,body:string}>
     */
    public static function adminChapters(): array
    {
        return [
            [
                'id' => 'painel',
                'title' => 'Painel',
                'body' => 'Os cartões mostram clientes, quem pagou e ainda não abriu o bot, o valor recebido no mês e posts com falha. O gráfico são os posts dos últimos 14 dias. Conexão vencendo significa que o Instagram daquela pessoa expira em até 10 dias: ela reconecta com /conectar. Publicação com falha não vira post publicado sozinha.',
            ],
            [
                'id' => 'clientes',
                'title' => 'Clientes',
                'body' => 'Aguardando ativação: pagou e ainda não enviou o código no Telegram. Ativo: o código já ligou o pagamento à conversa. Busque por nome, e-mail ou documento. No detalhe, Bloquear impede o uso. O link de conexão abre a página que o cliente usa no /conectar. Mudar plano vale para a assinatura ativa e não reabre o checkout. Cancelar assinatura encerra o período seguinte. Excluir dados apaga perfil, fotos e Instagram, e guarda o pagamento sem o nome.',
            ],
            [
                'id' => 'posts',
                'title' => 'Posts',
                'body' => 'Cada linha é uma tentativa do bot. Filtre por FAILED para ver o que não publicou. O cliente tenta de novo no Telegram, com /novo ou outra foto. Esta lista não tem botão de publicar no lugar dele.',
            ],
            [
                'id' => 'pagamentos',
                'title' => 'Pagamentos',
                'body' => 'Entram valor, status, meio (Pix, cartão ou cupom), bandeira e os 4 últimos dígitos. O número completo do cartão não é pedido de volta e não fica no banco. Pendente no Pix espera o webhook do banco. No sandbox local, o botão Confirmar no sandbox simula esse aviso.',
            ],
            [
                'id' => 'planos',
                'title' => 'Planos',
                'body' => 'Nome, preço mensal, preço do teste, dias de teste, limite de posts e a lista de itens saem na página pública assim que você salva. Os posts do teste são a fração desses dias em um mês de 30, arredondada. O anual continua sendo 10 vezes o mensal, sem teste. Quem já assinou permanece no preço que estava no checkout.',
            ],
            [
                'id' => 'cupons',
                'title' => 'Cupons',
                'body' => 'Cadastre o código, o desconto, a quantidade de usos e a data de validade. Quantidade vazia não tem limite. Data vazia não vence. Cada checkout que aplica o cupom conta um uso, mesmo se a pessoa não terminar o pagamento. Só na mensalidade deixa o anual de fora. Desmarque Ativo para o código parar de valer.',
            ],
            [
                'id' => 'ia',
                'title' => 'Uso de IA',
                'body' => 'Soma os tokens do mês e estima o custo com o preço por milhão e o câmbio definidos em Configurações. A relação por cliente só aparece depois que a pessoa ativou o código e passou a gerar legenda. A chave do OpenRouter não é mostrada.',
            ],
            [
                'id' => 'eventos',
                'title' => 'Erros e eventos',
                'body' => 'Histórico curto do que o sistema registrou, inclusive cliente_excluido. Filtre pelo tipo quando alguém disser que um dado sumiu ou que um post falhou.',
            ],
            [
                'id' => 'config',
                'title' => 'Configurações',
                'body' => 'E-mail de suporte, versões por post, horas da foto pública, modelo da IA e o prompt. Os cupons ficam na tela Cupons. O modelo em branco usa o do servidor (OpenRouter). Integrações dizem só se a chave existe. Telegram, Instagram, OpenRouter e o gateway de pagamento são gravados no .env do servidor, nunca nesta tela.',
            ],
            [
                'id' => 'antes',
                'title' => 'Antes de ligar as contas externas',
                'body' => 'Dá para revisar o site, o checkout sandbox e estes manuais sem chave nenhuma. Para um cliente real, ainda faltam: os nameservers do domínio apontando para a hospedagem; o token do bot, o webhook e os comandos; o app do Instagram com a URL de retorno; a chave e o webhook do Asaas, se o Pix e o cartão forem de verdade; a chave do OpenRouter no .env de produção; a razão social, o CNPJ e a revisão jurídica dos textos; e um admin criado com php bin/admin.php create-admin, sem senha de demonstração.',
            ],
        ];
    }

    /**
     * @param list<array{id:string,title:string,body:string,when?:string}> $steps
     */
    public static function steps(array $steps, bool $numbered = true): string
    {
        $html = '<div class="guide">';
        $n = 1;
        foreach ($steps as $step) {
            $html .= '<article id="' . Layout::e($step['id']) . '">';
            if ($numbered) {
                $html .= '<span class="n">' . $n . '</span>';
            }
            $html .= '<h2>' . Layout::e($step['title']) . '</h2>';
            $html .= '<p>' . Layout::e($step['body']) . '</p>';
            if (!empty($step['when'])) {
                $html .= '<p class="when">Deu certo quando: ' . Layout::e($step['when']) . '</p>';
            }
            $html .= '</article>';
            $n++;
        }

        return $html . '</div>';
    }

    /**
     * @param list<array{id:string,title:string}> $steps
     */
    public static function toc(array $steps, string $prefix = ''): string
    {
        $html = '<nav class="toc" aria-label="Neste manual">';
        foreach ($steps as $step) {
            $html .= '<a href="' . Layout::e($prefix . '#' . $step['id']) . '">' . Layout::e($step['title']) . '</a>';
        }

        return $html . '</nav>';
    }
}
