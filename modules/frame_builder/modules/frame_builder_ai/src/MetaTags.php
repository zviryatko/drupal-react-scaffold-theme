<?php

namespace Drupal\frame_builder_ai;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;
use Drupal\frame_builder\FieldDrafts;

/**
 * The meta tags of a page (Metatag module), for the assistant.
 *
 * A page has meta tags when its content type has a field of the Metatag
 * module. The assistant is told what they are now and may propose new values
 * for the tags listed in self::TAGS. Like everything it proposes, nothing is
 * saved: the values become unsaved values of the node form (FieldDrafts),
 * the Meta tags section of the page settings shows them, and they are saved
 * when the page is.
 */
class MetaTags {

  /**
   * Tags the assistant may fill, with what it is told about them and the
   * longest value that is kept. Only those the site has a plugin for.
   */
  public const TAGS = [
    'title' => ['The title of the page for search results and the browser tab: about 50 to 60 characters.', 120],
    'description' => ['A summary of the page for search results: one or two sentences, about 150 characters.', 320],
    'abstract' => ['A one-sentence abstract of the page.', 320],
    'keywords' => ['A few keywords, separated by commas.', 255],
    'og_title' => ['The title when the page is shared on social networks.', 120],
    'og_description' => ['The text when the page is shared on social networks: one or two sentences.', 320],
    'twitter_cards_title' => ['The title of the card on X/Twitter.', 120],
    'twitter_cards_description' => ['The text of the card on X/Twitter.', 320],
  ];

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected FieldDrafts $fieldDrafts,
  ) {}

  /**
   * The name of the meta tags field of a page, NULL when it has none.
   */
  public function field(NodeInterface $node): ?string {
    if (!$this->moduleHandler->moduleExists('metatag')) {
      return NULL;
    }
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'metatag') {
        return $name;
      }
    }
    return NULL;
  }

  /**
   * The tags the assistant may fill on this site: name => description.
   */
  public function offered(): array {
    $definitions = \Drupal::hasService('plugin.manager.metatag.tag') ? \Drupal::service('plugin.manager.metatag.tag')->getDefinitions() : [];
    return array_map(static fn (array $tag): string => $tag[0], array_intersect_key(self::TAGS, $definitions));
  }

  /**
   * The values set on the page itself (not the site's defaults), with what
   * the user has not saved yet.
   */
  public function current(NodeInterface $node): array {
    $field = $this->field($node);
    if (!$field) {
      return [];
    }
    $value = $this->fieldDrafts->apply($node)->get($field)->value;
    $tags = $value ? metatag_data_decode($value) : [];
    return is_array($tags) ? array_filter($tags, 'is_scalar') : [];
  }

  /**
   * Keeps proposed values as unsaved values of the node form.
   *
   * @param array $proposed
   *   Tag name => text, as the model gave it.
   *
   * @return array
   *   The values that were kept: only offered tags, as plain text.
   */
  public function propose(NodeInterface $node, array $proposed): array {
    $field = $this->field($node);
    if (!$field) {
      return [];
    }
    $kept = [];
    foreach (array_intersect_key($proposed, $this->offered()) as $name => $value) {
      if (is_scalar($value)) {
        // Plain text; tokens such as [node:title] are left for Metatag.
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
        $kept[$name] = mb_substr($text, 0, self::TAGS[$name][1]);
      }
    }
    if ($kept) {
      $tags = array_filter($kept + $this->current($node), static fn ($value): bool => $value !== '');
      $this->fieldDrafts->merge($node, [$field => [['value' => metatag_data_encode($tags)]]]);
    }
    return $kept;
  }

}
