<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\LlmContentEditor\Infrastructure\Provider\AIProviderFactoryInterface;
use App\LlmContentEditor\Infrastructure\Provider\Dto\FakeProviderSeedingRulesDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\PostToolResponseRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolCallRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolInputsDto;
use App\LlmContentEditor\Infrastructure\Provider\FakeAIProviderFactory;
use App\Tests\TestHelpers\FakeAIProviderSeeder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Integration tests for file editing tools (apply_diff_to_file, replace_in_file).
 * These tests verify that the AI agent can successfully modify files using the workspace tooling.
 */
final class FileEditingToolsIntegrationTest extends KernelTestCase
{
    private string $tempWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->tempWorkspace = sys_get_temp_dir() . '/file_edit_test_' . uniqid();
        mkdir($this->tempWorkspace, 0755, true);

        // Clear any existing rules from the fake provider factory
        $container = static::getContainer();
        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        if ($providerFactory instanceof FakeAIProviderFactory) {
            FakeAIProviderSeeder::clear($providerFactory);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempWorkspace);
        parent::tearDown();
    }

    public function testApplyDiffToFile(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create a test file with initial content
        $testFilePath   = $this->tempWorkspace . '/test-file.txt';
        $initialContent = <<<'CONTENT'
line 1
line 2
line 3
line 4
CONTENT;
        file_put_contents($testFilePath, $initialContent);

        // Create a unified diff that adds a new line between line 2 and line 3
        $diff = <<<'DIFF'
@@ -1,4 +1,5 @@
 line 1
 line 2
+inserted line
 line 3
 line 4
DIFF;

        $instruction = 'apply diff to test file';

        // Seed the fake provider to trigger apply_diff_to_file
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'apply_diff_to_file',
                    ToolInputsDto::fromArray([
                        'path' => $testFilePath,
                        'diff' => $diff,
                    ])
                ),
            ],
            [
                new PostToolResponseRuleDto('Applied', 'Successfully applied the diff to the file.'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify tool events
        $eventChunks      = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $toolCallingFound = false;
        $toolCalledFound  = false;

        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_calling' && $event->toolName === 'apply_diff_to_file') {
                $toolCallingFound = true;
            } elseif ($event !== null && $event->kind === 'tool_called' && $event->toolName === 'apply_diff_to_file') {
                $toolCalledFound = true;
                // Verify the tool returned a success message
                self::assertNotNull($event->toolResult);
                self::assertStringContainsString('Applied', $event->toolResult);
            }
        }

        self::assertTrue($toolCallingFound, 'Should have tool_calling event for apply_diff_to_file');
        self::assertTrue($toolCalledFound, 'Should have tool_called event for apply_diff_to_file');

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertNull($doneChunk->errorMessage, 'Should not have error: ' . ($doneChunk->errorMessage ?? 'none'));
        self::assertTrue($doneChunk->success, 'Should be successful');

        // Verify the file was actually modified correctly
        $modifiedContent = file_get_contents($testFilePath);
        self::assertIsString($modifiedContent, 'Should be able to read modified file');
        $expectedContent = <<<'EXPECTED'
line 1
line 2
inserted line
line 3
line 4
EXPECTED;
        self::assertEquals($expectedContent, $modifiedContent, 'File content should be modified by the diff');
    }

    public function testReplaceInFile(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create a test file with initial content
        $testFilePath   = $this->tempWorkspace . '/replace-test.txt';
        $initialContent = <<<'CONTENT'
Hello World!
This is a test file.
Goodbye World!
CONTENT;
        file_put_contents($testFilePath, $initialContent);

        $instruction = 'replace text in file';

        // Seed the fake provider to trigger replace_in_file
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'replace_in_file',
                    ToolInputsDto::fromArray([
                        'path'       => $testFilePath,
                        'old_string' => 'Hello World!',
                        'new_string' => 'Hello Universe!',
                    ])
                ),
            ],
            [
                new PostToolResponseRuleDto('', 'Successfully replaced the text.'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify tool events
        $eventChunks      = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $toolCallingFound = false;
        $toolCalledFound  = false;

        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_calling' && $event->toolName === 'replace_in_file') {
                $toolCallingFound = true;
            } elseif ($event !== null && $event->kind === 'tool_called' && $event->toolName === 'replace_in_file') {
                $toolCalledFound = true;
            }
        }

        self::assertTrue($toolCallingFound, 'Should have tool_calling event for replace_in_file');
        self::assertTrue($toolCalledFound, 'Should have tool_called event for replace_in_file');

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');

        // Verify the file was actually modified correctly
        $modifiedContent = file_get_contents($testFilePath);
        self::assertIsString($modifiedContent, 'Should be able to read modified file');
        $expectedContent = <<<'EXPECTED'
