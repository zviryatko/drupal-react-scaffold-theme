<?php

declare(strict_types=1);

namespace Drupal\Tests\frame_builder\Traits;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * What the tests of translated layouts need: a second language, a
 * translatable content type and translations of pages.
 *
 * The test class enables the modules "language" and "content_translation".
 */
trait LayoutTranslationTestTrait {

  /**
   * Adds German and makes a builder content type translatable.
   *
   * @param string $mode
   *   Translation mode of the layouts of the type: "synced" or "independent".
   * @param string $type
   *   The content type.
   */
  protected function setUpTranslation(string $mode, string $type = 'landing'): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $node_type = $this->container->get('entity_type.manager')->getStorage('node_type')->load($type);
    assert($node_type instanceof NodeTypeInterface);
    $node_type->setThirdPartySetting('frame_builder', 'translation_mode', $mode)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', $type, TRUE);
    // Content Translation adds its fields to nodes once a type is translated.
    $manager = $this->container->get('entity.definition_update_manager');
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    foreach ($this->container->get('entity_field.manager')->getFieldStorageDefinitions('node') as $name => $definition) {
      if (str_starts_with($name, 'content_translation_') && !$manager->getFieldStorageDefinition($name, 'node')) {
        $manager->installFieldStorageDefinition($name, 'node', 'content_translation', $definition);
      }
    }
  }

  /**
   * Adds the German translation of a page. Not saved.
   *
   * As Drupal does when a translation is created, it starts with the values
   * of the original, the layout among them.
   */
  protected function translate(NodeInterface $node, string $title = 'Eine Seite'): NodeInterface {
    return $node->addTranslation('de', ['title' => $title] + $node->toArray());
  }

  /**
   * The stored document of exactly this translation, decoded.
   */
  protected function stored(NodeInterface $node): array {
    $data = json_decode((string) $node->get('frame_layout')->value, TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * A page as it is stored now, in a language.
   */
  protected function reload(NodeInterface $node, string $langcode = 'en'): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id())->getTranslation($langcode);
  }

}
