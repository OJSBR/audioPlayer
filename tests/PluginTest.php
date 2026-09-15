<?php

/**
 * @file plugins/generic/audioPlayer/tests/PluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTest
 *
 * @brief The plugin classes compiled against the PKP classes of the
 *        installation: an override whose return type differs from its parent is
 *        a fatal error that php -l does not catch.
 */

namespace APP\plugins\generic\audioPlayer\tests;

use PKP\tests\PKPTestCase;
use ReflectionClass;
use ReflectionNamedType;

class PluginTest extends PKPTestCase
{
    /** @return string[] */
    protected function classes(): array
    {
        return [
            \APP\plugins\generic\audioPlayer\AudioPlayerPlugin::class,
            \APP\plugins\generic\audioPlayer\AudioPlayerSettingsForm::class,
        ];
    }

    public function testEveryClassLoadsAgainstThisPkpVersion(): void
    {
        foreach ($this->classes() as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not load.");
        }
    }

    public function testOverriddenMethodsDeclareCompatibleReturnTypes(): void
    {
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            $this->assertTrue($parent !== false, "{$class} extends nothing.");
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasMethod($method->getName())) {
                    continue;
                }
                $parentType = $parent->getMethod($method->getName())->getReturnType();
                if ($parentType === null) {
                    continue;
                }
                $type = $method->getReturnType();
                $covariant = $type instanceof ReflectionNamedType && $parentType instanceof ReflectionNamedType
                    && !$type->isBuiltin() && !$parentType->isBuiltin() && is_a($type->getName(), $parentType->getName(), true);
                $this->assertTrue(
                    $type !== null && ((string) $type === (string) $parentType || $covariant || ($type instanceof ReflectionNamedType && '?' . $type->getName() === (string) $parentType)),
                    sprintf('%s::%s() must declare a return type compatible with %s.', $reflection->getShortName(), $method->getName(), $parentType)
                );
            }
        }
    }

    public function testNoInheritedPropertyIsRedeclaredWithAType(): void
    {
        // A typed redeclaration of an untyped parent property ($pluginPath...) is fatal.
        $this->assertNotEmpty($this->classes());
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName() || !$parent->hasProperty($property->getName())) {
                    continue;
                }
                $this->assertSame((string) $parent->getProperty($property->getName())->getType(), (string) $property->getType(), "{$class}::\${$property->getName()}");
            }
        }
    }
}
