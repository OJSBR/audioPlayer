<?php

/**
 * @file plugins/generic/audioPlayer/tests/AudioPlayerPluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AudioPlayerPluginTest
 *
 * @brief The HTTP range rules (RFC 9110, section 14.1) browsers exercise on every
 *        drag of the progress bar, audio detection, and the guarantee that the
 *        plugin only acts after the core has authorized the download.
 */

namespace APP\plugins\generic\audioPlayer\tests;

use APP\plugins\generic\audioPlayer\AudioPlayerPlugin;
use APP\plugins\generic\audioPlayer\AudioPlayerSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\plugins\Hook;
use PKP\tests\PKPTestCase;

#[CoversClass(AudioPlayerPlugin::class)]
#[CoversClass(AudioPlayerSettingsForm::class)]
class AudioPlayerPluginTest extends PKPTestCase
{
    /** Size of the file in the examples. */
    private const SIZE = 1000;

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/AudioPlayerPlugin.php');
    }

    public function testWithoutARangeTheWholeFileIsSent(): void
    {
        $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange('', self::SIZE));
        $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange('   ', self::SIZE));
    }

    public function testValidRanges(): void
    {
        $this->assertSame([0, 499, true], AudioPlayerPlugin::resolveRange('bytes=0-499', self::SIZE));
        $this->assertSame([500, 999, true], AudioPlayerPlugin::resolveRange('bytes=500-', self::SIZE));
        $this->assertSame([0, 0, true], AudioPlayerPlugin::resolveRange('bytes=0-0', self::SIZE));
        $this->assertSame([999, 999, true], AudioPlayerPlugin::resolveRange('bytes=999-', self::SIZE));
    }

    public function testSuffixRanges(): void
    {
        $this->assertSame([500, 999, true], AudioPlayerPlugin::resolveRange('bytes=-500', self::SIZE));
        // A suffix longer than the file sends the whole file.
        $this->assertSame([0, 999, true], AudioPlayerPlugin::resolveRange('bytes=-5000', self::SIZE));
    }

    public function testAnEndBeyondTheFileIsCutAtTheLastByte(): void
    {
        $this->assertSame([0, 999, true], AudioPlayerPlugin::resolveRange('bytes=0-99999', self::SIZE));
    }

    public function testUnsatisfiableRangesAreRefused(): void
    {
        foreach (['bytes=-', 'bytes=-0', 'bytes=1000-', 'bytes=5000-6000', 'bytes=600-500'] as $header) {
            $this->assertFalse(AudioPlayerPlugin::resolveRange($header, self::SIZE), "Accepted {$header}.");
        }
    }

    public function testAMalformedHeaderIsIgnored(): void
    {
        foreach (['items=0-10', 'bytes=abc-def', 'bytes 0-10', 'bytes=0-10, 20-30'] as $header) {
            $this->assertSame([0, 999, false], AudioPlayerPlugin::resolveRange($header, self::SIZE), "Used {$header}.");
        }
    }

    public function testAudioIsRecognisedByMimetypeOrExtension(): void
    {
        $this->assertTrue(AudioPlayerPlugin::isAudioFile('audio/mpeg', null, null));
        $this->assertTrue(AudioPlayerPlugin::isAudioFile('application/octet-stream', 'track.mp3', null));
        $this->assertTrue(AudioPlayerPlugin::isAudioFile(null, null, '/x/y/track.m4b'));
        $this->assertFalse(AudioPlayerPlugin::isAudioFile('application/pdf', 'book.pdf', null));
        $this->assertFalse(AudioPlayerPlugin::isAudioFile(null, 'book.epub', null));
    }

    public function testTheExtensionDecidesTheMimetypeSent(): void
    {
        // Audio sent as application/octet-stream does not play.
        $this->assertSame('audio/mpeg', AudioPlayerPlugin::resolveMimetype('application/octet-stream', 'a.mp3', null));
        $this->assertSame('audio/mp4', AudioPlayerPlugin::resolveMimetype(null, 'a.m4b', null));
        $this->assertSame('audio/ogg', AudioPlayerPlugin::resolveMimetype('audio/ogg', 'a.unknown', null));
        $this->assertSame('application/octet-stream', AudioPlayerPlugin::resolveMimetype(null, 'a.xyz', null));
    }

    /**
     * The plugin must never get a way to the file of its own.
     *
     * All the download authorization of OMP lives in CatalogBookHandler::download
     * (OmpPublishedSubmissionAccessPolicy, the format available, the publication
     * published, the direct sales price of the file). The hook is only called
     * after all of it, so the plugin inherits the whole check. The risk is a later
     * move to a hook that runs earlier, such as LoadHandler; this test fails then.
     *
     * Also checked on a server, requesting the same mp3 with ?audioStream=1: file
     * available 206; no direct sales price 404; format unavailable 404;
     * publication unpublished 404 - the same as the normal download.
     */
    public function testOnlyHooksCalledAfterAuthorizationAreUsed(): void
    {
        preg_match_all("/Hook::add\\(\\s*'([^']+)'/", $this->source(), $m);
        $expected = ['CatalogBookHandler::download', 'TemplateManager::display', 'Templates::Catalog::Book::Main'];
        sort($expected);
        $found = array_unique($m[1]);
        sort($found);

        $this->assertSame($expected, $found, 'The hooks changed: review the access control before accepting it.');
        foreach (['LoadHandler', 'LoadComponentHandler', 'Dispatcher::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->source(), "The plugin must not route requests itself ({$forbidden}).");
        }
    }

    public function testWithoutAFileTheCoreAnswers(): void
    {
        $plugin = new AudioPlayerPlugin();
        $this->assertSame(Hook::CONTINUE, $plugin->streamAudio('CatalogBookHandler::download', [null, null, null, null, false]));
        $this->assertSame(Hook::CONTINUE, $plugin->streamAudio('CatalogBookHandler::download', []));
    }

    public function testTheSiteLevelHasNoSettingsToOpen(): void
    {
        $request = new class () {
            public function getContext()
            {
                return null;
            }

            public function getUserVar($name)
            {
                return $name === 'verb' ? 'settings' : null;
            }

            public function getRouter()
            {
                throw new \RuntimeException('The site level must not build a settings URL.');
            }
        };
        $plugin = new class () extends AudioPlayerPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }
        };

        $this->assertSame([], array_filter($plugin->getActions($request, []), fn ($action) => $action->getId() === 'settings'));
        $this->expectExceptionMessage('Unhandled management action!');
        $plugin->manage([], $request);
    }
}
