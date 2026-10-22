# Prompt para agente revisor: rota completa de geração (Feed e Story)

Copie tudo abaixo (a partir de "---INÍCIO DO PROMPT---") e cole no outro agente.

---INÍCIO DO PROMPT---

## Papel

Você é um revisor sênior de produto + engenharia, especializado em fluxos conversacionais (Telegram), geração multimodal (texto + imagem) e publicação no Instagram (feed e story). Sua tarefa **não** é implementar código agora: é **auditar** se a rota atual de geração de posts no projeto **Perfil em Dia** está correta para o **usuário final** e para a **qualidade da geração**, cobrindo **cada escolha** do funil.

Responda em **português (Brasil)**. Seja específico: cite arquivos, métodos, callbacks e mensagens. Proponha melhorias priorizadas (P0 bloqueio, P1 confusão/qualidade, P2 polish).

## Repositório

- Caminho local de referência: `/Users/evand/Projects/docker-local/public_html/perfilemdia`
- Stack: PHP, Telegram (`UpdateHandler`, `PostService`), OpenRouter (chat + imagem), Instagram API
- Entrada do usuário: `/novo` no Telegram (portal web usa `PortalOrchestrator.php`, verificar paridade)

## Funil esperado (ordem ideal para o usuário)

Documente o que o código **realmente** faz e compare com este funil alvo:

1. **Destino:** Feed (4:5) ou Story (9:16) — callbacks `wh:feed` / `wh:story` → `PostService::chooseWhere()`
2. **Tipo de mídia:** `pk:*` → `choosePostKind()`  
   - Feed: foto, carrossel, vídeo, IA, vídeo IA  
   - Story: foto, vídeo, IA (sem carrossel no menu story)
3. **Formato visual (somente quando o tipo é IA):** menu `fm:<key>` → `chooseIdeaFormat()` / `Keyboards::ideaFormatPick()`  
   Opções (`IdeaPieces::formatChoices()`):
   - `photo` — Foto simples (sem texto na arte)
   - `infographic` — Infográfico (sequência; 3 slides feed / 3 telas story)
   - `quote` — Citação
   - `checklist` — Checklist
   - `compare` — Comparativo
   - `before_after` — Antes e depois
   - `steps` — Passo a passo
   - `steps3` — Passo a passo (3 passos)
4. **Conteúdo:** texto, foto de referência, Surpreenda-me (`pk:surpresa` após formato), escolha foto própria vs IA (`photoask:`)
5. **Geração:** imagem(ns) + legenda → prévia → aprovação → publicação/agendamento

Estado em `users.pending_action` (ex.: `where:feed`, `kind:ia|fmt:quote`, `kind:ia@story|fmt:quote`, `photoask:<id>`, `surprise:wait`).

## Arquivos que você DEVE ler (mínimo)

| Área | Arquivos |
|------|----------|
| Orquestração Telegram | `src/Telegram/UpdateHandler.php`, `src/Telegram/Keyboards.php`, `src/Messages.php` |
| Domínio do fluxo | `src/Domain/PostService.php` (handleIncomingMedia, chooseWhere/Format/PostKind, startIdea, surprise*, finishPublish, publishStorySet, replyWhenIdle, handleReplaceDecision) |
| Formatos e prompts de imagem | `src/Image/IdeaPieces.php`, `src/Image/IdeaImage.php`, `src/Image/IdeaFormatBrief.php` |
| Surpreenda-me (texto) | `src/Ai/SurpriseBriefGenerator.php`, `src/Growth/BenefitOrchestrator.php`, `src/Domain/SurpriseDraft.php` |
| Legenda | `src/Ai/CaptionGenerator.php` (ou interface usada por PostService) |
| Portal | `src/Site/PortalOrchestrator.php`, `public/assets/portal-studio.js` |
| Testes | `tests/IdeaImagePromptTest.php`, `tests/BenefitSurpriseProfileTest.php`, `tests/SurpriseBriefGeneratorTest.php`, `tests/PostServiceTest.php`, `tests/StoryIdeaDryRunTest.php` |

## Matriz de validação (cada escolha × critérios)

Para **cada combinação relevante** destino × formato × tipo de mídia, preencha mentalmente e no relatório:

### A. Experiência do usuário (UX)

- Mensagens batem com a ação? (ex.: "Estou montando a imagem" vs "Recebi! Preparando sua legenda")
- O usuário precisa responder algo quando não deveria?
- Botões antigos ("Ver formatos") confundem após avançar?
- `/cancelar` limpa stash, surprise draft e pending_action?
- Post "fantasma" em `AWAITING_APPROVAL` dispara diálogo "descartar anterior" sem prévia visível?
- Fluxo IA tem prioridade sobre rascunho `newpost:` quando `kind:ia`?