Hello Universe!
This is a test file.
Goodbye World!
EXPECTED;
        self::assertEquals($expectedContent, $modifiedContent, 'File content should have Hello World replaced with Hello Universe');
    }

    public function testApplyDiffWithMultipleChanges(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create a test file with a simple function
        $testFilePath   = $this->tempWorkspace . '/function.js';
        $initialContent = <<<'CONTENT'
function greet(name) {
    console.log("Hello, " + name);
}

greet("World");
CONTENT;
        file_put_contents($testFilePath, $initialContent);

        // Create a diff that modifies the function to add a return value
        $diff = <<<'DIFF'
@@ -1,5 +1,6 @@
 function greet(name) {
-    console.log("Hello, " + name);
+    const message = "Hello, " + name;
+    console.log(message);
+    return message;
 }
 
-greet("World");
+const result = greet("World");
DIFF;

        $instruction = 'modify javascript function';

        // Seed the fake provider
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'apply_diff_to_file',
                    ToolInputsDto::fromArray([
                        'path' => $testFilePath,
                        'diff' => $diff,
                    ])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertNull($doneChunk->errorMessage, 'Should not have error: ' . ($doneChunk->errorMessage ?? 'none'));
        self::assertTrue($doneChunk->success, 'Should be successful');

        // Verify the file was modified correctly
        $modifiedContent = file_get_contents($testFilePath);
        self::assertIsString($modifiedContent, 'Should be able to read modified file');
        self::assertStringContainsString('const message = "Hello, " + name;', $modifiedContent);
        self::assertStringContainsString('return message;', $modifiedContent);
        self::assertStringContainsString('const result = greet("World");', $modifiedContent);
    }

    public function testGetFileContentTool(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create a test file
        $testFilePath = $this->tempWorkspace . '/readme.md';
        $fileContent  = <<<'CONTENT'
# Test Project

This is a test project for integration testing.

## Features
- Feature 1
- Feature 2
CONTENT;
        file_put_contents($testFilePath, $fileContent);

        $instruction = 'read the readme file';

        // Seed the fake provider to trigger get_file_content
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'get_file_content',
                    ToolInputsDto::fromArray([
                        'path' => $testFilePath,
                    ])
                ),
            ],
            [
                new PostToolResponseRuleDto('# Test Project', 'I found the readme file. It describes a test project with two features.'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify tool events
        $eventChunks       = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $toolCalledFound   = false;
        $toolResultContent = null;

        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_called' && $event->toolName === 'get_file_content') {
                $toolCalledFound   = true;
                $toolResultContent = $event->toolResult;
            }
        }

        self::assertTrue($toolCalledFound, 'Should have tool_called event for get_file_content');
        self::assertNotNull($toolResultContent, 'Tool result should not be null');
        self::assertStringContainsString('# Test Project', $toolResultContent, 'Tool result should contain file content');
        self::assertStringContainsString('Feature 1', $toolResultContent, 'Tool result should contain Feature 1');

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');
    }

    public function testGetFolderContentTool(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        // Create test files and directories
        file_put_contents($this->tempWorkspace . '/file1.txt', 'content 1');
        file_put_contents($this->tempWorkspace . '/file2.txt', 'content 2');
        mkdir($this->tempWorkspace . '/subdir');
        file_put_contents($this->tempWorkspace . '/subdir/file3.txt', 'content 3');

        $instruction = 'list workspace contents';

        // Seed the fake provider to trigger get_folder_content
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'get_folder_content',
                    ToolInputsDto::fromArray([
                        'path' => $this->tempWorkspace,
                    ])
                ),
            ],
            [
                new PostToolResponseRuleDto('file1.txt', 'The workspace contains 2 text files and a subdirectory.'),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify tool events
        $eventChunks       = array_filter($chunks, fn (EditStreamChunkDto $c) => $c->chunkType === 'event');
        $toolCalledFound   = false;
        $toolResultContent = null;

        foreach ($eventChunks as $chunk) {
            $event = $chunk->event;
            if ($event !== null && $event->kind === 'tool_called' && $event->toolName === 'get_folder_content') {
                $toolCalledFound   = true;
                $toolResultContent = $event->toolResult;
            }
        }

        self::assertTrue($toolCalledFound, 'Should have tool_called event for get_folder_content');
        self::assertNotNull($toolResultContent, 'Tool result should not be null');
        self::assertStringContainsString('file1.txt', $toolResultContent, 'Tool result should list file1.txt');
        self::assertStringContainsString('file2.txt', $toolResultContent, 'Tool result should list file2.txt');
        self::assertStringContainsString('subdir', $toolResultContent, 'Tool result should list subdir');

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');
    }

    public function testCreateDirectoryTool(): void
    {
        $container = static::getContainer();

        /** @var AIProviderFactoryInterface $providerFactory */
        $providerFactory = $container->get(AIProviderFactoryInterface::class);
        assert($providerFactory instanceof FakeAIProviderFactory);

        /** @var LlmContentEditorFacadeInterface $facade */
        $facade = $container->get(LlmContentEditorFacadeInterface::class);

        $newDirPath = $this->tempWorkspace . '/new-folder/nested';

        $instruction = 'create a new directory';

        // Seed the fake provider to trigger create_directory
        $rules = new FakeProviderSeedingRulesDto(
            [],
            [
                new ToolCallRuleDto(
                    $instruction,
                    'create_directory',
                    ToolInputsDto::fromArray([
                        'path' => $newDirPath,
                    ])
                ),
            ]
        );
        FakeAIProviderSeeder::seed($providerFactory, $rules);

        // Call the facade
        $chunks = [];
        foreach ($facade->streamEditWithHistory($this->tempWorkspace, $instruction) as $chunk) {
            $chunks[] = $chunk;
        }

        // Verify done chunk
        $doneChunk = null;
        foreach ($chunks as $chunk) {
            if ($chunk->chunkType === 'done') {
                $doneChunk = $chunk;
            }
        }
        self::assertNotNull($doneChunk, 'Should have done chunk');
        self::assertTrue($doneChunk->success, 'Should be successful');

        // Verify the directory was created
        self::assertTrue(is_dir($newDirPath), 'Directory should have been created');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        $files = array_diff($files, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
