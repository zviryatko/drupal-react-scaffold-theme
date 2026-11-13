<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Kernel;

use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;
use Drupal\Tests\frame_builder\Traits\RenderingTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The "Responsive image" formatter of media reference fields.
 */
#[Group('frame_builder')]
#[RunTestsInSeparateProcesses]
class MediaResponsiveImageFormatterTest extends BuilderKernelTestBase {

  use RenderingTestTrait;

  protected const FORMATTER = 'frame_builder_media_responsive';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'media', 'breakpoint', 'responsive_image'];

  /**
   * An image media item.
   */
  protected MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpImageMedia();
    FieldStorageConfig::create(['field_name' => 'field_picture', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'media']])->save();
    FieldConfig::create(['field_name' => 'field_picture', 'entity_type' => 'node', 'bundle' => 'landing', 'label' => 'Picture', 'settings' => ['handler' => 'default:media', 'handler_settings' => ['target_bundles' => ['image' => 'image']]]])->save();
    FieldStorageConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'node']])->save();
    FieldConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'bundle' => 'landing', 'label' => 'Related'])->save();
    $this->createSizesStyle('cat_wide', '(min-width: 700px) 700px, 100vw');
    $this->media = $this->createImageMedia('A red square');
  }

  /**
   * A page with the image in its media field.
   */
  protected function page(): NodeInterface {
    return $this->createBuilderNode(['field_picture' => $this->media->id()]);
  }

  /**
   * The HTML of the media field of a page shown with the formatter.
   */
  protected function view(NodeInterface $node, array $settings = ['responsive_image_style' => 'cat_wide']): string {
    return $this->renderHtml($node->get('field_picture')->view(['type' => self::FORMATTER, 'label' => 'hidden', 'settings' => $settings]));
  }

  /**
   * The formatter plugin with the given settings.
   */
  protected function formatter(array $settings = []): FormatterInterface {
    return $this->container->get('plugin.manager.field.formatter')->getInstance([
      'field_definition' => $this->page()->getFieldDefinition('field_picture'),
      'view_mode' => 'default',
      'configuration' => ['type' => self::FORMATTER, 'settings' => $settings, 'label' => 'hidden', 'third_party_settings' => []],
    ]);
  }

  /**
   * The formatter is for references to media, not to other entities, and
   * is offered for a Field block.
   */
  public function testApplicableToMediaReferences(): void {
    $node = $this->page();
    $class = $this->container->get('plugin.manager.field.formatter')->getDefinition(self::FORMATTER)['class'];
    $this->assertTrue($class::isApplicable($node->getFieldDefinition('field_picture')));
    $this->assertFalse($class::isApplicable($node->getFieldDefinition('field_related')));

    $catalog = $this->container->get('frame_builder.catalog');
    $this->assertSame('Responsive image', $catalog->formatterOptions($node, 'field_picture')[self::FORMATTER]);
    $this->assertArrayNotHasKey(self::FORMATTER, $catalog->formatterOptions($node, 'field_related'));
  }

  /**
   * The settings: a responsive image style, lazy loading, no link.
   */
  public function testDefaultSettings(): void {
    $settings = $this->formatter()->getSettings();
    $this->assertSame('', $settings['responsive_image_style']);
    $this->assertSame('', $settings['image_link']);
    $this->assertSame('', $settings['image_link_url']);
    $this->assertSame('lazy', $settings['image_loading']);
  }

  /**
   * The image of the media item is rendered with the responsive image
   * style, lazily.
   */
  public function testViewElements(): void {
    $html = $this->view($this->page());
    $this->assertStringContainsString('<img', $html);
    $this->assertMatchesRegularExpression('#srcset="[^"]*/styles/thumbnail/public/[^"]* 100w, [^"]*/styles/medium/public/[^"]* 220w, [^"]*/styles/large/public/[^"]* 360w"#', $html);
    $this->assertStringContainsString('sizes="(min-width: 700px) 700px, 100vw"', $html);
    $this->assertStringContainsString('alt="A red square"', $html);
    $this->assertStringContainsString('loading="lazy"', $html);
    $this->assertStringNotContainsString('<a ', $html);
    $this->assertStringNotContainsString('Picture', $html, 'The name of the media item is not printed.');
  }

  /**
   * A style of breakpoints gives a <picture> with a source per breakpoint.
   */
  public function testViewElementsWithBreakpoints(): void {
    $this->createBreakpointStyle('cat_art');
    $html = $this->view($this->page(), ['responsive_image_style' => 'cat_art']);
    $this->assertStringContainsString('<picture', $html);
    $this->assertStringContainsString('media="all and (min-width: 48em)"', $html);
    $this->assertStringContainsString('media="all and (min-width: 75em)"', $html);
  }

  /**
   * The image can load with the page.
   */
  public function testLoading(): void {
    $node = $this->page();
    $this->assertStringContainsString('loading="eager"', $this->view($node, ['responsive_image_style' => 'cat_wide', 'image_loading' => 'eager']));
    $this->assertStringContainsString('loading="lazy"', $this->view($node, ['responsive_image_style' => 'cat_wide', 'image_loading' => 'whenever']));
  }

  /**
   * The image can link to the page, the media item, the file or a URL.
   */
  public function testLink(): void {
    $node = $this->page();
    $settings = ['responsive_image_style' => 'cat_wide'];
    $this->assertMatchesRegularExpression('#<a href="/node/' . $node->id() . '"[^>]*>\s*<img#', $this->view($node, $settings + ['image_link' => 'content']));
    $this->assertStringContainsString('<a href="' . $this->media->toUrl()->toString() . '"', $this->view($node, $settings + ['image_link' => 'media']));
    $file = $this->container->get('file_url_generator')->generateAbsoluteString($this->media->get('field_media_image')->entity->getFileUri());
    $this->assertStringContainsString('<a href="' . $file . '"', $this->view($node, $settings + ['image_link' => 'file']));
    $this->assertStringContainsString('<a href="https://example.com/more"', $this->view($node, $settings + ['image_link' => 'custom', 'image_link_url' => 'https://example.com/more']));
    $this->assertStringContainsString('<a href="/about"', $this->view($node, $settings + ['image_link' => 'custom', 'image_link_url' => '/about']));

    foreach ([
      'A custom link without a URL' => ['image_link' => 'custom'],
      'A script' => ['image_link' => 'custom', 'image_link_url' => 'javascript:alert(1)'],
      'A URL without the choice to link to it' => ['image_link_url' => 'https://example.com/more'],
    ] as $case => $link) {
      $html = $this->view($node, $settings + $link);
      $this->assertStringContainsString('<img', $html, $case);
      $this->assertStringNotContainsString('<a ', $html, $case);
    }
  }

  /**
   * Without a responsive image style that exists nothing is printed.
   */
  public function testWithoutStyle(): void {
    $node = $this->page();
    $this->assertStringNotContainsString('<img', $this->view($node, []));
    $this->assertStringNotContainsString('<img', $this->view($node, ['responsive_image_style' => 'gone']));
  }

  /**
   * Every image of a field with several is printed; an empty field prints
   * nothing.
   */
  public function testSeveralAndNone(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_picture');
    $storage->setCardinality(-1)->save();
    $second = $this->createImageMedia('A blue circle');
    $node = $this->createBuilderNode(['field_picture' => [$this->media->id(), $second->id()]]);
    $html = $this->view($node);
    $this->assertSame(2, substr_count($html, '<img'));
    $this->assertLessThan(strpos($html, 'A blue circle'), strpos($html, 'A red square'));

    $this->assertSame('', trim($this->view($this->createBuilderNode())));
  }

  /**
   * The page changes when the media item, the responsive image style or
   * one of its image styles changes.
   */
  public function testCacheTags(): void {
    $build = $this->page()->get('field_picture')->view(['type' => self::FORMATTER, 'settings' => ['responsive_image_style' => 'cat_wide']]);
    $this->container->get('renderer')->renderRoot($build);
    foreach (['media:' . $this->media->id(), 'config:responsive_image.styles.cat_wide', 'config:image.style.thumbnail', 'config:image.style.medium', 'config:image.style.large'] as $tag) {
      $this->assertContains($tag, $build['#cache']['tags']);
    }
  }

  /**
   * Media the user may not view is not shown.
   */
  public function testMediaAccess(): void {
    $node = $this->page();
    $this->assertStringContainsString('<img', $this->view($node));
    $this->setUpCurrentUser([], ['access content']);
    $this->assertStringNotContainsString('<img', $this->view($node), 'A user who may not view media.');
    $this->setUpCurrentUser([], ['access content', 'view media']);
    $this->assertStringContainsString('<img', $this->view($node));
  }

  /**
   * The summary on "Manage display" says what the formatter does.
   */
  public function testSettingsSummary(): void {
    $summary = static fn (FormatterInterface $formatter): array => array_map('strval', $formatter->settingsSummary());
    $this->assertSame(['Select a responsive image style.', 'Image loading: lazy'], $summary($this->formatter()));
    $this->assertSame(['Responsive image style: Cat wide', 'Image loading: eager'], $summary($this->formatter(['responsive_image_style' => 'cat_wide', 'image_loading' => 'eager'])));
    $this->assertSame(
      ['Responsive image style: Cat wide', 'Linked to: Media item', 'Image loading: lazy'],
      $summary($this->formatter(['responsive_image_style' => 'cat_wide', 'image_link' => 'media'])),
    );
    $this->assertSame(
      ['Responsive image style: Cat wide', 'Linked to: https://example.com/more', 'Image loading: lazy'],
      $summary($this->formatter(['responsive_image_style' => 'cat_wide', 'image_link' => 'custom', 'image_link_url' => 'https://example.com/more'])),
    );
  }

  /**
   * The settings form offers the responsive image styles that have
   * mappings, the ways to load and the targets of a link.
   */
  public function testSettingsForm(): void {
    ResponsiveImageStyle::create(['id' => 'cat_bare', 'label' => 'Cat bare', 'breakpoint_group' => 'responsive_image', 'fallback_image_style' => 'medium'])->save();
    $form = $this->formatter(['responsive_image_style' => 'cat_wide', 'image_link' => 'custom', 'image_link_url' => '/about', 'image_loading' => 'eager'])->settingsForm([], new FormState());

    $this->assertSame(['cat_wide' => 'Cat wide'], array_map('strval', $form['responsive_image_style']['#options']), 'A style without mappings cannot render an image.');
    $this->assertSame('cat_wide', $form['responsive_image_style']['#default_value']);
    $this->assertTrue($form['responsive_image_style']['#required']);
    $this->assertSame(['lazy', 'eager'], array_keys($form['image_loading']['#options']));
    $this->assertSame('eager', $form['image_loading']['#default_value']);
    $this->assertSame(['content', 'media', 'file', 'custom'], array_keys($form['image_link']['#options']));
    $this->assertSame('custom', $form['image_link']['#default_value']);
    $this->assertSame('/about', $form['image_link_url']['#default_value']);
  }

  /**
   * A display that uses the formatter depends on its responsive image style.
   */
  public function testDependencies(): void {
    $this->assertContains('responsive_image.styles.cat_wide', $this->formatter(['responsive_image_style' => 'cat_wide'])->calculateDependencies()['config']);
    $this->assertNotContains('responsive_image.styles.gone', $this->formatter(['responsive_image_style' => 'gone'])->calculateDependencies()['config'] ?? []);
  }

  /**
   * A Field block of the media field shows it with the formatter and its
   * settings.
   */
  public function testInAFieldBlock(): void {
    $node = $this->page();
    $build = $this->container->get('frame_builder.renderer')->buildItem(self::item('drupal_field:field_picture', 'f1', [
      'formatter' => ['type' => self::FORMATTER, 'settings' => ['responsive_image_style' => 'cat_wide', 'image_link' => 'content', 'image_loading' => 'eager']],
      'label' => 'above',
    ]), $node);
    $this->assertSame(self::FORMATTER, $build['field']['#formatter']);
    $html = $this->renderHtml($build);
    $this->assertStringContainsString('frame-builder-field--label-above', $html);
    $this->assertStringContainsString('Picture', $html);
    $this->assertMatchesRegularExpression('#<a href="/node/' . $node->id() . '"[^>]*>\s*<img[^>]*loading="eager"#', $html);
    $this->assertStringContainsString('srcset="', $html);
  }

}