### B. Qualidade da geração (IA)

- **Formato × prompt de imagem:** `IdeaPieces::designedPrompt()` / `photoPrompt()` refletem o formato escolhido?
- **Formato × Surpreenda-me:** `SurpriseBriefGenerator` + `IdeaFormatBrief` pedem conteúdo do nicho (ex.: perfil espiritual → citação bíblica com `Texto na imagem:` e `Crédito:`)?
- **Foto de referência:** instrução exige **imagem nova** inspirada na referência, não cópia? (`photoPrompt` com referência)
- **Foto simples + IA:** ainda faz sentido vs infográfico/citação para pedidos longos ou homenagens?
- **Aspect ratio:** feed 4:5 vs story 9:16 em `requestOptions`, peças (`IdeaPieces::pieces`), limites STORY_CAP/FEED_CAP
- **Legenda:** usa `theme_text`, perfil (about, profession, tone, brand_style), destino story vs feed; não inventa indevidamente em vídeo

### C. Consistência de estado

- `chosenFormatKey()`, `chosenDestination()`, `selectedKind()` parseiam sufixo `|fmt:` e `@story`?
- `startIdea` limpa pending mas preserva formato até aplicar cue?
- Surpreenda-me guarda `format` em `SurpriseDraft` em "Outra sugestão" (`sur:again`)?

### D. Publicação

- Feed: carrossel, vídeo, imagem única
- Story: `publishStorySet`, retry erro Instagram 24, frase sobreposta vs designed (texto na arte)
- Destino escolhido no início respeitado até publicar (não publicar feed no story sem ação explícita)

## Cenários de teste obrigatórios (descreva resultado esperado vs código)

1. **Feed → Citação → IA → Surpreenda-me** — perfil `@exame_consciencia` / espiritual: ideia com versículo + layout, imagem com texto legível, legenda coerente  
2. **Feed → Infográfico → IA → texto manual** — 3 slides no feed  
3. **Story → Citação → IA → foto referência + texto longo** — nova imagem, não cópia; mensagens corretas  
4. **Feed → Foto simples → IA → referência** — photorealistic novo vs card; avaliar se UX deveria empurrar outro formato  
5. **Feed → Foto simples → 1 foto no feed** — imagem **inalterada**, só legenda (contraste com IA)  
6. **Texto longo + foto com kind foto** — redireciona para IA (`startIdeaFromPhotoReference`)  
7. **kind ia + post pendente AWAITING_APPROVAL** — cancela pendência e IA, sem `replacePending` enganoso  
8. **Confirmar "Sim, descartar anterior"** com rascunho Collecting + texto longo — `finalizeCollectingDraftAsIdea`  
9. **Surpreenda-me story** — destino story em draft e publicação story  
10. **Áudio / foto primeiro sem legenda** — stash referência, depois texto  

## Perfis de teste sugeridos

- **Espiritual:** about confissão/exame de consciência, brand contemplativo  
- **Contábil:** profession contador, formato citação ou checklist  
- **Professor:** tom acolhedor, passo a passo ou foto simples  

## Bugs/regressões recentes (verificar se corrigidos ou se ainda há lacuna)

- Formato pulado (IA direto para tipo de mídia)
- Surpreenda-me gerando só "paisagem" sem formato
- `BenefitOrchestrator::surpriseBrief` append "sem texto longo na imagem" em formatos designed
- Mensagem "Formato: … escolha como montar" **depois** de "Estou montando a imagem"
- Fluxo `Recebi!` quando usuário acreditava estar na IA

## Entregável do revisor

Estruture a resposta assim:

1. **Mapa do fluxo atual** (diagrama ASCII ou Mermaid): feed e story, com callbacks e `pending_action`
2. **Tabela de escolhas** (destino × formato × mídia): UX OK? Geração OK? Gaps?
3. **Lista de problemas** (P0/P1/P2) com evidência (arquivo + trecho/lógica)
4. **Recomendações de produto** (copy, ordem de passos, defaults, quando sugerir formato)
5. **Recomendações técnicas** (refactors mínimos, testes faltando)
6. **Checklist de testes manuais** no Telegram (passo a passo para QA)
7. **Paridade portal vs Telegram** (o que falta no web)

Não assuma que o deploy em produção está atualizado; baseie-se no **código do repositório**.

Se encontrar inconsistência entre testes automatizados e comportamento desejado, diga qual teste deveria existir.

---FIM DO PROMPT---
