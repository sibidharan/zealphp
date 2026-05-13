<?php

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

class RenderStreamTest extends TestCase
{
    private static string $tplDir;

    public static function setUpBeforeClass(): void
    {
        self::$tplDir = sys_get_temp_dir() . '/zealphp_test_templates_' . getmypid();
        mkdir(self::$tplDir, 0755, true);

        App::$cwd = self::$tplDir;
        mkdir(self::$tplDir . '/template', 0755, true);
    }

    public static function tearDownAfterClass(): void
    {
        $files = glob(self::$tplDir . '/template/*.php');
        foreach ($files as $f) unlink($f);
        rmdir(self::$tplDir . '/template');
        rmdir(self::$tplDir);
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents(self::$tplDir . "/template/{$name}.php", $content);
    }

    public function testRegularTemplateYieldsSingleChunk(): void
    {
        $this->writeTemplate('regular', '<?php echo "Hello " . $name; ?>');

        $chunks = iterator_to_array(App::renderStream('regular', ['name' => 'World']));

        $this->assertCount(1, $chunks);
        $this->assertEquals('Hello World', $chunks[0]);
    }

    public function testClosureTemplateWithParamInjection(): void
    {
        $this->writeTemplate('closure-stream', '<?php
            return function($items) {
                foreach ($items as $i) {
                    yield "<li>$i</li>";
                }
            };
        ');

        $chunks = iterator_to_array(App::renderStream('closure-stream', ['items' => ['A', 'B', 'C']]));

        $this->assertCount(3, $chunks);
        $this->assertEquals('<li>A</li>', $chunks[0]);
        $this->assertEquals('<li>B</li>', $chunks[1]);
        $this->assertEquals('<li>C</li>', $chunks[2]);
    }

    public function testClosureWithDefaultParam(): void
    {
        $this->writeTemplate('defaults', '<?php
            return function($title, $page = 1) {
                yield "$title page $page";
            };
        ');

        $chunks = iterator_to_array(App::renderStream('defaults', ['title' => 'Users']));
        $this->assertEquals('Users page 1', $chunks[0]);

        $chunks = iterator_to_array(App::renderStream('defaults', ['title' => 'Users', 'page' => 3]));
        $this->assertEquals('Users page 3', $chunks[0]);
    }

    public function testIifeGeneratorTemplate(): void
    {
        $this->writeTemplate('iife', '<?php
            return (function() use ($count) {
                for ($i = 0; $i < $count; $i++) {
                    yield "chunk$i";
                }
            })();
        ');

        $chunks = iterator_to_array(App::renderStream('iife', ['count' => 3]));

        $this->assertCount(3, $chunks);
        $this->assertEquals('chunk0', $chunks[0]);
        $this->assertEquals('chunk2', $chunks[2]);
    }

    public function testEmptyTemplateYieldsNothing(): void
    {
        $this->writeTemplate('empty', '<?php // nothing ?>');

        $chunks = iterator_to_array(App::renderStream('empty'));
        $this->assertEmpty($chunks);
    }

    public function testRenderToStringCaptures(): void
    {
        $this->writeTemplate('string-test', '<h1><?= $title ?></h1>');

        $html = App::renderToString('string-test', ['title' => 'Hello']);
        $this->assertEquals('<h1>Hello</h1>', $html);
    }

    public function testComposeMultipleStreams(): void
    {
        $this->writeTemplate('header', '<?php echo "<head>$title</head>"; ?>');
        $this->writeTemplate('body-stream', '<?php
            return function($items) {
                foreach ($items as $i) yield "<p>$i</p>";
            };
        ');
        $this->writeTemplate('footer', '<?php echo "<footer/>"; ?>');

        $gen = (function() {
            yield from App::renderStream('header', ['title' => 'Test']);
            yield from App::renderStream('body-stream', ['items' => ['X', 'Y']]);
            yield from App::renderStream('footer');
        })();

        $chunks = iterator_to_array($gen, false);

        $this->assertCount(4, $chunks);
        $this->assertEquals('<head>Test</head>', $chunks[0]);
        $this->assertEquals('<p>X</p>', $chunks[1]);
        $this->assertEquals('<p>Y</p>', $chunks[2]);
        $this->assertEquals('<footer/>', $chunks[3]);
    }
}
