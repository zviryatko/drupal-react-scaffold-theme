<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder_ai\Traits;

use Drupal\frame_builder_ai_test\Plugin\AiProvider\ScriptedProvider;

/**
 * What the tests of the assistant share: skipping without the modules they
 * need, and the scripted chat provider.
 */
trait AssistantTestTrait {

  /**
   * Skips the test when a module it needs is not in the codebase.
   *
   * To be called before the parent's setUp(), which fails on a module that
   * does not exist.
   */
  protected function skipWithoutModules(string ...$modules): void {
    static $found = NULL;
    if ($found === NULL) {
      $found = [];
      // Drupal.php is in core/lib of the site.
      $root = dirname((new \ReflectionClass(\Drupal::class))->getFileName(), 3);
      $directories = array_filter([$root . '/core/modules', $root . '/modules', $root . '/profiles', $root . '/sites'], 'is_dir');
      foreach ($directories as $directory) {
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
          static fn (\SplFileInfo $file): bool => $file->isDir()
            ? !in_array($file->getFilename(), ['node_modules', 'vendor', 'files', 'simpletest', 'src', 'config', 'js', 'css'], TRUE)
            : str_ends_with($file->getFilename(), '.info.yml'),
        ));
        foreach ($files as $file) {
          $found[$file->getBasename('.info.yml')] = TRUE;
        }
      }
    }
    foreach ($modules as $module) {
      if (!isset($found[$module])) {
        $this->markTestSkipped("The module $module is not in the codebase.");
      }
    }
  }

  /**
   * Makes the scripted provider the default for chat in the AI module.
   */
  protected function useScriptedProvider(): void {
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'frame_builder_ai_scripted', 'model_id' => 'scripted'])
      ->save();
  }

  /**
   * Sets what the model answers, one text per request. Arrays are sent as
   * JSON.
   */
  protected function script(array|string ...$answers): void {
    $state = \Drupal::state();
    $state->set(ScriptedProvider::ANSWERS, array_map(
      static fn (array|string $answer): string => is_array($answer) ? json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $answer,
      array_values($answers),
    ));
    $state->delete(ScriptedProvider::REQUESTS);
  }

  /**
   * The requests the provider got since the script was set: 'system',
   * 'messages' ([role, text, images]), 'model' and 'tags' of each.
   */
  protected function requests(): array {
    $state = \Drupal::state();
    $state->resetCache();
    return $state->get(ScriptedProvider::REQUESTS, []);
  }

}
