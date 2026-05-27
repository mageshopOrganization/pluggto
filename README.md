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
- **Versão:** 4.0.6
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

O comportamento é definido **pelas configurações** (principalmente `pluggto/configuration/base`).
Existem dois cenários:

- **PUSH — fluxo normal (maioria das lojas):** o **Magento é a origem**. A loja
  **envia** produtos/estoque/preço para a Plugg.To, que distribui aos marketplaces;
  os **pedidos voltam** da Plugg.To para o Magento.
- **INVERSO / PULL:** a **Plugg.To é a origem** (age como um ERP). Ela **traz**
  produtos/estoque/preço **para** o Magento; a loja apenas **envia pedidos**.

> ⚠️ As otimizações deste fork respeitam os dois fluxos. As configs novas têm
> **default que preserva o comportamento de loja PUSH** — só lojas inversas precisam
> ligar os ajustes específicos (veja a seção de melhorias).

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

Todas as expressões cron são **configuráveis** (grupo *Melhorias MageShop*), com
default igual ao valor histórico — então atualizar o módulo **não muda a cadência**.

| Job (cron) | Roda | Default | Config |
|---|---|---|---|
| `pluggto_observer` | `playline` (processa a fila) | `*/1 * * * *` | `cron_playline` |
| `pluggto_cron` | `runQueueCycle` (bulk + playline + limpeza) | `*/1 * * * *` | `cron_queuecycle` |
| `export_observer` | `runBulkExport` (exportação em lote) | `*/1 * * * *` | `cron_bulkexport` |
| `pluggto_orders_update` | `ordersUpdate` (pedidos) | `*/5 * * * *` | `cron_orders` |
| `pluggto_sync_stock_price` | `syncPriceStock` (estoque/preço) | `*/2 * * * *` | `sync_stock_cron` |
| `clear_observer` | `clearQueue` (limpeza) | `0 1 * * *` | `cron_clearqueue` |

> Tecnicamente, cada job usa `<config_path>` no crontab apontando para
> `pluggto/mageshop/*`, lido **por loja**. Requer um agendador compatível
> (Aoe_Scheduler ou cron padrão do Magento).

---

## 🚀 Melhorias MageShop (otimização de gargalos)

Otimizações feitas sobre a base original. A motivação: em lojas grandes a integração
causava **"pulos" no cron** (a execução era morta por timeout) e a **fila crescia sem
parar**.

Princípios que guiaram as mudanças:
1. **Medir antes de mexer.**
2. **Não mudar o fluxo** — só seguir a lógica das configs e cortar desperdício.
3. **Atrás de flag, com default igual ao comportamento atual** — quem não ligar nada continua como antes.

### 1. Busca paralela de produtos (`parallel_fetch`) — o gargalo principal

**Problema:** a fila era processada um item por vez. A maioria das chamadas à Plugg.To é
rápida (~0,4s), mas **algumas travam por vários segundos** — e, na fila serial, uma
chamada travada **segurava todas as seguintes**. O cron estourava o tempo e "pulava".

**O que fizemos:** as buscas de produto agora rodam **em paralelo** (com um limite de
chamadas simultâneas). Uma chamada lenta não trava mais as outras.

**Config:** `parallel_fetch` — `0` = como era (serial); **`5`** recomendado.
Medido: 70 itens em ~3s (antes ~40s).

### 2. Tempo-limite das chamadas (timeout)

Uma chamada à Plugg.To agora desiste em **20s** (era 60s). Se travar, o item fica para a
próxima rodada em vez de segurar a fila por um minuto. Vale para todas as lojas.

### 3. Parar de re-enfileirar a mesma divergência no sync de estoque/preço

A fila crescia sem parar porque o sync detectava as **mesmas divergências** toda vez,
sem nunca resolvê-las. Duas causas:

- **Produtos desabilitados:** o estoque deles é tratado como 0 na comparação, então nunca
  batiam com o estoque real da Plugg.To. → Opção **`sync_skip_disabled`** para ignorá-los
  no sync (default Não).
