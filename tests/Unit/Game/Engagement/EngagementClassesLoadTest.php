<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Engagement;

use PHPUnit\Framework\TestCase;

/**
 * Loads every class of src/Game/Engagement: an incompatible override (e.g. a method of a feature clashing with a new
 * EngagementFeature hook) is a fatal error that php -l does not see, only loading the class does.
 */
final class EngagementClassesLoadTest extends TestCase
{
    public function testEveryEngagementClassLoads(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Game/Engagement';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $loaded = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Game\\Engagement\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
            $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), $class);
            $loaded++;
        }
        $this->assertGreaterThan(20, $loaded);
    }
}
