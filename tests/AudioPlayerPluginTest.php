<?php

/**
 * @file plugins/generic/audioPlayer/tests/AudioPlayerPluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3.
 *
 * @class AudioPlayerPluginTest
 *
 * @brief Cobre o calculo de faixa HTTP (RFC 9110 secao 14.1), que e o que o
 *        navegador exercita a cada arrasto da barra de progresso, a deteccao de
 *        audio e a integridade dos locales.
 */

namespace APP\plugins\generic\audioPlayer\tests;

use APP\plugins\generic\audioPlayer\AudioPlayerPlugin;
use PKP\tests\PKPTestCase;

class AudioPlayerPluginTest extends PKPTestCase
{
    private const DIR = __DIR__ . '/..';
    private const TAM = 1000;   // arquivo de 1000 bytes nos exemplos

    /** Sem cabecalho Range a resposta e inteira (200, nao 206). */
    public function testSemFaixaDevolveArquivoInteiro(): void
    {
        $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange('', self::TAM));
        $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange('   ', self::TAM));
    }

    /** Faixa fechada, aberta e de um byte so. */
    public function testFaixasValidas(): void
    {
        $this->assertSame([0, 499, true], AudioPlayerPlugin::resolveRange('bytes=0-499', self::TAM));
        $this->assertSame([500, 999, true], AudioPlayerPlugin::resolveRange('bytes=500-', self::TAM));
        $this->assertSame([0, 0, true], AudioPlayerPlugin::resolveRange('bytes=0-0', self::TAM));
        $this->assertSame([999, 999, true], AudioPlayerPlugin::resolveRange('bytes=999-', self::TAM));
    }

    /** Faixa de sufixo: os ultimos N bytes. */
    public function testFaixaDeSufixo(): void
    {
        $this->assertSame([500, 999, true], AudioPlayerPlugin::resolveRange('bytes=-500', self::TAM));
        // sufixo maior que o arquivo entrega o arquivo todo, nao estoura
        $this->assertSame([0, 999, true], AudioPlayerPlugin::resolveRange('bytes=-5000', self::TAM));
    }

    /** O fim alem do tamanho e truncado no ultimo byte. */
    public function testFimAlemDoTamanhoETruncado(): void
    {
        $this->assertSame([0, 999, true], AudioPlayerPlugin::resolveRange('bytes=0-99999', self::TAM));
    }

    /** Faixa insatisfazivel tem de virar 416, nao resposta parcial errada. */
    public function testFaixasInsatisfaziveis(): void
    {
        foreach (['bytes=-', 'bytes=-0', 'bytes=1000-', 'bytes=5000-6000', 'bytes=600-500'] as $h) {
            $this->assertFalse(AudioPlayerPlugin::resolveRange($h, self::TAM), "deveria recusar: {$h}");
        }
    }

    /** Cabecalho malformado e ignorado (resposta inteira), nunca aceito. */
    public function testCabecalhoMalformadoNaoViraFaixa(): void
    {
        foreach (['items=0-10', 'bytes=abc-def', 'bytes 0-10', 'bytes=0-10, 20-30'] as $h) {
            $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange($h, self::TAM), "deveria ignorar: {$h}");
        }
    }

    /** Audio reconhecido pelo mimetype ou, se ele nao ajudar, pela extensao. */
    public function testDeteccaoDeAudio(): void
    {
        $this->assertTrue(AudioPlayerPlugin::isAudioFile('audio/mpeg', null, null));
        $this->assertTrue(AudioPlayerPlugin::isAudioFile('application/octet-stream', 'faixa.mp3', null));
        $this->assertTrue(AudioPlayerPlugin::isAudioFile(null, null, '/x/y/faixa.m4b'));
        $this->assertFalse(AudioPlayerPlugin::isAudioFile('application/pdf', 'livro.pdf', null));
        $this->assertFalse(AudioPlayerPlugin::isAudioFile(null, 'livro.epub', null));
    }

    /** Audio salvo como octet-stream nao toca: a extensao tem a palavra final. */
    public function testMimetypeResolvidoPelaExtensao(): void
    {
        $this->assertSame('audio/mpeg', AudioPlayerPlugin::resolveMimetype('application/octet-stream', 'a.mp3', null));
        $this->assertSame('audio/mp4', AudioPlayerPlugin::resolveMimetype(null, 'a.m4b', null));
        $this->assertSame('audio/ogg', AudioPlayerPlugin::resolveMimetype('audio/ogg', 'a.desconhecido', null));
        $this->assertSame('application/octet-stream', AudioPlayerPlugin::resolveMimetype(null, 'a.xyz', null));
    }

    /** Todo locale precisa ter TODAS as chaves: no 3.5 a que falta vira ##chave##. */
    public function testTodosOsLocalesTemTodasAsChaves(): void
    {
        $chavesEn = array_keys($this->chavesDe(self::DIR . '/locale/en/locale.po'));
        $this->assertNotEmpty($chavesEn);
        foreach (glob(self::DIR . '/locale/*/locale.po') as $arquivo) {
            $locale = basename(dirname($arquivo));
            $chaves = $this->chavesDe($arquivo);
            $this->assertSame([], array_values(array_diff($chavesEn, array_keys($chaves))), "locale {$locale} sem chaves");
            foreach ($chaves as $chave => $valor) {
                $this->assertNotSame('', trim($valor), "locale {$locale}: chave {$chave} vazia");
            }
        }
    }

    /** Codigos legados nao carregam no 3.5. */
    public function testNaoUsaCodigosDeLocaleLegados(): void
    {
        foreach (['fr_FR', 'pt_PT', 'nb', 'sr', 'zh_CN'] as $legado) {
            $this->assertDirectoryDoesNotExist(self::DIR . '/locale/' . $legado);
        }
    }

    /** @return array<string,string> */
    private function chavesDe(string $arquivo): array
    {
        $this->assertFileExists($arquivo);
        $out = [];
        foreach ((new \Gettext\Loader\PoLoader())->loadFile($arquivo) as $t) {
            if ($t->getOriginal() === '') {
                continue;
            }
            $out[$t->getOriginal()] = (string) $t->getTranslation();
        }
        return $out;
    }

    /**
     * O plugin nao pode ganhar um caminho proprio ate o arquivo.
     *
     * Toda a autorizacao do download no OMP vive em CatalogBookHandler::download:
     * OmpPublishedSubmissionAccessPolicy, o formato disponivel, a publicacao
     * publicada e o direct_sales_price do arquivo. O hook so e disparado depois
     * de tudo isso passar, entao o plugin herda a checagem inteira sem repetir
     * uma linha dela. O risco real nao e a logica de hoje, e alguem amanha mover
     * o plugin para um hook que roda ANTES (LoadHandler, por exemplo) e abrir um
     * caminho para o arquivo sem passar pela politica. Este teste quebra nesse dia.
     *
     * Verificado tambem em servidor, pedindo o mesmo mp3 com ?audioStream=1:
     * arquivo liberado 206; direct_sales_price NULL 404; formato indisponivel
     * 404; publicacao nao publicada 404 — identico ao download normal.
     */
    public function testSoEnganchaEmHooksPosAutorizacao(): void
    {
        $codigo = file_get_contents(self::DIR . '/AudioPlayerPlugin.php');
        preg_match_all("/Hook::add\\(\\s*'([^']+)'/", $codigo, $m);
        $esperados = [
            'CatalogBookHandler::download',   // disparado apos toda a politica de acesso
            'TemplateManager::display',
            'Templates::Catalog::Book::Main',
        ];
        sort($esperados);
        $achados = array_unique($m[1]);
        sort($achados);
        $this->assertSame($esperados, $achados, 'conjunto de hooks mudou: revisar o acesso antes de aceitar');
    }

    /** Nenhum roteamento proprio, que serviria arquivo fora da politica do core. */
    public function testNaoRegistraRotaPropria(): void
    {
        $codigo = file_get_contents(self::DIR . '/AudioPlayerPlugin.php');
        foreach (['LoadHandler', 'LoadComponentHandler', 'Dispatcher::'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $codigo, "o plugin nao pode registrar rota propria ({$proibido})");
        }
    }

    /** Sem arquivo no hook o plugin declina em vez de improvisar. */
    public function testDeclinaSemArquivo(): void
    {
        $plugin = new AudioPlayerPlugin();
        $this->assertFalse($plugin->streamAudio('CatalogBookHandler::download', [null, null, null, null, false]));
        $this->assertFalse($plugin->streamAudio('CatalogBookHandler::download', []));
    }
}
