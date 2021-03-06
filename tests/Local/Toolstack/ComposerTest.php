<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Local\LocalProject;

class ComposerTest extends BaseToolstackTest
{

    public function testBuildComposer()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer');
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $this->assertFileExists($webRoot . '/vendor/guzzlehttp/guzzle/src/Client.php');

        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $this->assertFileExists($repositoryDir . '/.gitignore');
    }

    /**
     * Test the case where a user has specified "php:symfony" as the toolstack,
     * for an application which does not contain a composer.json file. The build
     * may not do much, but at least it should not throw an exception.
     */
    public function testBuildFakeSymfony()
    {
        $this->assertBuildSucceeds('tests/data/apps/fake-symfony');
    }
}
