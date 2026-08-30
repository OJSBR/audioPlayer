<?php

/**
 * @file plugins/generic/audioPlayer/AudioPlayerPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AudioPlayerPlugin
 *
 * @ingroup plugins_generic_audioPlayer
 *
 * @brief Player de audiolivro para a pagina do livro no catalogo do OMP.
 *
 *        Acrescenta um botao tocar/pausar em cada arquivo de audio de um
 *        formato de publicacao e uma barra de player com busca na faixa,
 *        faixa anterior/proxima, velocidade de reproducao, reproducao em
 *        sequencia e retomada da posicao onde o ouvinte parou.
 *
 *        O botao de download original nao e alterado.
 *
 *        Duas pecas:
 *
 *          1. Um endpoint de streaming com suporte a HTTP Range. O
 *             PKPFileService responde `Accept-Ranges: none`, o que impede
 *             busca dentro da faixa e retomada. O plugin assume a resposta
 *             pelo hook `CatalogBookHandler::download`, que so e disparado
 *             DEPOIS de toda a validacao de acesso do core (formato
 *             disponivel, publicacao publicada, acesso aberto ou compra
 *             paga, restricao de acesso da editora). Nenhuma regra de
 *             autorizacao e reimplementada aqui.
 *
 *          2. CSS e JS injetados apenas na pagina do livro, que enriquecem
 *             as linhas de download ja renderizadas pelo core.
 *
 *        Compatibilidade: OMP 3.5.x.
 */

namespace APP\plugins\generic\audioPlayer;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class AudioPlayerPlugin extends GenericPlugin
{
    /** Template da pagina publica do livro. */
    private const BOOK_TEMPLATE = 'frontend/pages/book.tpl';

    /** Parametro que marca a requisicao como reproducao, e nao download. */
    private const STREAM_PARAM = 'audioStream';

    /** Tamanho do bloco lido do disco a cada iteracao do streaming. */
    private const CHUNK_SIZE = 262144; // 256 KB

    /**
     * Extensoes tratadas como audio quando o mimetype registrado nao ajuda.
     * O OMP as vezes grava application/octet-stream para arquivos enviados.
     */
    private const AUDIO_EXTENSIONS = [
        'mp3', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'weba',
    ];

