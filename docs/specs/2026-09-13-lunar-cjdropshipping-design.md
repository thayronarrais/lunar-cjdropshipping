# thayron/lunar-cjdropshipping — Design

- **Data:** 2026-09-13
- **Status:** design aprovado em brainstorming; aguardando revisão da spec
- **Depende de:** `thayron/cjdropshipping-php` ^0.1 (SDK publicado; https://github.com/thayronarrais/cjdropshipping-php)
- **App alvo inicial:** `C:\laraenv\www\lunar` (Laravel 11.56, `lunarphp/core` 1.4, `lunarphp/lunar` (admin Filament) 1.4, spatie/laravel-medialibrary 11)

## 1. Objetivo

Importar produtos da CJdropshipping para lojas Lunar e mantê-los sincronizados.

- **Seleção:** a importação parte de regras (categorias CJ e/ou palavra-chave, com filtros). As regras geram **candidatos**, e a equipe **aprova** no admin do Lunar quais entram.
- **Estado inicial:** o produto importado entra em **rascunho**, com conteúdo em inglês replicado em todas as línguas da loja, para tradução e revisão no admin.
- **Moedas:** o package não assume moeda nenhuma; as lojas-alvo usam EUR e GBP.
- **Sincronização:** estoque, custo/preço e indisponibilidade. Nome, descrição e imagens editados no admin nunca são sobrescritos.
- **Reutilização:** o package deve servir a qualquer app Laravel + Lunar 1.x.

### Fora de escopo (v1)

- Tradução automática.
- Pedidos, frete e tracking (dependem da Fase B do SDK).
- Criação automática de variantes novas; só existe a ação manual.
- Aba ou widget CJ na tela de edição de produto do Lunar, e dashboard.
- Tela de configurações no admin (usa `.env` e config).
- Lock de token entre processos no SDK; a mitigação é 1 worker na fila, ver §5.

## 2. Decisões de negócio

| Tema | Decisão |
|---|---|
| Entrada | Regras por categoria/busca geram candidatos; aprovação manual (individual ou em massa) |
| Interface | Plugin do admin Lunar (Filament) + comandos artisan |
| Preço | Independente de moeda: custo USD × (1 + markup%), convertido para a moeda padrão da loja (via `Currency` USD do Lunar ou `usd_to_default_rate`) e para cada `Currency` habilitada pelo seu `exchange_rate`, com arredondamento configurável por regra. Lojas-alvo: EUR e GBP |
| Sync | Estoque + custo/preço + indisponível; conteúdo editado nunca é sobrescrito |
| Idioma | Importa o conteúdo inglês da CJ em todas as `Language` cadastradas (valor idêntico), para tradução manual; produto entra como `draft` |

## 3. Package

- **Local:** `packages/lunar-cjdropshipping`, com git próprio, instalado no app via o path repository `packages/*` (symlink).
- **Composer:** `thayron/lunar-cjdropshipping`, namespace `Thayron\LunarCjDropshipping\`.
- **require:** `php ^8.2`, `lunarphp/core ^1.4`, `lunarphp/lunar ^1.4`, `thayron/cjdropshipping-php ^0.1`.
- **require-dev:** `orchestra/testbench ^9`, `phpunit/phpunit ^11`, `guzzlehttp/guzzle ^7.8`, `larastan/larastan ^3`, `laravel/pint ^1`.
- **Descoberta Laravel:** `extra.laravel.providers` → `LunarCjDropshippingServiceProvider`.
- **Estrutura:**

```
config/lunar-cjdropshipping.php
database/migrations/                 4 tabelas (§4)
lang/en, lang/pt_BR
routes/webhooks.php
src/
  LunarCjDropshippingServiceProvider.php
  Models/          ImportRule, Candidate, ProductLink, VariantLink
  Enums/           CandidateStatus, CjProductStatus, PriceRounding, UnavailableAction
  Pricing/         PriceCalculator
  Mapping/         VariantOptionParser, MeasurementConverter, StockResolver
  Actions/         DiscoverCandidates, ImportProduct, SyncProduct, ImportNewVariants, ImportProductImages
  Jobs/            DiscoverCandidatesJob, ImportProductJob, ImportProductImagesJob, SyncProductJob
  Media/           ImageDownloader (interface), HttpImageDownloader
  Http/Controllers/WebhookController.php
  Console/         DiscoverCommand (cj:discover), SyncCommand (cj:sync), WebhooksSetupCommand (cj:webhooks:setup)
  Filament/        CjDropshippingPlugin, Resources/{ImportRuleResource, CandidateResource, ProductLinkResource}
tests/             Unit, Feature, Filament, Integration (live)
```

- **Divisão de responsabilidades:**
  - **Actions** contêm a regra de negócio e são testáveis sem fila.
  - **Jobs** são finos: aplicam rate limit e retry e chamam uma Action.
  - **Resources** Filament só disparam jobs e alteram status.

## 4. Modelo de dados

### `cj_import_rules`

| Coluna | Tipo | Notas |
|---|---|---|
| id | bigint | |
| name | string | |
| is_active | bool | default true |
| category_ids | json | IDs de 3º nível da CJ (lista de strings) |
| keyword | string nullable | |
| country_code | string(2) nullable | país do armazém: filtra a busca e o estoque |
| min_stock | unsigned int | default 0 |
| min_cost / max_cost | decimal(12,2) nullable | USD |
| markup_percent | decimal(8,2) | ≥ 0 |
| rounding | string | enum `PriceRounding`: `none`, `ends_90`, `ends_99`, `whole` |
| product_type_id | FK lunar product_types | obrigatório |
| brand_id | FK lunar brands nullable | |
| collection_id | FK lunar collections nullable | |
| max_pages | unsigned smallint | 1–1000, default 5 |
| last_run_at | timestamp nullable | |
| last_run_stats | json nullable | `{found, created, updated, skipped_ignored, already_imported, error?}` |
| timestamps | | |

A regra salva só é válida com `category_ids` não vazio **ou** `keyword` preenchida.

### `cj_candidates`

| Coluna | Tipo | Notas |
|---|---|---|
| id | bigint | |
| import_rule_id | FK cascade | |
| cj_product_id | string | unique com `import_rule_id` |
| cj_sku | string nullable | |
| name | string | |
| image_url | text nullable | |
| cost_usd | decimal(12,2) nullable | |
| warehouse_stock | int nullable | |
| cj_category_id | string nullable | |
| status | string | enum `CandidateStatus`: `pending`, `approved`, `ignored`, `importing`, `imported`, `failed` |
| error | text nullable | |
| lunar_product_id | FK nullable | |
| payload | json | raw do item listV2 |
| discovered_at | timestamp | |
| timestamps | | |

### `cj_product_links`

| Coluna | Tipo | Notas |
|---|---|---|
| id | bigint | |
| cj_product_id | string | unique |
| lunar_product_id | FK lunar products, cascade | |
| import_rule_id | FK nullable, null on delete | |
| markup_percent | decimal(8,2) | cópia do valor da regra na importação |
| rounding | string | cópia do valor da regra na importação |
| country_code | string(2) nullable | cópia do valor da regra na importação |
| cj_status | string | enum `CjProductStatus`: `active`, `unavailable` |
| not_found_count | unsigned tinyint | default 0 |
| new_cj_variant_ids | json | default [] |
| last_synced_at | timestamp nullable | |
| sync_error | text nullable | |
| timestamps | | |

### `cj_variant_links`

| Coluna | Tipo | Notas |
|---|---|---|
| id | bigint | |
| cj_variant_id | string | unique |
| cj_product_link_id | FK cascade | |
| lunar_variant_id | FK lunar product_variants, cascade | |
| cj_sku | string nullable | |
| cost_usd | decimal(12,2) nullable | |
| stock | int | |
| last_synced_at | timestamp nullable | |
| timestamps | | |

Os nomes das tabelas do Lunar vêm dos models (`(new Product)->getTable()`), respeitando o prefixo configurado no Lunar.

## 5. Configuração (`config/lunar-cjdropshipping.php`)

| Chave | Default | Uso |
|---|---|---|
| `queue` | `cjdropshipping` | fila dos jobs |
| `requests_per_second` | `1` | `RateLimiter::for('cj-api')` |
| `schedule.enabled` | `true` | registra os agendamentos |
| `schedule.discover` | `daily` | frequência do `cj:discover` |
| `schedule.sync` | `everySixHours` | frequência do `cj:sync` |
| `sync.stale_after_hours` | `6` | vínculos elegíveis para reconciliação |
| `sync.not_found_threshold` | `2` | NotFound consecutivos antes de marcar indisponível |
| `sync.unavailable_action` | `out_of_stock` | ou `draft` |
| `webhooks.path` | `cjdropshipping/webhook` | rota |
| `webhooks.dedupe_ttl_hours` | `48` | |
| `media.collection` | `images` | |
| `pricing.usd_to_default_rate` | `null` | valor de 1 USD na moeda padrão, usado só se não houver `Currency` USD |
| `log_channel` | `null` | canal padrão do app |

**Operação recomendada:**
- Driver de fila real (`database` ou `redis`).
- **Um worker** na fila `cjdropshipping`, com `php artisan queue:work --queue=cjdropshipping`. Isso serializa as chamadas e evita a corrida de refresh de token entre processos.
- Scheduler do app ativo.

## 6. Mapeamento CJ → Lunar

### 6.1 Produto

`Product::create` com:
- `status = 'draft'`;
- `product_type_id` e `brand_id` da regra;
- `attribute_data`:
  - `name` = `TranslatedText` com o mesmo valor (`productNameEn`) para cada `Language` cadastrada;
  - `description` = HTML da CJ, nas mesmas línguas.

Os atributos `name` e `description` do tipo de produto precisam existir (padrão do Lunar). Depois:
- `scheduleChannel(Channel default)`;
- `collection->products()->attach` se a regra tiver coleção.

### 6.2 Opções e variantes (`VariantOptionParser`)

Entradas: `raw()['productKeyEn']` do produto (ex.: `"Color-Size"`) e `Variant::$key` (ex.: `"Black-XL"`).

1. **Uma variante só:** não cria opções.
2. **Nomes das opções:** `productKeyEn` separado por `-`.
3. **Valores:** quebra o `variantKey` pelo número de opções. Os primeiros N−1 segmentos vêm antes do último, e o restante vai para a última opção, o que preserva hífens dentro do último valor.
4. **Sem `productKeyEn`, ou número de partes incompatível:** uma única opção `Variant` cujo valor é o `variantKey` inteiro.
5. **`ProductOption`:** reaproveitada por `handle` (`Str::slug(nome)`) e criada com `shared = true` se não existir.
6. **`ProductOptionValue`:** reaproveitado por nome `en` dentro da opção.
7. **Associação:** `productOptions()->attach` com `position`, e `variant->values()->attach`.

### 6.3 Variante

- `sku` = `variantSku`; `tax_class_id` = `TaxClass::getDefault()`.
- `purchasable = 'in_stock'`; `shippable = true`; `backorder = 0`.
- `stock` = `StockResolver`.
- **Medidas:** `weight_value` = g/1000 com `weight_unit` `kg`; `length/width/height_value` = mm/10 com unidade `cm`. Medida ausente fica nula.

### 6.4 Estoque (`StockResolver`)

- **Fonte:** `ProductResource::inventoryByProduct($pid)->forVariant($vid)`. O `product/query` não retorna estoque por variante; isso foi verificado contra a API real.
- **Cálculo:** soma de `total` dos registros, filtrando por `country_code` do vínculo quando definido.
- **Fallback:** variante sem registro fica com estoque 0.

### 6.5 Preço (`PriceCalculator`)

Para cada `Currency` habilitada:

1. **Taxa USD → moeda padrão (`usdRate`):** se existir `Currency` com código `USD` e `exchange_rate > 0`, `usdRate = 1 / exchange_rate(USD)` (as taxas do Lunar são relativas à moeda padrão); senão `config('lunar-cjdropshipping.pricing.usd_to_default_rate')`. Sem nenhuma das duas, a importação falha com mensagem clara.
2. `bruto = cost_usd × (1 + markup/100) × usdRate × exchange_rate(moeda)` (BCMath, a partir das strings decimais). Para a moeda padrão, `exchange_rate = 1`.
3. Converte para unidades mínimas (`× 10^decimal_places`).
4. Aplica o arredondamento, em unidades maiores:
   - `ends_90`: teto do inteiro menos 0,10 (se o resultado ficar abaixo do bruto, soma 1);
   - `ends_99`: análogo, com 0,01;
   - `whole`: teto;
   - `none`: arredonda meio para cima;
   - moedas com 0 casas decimais: `ends_*` vira `whole`.
5. `Price` com `customer_group_id = null`, `min_quantity = 1`, `currency_id` e `priceable` = variante. Na sincronização é atualizado (`updateOrCreate` por variante + moeda + grupo nulo + min_quantity 1).

**Exemplo (loja EUR padrão, GBP 0,85, USD 1,08):** custo US$ 10,00, markup 100% → EUR `10 × 2 × (1/1,08)` = 18,52 → `ends_90` = 18,90; GBP `18,52 × 0,85` = 15,74 → `ends_90` = 15,90. Uma loja com USD padrão funciona com `usdRate = 1`.

### 6.6 Imagens (`ImportProductImages` + `ImageDownloader`)

- **Fonte:** `Product::$images`; se vazio, `mainImage`.
- **Por URL:** se já existe media no produto com `custom_properties.cj_source_url` = URL, pula. Senão:
  1. `ImageDownloader::download($url): string` baixa via `Http` do Laravel (timeout 30s) para um arquivo temporário;
  2. `addMedia($path)->withCustomProperties(['cj_source_url' => $url, 'primary' => $isFirst])->toMediaCollection(config media.collection)`.
- **Falha em uma imagem:** registra no `sync_error` do vínculo e continua as demais. O job lança exceção no final se alguma falhou, para acionar retry (3 tentativas, backoff 60s), sem duplicar as já salvas.

## 7. Fluxos

Todos os jobs:
- rodam em `config('lunar-cjdropshipping.queue')`;
- usam o middleware `RateLimited('cj-api')`;
- têm `tries`/`backoff` definidos;
- em `QuotaExceededException`, fazem `release()` até 00:05 UTC do dia seguinte.

### 7.1 Descoberta — `DiscoverCandidatesJob(ImportRule)` → `DiscoverCandidates`

1. **Critério:** `ProductSearch::make()` + `category()` (uma busca por categoria da regra; sem categorias, só `keyword`) + `keyword` + `country` + `perPage(100)`.
2. **Busca:** `ProductResource::search()` por página até `max_pages` ou fim. O limite `max_pages` vale **por categoria**: uma regra com 3 categorias faz no máximo 3 × `max_pages` páginas.
3. **Filtros por item** (sobre o `ProductSummary`): `warehouseInventory ≥ min_stock` e custo dentro de `[min_cost, max_cost]`.
4. **Upsert por página:**
   - existe vínculo para o pid → candidato `imported` (com `lunar_product_id`), conta `already_imported`;
   - candidato `ignored` → não altera, conta `skipped_ignored`;
   - candidato `pending`/`failed` → atualiza nome, custo, estoque, imagem e payload, conta `updated`;
   - novo → `pending`, conta `created`.
5. **Fechamento:** atualiza `last_run_at` e `last_run_stats`.
6. **Erro não recuperável:** grava `last_run_stats.error` e relança (os candidatos das páginas já processadas ficam).
7. **Unicidade:** `ShouldBeUnique` por `import_rule_id`.

### 7.2 Aprovação

- Filament "Importar" (individual/massa): candidatos `pending`/`failed` → `approved`, depois `ImportProductJob` para cada.
- "Ignorar" → `ignored`.
- "Voltar para pendente" → `pending`.

### 7.3 Importação — `ImportProductJob(Candidate)` → `ImportProduct`

1. **Pré-condições:** moeda padrão, `TaxClass` default e canal default existem; senão `failed` com mensagem.
2. **Vínculo existente:** candidato vira `imported` e despacha `SyncProductJob`. Fim.
3. **Candidato** → `importing`.
4. **Busca na CJ:** `find(pid)` e `inventoryByProduct(pid)`.
5. **Transação:** produto (§6.1), opções/variantes (§6.2–6.3), preços (§6.5), `cj_product_links` (copiando markup, arredondamento e país da regra), `cj_variant_links`.
6. **Após o commit:**
   - candidato → `imported` + `lunar_product_id`;
   - despacha `ImportProductImagesJob(link)`;
   - `webhooks()->subscribeProducts([pid])`; falha aqui só gera log e não reverte.
7. **Exceções:**
   - `NotFoundException` → `failed` ("Produto indisponível na CJ"), sem retry;
   - outras → `failed` com a mensagem, relançadas para retry quando transitórias (`RateLimit`/`Server`/`Transport`), com `tries = 3`.
8. **Unicidade:** `ShouldBeUnique` por `cj_product_id`.

### 7.4 Webhooks — `POST {webhooks.path}`

- **Rota:** registrada pelo provider com o middleware `VerifyCjWebhookSignature` do SDK, sem middleware `web` (sem CSRF/sessão).
- **Controller:**
  - lê o `WebhookEvent`;
  - se o `messageId` já foi visto (cache `dedupe_ttl_hours`) → 200;
  - se `type` ∈ {`Product`, `Variant`, `Stock`} e `params` identifica um pid vinculado → `SyncProductJob`;
  - pid: `params.pid` ou `params.productId`; para `Variant`/`Stock` sem pid, busca por `params.vid` em `cj_variant_links`;
  - qualquer outro caso → 200, com log em debug.
- **`cj:webhooks:setup`:**
  - chama `webhooks()->configure(WebhookSettings::make()->product($url)->stock($url))`, com `$url` = `url(config webhooks.path)`, que exige HTTPS público;
  - chama `subscribeProducts` com todos os pids vinculados (o SDK já envia em lotes);
  - imprime o resultado.

### 7.5 Sincronização — `SyncProductJob(ProductLink)` → `SyncProduct`

1. **Busca na CJ:** `find(pid)`.
   - `NotFoundException` → `not_found_count++`; se atingir o limite (padrão 2), aplica o fluxo de indisponível e termina.
2. **Com sucesso:** `not_found_count = 0`, `cj_status = active`; `inventoryByProduct(pid)`.
3. **Para cada `cj_variant_link`:**
   - variante ausente na resposta → estoque 0;
   - senão → estoque via `StockResolver`, e se o custo mudou, `cost_usd` e preços recalculados com markup/arredondamento do vínculo.
4. **Variantes novas:** vids da CJ sem vínculo vão para `new_cj_variant_ids`, sem criar nada.
5. **Encerramento:** `last_synced_at = now`, `sync_error = null`. Nunca altera nome, descrição, imagens ou opções.
6. **Fluxo de indisponível:**
   - `cj_status = unavailable`, estoque 0 em todas as variantes vinculadas;
   - se `unavailable_action = draft`, `product.status = draft`.
7. **Erro:** grava `sync_error`, sem avançar `last_synced_at`, e relança para retry.
8. **Unicidade:** `ShouldBeUnique` por `cj_product_id`.

### 7.6 Variantes novas — `ImportNewVariants(ProductLink)`

Ação manual no admin:
1. `find` + `inventoryByProduct`.
2. Para os ids em `new_cj_variant_ids`: cria as variantes com opções (§6.2), preço (markup do vínculo) e estoque, mais os vínculos.
3. Limpa a lista.

### 7.7 Reconciliação — `cj:sync`

- Despacha `SyncProductJob` em chunks para vínculos com `last_synced_at` nulo ou mais antigo que `stale_after_hours`.
- `--all` força todos.
- `--product={cj_product_id}` sincroniza um só.

### 7.8 Agendamento

Se `schedule.enabled`, o provider registra em `callAfterResolving(Schedule::class)`:
- `cj:discover` (roda as regras ativas) na frequência configurada;
- `cj:sync` na frequência configurada.

## 8. Admin (Filament)

`CjDropshippingPlugin` (padrão `StorefrontThemesPlugin`) registra os 3 resources no grupo de navegação **CJdropshipping**, traduzidos (`en`, `pt_BR`). O app registra o plugin em `LunarPanel::panel(fn ($panel) => $panel->plugins([... , CjDropshippingPlugin::make()]))`. O acesso segue a permissão de catálogo de produtos usada pelos resources do Lunar 1.4; o nome exato é confirmado no plano.

### 8.1 Regras (`ImportRuleResource`, CRUD)

- **Formulário:**
  - nome, ativo;
  - categorias CJ (select múltiplo, pesquisável, opções "Nível1 › Nível2 › Nível3" das categorias de 3º nível via `categories()`, cache 24h);
  - palavra-chave;
  - país do armazém (via `warehouses()`, cache 24h);
  - estoque mínimo, custo mín./máx.;
  - markup %, arredondamento;
  - tipo de produto, marca, coleção;
  - máx. de páginas.
- **Prévia de preço:** placeholder reativo com o preço de um custo exemplo de US$ 10,00 em cada moeda habilitada.
- **Tabela:** nome, ativa, última execução, estatísticas.
- **Ações:** "Buscar candidatos agora" (despacha job + notificação), editar, excluir.

### 8.2 Candidatos (`CandidateResource`, listagem)

- **Colunas:** miniatura (URL remota), nome, SKU CJ, custo USD, preço calculado (moeda padrão e demais habilitadas), estoque, regra, status (badge), descoberto em.
- **Filtros:** status (default `pending`), regra, estoque mínimo.
- **Ações em massa:** Importar, Ignorar, Voltar para pendente.
- **Por linha:** Importar, Ignorar, Tentar de novo (`failed`, erro em tooltip), Abrir no Lunar (`imported`).

### 8.3 Produtos CJ (`ProductLinkResource`, listagem)

- **Colunas:** produto (link para a edição Lunar), SKU CJ, status Lunar, status CJ, nº de variantes vinculadas, nº de variantes novas na CJ, última sincronização, erro.
- **Filtros:** indisponível, com erro, com variantes novas.
- **Ações:** Sincronizar agora (linha/massa), Importar variantes novas (§7.6).

## 9. Erros e observabilidade

- **Status visível:** estatísticas das regras, `error` do candidato, `sync_error` e `last_synced_at` do vínculo.
- **Logs:** canal `log_channel`, contexto `{cj_product_id, job, rule_id}`.
- **Validações da regra (Filament + model):** `product_type_id` obrigatório; `category_ids` ou `keyword`; `markup_percent ≥ 0`; `max_pages` 1–1000; `min_cost ≤ max_cost`.
- **Idempotência:** índices únicos (§4) e `ShouldBeUnique` nos jobs.

## 10. Testes

Orchestra Testbench com os service providers do Lunar core/admin, SQLite em memória e `RefreshDatabase`. O baseline de teste cria `Language` en (padrão) e fr, `Currency` EUR (padrão, rate 1, 2 casas), GBP (rate 0,85) e USD (rate 1,08), `Channel`, `CustomerGroup`, `TaxClass` e `ProductType` com atributos `name`/`description`.

- **CJ fake:** `Psr\Http\Client\ClientInterface` com o `MockHandler` do Guzzle, registrado no container; o provider do SDK o usa. Fixtures JSON reais em `tests/Fixtures/` (listV2, product/query com `productKeyEn`, getInventoryByPid, token, webhook payloads).
- **Imagens:** `Http::fake()` + `Storage::fake(media disk)`.
- **Unit:**
  - `PriceCalculator`: markup, conversão via `Currency` USD e via `usd_to_default_rate`, erro sem taxa, EUR/GBP, arredondamentos, 0 casas decimais, BCMath sem float;
  - `VariantOptionParser`: 1 variante, 2 opções, hífen no último valor, `productKeyEn` ausente/incompatível;
  - `MeasurementConverter`;
  - `StockResolver`: soma, filtro por país, variante ausente.
- **Feature:**
  - `DiscoverCandidates`: filtros, upsert, ignorados, já importados, `max_pages`, quota adiada;
  - `ImportProduct`: rascunho, atributos em todas as línguas, opções/variantes, preços EUR/GBP/USD, estoque, vínculos, coleção/canal, idempotência, rollback, pré-condições, NotFound;
  - `ImportProductImages`: primary, sem duplicar, falha parcial;
  - `SyncProduct`: estoque, recálculo de preço com markup do vínculo, 2× NotFound → indisponível (+ draft opcional), variantes novas registradas;
  - `ImportNewVariants`;
  - `WebhookController`: 200 + job despachado, dedupe, pid não vinculado, busca por vid, 401;
  - comandos `cj:discover`, `cj:sync` (stale, `--all`, `--product`), `cj:webhooks:setup`.
- **Filament** (Livewire tests): formulário de regra (validações), ação de buscar candidatos, ações individual/massa de candidatos, ações de vínculos.
- **Integration (grupo `live`, fora do padrão):** descobrir 1 página de uma categoria real e importar 1 candidato de ponta a ponta (com `CJ_API_KEY`).
- **Qualidade:** Larastan nível 6 e Pint.

## 11. Critérios de aceite

1. `composer test` passa sem rede; Larastan nível 6 e Pint limpos.
2. No app lunar, com o plugin registrado, `QUEUE_CONNECTION=database` e um worker na fila `cjdropshipping`:
   1. criar uma regra para uma categoria CJ e buscar candidatos lista produtos com preço calculado em cada moeda habilitada (EUR e GBP);
   2. aprovar 3 candidatos cria 3 produtos `draft` com variantes/opções, preços EUR/GBP, estoque e imagens;
   3. `php artisan cj:sync --product={pid}` atualiza estoque/preço de um produto importado;
   4. um webhook `STOCK` assinado para um pid vinculado despacha a sincronização.
