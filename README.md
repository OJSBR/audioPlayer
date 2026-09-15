# audioPlayer — OMP plugin

[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.2.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OMP 3.5](https://github.com/OJSBR/audioPlayer/releases/download/1.0.2.0-omp3.5/audioPlayer-1.0.2.0-omp3.5.tar.gz) — or browse all [Releases](../../releases).

**▶️ Live demo:** [Editora UEMG — audiobook with 26 tracks](https://ebooks.editora.uemg.br/editora/pt_BR/catalog/book/5)

Turns the audio files of a monograph into a listenable **audiobook**: a play button
next to every audio file on the book page, plus a player bar with in-track seeking,
previous/next track, adjustable speed, continuous playback and resume from where the
listener stopped. The original download button is left untouched.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Version | Branch | Plugin release |
|-------------|---------|--------|----------------|
| OMP | 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.2.0 |

## What it does

- **Play button on every audio file** of the book page, beside the download button.
- **Player bar** with seeking inside the track, previous/next, speed and close.
- **Continuous playback** within the same publication format, so an audiobook never
  runs into the PDF of another format.
- **Resume**: the position is kept per track in the listener's own browser.
- **Downloads are not inflated**: playback is not counted as a download. Counting every
  drag of the progress bar in the same metric would distort the series. The download
  button keeps counting normally.

Accepted formats: `mp3`, `m4a`, `m4b`, `aac`, `ogg`, `oga`, `opus`, `wav`, `flac`,
`weba` — by the registered mimetype or, when it is generic
(`application/octet-stream`, which OMP stores for some uploads), by the extension.

## Installation

Upload the package in **Settings → Website → Plugins → Upload A New Plugin**, or clone
the branch into `plugins/generic/audioPlayer`. Then enable it in the plugin list.

## Configuration

**Settings → Website → Plugins → Audiobook Player → Settings**

- **Play the next track automatically** (default: on)
- **Remember where the listener stopped** (default: on) — stored in the listener's
  `localStorage`; nothing is sent to the server.
- **Initial playback speed** (default: 1×) — from 0.75× to 2×.

## How it works (technical)

**Why the plugin serves the file itself.** `PKPFileService` answers
`Accept-Ranges: none`. Without HTTP Range the browser cannot drag the progress bar or
resume in the middle of a track — with 10–15 MB chapters that makes an audiobook
unusable.

The plugin takes over delivery through the `CatalogBookHandler::download` hook, which
fires **after** every access check the core performs:

- publication format available and not remote;
- publication in published status;
- file belonging to that format;
- open access (`directSalesPrice = 0`) or paid purchase;
- press access restriction (`restrictMonographAccess`).

**None of those rules is reimplemented.** The plugin only steps in when the request
carries `audioStream=1` **and** the file really is audio; anything else follows the
core path untouched.

Range support is complete: `206 Partial Content`, suffix ranges (`bytes=-500`) and
`416` for an unsatisfiable range.

> **Known limitation of the hosting layer, not of the plugin.** If nginx (or any proxy)
> sits in front with `proxy_cache` enabled and without `proxy_set_header Range
> $http_range`, the `Range` header is stripped before it reaches PHP: the endpoint then
> answers `200` instead of `206`, and seeking stops working. Two commands tell this
> apart from a plugin bug — a static file returns `206` while the PHP route returns
> `200`.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`, 26 tests): the HTTP Range rules of
  RFC 9110 §14.1 (closed, open, single-byte, suffix, suffix larger than the file, end past the
  file, and five unsatisfiable forms that must become `416`), audio detection by mimetype and by
  extension, the mimetype sent when the stored one is generic, access control (the set of hooks
  is asserted exactly, all of them called after `CatalogBookHandler::download` has run OMP's
  access policy, and no route of its own), the site level without settings, the plugin classes
  against the installed PKP, the 38 translations and the template. From the installation root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/audioPlayer/tests"
  ```

- **Cypress** (`cypress/tests/functional/AudioPlayer.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on OMP on every push): enables
  the plugin and saves its settings, reopening the form to prove they persisted and putting them
  back. With `audioBookPage` (the path of a book page with an audio format) it also builds the
  player, checks that a `Range` request answers `206` with a correct `Content-Range` (it fails
  with the hook off) and an unsatisfiable one `416`, and that a file outside the format is refused
  with the same status with and without `?audioStream=1`.

  ```bash
  npx cypress run --config specPattern='plugins/generic/audioPlayer/cypress/tests/functional/*.cy.js' \
    --env contextPath=<press>,adminUser=<user>,adminPassword=<password>,audioBookPage=index.php/<press>/catalog/book/14
  ```

- Verified on OMP 3.5.0.3 with a published audiobook.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **OJSBR** — https://ojsbr.com — plugin design, implementation and maintenance.
- **Public Knowledge Project (PKP)** — Open Monograph Press and the plugin API this
  builds on.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome — see `CONTRIBUTING.md` and
`CODE_OF_CONDUCT.md`. Target the branch matching your OMP version.

## License

GNU GPL v3 — see [`LICENSE`](LICENSE) and [`docs/COPYING`](docs/COPYING).

---

## 🇧🇷 Português

Transforma os arquivos de áudio de um livro em **audiolivro**: botão de tocar ao lado
de cada arquivo na página do livro, mais uma barra de player com busca dentro da faixa,
faixa anterior/próxima, velocidade, reprodução em sequência e retomada de onde o ouvinte
parou. O botão de download original **não é alterado**.


**▶️ Demonstração:** [Editora UEMG — audiolivro com 26 faixas](https://ebooks.editora.uemg.br/editora/pt_BR/catalog/book/5)

### Compatibilidade e branches

| Aplicação | Versão | Branch | Release do plugin |
|-----------|--------|--------|-------------------|
| OMP | 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.2.0 |

### O que faz

- **Botão de tocar em cada arquivo de áudio**, ao lado do botão de download.
- **Barra de player** com busca na faixa, anterior/próxima, velocidade e fechar.
- **Reprodução em sequência** dentro do mesmo formato de publicação, para um audiolivro
  não emendar no PDF de outro formato.
- **Retomada**: a posição fica guardada por faixa no navegador do próprio ouvinte.
- **Não infla estatística**: reprodução não conta como download. Somar cada busca na
  barra à mesma métrica distorceria a série histórica; o botão de download continua
  contando normalmente.

Formatos aceitos: `mp3`, `m4a`, `m4b`, `aac`, `ogg`, `oga`, `opus`, `wav`, `flac`,
`weba` — pelo mimetype registrado ou, quando ele é genérico
(`application/octet-stream`, que o OMP grava em alguns envios), pela extensão.

### Instalação

Envie o pacote em **Configurações → Website → Plugins → Enviar um novo plugin**, ou
clone a branch em `plugins/generic/audioPlayer`. Depois ative na lista de plugins.

### Configuração

**Configurações → Website → Plugins → Player de audiolivro → Configurações**

- **Tocar a próxima faixa automaticamente** (padrão: ligado)
- **Lembrar onde o ouvinte parou** (padrão: ligado) — guardado no `localStorage` do
  navegador do leitor; nada vai para o servidor.
- **Velocidade inicial** (padrão: 1×) — de 0,75× a 2×.

### Como funciona (técnico)

**Por que o plugin entrega o arquivo.** O `PKPFileService` responde
`Accept-Ranges: none`. Sem HTTP Range o navegador não arrasta a barra de progresso nem
retoma no meio da faixa — em capítulos de 10 a 15 MB isso inviabiliza ouvir.

A entrega é assumida pelo hook `CatalogBookHandler::download`, que dispara **depois** de
toda a validação de acesso do core (formato disponível e não remoto, publicação
publicada, arquivo pertencente ao formato, acesso aberto ou compra paga, e a restrição
de acesso da editora). **Nenhuma dessas regras é reimplementada.**

> **Limitação da hospedagem, não do plugin.** Com nginx à frente usando `proxy_cache` e
> sem `proxy_set_header Range $http_range`, o cabeçalho `Range` é removido antes de
> chegar ao PHP: o endpoint passa a responder `200` em vez de `206` e a busca na barra
> para de funcionar. Para distinguir de bug do plugin: arquivo estático devolve `206` e
> a rota PHP devolve `200`.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`, 26 testes) e Cypress em
`cypress/tests/functional/` (rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions)
no OMP a cada push), com os comandos da seção em inglês. A suíte cobre as regras de HTTP Range
(RFC 9110 §14.1, incluindo as formas que viram `416`), a detecção de áudio, o mimetype enviado, o
controle de acesso (o conjunto de hooks é conferido exatamente e nenhum roda antes da política de
acesso do OMP), o nível do site sem configurações, as 38 traduções e o template. Com `audioBookPage`,
o Cypress monta o player e confere a resposta `206` a um pedido `Range`.

Verificado no OMP 3.5.0.3 com um audiolivro publicado.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **OJSBR** — https://ojsbr.com — concepção, implementação e manutenção.
- **Public Knowledge Project (PKP)** — o Open Monograph Press e a API de plugins.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

GNU GPL v3 — veja [`LICENSE`](LICENSE) e [`docs/COPYING`](docs/COPYING).
