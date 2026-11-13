<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\frame_builder\Traits\BuilderTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base of the kernel tests of the page builder.
 *
 * Installs the module with nodes and users, makes the test subtheme of Frame
 * the default and active theme, and creates the "landing" content type that
 * uses the builder.
 */
#[RunTestsInSeparateProcesses]
abstract class BuilderKernelTestBase extends KernelTestBase {

  use BuilderTestTrait;
  use UserCreationTrait;

  /**
   * The theme of the tests: a subtheme of Frame with known tokens.
   */
  protected const THEME = 'frame_builder_test_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'filter', 'frame_builder'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('frame_saved');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);
    $this->useTheme(static::THEME);
    $this->createBuilderType();
    // User 1 has every permission: tests that check access create their own.
    $this->setUpCurrentUser(['uid' => 1]);
  }

  /**
   * Installs a theme and makes it the default and the active one.
   */
  protected function useTheme(string $theme): void {
    $this->container->get('theme_installer')->install([$theme]);
    $this->config('system.theme')->set('default', $theme)->save();
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->initTheme($theme));
  }

}
