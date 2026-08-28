# LigaHub Finance — Handoff técnico (para outra IA / dev continuar)

> Documento de transferência. Leia inteiro antes de mexer. A **fonte da verdade do código** é o
> repositório GitHub `ligahubfinance` (NÃO a pasta local, que é gitignorada). As **funções de
> servidor** vivem no Supabase. Nada de segredo (senhas, service_role, chave Resend) está neste
> documento — eles ficam nos lugares indicados.

---

## 1. O que é

Central de gestão interna de uma liga acadêmica ("LigaHub Finance"). App **single-file** (um
`index.html` com HTML+CSS+JS puro) servido por **GitHub Pages**, usando **Supabase**
(Postgres + Auth + Realtime + Storage + Edge Functions) como back-end. Sem build, sem framework,
sem node_modules. Editar = editar o `index.html`.

- **App no ar:** https://riksonmiguellz.github.io/ligahubfinance/
- **Repo (fonte da verdade):** https://github.com/riksonmiguellz/ligahubfinance  (branch `main`, arquivo único `index.html`)
- **Supabase project ref:** `eplmfmtsaspwrywytffk`  (URL: `https://eplmfmtsaspwrywytffk.supabase.co`)
- **anon key (pública, pode versionar):**
  `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImVwbG1mbXRzYXNwd3J5d3l0ZmZrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODI5OTY2MTYsImV4cCI6MjA5ODU3MjYxNn0.Uxak-0KGQT-hPW2QQuS6Q1yj2vwucjVx2LneNHIPypU`

---

## 2. Stack e princípios

- **Frontend:** um `index.html`. `supabase-js@2` carregado lazy via CDN (jsdelivr). jsPDF e SheetJS
  também via CDN só quando precisa (PDF de certificado/relatório, export XLSX).
- **Config no topo do `<script>`:**
  ```js
  const CONFIG={SUPABASE_URL:"https://eplmfmtsaspwrywytffk.supabase.co",
    SUPABASE_ANON_KEY:"...anon...", DIR_EMAIL:"direcao@ligahubfinance.com"};
  ```
- **Realtime:** cada tabela `liga_*` tem canal `postgres_changes`. Há um guard `lastWrite`
  (~1800ms) pra não sobrescrever a escrita local com o eco do realtime. Escritas são **otimistas**
  (atualiza o array local e só dá reload em caso de erro).
- **Sem framework de estado:** arrays globais (`PESSOAS`, `PROJETOS`, `TAREFAS`, …) + `render()`
  que despacha por `VIEW`.

---

## 3. Papéis e login (IMPORTANTE — é aqui que mora a blindagem)

Cada pessoa tem **login individual** (Supabase Auth, e-mail + senha). O papel vem de uma tabela-mapa.

- **Tabela `liga_membros_auth`** (`email` PK, `nome`, `role`, `provisiona`):
  - `role` = `'direcao'` (vê tudo, inclusive financeiro) ou `'membro'` (não vê financeiro).
  - `provisiona` = `true` para quem pode gerar acesso de terceiros (hoje: Presidente, Vice, e o admin).
  - Há também a conta **master** `direcao@ligahubfinance.com` (role direcao) como administrador geral.
- **Funções SQL `SECURITY DEFINER`** (usadas nas policies):
  - `public.liga_role()` → lê o papel do usuário logado pelo e-mail do JWT (`auth.jwt()->>'email'`).
  - `public.liga_my_name()` → devolve o `nome` do usuário logado (usado p/ isolar notificações).
- **No app:** ao logar, `Store.login()` faz `signInWithPassword`, depois lê `liga_membros_auth`
  (nome/role/provisiona) e chama `enter(role, nome)`. `EU` = nome logado (identidade travada).
- **Trocar senha:** botão no topo → `sb.auth.updateUser({password})` (qualquer usuário).

> ⚠️ **Nunca** troque as policies de `liga_financeiro` para `to authenticated using(true)` — isso
> vaza o financeiro pra todo membro. A regra correta é `using (public.liga_role()='direcao')`.

