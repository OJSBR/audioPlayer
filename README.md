# audioPlayer — OMP plugin

[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OMP 3.5](https://github.com/OJSBR/audioPlayerOmp/releases/download/1.0.1.0-omp3.5/audioPlayer-1.0.1.0-omp3.5.tar.gz) — or browse all [Releases](../../releases).

Turns the audio files of a monograph into a listenable **audiobook**: a play button
next to every audio file on the book page, plus a player bar with in-track seeking,
previous/next track, adjustable speed, continuous playback and resume from where the
listener stopped. The original download button is left untouched.

> **Developed and maintained by [OJSBR](https://ojsbr.com.br).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Version | Branch | Plugin release |
|-------------|---------|--------|----------------|
| OMP | 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |

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

PHPUnit, in the PKP `ApplicationPlugins` suite:

```bash
cd lib/pkp/tests
php ../lib/vendor/bin/phpunit --no-coverage -c phpunit.xml \
  /absolute/path/to/plugins/generic/audioPlayer/tests
```

10 tests / 680 assertions covering the HTTP Range computation against RFC 9110 §14.1
(closed, open, single-byte, suffix, suffix larger than the file, end past the file, and
five unsatisfiable forms that must become `416`), audio detection by mimetype and by
extension, mimetype resolution when the stored type is generic, and locale integrity —
every locale carrying every key with no empty value, and no legacy locale codes, since
in 3.5 a missing key renders as `##key##` instead of falling back to English.

Cypress specs are not included yet; the browser behaviour was verified by hand against
a real installation.

## Credits & authorship

- **OJSBR** — https://ojsbr.com.br — plugin design, implementation and maintenance.
- **Public Knowledge Project (PKP)** — Open Monograph Press and the plugin API this
  builds on.

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

### Compatibilidade e branches

| Aplicação | Versão | Branch | Release do plugin |
|-----------|--------|--------|-------------------|
| OMP | 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |

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

PHPUnit, na suíte `ApplicationPlugins` do PKP — 10 testes e 680 asserções cobrindo o
cálculo de faixa HTTP contra a RFC 9110 §14.1 (fechada, aberta, de um byte, sufixo,
sufixo maior que o arquivo, fim além do tamanho e as cinco formas insatisfazíveis que
têm de virar `416`), a detecção de áudio por mimetype e por extensão, a resolução de
mimetype quando o tipo gravado é genérico, e a integridade dos locales — todos com todas
as chaves, sem valor vazio e sem códigos legados, já que no 3.5 chave faltante vira
`##chave##` em vez de cair no inglês.

Ainda não há specs de Cypress; o comportamento no navegador foi verificado à mão contra
uma instalação real.

### Créditos e autoria

- **OJSBR** — https://ojsbr.com.br — concepção, implementação e manutenção.
- **Public Knowledge Project (PKP)** — o Open Monograph Press e a API de plugins.

### Licença

GNU GPL v3 — veja [`LICENSE`](LICENSE) e [`docs/COPYING`](docs/COPYING).