- **Casas decimais do preço:** a loja usa 2 casas e a Plugg.To 3 (ex.: `1.34` vs `1.344`),
  gerando uma diferença que nunca sumia. → A comparação passou a arredondar os dois lados
  igual.

### 4. Loja que só recebe produtos (`skip_product_export`)

Em loja de fluxo **inverso**, ao importar um produto da Plugg.To a loja o salvava e isso
**disparava um envio de volta** inútil — um pedido que a Plugg.To respondia *"No changes
found"*, entupindo a fila.

**O que fizemos:** opção **`skip_product_export`** (default Não) que desliga o envio de
**produto/estoque** para a Plugg.To. **Os pedidos continuam indo normalmente.**

### 5. Frequência dos crons configurável

Todas as expressões cron viraram **configuração** (por loja), com o valor atual como
default. Dá para, por exemplo, espaçar o sync de estoque numa loja específica sem mexer
em código.

### 6. Log de performance sob demanda (`perf_log`)

Liga/desliga a gravação dos tempos de execução dos crons em `var/log/pluggto_perf.log`.
**Desligado por padrão** — use só para investigar e desligue depois.

### 7. Saneamento da fila (v4.0.6)

Correções na tabela da fila (`thirdlevel_pluggto_line`):

- **Sem duplicados (mantém o mais fresco):** quando chega uma nova pendência para o mesmo
  produto e a mesma operação, as pendências antigas iguais são **apagadas** e fica só a
  **mais recente**. Evita processar a mesma coisa várias vezes.
- **Freio em produtos inexistentes:** itens de produtos que retornam 404 deixam de ser
  re-enfileirados sem parar.
- **Limpeza efetiva** dos registros antigos já processados/falhados.
- **Ordem correta** — processa o mais antigo primeiro.
- **Índices** no banco para as consultas da fila ficarem rápidas.

### Resumo das configs novas (grupo *Melhorias MageShop*)

| Config | Default | Loja PUSH | Loja INVERSA/PULL |
|---|---|---|---|
| `parallel_fetch` | `0` | 0 (opcional 5) | **5** |
| `sync_skip_disabled` | `Não` | Não | **Sim** |
| `skip_product_export` | `Não` | **Não** (obrigatório) | **Sim** |
| `perf_log` | `Não` | só p/ debug | só p/ debug |
| `sync_stock_cron` | `*/2 * * * *` | `*/2` | `*/10` |
| `cron_playline` · `cron_queuecycle` · `cron_bulkexport` | `*/1` | `*/1` | `*/1` |
| `cron_orders` | `*/5` | `*/5` | `*/5` |
| `cron_clearqueue` | `0 1 * * *` | 1x/dia | 1x/dia |

---

## Estrutura do módulo

```
app/
├── code/community/Thirdlevel/Pluggto/
│   ├── Block/            # blocos do admin (dashboard, fila, botões)
│   ├── Helper/Data.php   # log/config helpers (gate do perf_log)
│   ├── Model/
│   │   ├── Call.php          # camada HTTP (doCall, doCallMultiGet)
│   │   ├── Line.php          # fila: playline, processNotification, _playlineParallel
│   │   ├── Cron.php          # entrypoints dos crons
│   │   ├── Product.php       # import/export de produtos, syncPriceStock
│   │   ├── Export.php        # enfileiramento de exports
│   │   ├── Observer.php      # observers de save/estoque/pedido
│   │   └── ...
│   ├── controllers/      # admin + NotificationController (webhooks)
│   ├── etc/              # config.xml, system.xml, adminhtml.xml
│   └── sql/              # install/upgrade
├── design/adminhtml/...  # templates do admin
└── etc/modules/Thirdlevel_Pluggto.xml
```

---

## Créditos

- **Base original:** Plugg.To — https://bitbucket.org/pluggto/magento
- **Correções e otimizações desta versão:** MageShop.