---

## 4. Banco (tabelas `public.liga_*`)

| Tabela | Para quê | RLS (resumo) |
|---|---|---|
| `liga_pessoas` | membros (nome, apelido, foto, email, telefone, curso, diretoria, cargo, aprovado…) | select público; **update:** direção OU dono (email do login = email da pessoa); insert público; delete direção |
| `liga_diretorias` | estrutura (nome, ordem) | select público; escrita autenticado |
| `liga_projetos` | projetos (status, equipe[], checklist[], comentarios[], **tags[]**, percentual…) | select público; escrita autenticado |
| `liga_tarefas` | tarefas (responsavel, status, prazo, **tags[]**, comentarios[]) | select público; escrita autenticado |
| `liga_ideias` | banco de ideias (autor, status) | idem |
| `liga_reunioes` | reuniões (participantes, pauta, presenca[], ata) | idem |
| `liga_candidatos` | onboarding/processo seletivo (etapa Kanban, checklist) | idem |
| `liga_aprovacoes` | aprovações (tipo, status, historico) | idem |
| `liga_mural` | mural/avisos (fixado, comentarios[]) | idem |
| `liga_eventos` | eventos (tipo, equipe[], cronograma[], inscritos[+presente], status) | idem |
| `liga_financeiro` | **receitas/despesas** (tipo, categoria, valor, status, diretoria) | **SÓ direção** (`liga_role()='direcao'`) |
| `liga_conquistas` | badges manuais do ranking (pessoa, titulo, icone, pontos) | select público; escrita autenticado |
| `liga_avaliacoes` | avaliação 360 (avaliador, avaliado, scores{}, comentario) | select público; insert público; update/delete autenticado |
| `liga_notificacoes` | notificações (membro, titulo, texto, ref_id, lida) | **só o dono ou direção** vê (`membro=liga_my_name() or liga_role()='direcao'`); insert autenticado |
| `liga_membros_auth` | mapa e-mail→papel (ver §3) | cada um lê só a própria linha |

- **Storage bucket `avatars`** (público) guarda as fotos de perfil.
- **Colunas jsonb** guardam arrays/objetos (equipe, checklist, comentarios, tags, inscritos, scores…).
- **Ranking (gamificação):** pontos são **calculados no cliente** (`pontosAuto`) a partir de tarefas
  concluídas (+10), projetos entregues (+50), eventos organizados (+15), presença em reunião (+5),
  ideia aprovada (+20). Badges automáticos (`autoBadges`) são derivados em tempo de render (não
  gravam no banco). `liga_conquistas` guarda só as badges manuais da Direção.

---

## 5. Edge Functions (Supabase → Functions)

1. **`notify-email`** (`verify_jwt=false`) — envia e-mail via **Resend**.
   - Lê `RESEND_API_KEY` e `EMAIL_FROM` dos **Secrets** do projeto (NÃO no código).
   - Corpo: `{tipo, alvoNome|candidatoId|toOverride, titulo, texto, link}`. Busca o e-mail do
     destinatário em `liga_pessoas`/`liga_candidatos` pelo nome (ou usa `toOverride`).
   - Remetente atual: `no-reply@ligahubfinance.com.br` (domínio **verificado** no Resend).
2. **`provision-login`** (`verify_jwt=true`) — cria/atualiza login de terceiros ("Gerar acesso").
   - Só executa se quem chama tem `provisiona=true` (checa via service_role em `liga_membros_auth`).
   - Usa `auth.admin.createUser` (service_role), gera senha, faz upsert no mapa. Retorna a senha.

**Secrets** (Supabase → Edge Functions → Secrets): `RESEND_API_KEY`, `EMAIL_FROM`. O service_role
e o `SUPABASE_URL` são injetados automaticamente nas functions.

**Gatilhos automáticos de e-mail** (já ligados): `Store.notificar()` chama `emailNotify()`. Logo,
tarefa atribuída, entrar em projeto, ideia virar projeto, conquista, comentário em tarefa e
candidato avançando no processo seletivo → todos disparam e-mail.

