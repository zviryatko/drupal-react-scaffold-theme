<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Tests\frame_builder\Traits\ParagraphsTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base of the kernel tests of Paragraph blocks: the Paragraphs module with
 * the "text_block" paragraph type, which has one text field.
 *
 * Skipped on a site without Paragraphs.
 */
#[RunTestsInSeparateProcesses]
abstract class ParagraphsKernelTestBase extends BuilderKernelTestBase {

  use ParagraphsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'entity_reference_revisions', 'paragraphs'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->requireModules('entity_reference_revisions', 'paragraphs');
    parent::setUp();
    $this->installEntitySchema('paragraph');
    $this->installConfig(['paragraphs']);
    $this->createParagraphType();
  }

  /**
   * Adds a language to the site.
   */
  protected function addLanguage(string $langcode): void {
    $this->container->get('entity_type.manager')->getStorage('configurable_language')->create(['id' => $langcode])->save();
  }

}
