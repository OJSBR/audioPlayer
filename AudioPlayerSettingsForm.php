<?php

/**
 * @file plugins/generic/audioPlayer/AudioPlayerSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AudioPlayerSettingsForm
 *
 * @ingroup plugins_generic_audioPlayer
 *
 * @brief Configuracao do player por editora.
 */

namespace APP\plugins\generic\audioPlayer;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class AudioPlayerSettingsForm extends Form
{
    /** Velocidades oferecidas na barra do player. */
    public const SPEEDS = ['0.75', '1', '1.25', '1.5', '1.75', '2'];

    public int $contextId;

    public AudioPlayerPlugin $plugin;

    public function __construct(AudioPlayerPlugin $plugin, int $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settings.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $autoplay = $this->plugin->getSetting($this->contextId, 'autoplayNext');
        $remember = $this->plugin->getSetting($this->contextId, 'rememberPosition');
        $speed = $this->plugin->getSetting($this->contextId, 'defaultSpeed');

        $this->setData('autoplayNext', $autoplay === null ? true : (bool) $autoplay);
        $this->setData('rememberPosition', $remember === null ? true : (bool) $remember);
        $this->setData('defaultSpeed', $speed === null ? '1' : (string) $speed);

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['autoplayNext', 'rememberPosition', 'defaultSpeed']);
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'speedOptions' => array_combine(self::SPEEDS, array_map(fn ($s) => $s . 'x', self::SPEEDS)),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $speed = (string) $this->getData('defaultSpeed');
        if (!in_array($speed, self::SPEEDS, true)) {
            $speed = '1';
        }

        $this->plugin->updateSetting($this->contextId, 'autoplayNext', (bool) $this->getData('autoplayNext'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'rememberPosition', (bool) $this->getData('rememberPosition'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'defaultSpeed', $speed, 'string');

        return parent::execute(...$functionArgs);
    }
}
