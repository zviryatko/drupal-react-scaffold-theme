<?php

namespace Drupal\frame_builder\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\frame_builder\SavedBlockListBuilder;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\frame_builder\LayoutStorage;

/**
 * A block of the page builder's library: a component with everything in it,
 * saved to be used on other pages.
 *
 * Pages refer to it by UUID ("drupal_saved:<uuid>" in a layout) and show it
 * as it is saved now. The layout is in the same field, and the same format,
 * as the layout of a page: a document with one item.
 */
#[ContentEntityType(
  id: 'frame_saved',
  label: new TranslatableMarkup('Saved block'),
  label_collection: new TranslatableMarkup('Page builder library'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
  handlers: [
    'list_builder' => SavedBlockListBuilder::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/content/page-builder-library',
    'edit-form' => '/admin/content/page-builder-library/{frame_saved}/edit',
    'delete-form' => '/admin/content/page-builder-library/{frame_saved}/delete',
  ],
  admin_permission: 'use frame builder',
  base_table: 'frame_saved',
)]
class SavedBlock extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);
    $fields[LayoutStorage::FIELD] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Builder layout'))
      ->setDescription(new TranslatableMarkup('The saved component tree.'));
    $fields['log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Log'))
      ->setDescription(new TranslatableMarkup('Who saved the block, when, and their revision log messages (JSON).'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
