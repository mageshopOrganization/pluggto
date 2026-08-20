# Plugg.To para Magento 1 / OpenMage — Fork MageShop

Módulo de integração **Plugg.To ⇄ Magento 1 (OpenMage LTS)**: sincroniza produtos,
preços, estoque e pedidos entre a loja e o hub de marketplaces Plugg.To.

> **Sobre este fork**
> Este repositório é uma **extensão/fork** do módulo oficial da Plugg.To
> ([bitbucket.org/pluggto/magento](https://bitbucket.org/pluggto/magento/src/master/)).
> A **MageShop** mantém esta versão, com **correções de bugs e otimizações de
> performance** sobre a base original — veja a seção
> [🚀 Melhorias MageShop](#-melhorias-mageshop-otimização-de-gargalos).

- **Módulo:** `Thirdlevel_Pluggto` (code pool `community`)
- **Versão:** 4.0.7
- **Compatibilidade:** Magento 1.9.x / OpenMage LTS · PHP 8.1–8.4
- **Upstream original:** https://bitbucket.org/pluggto/magento/src/master/

---

## Índice

- [Como funciona](#como-funciona)
- [Os dois fluxos de loja](#os-dois-fluxos-de-loja-push-x-inverso)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Crons](#crons)
- [🚀 Melhorias MageShop (otimização de gargalos)](#-melhorias-mageshop-otimização-de-gargalos)
- [Estrutura do módulo](#estrutura-do-módulo)
- [Créditos](#créditos)

---

## Como funciona

A integração é **assíncrona, baseada em fila**. Toda operação (buscar um produto,
empurrar preço/estoque, criar um pedido) vira uma linha na tabela
**`thirdlevel_pluggto_line`** com um `status`:

| status | significado |
|---|---|
| `0` | pendente (a processar) |
| `1` | processado com sucesso |
| `2` | falha |
| `3` | em processamento (modo multi-filas) |

Um conjunto de **crons** consome essa fila:

- **`playline`** — processa os itens pendentes: `GET` busca dados na Plugg.To e grava
  na loja; `PUT`/`POST` empurram dados da loja para a Plugg.To.
- **`runBulkExport`** — exporta produtos em lote.
- **`syncPriceStock`** — baixa a tabela de produtos da Plugg.To e compara estoque/preço,
  enfileirando o que estiver divergente.
- **`ordersUpdate`** — sincroniza pedidos.
- **`clearQueue`** — limpa registros antigos da fila.

As chamadas HTTP à API (`https://api.plugg.to/`) ficam em `Model/Call.php`; a
autenticação (OAuth) é cacheada por token.

---

## Os dois fluxos de loja (PUSH x INVERSO)

O comportamento é definido **pelas configurações**. O campo-chave é
**"Não exportar produtos/estoque para a Plugg.To (loja só recebe)"**
(`pluggto/mageshop/skip_product_export`).

- **PUSH — fluxo normal (maioria das lojas):** o **Magento é a origem**. A loja
  **envia** produtos/estoque/preço para a Plugg.To, que distribui aos marketplaces;
  os **pedidos voltam** da Plugg.To para o Magento. → `skip_product_export = Não`.
- **INVERSO / PULL:** a **Plugg.To é a origem** (age como um ERP). Ela **traz**
  produtos/estoque/preço **para** o Magento; a loja apenas **envia pedidos**.
  → `skip_product_export = Sim`.

> ⚠️ As otimizações deste fork respeitam os dois fluxos. Cada campo novo no admin
> traz explicação com **regras condicionais** ("ligue se o campo X estiver Sim") —
> não precisa decorar path de config.

---

## Instalação

Módulo Magento 1 no padrão de cópia de arquivos:

```bash
# a partir da raiz do Magento
cp -r app/* /caminho/do/magento/app/

# limpar o cache
rm -rf var/cache/*
```

O `Thirdlevel_Pluggto.xml` em `app/etc/modules/` ativa o módulo. Os scripts de
instalação/upgrade ficam em `app/code/community/Thirdlevel/Pluggto/sql/pluggto_setup/`
e **rodam automaticamente** no primeiro acesso ao admin após a cópia. Se necessário,
reindexe o catálogo pelo admin (**Sistema → Gerenciamento de Índices**).

### Compatibilidade com OpenMage LTS / PHP 8+

Esta versão substitui `Mage_Core_Model_Mysql4_Abstract` por
`Mage_Core_Model_Resource_Db_Abstract` (as classes `Mysql4_*` continuam sendo
aliases no OpenMage, mas o parent oficial é `Resource_Db`). Os controllers admin
usam `_isAllowed(): bool` (tipo de retorno explícito exigido pelo OpenMage
em PHP 8+).

---

## Configuração

No admin: **Sistema → Configuração → Plugg.To**.

- **Configuração / Autenticação:** `client_id`, `client_secret`, `app_id`, `app_secret`, `base`.
- **Produtos:** o que sincronizar, flags `update_*` (quais campos vão no export),
  `disable_product`, `export_not_visible`, etc.
- **Pedidos / Campos / Frete / Status:** mapeamentos diversos.
- **Outras configurações:** fila (`queues_quantity`, `multi_queues`), `clear_queue`, etc.
- **Melhorias MageShop:** ⭐ grupo novo com os ajustes de performance e as **expressões
  cron configuráveis** — detalhado abaixo.

---

## Crons

Todas as expressões cron são **configuráveis** (grupo *Melhorias MageShop*), lidas
por loja via `<config_path>` no crontab. Requer agendador compatível
(Aoe_Scheduler ou cron padrão do Magento).

| Job (cron) | Roda | Default | Config | Status |
|---|---|---|---|---|
| `pluggto_cron` | `runQueueCycle` (bulk + playline + limpeza) | `*/1 * * * *` | `cron_queuecycle` | **Ativo** |
| `pluggto_orders_update` | `ordersUpdate` (pedidos) | `*/5 * * * *` | `cron_orders` | Ativo |
| `pluggto_sync_stock_price` | `syncPriceStock` (estoque/preço) | `*/10 * * * *` | `sync_stock_cron` | Ativo |
| ~~`pluggto_observer`~~ | ~~`playline`~~ | — | ~~`cron_playline`~~ | **Obsoleto** (v4.0.7) |
| ~~`export_observer`~~ | ~~`runBulkExport`~~ | — | ~~`cron_bulkexport`~~ | **Obsoleto** (v4.0.7) |
| ~~`clear_observer`~~ | ~~`clearQueue`~~ | — | ~~`cron_clearqueue`~~ | **Obsoleto** (v4.0.7) |

Os 3 jobs marcados como obsoletos foram **consolidados** dentro de `runQueueCycle`
(v4.0.7). O worker único agora executa `runBulkExport + playline + clearQueue`
sob um lock compartilhado — elimina risco de PUTs concorrentes no mesmo produto.
Comentários explicativos ficam no `config.xml` e no `system.xml`.

---

## 🚀 Melhorias MageShop (otimização de gargalos)

Otimizações feitas sobre a base original. A motivação: em lojas grandes a integração
causava **"pulos" no cron** (a execução era morta por timeout) e a **fila crescia sem
parar**.

Princípios que guiaram as mudanças:
1. **Medir antes de mexer.**
2. **Não mudar o fluxo** — só seguir a lógica das configs e cortar desperdício.
3. **Atrás de flag, com default saneado** — respeita retrocompatibilidade quando o
   custo é baixo (ex.: cache anti-loop), mas ativa por default o que é armadilha
   pura de UX (ex.: paralelismo).

---

### 1. Busca paralela de produtos (`parallel_fetch`)

**Problema:** a fila era processada um item por vez. A maioria das chamadas à
Plugg.To é rápida (~0,4s), mas **algumas travam por vários segundos** — e, na fila
serial, uma chamada travada **segurava todas as seguintes**. O cron estourava o
tempo e "pulava".

**O que fizemos:** as buscas de produto agora rodam **em paralelo** (curl_multi
com limite de chamadas simultâneas). Uma chamada lenta não trava mais as outras.
70 itens em ~3s (antes ~40s).

**Config:** `parallel_fetch` — **default `5`**. É a **chave-mestra do paralelismo**:
`0` desativa TUDO, inclusive as escritas paralelas.

---

### 2. Escritas paralelas (`parallel_writes`) — v4.0.7

**Problema:** mesmo com GETs paralelizados, todos os PUT/POST/DEL continuavam em
loop serial. Em lojas com muita atualização, uma playline com 85 PUTs levava ~85s
de wall-clock. Backlog crescia.

**O que fizemos:** novo caminho paralelo em `_dispatchWritesInParallel` (Line.php):
- **Fase 1a**: iteração serial (só DB) pra carregar Line + produto Magento e
  coletar SKUs a buscar.
- **Fase 1b**: **batch curl_multi** dos "old products" via
  `products?bysku=<sku>` (paralelo). O `_prefetchOldProducts` substitui as N
  chamadas seriais que o `getProductInPluggto` fazia inline.
- **Fase 1c**: `formateToPluggto($product, $old)` monta bodies com o `$old` do
  batch.
- **Fase 2**: dispatch das escritas em paralelo (`doCallMultiWrite` novo,
  irmão de `doCallMultiGet`). Trava por `pluggtoid` (ou `storeid`) preserva ordem
  por recurso — 2 escritas do mesmo produto NUNCA correm concorrentes.
- **Fase 3**: post-processing sequencial (só DB) via `_applyWriteResponse`,
  replicando fielmente o switch case do `processNotification`. Fallbacks HTTP
  secundários (400 refetch, 404→POST) seguem serial dentro dessa fase.

**Config:** `parallel_writes` — **default `5`**. Requer `parallel_fetch>0`
pra ativar. `1` = comportamento antigo (serial).

**Métrica**: 100 items em ~15s (antes ~170s) — **~10× de ganho**.

---

### 3. Consolidação dos crons — v4.0.7

Antes existiam 4 crons independentes fazendo trabalho relacionado
(`pluggto_observer`/`playline`, `export_observer`/`runBulkExport`,
`clear_observer`/`clearQueue`, e `pluggto_cron`/`runQueueCycle`). Sem lock
compartilhado, dois workers podiam rodar em paralelo — risco de PUT concorrente
no mesmo produto e DELETE duplicado.

**O que fizemos:** `runQueueCycle` (via `safeWorkerLikeProcess`) agora executa
os 3 trabalhos sob um único lock `pluggto_worker.lock` (TTL 300s):
`runBulkExport + playline + clearQueue`. Os 3 jobs antigos foram **comentados**
no `config.xml` e no `system.xml`, com marca `<!-- OBSOLETO ... -->` explicando.

**Empírico (Sorasa, ~5h de log)**: 247 `runQueueCycle`, 247 `runBulkExport`,
247 `clearQueue` — todos com o mesmo `cron_id`, zero `SKIP worker lock`. Sem
sobreposição.

---

### 4. Cache anti-loop de PUTs rejeitados (`is_cached_reject`)

**Problema:** a Plugg.To rejeita silenciosamente PUTs quando o body enviado bate
com o que ela tem armazenado, respondendo HTTP 400 com
`type="not_updated" / "Changes not found in the document"`. Sem cache, o
`syncPriceStock` detectava a divergência (`getTableData` stale) e re-enfileirava
infinitamente os mesmos SKUs.

**O que fizemos:** nova coluna `is_cached_reject SMALLINT` em
`thirdlevel_pluggto_line` + índice `idx_cached_reject_pluggtoid`. Fluxo:
- **Marcação (`Line.php:241`)**: quando PUT retorna
  `code=400 && details=='Changes not found in the document'`, seta
  `is_cached_reject=1` na linha.
- **Preservação (`Line.php:640`)**: `clearQueue` não apaga linhas com
  `is_cached_reject=1` — são a memória do cache.
- **Filtro pré-envio (`Product.php:_matchesRejectedCache`)**: antes de enfileirar
  novo PUT, compara body cacheado vs local. Se bate, pula (log `skip_rejected`).
  Se não bate, `_releaseRejectedCache` limpa a flag e enfileira.
- **Feature-detection multi-tenant**: `Line::hasCachedRejectColumn()` cacheia por
  processo se a coluna existe. Lojas sem upgrade caem em código legacy silencioso.

Migração: `sql/pluggto_setup/mysql4-upgrade-4.0.6-4.0.7.php` (idempotente).

**Config:** `skip_previously_rejected` — **default `Sim`**. Grid da fila mostra
linhas cacheadas na coluna "Rejeitado".

---

### 5. Prune de campos no body do PUT (Opção B) — v4.0.7

**Problema (mais fundo):** o `getTableData` da Plugg.To sofre de eventual
consistency — mostra valores desatualizados que já foram atualizados via PUT
anterior. `syncPriceStock` detecta "diff" falso, enfileira PUT, PUT chega no
endpoint individual `/skus/xyz` que já tem o valor novo, Plugg.To rejeita
"Changes not found". Cache marca. Próximo ciclo: mesma coisa. Loop patológico
observado em produção (7 SKUs teimosos, 6-7 rejeições/hora cada).

**O que fizemos:** `formateToPluggto` chama `_pruneUnchangedFields` no fim.
Percorre campos **escalares** do body (`name`, `description`, `price`,
`special_price`, `quantity`, `link`, `brand`) e remove os que já batem com
`$old` (que agora vem do prefetch). Body vira só sku+external+ o que realmente
mudou. A Plugg.To não tem o que rejeitar como "no changes".

Campos array (photos, categories, attributes, variations, dimension) NÃO são
prunados — comparação complexa, risco de bug alto. Ficam sempre incluídos
quando as configs `update_*` permitem.

**Gate:** mesma flag `skip_previously_rejected` — cache e prune trabalham em par.

**Confirmação oficial:** documentação da Plugg.To confirma o comportamento
`not_updated` como safeguard intencional. Não há endpoint documentado pra
partial updates — prunar no cliente é a única saída.

---

### 6. Normalização final do `_matchesRejectedCache` — v4.0.7

**Regra simples pós-prune:** se `cache[campo] === null`, **ignora** (não força
mismatch). Mismatch só quando **ambos** (cache e local) têm valor E são
diferentes. Aplica pra `price`, `special_price`, `quantity`.

Antes, `cache=null` era interpretado como "remoto sem esse campo" e gerava
mismatch — quebrava o comportamento junto com o prune agressivo. Depois do
ajuste, os 7 SKUs teimosos desapareceram do log em <15min.

---

### 7. Filtro de save no observer (`observer_skip_unchanged`)

**Problema:** lojas com ERP externo (T5, `erptecinco` via SOAP) re-salvam
produtos em massa sem mudança real, gerando milhares de PUT que a Plugg.To
responde "No changes found".

**O que fizemos:** o `aftersaveproduct` compara **6 campos** entre `getOrigData`
e `getData` (`price`, `special_price`, `name`, `description`, `status`,
`visibility`). Se nada relevante mudou, não enfileira. Estoque e pedidos não são
afetados (têm caminhos separados).

**Config:** `observer_skip_unchanged` — **default `Sim`** (v4.0.7, era `Não`).
Proteção sem downside: loja sem ERP não é afetada; loja com ERP economiza.

---

### 8. Anti-flood de pedidos + fix N+1 (`updateOrders`) — v4.0.7

Anti-flood: só re-envia pedido se `updated_at` mudou desde o último envio. Sem
isso, todo pedido alterado nas últimas 24h era re-enviado a cada ciclo (5min)
e a Plugg.To respondia "Changes not found in the order".

O comparador antes fazia **1 SELECT por pedido** em `thirdlevel_pluggto_line`
pra pegar o `MAX(created)` — ~50s em lojas com 200-500 pedidos ativos. Agora:
**1 SELECT bulk** (`WHERE storeid IN (...) GROUP BY storeid` via `fetchPairs`)
+ lookup em array. Todas as execuções <1s.

---

### 9. Frequências e defaults saneados — v4.0.7

Novos defaults no `config.xml`:

| Config | Era | Agora | Motivo |
|---|---|---|---|
| `parallel_fetch` | `0` | **`5`** | `0` desativava TODO o paralelismo — armadilha pura de UX |
| `sync_stock_cron` | `*/2 * * * *` | **`*/10 * * * *`** | `getTableData` custa ~140s pra 20k produtos; `*/2` sobrecarrega sem ganho |
| `observer_skip_unchanged` | `Não` | **`Sim`** | Proteção sem downside |

---

### 10. Logs de auditoria (todos gated por `perf_log`)

Todos gravam em `var/log/pluggto_perf.log` (arquivo cresce rápido — só ligar
temporariamente pra debug):

| Log | O que registra |
|---|---|
| `writes.plan: items=X buckets=Y ... conc=N` | Início do dispatch paralelo de escritas |
| `writes.wave.start/end: wave=N duration=Xs ok=A err=B` | Cada onda do dispatch |
| `writes.prefetch: skus=X fetched=Y duration=Zs` | Batch de GETs `bysku` |
| `formate.prune: sku=X pruned=[...] count=N` | Prune de campos redundantes no body |
| `skip_rejected: sku=X current={p,s,q}` | Cache pegou (economizou 1 PUT) |
| `skip_rejected_MISS: sku=X reason=... cache={...} local={...}` | Cache tinha entry mas divergiu — mostra qual campo |
| `cached_reject: line_id=X url=skus/Y` | Nova rejeição marcada no cache |
| `observer_enqueue: sku=X diff={campo: antigo->novo}` | Save real (passou pelo filtro) |
| `observer_skip: sku=X values={...}` | Save filtrado (nada relevante mudou) |
| `END playline duration=Xs processed=Y writes=W serial=V` | Resumo do ciclo |

---

### 11. Resumo das configs (grupo *Melhorias MageShop*)

| Config | Default | Loja PUSH | Loja INVERSA/PULL |
|---|---|---|---|
| `parallel_fetch` | **`5`** | `5` | `5` |
| `parallel_writes` | **`5`** | `5` | `1`-`2` |
| `sync_skip_disabled` | `Não` | Depende (ver texto do campo) | **Sim** |
| `skip_product_export` | `Não` | **Não** | **Sim** |
| `observer_skip_unchanged` | **`Sim`** | Sim | Sim |
| `skip_previously_rejected` | `Sim` | Sim | Sim |
| `perf_log` | `Não` | só p/ debug | só p/ debug |
| `sync_stock_cron` | **`*/10 * * * *`** | `*/10` | `*/10`-`*/15` |
| `cron_queuecycle` | `*/1 * * * *` | `*/1` | `*/1` |
| `cron_orders` | `*/5 * * * *` | `*/5` | `*/5` |

Cada campo no admin tem texto explicando **o que faz + recomendado + quando
desligar**, com **regras condicionais** que referenciam outros campos pelo
label (não pelo código).

---

## Estrutura do módulo

```
app/
├── code/community/Thirdlevel/Pluggto/
│   ├── Block/            # blocos do admin (dashboard, fila, botões)
│   │   └── Queue/Grid.php    # coluna "Rejeitado" (is_cached_reject)
│   ├── Helper/Data.php   # log/config helpers (gate do perf_log)
│   ├── Model/
│   │   ├── Call.php          # HTTP: doCall, doCallMultiGet, doCallMultiWrite (v4.0.7)
│   │   ├── Line.php          # fila: playline, _playlineParallel, _dispatchWritesInParallel (v4.0.7), _applyWriteResponse (v4.0.7), _prefetchOldProducts (v4.0.7), is_cached_reject
│   │   ├── Cron.php          # runQueueCycle (worker consolidado)
│   │   ├── Product.php       # formateToPluggto (com prune Opção B), _matchesRejectedCache, syncPriceStock
│   │   ├── Export.php        # updateOrders (fix N+1), enfileiramento
│   │   ├── Observer.php      # aftersaveproduct com filtro de diff (6 campos)
│   │   └── Mysql4/...        # Resource_Db_Abstract (OpenMage compat)
│   ├── controllers/      # admin (_isAllowed(): bool) + NotificationController
│   ├── etc/              # config.xml, system.xml (defaults saneados)
│   └── sql/pluggto_setup/
│       └── mysql4-upgrade-4.0.6-4.0.7.php   # coluna is_cached_reject + índice
├── design/adminhtml/...
└── etc/modules/Thirdlevel_Pluggto.xml
```

---

## Créditos

- **Base original:** Plugg.To — https://bitbucket.org/pluggto/magento
- **Correções e otimizações desta versão:** MageShop.