    /**
     * Mimetype correto para cada extensao, usado quando o registrado e
     * generico. Sem isso o navegador recusa tocar octet-stream.
     */
    private const EXTENSION_MIME = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'm4b' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'weba' => 'audio/webm',
    ];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        // Nao registra durante instalacao/upgrade.
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return $success;
        }

        if ($success && $this->getEnabled($mainContextId)) {
            // Assume a entrega do arquivo quando a requisicao e de reproducao.
            Hook::add('CatalogBookHandler::download', $this->streamAudio(...));

            // Carrega CSS e JS somente na pagina do livro.
            Hook::add('TemplateManager::display', $this->addAssets(...));

            // Injeta o container com a lista de faixas dentro da pagina.
            Hook::add('Templates::Catalog::Book::Main', $this->injectPlayerData(...));
        }

        return $success;
    }

    /**
     * Nome estavel no registry e nas URLs do gerenciador de plugins.
     * Com namespace, o getName() padrao devolveria o FQCN em minusculas.
     *
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'audioplayerplugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.audioPlayer.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.audioPlayer.description');
    }

    /**
     * Botao "Configuracoes" na linha do plugin.
     *
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        $form = new AudioPlayerSettingsForm($this, $context->getId());

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
        } else {
            $form->initData();
        }
        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * Carrega CSS e JS apenas na pagina publica do livro.
     *
     * @param array  $args     [$templateMgr, &$template, &$sendContentType, &$charset, &$output]
     */
    public function addAssets(string $hookName, array $args): bool
    {
        if (($args[1] ?? null) !== self::BOOK_TEMPLATE) {
            return false;
        }

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();

        // O PKP so acrescenta ?v={versao do OMP} quando a URL nao tem query.
        // Versionar pela versao do plugin faz o navegador buscar o arquivo
        // novo a cada atualizacao, em vez de servir o CSS/JS antigo do cache.
        $version = $this->getCurrentVersion();
        $stamp = '?v=' . urlencode($version ? $version->getVersionString() : '1.0.0.0');

        $templateMgr->addStyleSheet(
            'audioPlayer',
            $base . '/css/audioPlayer.css' . $stamp,
            ['contexts' => ['frontend']]
        );
        $templateMgr->addJavaScript(
            'audioPlayer',
            $base . '/js/audioPlayer.js' . $stamp,
            ['contexts' => ['frontend']]
        );

        return false;
    }

    /**
     * Injeta na pagina um elemento com a lista de faixas de audio em JSON.
     * O JS le esse elemento e enriquece as linhas ja renderizadas pelo core.
     *
     * Sem faixas de audio no livro, nada e injetado.
     *
     * @param array  $args     [$params, $smarty, &$output]
     */
    public function injectPlayerData(string $hookName, array $args): bool
    {
        $smarty = $args[1] ?? null;
        $output = &$args[2];
        if (!$smarty) {
            return false;
        }

        // publicationFormats e availableFiles sao atribuidos pelo handler no
        // template manager, entao sao visiveis aqui. Ja $monograph chega ao
        // monograph_full.tpl como parametro de {include}, de escopo local, e
        // nao aparece em getTemplateVars(). A submissao vem do handler.
        $formats = $smarty->getTemplateVars('publicationFormats');
        $files = $smarty->getTemplateVars('availableFiles');
        if (empty($formats) || empty($files)) {
            return false;
        }

        $request = Application::get()->getRequest();
        $handler = $request->getRouter()->getHandler();
        if (!$handler) {
            return false;
        }
        $monograph = $handler->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        if (!$monograph) {
            return false;
        }
        $publication = $handler->publication ?? $monograph->getCurrentPublication();
        if (!$publication) {
            return false;
        }

        $payload = $this->buildTrackList($formats, $files, $monograph, $publication);
        if (empty($payload['formats'])) {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $output .= '<div class="ojsbrAudioPlayerData" hidden data-config="'
            . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"></div>';

        return false;
    }

    /**
     * Monta a lista de faixas agrupada por formato de publicacao.
     *
     * A ordem e a mesma em que o core lista os arquivos, que ja e a ordem
     * definida pela editora no formato. Nomes do tipo "001_Abertura.mp3"
     * ficam naturalmente na sequencia certa.
     *
     * @param array $formats     PublicationFormat[]
     * @param array $files       SubmissionFile[]
     */
    private function buildTrackList(array $formats, array $files, $monograph, $publication): array
    {
        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $isCurrent = $publication->getId() === $monograph->getCurrentPublication()->getId();

        $out = [];
        foreach ($formats as $format) {
            if ($format->getData('urlRemote')) {
                continue;
            }
            $tracks = [];
            foreach ($files as $file) {
                if ((int) $file->getData('assocId') !== (int) $format->getId()) {
                    continue;
                }
                $name = $file->getLocalizedData('name');
                if (!self::isAudioFile($file->getData('mimetype'), $name, $file->getData('path'))) {
                    continue;
                }

                $path = [$monograph->getBestId()];
                if (!$isCurrent) {
                    $path[] = 'version';
                    $path[] = $publication->getId();
                }
                $path[] = $format->getBestId();
                $path[] = $file->getBestId();

                $tracks[] = [
                    'id' => (int) $file->getId(),
                    'name' => $name,
                    // URL identica a do link de download ja presente na pagina,
                    // usada pelo JS para localizar a linha correspondente.
                    'viewUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'view', $path),
                    // URL de reproducao: mesmo controle de acesso, com Range.
                    'streamUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'download', $path, [
                        'inline' => 1,
                        self::STREAM_PARAM => 1,
                    ]),
                ];
            }
            if ($tracks) {
                $out[] = [
                    'id' => (int) $format->getId(),
                    'label' => $format->getLocalizedName(),
                    'tracks' => $tracks,
                ];
            }
        }

        $contextId = $request->getContext() ? $request->getContext()->getId() : null;

        return [
            'submissionId' => (int) $monograph->getId(),
            'autoplayNext' => (bool) $this->getSettingWithDefault($contextId, 'autoplayNext', true),
            'rememberPosition' => (bool) $this->getSettingWithDefault($contextId, 'rememberPosition', true),
            'defaultSpeed' => (float) $this->getSettingWithDefault($contextId, 'defaultSpeed', 1),
            'formats' => $out,
            'i18n' => [
                'play' => __('plugins.generic.audioPlayer.play'),
                'pause' => __('plugins.generic.audioPlayer.pause'),
                'previous' => __('plugins.generic.audioPlayer.previous'),
                'next' => __('plugins.generic.audioPlayer.next'),
                'close' => __('plugins.generic.audioPlayer.close'),
                'speed' => __('plugins.generic.audioPlayer.speed'),
                'seek' => __('plugins.generic.audioPlayer.seek'),
                'player' => __('plugins.generic.audioPlayer.player'),
                'error' => __('plugins.generic.audioPlayer.error'),
            ],
        ];
    }

    /**
     * Setting da editora com valor padrao quando nunca foi gravado.
     * getSetting() devolve null tanto para "nao configurado" quanto para
     * um valor vazio; so o primeiro caso deve cair no padrao.
     */
    private function getSettingWithDefault(?int $contextId, string $name, $default)
    {
        if ($contextId === null) {
            return $default;
        }
        $value = $this->getSetting($contextId, $name);
        return $value === null ? $default : $value;
    }

    /**
     * Entrega o arquivo de audio com suporte a HTTP Range.
     *
     * Este hook so e alcancado depois que o CatalogBookHandler validou:
     * formato de publicacao disponivel e nao remoto, publicacao publicada,
     * arquivo pertencente ao formato, acesso aberto ou compra paga, e a
     * restricao de acesso da editora. Nenhuma dessas regras e refeita aqui.
     *
     * So assume a resposta quando a requisicao traz o parametro de
     * reproducao E o arquivo e de fato audio; qualquer outro caso segue
     * pelo caminho normal do core, com o download intacto.
     *
     * @param array  $args     [&$handler, &$submission, &$publicationFormat, &$submissionFile, &$inline]
     *
     * @return bool true quando o plugin respondeu (o core entao encerra)
     */
    public function streamAudio(string $hookName, array $args): bool
    {
        $submissionFile = $args[3] ?? null;
        if (!$submissionFile) {
            return false;
        }

        $request = Application::get()->getRequest();
        if (!$request->getUserVar(self::STREAM_PARAM)) {
            return false;
        }

        $fileService = app()->get('file');
        $file = $fileService->get($submissionFile->getData('fileId'));
        if (!$file) {
            return false;
        }

        $name = $submissionFile->getLocalizedData('name');
        if (!self::isAudioFile($file->mimetype ?? null, $name, $file->path)) {
            return false;
        }

        return $this->sendRangeResponse(
            $fileService,
            $file->path,
            self::resolveMimetype($file->mimetype ?? null, $name, $file->path),
            $fileService->formatFilename($file->path, $name)
        );
    }

    /**
     * Escreve a resposta HTTP honrando o cabecalho Range.
     *
     * @return bool true se a resposta foi enviada
     */
    private function sendRangeResponse($fileService, string $path, string $mimetype, string $filename): bool
    {
        $fs = $fileService->fs;
        if (!$fs->has($path)) {
            return false;
        }

        $size = (int) $fs->fileSize($path);
        if ($size <= 0) {
            return false;
        }

        $faixa = self::resolveRange($_SERVER['HTTP_RANGE'] ?? '', $size);
        if ($faixa === false) {
            return $this->sendUnsatisfiable($size);
        }
        [$start, $end, $partial] = $faixa;

        $length = $end - $start + 1;
        $etag = '"' . md5($path . ':' . $size) . '"';

        // Buffers e compressao atrapalham o streaming por faixa.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ini_set('zlib.output_compression', 'Off');
        set_time_limit(0);

        header_remove('Pragma');
        if ($partial) {
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            header('HTTP/1.1 200 OK');
        }
        header("Content-Type: {$mimetype}");
        header("Content-Length: {$length}");
        header('Accept-Ranges: bytes');
        header("ETag: {$etag}");
        $encoded = rawurlencode($filename);
        header("Content-Disposition: inline; filename=\"{$encoded}\"; filename*=UTF-8''{$encoded}");
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }

        $stream = $fs->readStream($path);
        if (!is_resource($stream)) {
            return false;
        }

        // O adaptador local devolve um stream de arquivo real, que aceita
        // fseek. Se algum dia nao aceitar, descarta os bytes iniciais lendo.
        if ($start > 0 && fseek($stream, $start) !== 0) {
            $skipped = 0;
            while ($skipped < $start && !feof($stream)) {
                $chunk = fread($stream, min(self::CHUNK_SIZE, $start - $skipped));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $skipped += strlen($chunk);
            }
        }

        // Interrompe a leitura se o ouvinte fechar a pagina ou pular a faixa.
        ignore_user_abort(false);

        $remaining = $length;
        while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
            $chunk = fread($stream, (int) min(self::CHUNK_SIZE, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }
        fclose($stream);
        exit;
    }

    /**
     * Responde 416 quando a faixa pedida nao existe no arquivo.
     */
    private function sendUnsatisfiable(int $size): bool
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes */{$size}");
        header('Accept-Ranges: bytes');
        exit;
    }

    /**
     * O arquivo e audio? Considera o mimetype registrado e, quando ele e
     * generico (o OMP grava application/octet-stream em alguns envios),
     * a extensao do nome exibido ou do arquivo em disco.
     */
    /**
     * Calcula a faixa a servir a partir do cabecalho Range.
     *
     * Puro de proposito: e a regra mais delicada do plugin (RFC 9110 secao
     * 14.1) e a unica que o navegador exercita a cada arrasto da barra de
     * progresso. Separada do I/O, da para cobrir por teste sem servidor.
     *
     * @return array{0:int,1:int,2:bool}|false [inicio, fim, parcial] ou false
     *         quando a faixa e insatisfazivel (deve virar 416).
     */
    public static function resolveRange(string $header, int $size)
    {
        $start = 0;
        $end = $size - 1;

        $header = trim($header);
        if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m)) {
            return [$start, $end, false];   // sem faixa: resposta inteira, 200
        }

        [$from, $to] = [$m[1], $m[2]];
        if ($from === '' && $to === '') {
            return false;
        }
        if ($from === '') {
            // Sufixo: os ultimos N bytes.
            $length = (int) $to;
            if ($length <= 0) {
                return false;
            }
            $start = max(0, $size - $length);
        } else {
            $start = (int) $from;
            if ($to !== '') {
                $end = min((int) $to, $size - 1);
            }
        }
        if ($start > $end || $start >= $size) {
            return false;
        }
        return [$start, $end, true];
    }

    public static function isAudioFile(?string $mimetype, ?string $name, ?string $path): bool
    {
        if ($mimetype && str_starts_with(strtolower($mimetype), 'audio/')) {
            return true;
        }
        return in_array(self::extensionOf($name) ?: self::extensionOf($path), self::AUDIO_EXTENSIONS, true);
    }

    /**
     * Mimetype a enviar. Um audio servido como application/octet-stream
     * nao toca no navegador, entao a extensao tem a palavra final.
     */
    public static function resolveMimetype(?string $mimetype, ?string $name, ?string $path): string
    {
        $ext = self::extensionOf($name) ?: self::extensionOf($path);
        if (isset(self::EXTENSION_MIME[$ext])) {
            return self::EXTENSION_MIME[$ext];
        }
        if ($mimetype && str_starts_with(strtolower($mimetype), 'audio/')) {
            return $mimetype;
        }
        return 'application/octet-stream';
    }

    public static function extensionOf(?string $filename): string
    {
        if (!$filename) {
            return '';
        }
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: '');
    }
}
