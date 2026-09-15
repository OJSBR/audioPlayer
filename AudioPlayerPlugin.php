<?php

/**
 * @file plugins/generic/audioPlayer/AudioPlayerPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AudioPlayerPlugin
 *
 * @brief Audiobook player for the book page of the OMP catalogue.
 *
 * Adds a play/pause button to every audio file of a publication format and a
 * player bar with seeking, previous/next track, playback speed, continuous
 * play and resuming where the listener stopped. The core download link is not
 * changed.
 *
 * Two parts:
 *
 *   1. Streaming with HTTP Range. The core file service answers
 *      `Accept-Ranges: none`, which rules out seeking and resuming. The plugin
 *      takes over the response in `CatalogBookHandler::download`, which is only
 *      called AFTER the core has checked access (format available, publication
 *      published, open access or paid purchase, press restrictions). No
 *      authorization rule is repeated here.
 *
 *   2. A stylesheet and a script added only to the book page, which enhance the
 *      download rows the core has already rendered.
 */

namespace APP\plugins\generic\audioPlayer;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class AudioPlayerPlugin extends GenericPlugin
{
    /** Template of the public book page. */
    private const BOOK_TEMPLATE = 'frontend/pages/book.tpl';

    /** Query parameter that marks a request as playback rather than download. */
    private const STREAM_PARAM = 'audioStream';

    /** Bytes read from the disk at each iteration of the stream. */
    private const CHUNK_SIZE = 262144;

    /**
     * Extensions treated as audio when the stored mimetype does not help: OMP
     * sometimes stores application/octet-stream for uploaded files.
     */
    private const AUDIO_EXTENSIONS = [
        'mp3', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'weba',
    ];

    /**
     * Mimetype sent for each extension when the stored one is generic: browsers
     * refuse to play application/octet-stream.
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
     * Register the plugin and, where it is enabled, its hooks.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        // Only reader-facing requests of a press reach these hooks.
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        Hook::add('CatalogBookHandler::download', $this->streamAudio(...));
        Hook::add('TemplateManager::display', $this->addAssets(...));
        Hook::add('Templates::Catalog::Book::Main', $this->injectPlayerData(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.audioPlayer.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.audioPlayer.description');
    }

    /**
     * Add the settings action to the plugin entry in the plugins list.
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        // The settings belong to a press; there is nothing to configure site-wide.
        if ($request->getUserVar('verb') !== 'settings' || !$request->getContext()) {
            return parent::manage($args, $request);
        }

        $form = new AudioPlayerSettingsForm($this, $request->getContext()->getId());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();

        return new JSONMessage(true);
    }

    /**
     * Add the stylesheet and the script to the public book page only.
     *
     * @param string $hookName
     * @param array $args [$templateMgr, $template, $sendContentType, $charset, $output]
     */
    public function addAssets($hookName, $args): bool
    {
        if (($args[1] ?? null) !== self::BOOK_TEMPLATE) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();

        // PKP only appends ?v={application version} to URLs without a query. The
        // plugin version makes browsers fetch the new files after each update.
        $version = $this->getCurrentVersion();
        $stamp = '?v=' . urlencode($version ? $version->getVersionString() : '0');

        $templateMgr->addStyleSheet('audioPlayer', $base . '/css/audioPlayer.css' . $stamp, ['contexts' => ['frontend']]);
        $templateMgr->addJavaScript('audioPlayer', $base . '/js/audioPlayer.js' . $stamp, ['contexts' => ['frontend']]);

        return Hook::CONTINUE;
    }

    /**
     * Add an element with the audio track list in JSON to the book page. The
     * script reads it and enhances the rows the core has rendered. A book
     * without audio tracks gets nothing.
     *
     * @param string $hookName
     * @param array $args [$params, $smarty, &$output]
     */
    public function injectPlayerData($hookName, $args): bool
    {
        $smarty = $args[1] ?? null;
        $output = &$args[2];
        if (!$smarty) {
            return Hook::CONTINUE;
        }

        // publicationFormats and availableFiles are assigned by the handler to the
        // template manager, so they are visible here. The monograph reaches
        // monograph_full.tpl as an {include} parameter, which is local and not in
        // getTemplateVars(), so the submission comes from the handler.
        $formats = $smarty->getTemplateVars('publicationFormats');
        $files = $smarty->getTemplateVars('availableFiles');
        if (empty($formats) || empty($files)) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $handler = $request->getRouter()->getHandler();
        $monograph = $handler ? $handler->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION) : null;
        $publication = $monograph ? ($handler->publication ?? $monograph->getCurrentPublication()) : null;
        if (!$publication) {
            return Hook::CONTINUE;
        }

        $payload = $this->buildTrackList($formats, $files, $monograph, $publication);
        if (empty($payload['formats'])) {
            return Hook::CONTINUE;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $output .= '<div class="ojsbrAudioPlayerData" hidden data-config="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"></div>';

        return Hook::CONTINUE;
    }