---

## 6. Deploy (passo a passo — testado)

```bash
# 1. clonar o repo canônico
gh repo clone ligahubfinance && cd ligahubfinance
# 2. copiar o index.html editado por cima
cp /caminho/do/index.html index.html
# 3. commit + push
git commit -am "descricao" && git push
# 4. GitHub Pages publica em ~1min. Confirmar buscando um marcador novo:
curl -s "https://riksonmiguellz.github.io/ligahubfinance/index.html?cb=$RANDOM" | grep -c "MARCADOR"
```
- **Sempre** validar sintaxe antes: extrair o conteúdo de `<script>` e rodar `node --check`.
- Migrações de banco: aplicar via Supabase (SQL Editor ou MCP `apply_migration`). Edge functions:
  `deploy_edge_function`.

---

## 7. Rodar/editar local

- Abrir `index.html` no navegador já conecta no Supabase (a `CONFIG` está preenchida). Login
  individual funciona local igual em produção.
- Não há servidor; não há `npm install`.

---

## 8. Teste automatizado (PHP)

`ligahub-teste.php` (no Desktop do dono) valida o back-end: login de direção (vê financeiro), login
de membro (financeiro vazio = blindado), anônimo bloqueado, senha errada rejeitada. Rodar:
`php ligahub-teste.php` → espera **10/10 PASS**. (No Windows o script desliga verificação SSL só
pro teste local.)

---

## 9. Credenciais & segredos (onde vivem)

- **Senhas dos usuários:** no arquivo `LigaHub-Acessos.docx` (Desktop do dono). NÃO estão aqui.
- **RESEND_API_KEY / EMAIL_FROM:** Supabase Secrets.
- **service_role key:** só no Supabase (nunca no front nem em docs).
- **anon key:** pública (está no `index.html` e na §1) — protegida por RLS.
- Reset de senha de alguém: pelo app (a própria pessoa) ou Direção via "Gerar acesso"
  (regera a senha).

---

## 10. Pegadinhas / dívida técnica conhecida

- **Ligações por NOME:** `tarefa.responsavel`, `projeto.equipe[]`, `notificacao.membro`, `EU` e o
  `nome` em `liga_membros_auth` são **strings de nome**. Se renomear uma pessoa, essas referências
  não seguem sozinhas. (Melhoria futura: linkar por id/email.) Hoje o mapa e as pessoas usam os
  mesmos nomes — manter assim.
- **Update de pessoa por membro** exige que `liga_pessoas.email` == e-mail do login. Se um membro
  não conseguir editar o próprio perfil, confira esse casamento de e-mail.
- **GitHub Pages** demora ~1min e às vezes serve versão cacheada; use `?cb=<rand>` pra furar cache.

---

## 11. Marca (usar em qualquer tela nova)

- Azul primário `#2563EB`, deep `#0F2A4A`; ok `#12A150`, warn `#C67C00`, crit `#D62F2F`.
- Fontes Montserrat (títulos) + Inter (corpo). Fundo claro. Fundo animado de partículas (`BGFX`) no login.

---

## 12. Backlog / ideias (não obrigatórias)

- Comentários com @menção que notificam o citado (hoje notifica o responsável da tarefa).
- Linkar dados por id/email em vez de nome (robustez a rename).
- PWA (instalar no celular) — o dono pediu pra deixar pra depois.
- Biblioteca de arquivos com versões, base de conhecimento, auditoria/logs, parceiros, busca global.

---

## 13. Contatos

- Dono/admin: Rikson (rikson.miguel@icloud.com) — papel `direcao`, `provisiona=true`.
- Presidente: Lucas de Moura · Vice: Pedro Hubner (ambos `provisiona=true`).

**Regra de ouro:** este app lida com pessoas reais e com valores financeiros sensíveis. Antes de
qualquer mudança em RLS/policies, teste que **membro continua vendo 0 no `liga_financeiro`** e que
**direção continua vendo**. O `ligahub-teste.php` faz exatamente essa verificação.
