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
                'body' => 'Responda como quer ser chamado, o que você faz (a profissão ou o produto), onde atende e o tom da legenda. Depois, o contato que entra na legenda, depois das hashtags, e o que você faz de melhor. Para corrigir qualquer resposta mais tarde, envie /perfil e toque no campo.',
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
                'title' => 'Escolha o tipo do post',
                'body' => 'No bot, envie /novo ou mande o material. O bot pergunta o tipo: foto única, carrossel, vídeo curto ou criado pela IA. Na foto única, mande uma foto e uma frase na mesma mensagem. No carrossel, mande as fotos juntas, até 10, e uma frase. O vídeo curto tem de 3 a 90 segundos, até 20 MB, sozinho, com uma frase, nos planos Profissional e Estúdio. Criado pela IA é só do plano Estúdio: o texto é a ideia, e a imagem e a legenda saem a partir dele. Uma foto junto, se quiser, é só referência. Se o arquivo chegar antes da escolha, o bot pergunta o tipo e você envia de novo. A legenda usa o material, a frase e o contato do perfil. Esse contato entra depois das hashtags.',
                'when' => 'O bot mostra os botões do tipo. Depois da escolha, chega a prévia com a legenda, as hashtags e os botões Publicar, Ajustar e Outra versão.',
            ],
            [
                'id' => 'aprovar',
                'title' => 'Publique só se a prévia estiver boa',
                'body' => 'Publicar manda para o Instagram. Ajustar serve para dizer o que mudar. Outra versão pede um texto novo. Você também pode escrever a legenda do seu jeito. Nada sai sem esse toque. Nos planos Profissional e Estúdio, Texto na foto escreve uma frase curta em cima da imagem. Marca d\'água coloca a foto do Instagram em um círculo com borda, no canto que você escolher. Agendar marca o dia e a hora, e o bot publica sozinho. Dá para escolher um horário pronto ou escrever só a hora, ou o dia e a hora. O menu da prévia segue até o post sair. Tratar foto muda a imagem com IA, e a frase entre aspas também entra em cima da foto. O post criado pela IA segue a mesma regra: só publica quando você toca em Publicar, ou na hora agendada.',
                'when' => 'O bot devolve o link do post publicado.',
            ],
            [
                'id' => 'sozinho',
                'title' => 'Se travar, resolva na conversa',
                'body' => 'Conexão expirada: /conectar. Foto cortada: o Instagram só aceita do retrato 4:5 ao paisagem 1,91:1, e o corte é no centro. Limite do mês: /status. Cancelar a assinatura, trocar o cartão ou ver os pagamentos: /assinatura, no botão Minha conta. Apagar os dados: /excluirconta, ou a página Exclusão de dados, que gera um protocolo. Se nada disso resolver, envie /chamado e descreva o problema. A resposta chega na mesma conversa.',
                'when' => 'O bot confirma a ação. Se a dúvida for de plano ou de pagamento, /assinatura abre a página da conta. Se o bot perguntar o tipo de novo, envie /novo e escolha outra vez.',
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
                'body' => 'A lista mostra o plano, o ciclo, a vigência, o Instagram e o celular. Aguardando ativação: pagou e ainda não enviou o código no Telegram. Ativo: o código já ligou o pagamento à conversa. Busque por nome, e-mail ou documento. No detalhe, Bloquear marca o cliente como cancelado. A assinatura segue ativa e o bot continua publicando. Para cortar o uso, cancele a assinatura. O link de conexão abre a página que o cliente usa no /conectar. Mudar plano troca o plano na hora, sem abrir outro checkout. O preço cobrado continua o do checkout. O limite de posts da assinatura muda na renovação. Cancelar assinatura encerra agora: a assinatura fica cancelada e o cliente, cancelado. Quem cancela sozinho, em Minha conta, segue até o fim do período já pago. Mesmo cancelada no painel, o bot ainda aceita post até 30 no mês. Excluir dados apaga perfil, fotos e Instagram, e guarda o pagamento sem o nome. Isentar para sempre, ou até uma data, libera o plano escolhido no formulário, sem cobrança, e o limite daquele plano vale na hora. Tirar a isenção marca a cobrança para o dia seguinte. Sem cartão salvo, essa cobrança não completa.',
            ],
            [
                'id' => 'chamados',
                'title' => 'Chamados',
                'body' => 'A pessoa abre pelo comando /chamado no Telegram e descreve o problema em uma mensagem. O chamado entra em Em análise. Responder muda para Em andamento e a resposta chega na conversa do bot. Encerrado fecha. Se você responder um chamado encerrado, ele volta para Em andamento. Apagar a conta da pessoa apaga os chamados dela.',
            ],
            [
                'id' => 'posts',
                'title' => 'Posts',
                'body' => 'A lista mostra o cliente e o @ do Instagram, com link para o perfil. Cada linha é uma tentativa do bot. Filtre por Falhou para ver o que não publicou. O cliente tenta de novo no Telegram. Se a publicação falhar, o bot mostra o motivo e o botão Tentar de novo. Post agendado sai na hora marcada, sem um novo toque. Esta lista não tem botão de publicar no lugar dele. Foto, carrossel, vídeo curto e post criado pela IA entram nesta lista. O cliente escolhe o tipo no Telegram, com /novo.',
            ],
            [
                'id' => 'pagamentos',
                'title' => 'Pagamentos',
                'body' => 'Cada linha mostra o cliente, com nome, e-mail e documento. O nome abre a ficha. Entram valor, status, meio (Pix, cartão ou cupom), bandeira e os 4 últimos dígitos. O número completo do cartão não é pedido de volta e não fica no banco. Pendente no Pix espera o webhook do banco. No sandbox local, o botão Confirmar no sandbox simula esse aviso.',
            ],
            [
                'id' => 'planos',
                'title' => 'Planos',
                'body' => 'Nome, preço mensal, preço do teste, dias de teste, limite de posts e a lista de itens saem na página pública assim que você salva. Os posts do teste são a fração desses dias em um mês de 30, arredondada. O anual continua sendo 10 vezes o mensal, sem teste. Quem já assinou permanece no preço que estava no checkout. Estúdio é o plano com post criado pela IA. Vídeo curto e tratamento da foto ficam no Profissional e no Estúdio. Mudar o texto do item não muda essa regra: ela segue o plano.',
            ],
            [
                'id' => 'cupons',
                'title' => 'Cupons',
                'body' => 'Cadastre o código, o desconto, a quantidade de usos e a data de validade. Quantidade vazia não tem limite. Data vazia não vence. Cada checkout que aplica o cupom conta um uso, mesmo se a pessoa não terminar o pagamento. Só na mensalidade deixa o anual de fora. Desmarque Ativo para o código parar de valer.',
            ],
            [
                'id' => 'ia',
                'title' => 'Uso de IA',
                'body' => 'O custo do mês, do dia e o total vêm da chave no OpenRouter. As tabelas contam os tokens das legendas gravados aqui. A imagem do post criado pela IA entra no custo da chave e não entra nessa contagem. A chave não aparece nesta tela.',
            ],
            [
                'id' => 'eventos',
                'title' => 'Erros e eventos',
                'body' => 'A lista mostra o cliente e o @ do Instagram. Histórico curto do que o sistema registrou, inclusive cliente_excluido. Filtre pelo tipo quando alguém disser que um dado sumiu ou que um post falhou.',
            ],
            [
                'id' => 'config',
                'title' => 'Configurações',
                'body' => 'E-mail de suporte, versões por post, horas da foto pública, modelo da IA e o prompt. Os cupons ficam na tela Cupons. O modelo em branco usa o do servidor (OpenRouter). Integrações dizem só se a chave existe. Telegram, Instagram, OpenRouter e o gateway de pagamento são gravados no .env do servidor, nunca nesta tela.',
            ],
            [
                'id' => 'antes',
                'title' => 'O que fica fora do painel',
                'body' => 'O site, o bot, o Instagram, a AppMax e o OpenRouter já estão em uso. Em Configurações, Integrações mostra se a chave existe, sem revelar o valor. Nameservers, token do bot, webhook, URL de retorno do Instagram, token da AppMax e chave do OpenRouter ficam no .env do servidor. Razão social, CNPJ e a revisão jurídica dos textos continuam fora deste painel. Um admin novo se cria com php bin/admin.php create-admin.',
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