    /**
     * The track list grouped by publication format, in the order the core lists
     * the files, which is the order the press set for the format.
     *
     * @param array $formats PublicationFormat[]
     * @param array $files SubmissionFile[]
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
                    array_push($path, 'version', $publication->getId());
                }
                array_push($path, $format->getBestId(), $file->getBestId());

                $tracks[] = [
                    'id' => (int) $file->getId(),
                    'name' => $name,
                    // Same URL as the download link already in the page, used by the
                    // script to find the matching row.
                    'viewUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'catalog', 'view', $path),
                    // Playback URL: same access control, with Range support.
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

        $contextId = $request->getContext()?->getId();

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
     * A press setting, or the default when it was never saved. getSetting()
     * returns null for "never saved" only.
     */
    private function getSettingWithDefault(?int $contextId, string $name, mixed $default): mixed
    {
        if ($contextId === null) {
            return $default;
        }
        $value = $this->getSetting($contextId, $name);

        return $value === null ? $default : $value;
    }

    /**
     * Send the audio file honouring HTTP Range.
     *
     * The hook is only reached after CatalogBookHandler has checked that the
     * format is available and not remote, the publication is published, the file
     * belongs to the format, access is open or paid for, and the press
     * restrictions. None of those rules is repeated here.
     *
     * The response is taken over only when the request carries the playback
     * parameter AND the file is audio; anything else follows the core path with
     * the download untouched.
     *
     * @param string $hookName
     * @param array $args [$handler, $submission, $publicationFormat, $submissionFile, $inline]
     */
    public function streamAudio($hookName, $args): bool
    {
        $submissionFile = $args[3] ?? null;
        $request = Application::get()->getRequest();
        if (!$submissionFile || !$request->getUserVar(self::STREAM_PARAM)) {
            return Hook::CONTINUE;
        }

        $fileService = app()->get('file');
        $file = $fileService->get($submissionFile->getData('fileId'));
        if (!$file) {
            return Hook::CONTINUE;
        }

        $name = $submissionFile->getLocalizedData('name');
        if (!self::isAudioFile($file->mimetype ?? null, $name, $file->path)) {
            return Hook::CONTINUE;
        }

        return $this->sendRangeResponse(
            $fileService,
            $file->path,
            self::resolveMimetype($file->mimetype ?? null, $name, $file->path),
            $fileService->formatFilename($file->path, $name)
        ) ? Hook::ABORT : Hook::CONTINUE;
    }

    /**
     * Write the HTTP response honouring the Range header.
     *
     * @return bool False when the file cannot be read, so the core answers instead
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

        $range = self::resolveRange($_SERVER['HTTP_RANGE'] ?? '', $size);
        if ($range === false) {
            $this->sendUnsatisfiable($size);
        }
        [$start, $end, $partial] = $range;

        $length = $end - $start + 1;
        $etag = '"' . md5($path . ':' . $size) . '"';

        // Output buffers and compression get in the way of a ranged stream.
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

        // The local adapter returns a real file stream, which can seek. Should it
        // ever not, the leading bytes are read and discarded.
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

        // Stop reading when the listener closes the page or skips the track.
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
     * Answer 416 when the requested range is not in the file.
     */
    private function sendUnsatisfiable(int $size): never
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
     * The range to send for a Range header (RFC 9110, section 14.1).
     *
     * Kept free of I/O on purpose: it is the most delicate rule of the plugin and
     * the one browsers exercise on every drag of the progress bar.
     *
     * @return array{0:int,1:int,2:bool}|false [start, end, partial], or false when
     *                                         the range cannot be satisfied (416)
     */
    public static function resolveRange(string $header, int $size): array|false
    {
        $start = 0;
        $end = $size - 1;

        $header = trim($header);
        if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m)) {
            // No range: the whole file, 200.
            return [$start, $end, false];
        }

        [$from, $to] = [$m[1], $m[2]];
        if ($from === '' && $to === '') {
            return false;
        }
        if ($from === '') {
            // Suffix: the last N bytes.
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

    /**
     * Whether a file is audio: the stored mimetype, or the extension of the
     * displayed name or of the stored file when the mimetype is generic.
     */
    public static function isAudioFile(?string $mimetype, ?string $name, ?string $path): bool
    {
        if ($mimetype && str_starts_with(strtolower($mimetype), 'audio/')) {
            return true;
        }

        return in_array(self::extensionOf($name) ?: self::extensionOf($path), self::AUDIO_EXTENSIONS, true);
    }

    /**
     * The mimetype to send. Audio sent as application/octet-stream does not play
     * in browsers, so the extension has the last word.
     */
    public static function resolveMimetype(?string $mimetype, ?string $name, ?string $path): string
    {
        $extension = self::extensionOf($name) ?: self::extensionOf($path);
        if (isset(self::EXTENSION_MIME[$extension])) {
            return self::EXTENSION_MIME[$extension];
        }
        if ($mimetype && str_starts_with(strtolower($mimetype), 'audio/')) {
            return $mimetype;
        }

        return 'application/octet-stream';
    }

    /**
     * The lower-case extension of a file name, or an empty string.
     */
    public static function extensionOf(?string $filename): string
    {
        return $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: '') : '';
    }
}
