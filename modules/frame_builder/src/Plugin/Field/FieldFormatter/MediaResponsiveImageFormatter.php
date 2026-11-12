<?php

namespace Drupal\frame_builder\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows referenced image media with a responsive image style.
 *
 * Core has this only for image fields ("Responsive image"). For a media
 * reference field it offers "Thumbnail", with a plain image style, and
 * "Rendered entity", which is responsive only through a view mode of the
 * media type that someone has to configure. This formatter is the direct
 * way: the image of the media item, with a responsive image style.
 *
 * It works on "Manage display" as well as in the page builder.
 */
#[FieldFormatter(
  id: 'frame_builder_media_responsive',
  label: new TranslatableMarkup('Responsive image'),
  field_types: ['entity_reference'],
)]
class MediaResponsiveImageFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'media'
      && \Drupal::moduleHandler()->moduleExists('responsive_image');
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return ['responsive_image_style' => '', 'image_link' => '', 'image_link_url' => '', 'image_loading' => 'lazy'] + parent::defaultSettings();
  }

  /**
   * Responsive image styles, as id => label.
   */
  protected function styleOptions(): array {
    $options = [];
    foreach (\Drupal::entityTypeManager()->getStorage('responsive_image_style')->loadMultiple() as $style) {
      if ($style->hasImageStyleMappings()) {
        $options[$style->id()] = $style->label();
      }
    }
    return $options;
  }

  /**
   * What the image can link to, as value => label.
   */
  protected function linkOptions(): array {
    return [
      'content' => $this->t('Content (the page)'),
      'media' => $this->t('Media item'),
      'file' => $this->t('Image file'),
      'custom' => $this->t('Custom URL'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $elements['responsive_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Responsive image style'),
      '#options' => $this->styleOptions(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->getSetting('responsive_image_style'),
      '#required' => TRUE,
    ];
    $elements['image_loading'] = [
      '#type' => 'select',
      '#title' => $this->t('Image loading'),
      '#options' => ['lazy' => $this->t('Lazy: when it comes near the screen'), 'eager' => $this->t('Eager: with the page')],
      '#default_value' => $this->getSetting('image_loading') === 'eager' ? 'eager' : 'lazy',
      '#description' => $this->t('Eager suits the first image of a page; everything below loads faster lazily.'),
    ];
    $elements['image_link'] = [
      '#type' => 'select',
      '#title' => $this->t('Link image to'),
      '#options' => $this->linkOptions(),
      '#empty_option' => $this->t('Nothing'),
      '#default_value' => $this->getSetting('image_link'),
    ];
    $elements['image_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom URL'),
      '#description' => $this->t('Used with "Custom URL": an address such as https://example.com, or a path such as /about.'),
      '#default_value' => $this->getSetting('image_link_url'),
      '#maxlength' => 2048,
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $style = $this->styleOptions()[$this->getSetting('responsive_image_style')] ?? NULL;
    $summary = [$style ? $this->t('Responsive image style: @style', ['@style' => $style]) : $this->t('Select a responsive image style.')];
    $link = $this->linkOptions()[$this->getSetting('image_link')] ?? NULL;
    if ($link) {
      $summary[] = $this->t('Linked to: @target', ['@target' => $this->getSetting('image_link') === 'custom' ? $this->getSetting('image_link_url') : $link]);
    }
    $summary[] = $this->t('Image loading: @mode', ['@mode' => $this->getSetting('image_loading') === 'eager' ? 'eager' : 'lazy']);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $style = \Drupal::entityTypeManager()->getStorage('responsive_image_style')->load((string) $this->getSetting('responsive_image_style'));
    if (!$style) {
      return $elements;
    }
    // The page changes when the style or one of its image styles changes.
    $tags = $style->getCacheTags();
    foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple($style->getImageStyleIds()) as $image_style) {
      $tags = Cache::mergeTags($tags, $image_style->getCacheTags());
    }

    /** @var \Drupal\frame_builder\MediaItems $media_items */
    $media_items = \Drupal::service('frame_builder.media_items');
    $parent = $items->getEntity();
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $media) {
      // The image of the media item: its source when that is an image
      // (image media), otherwise its thumbnail (a video's poster).
      $source = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
      $field = $source && $source->getType() === 'image' ? $source->getName() : 'thumbnail';
      $item = $media->get($field)->first();
      if (!$item || !$item->entity) {
        continue;
      }
      $elements[$delta] = [
        '#theme' => 'responsive_image_formatter',
        '#item' => $item,
        '#item_attributes' => ['loading' => $this->getSetting('image_loading') === 'eager' ? 'eager' : 'lazy'],
        '#responsive_image_style_id' => $style->id(),
        '#url' => $media_items->linkUrl((string) $this->getSetting('image_link'), (string) $this->getSetting('image_link_url'), $parent, $media, $item->entity->getFileUri()),
        '#cache' => ['tags' => Cache::mergeTags($tags, $media->getCacheTags())],
      ];
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $style = \Drupal::entityTypeManager()->getStorage('responsive_image_style')->load((string) $this->getSetting('responsive_image_style'));
    if ($style) {
      $dependencies[$style->getConfigDependencyKey()][] = $style->getConfigDependencyName();
    }
    return $dependencies;
  }

}
