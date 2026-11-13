<?php

/**
 * @file
 * State of the site the end-to-end tests expect. Run by setup.sh with
 * "drush php:script"; safe to run again.
 *
 * - Languages: English (default) and German, with the /de path prefix.
 * - Content types, all with the page builder on:
 *   - landing_page (of the recipe): translations synced, translatable;
 *   - e2e_independent: translations independent, translatable;
 *   - e2e_moderated: under the content moderation workflow "e2e_editorial"
 *     (Draft, Published);
 *   - e2e_grouped: fields in Field Group groups. "Lead" is in no group (the
 *     Content panel), "Story" has field_e2e_story, "Facts" (icon "list") has
 *     field_e2e_source and the required field_e2e_must.
 * - Users: admin / admin (user 1) and editor / editor, with the roles
 *   content_editor (of the recipe) and e2e_editor (the types above,
 *   translations, the workflow); author / author with the role e2e_author,
 *   who may save drafts of e2e_moderated pages but not publish them.
 * - Paragraph type text_block (of the recipe frame_builder_paragraphs).
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\workflows\Entity\Workflow;

// --- languages
if (!ConfigurableLanguage::load('de')) {
  ConfigurableLanguage::createFromLangcode('de')->save();
}
\Drupal::configFactory()->getEditable('language.negotiation')
  ->set('url.source', 'path_prefix')
  ->set('url.prefixes', ['en' => '', 'de' => 'de'])
  ->save();

// --- content types
$types = [
  'e2e_independent' => ['E2E independent', 'independent'],
  'e2e_moderated' => ['E2E moderated', 'synced'],
  'e2e_grouped' => ['E2E grouped', 'synced'],
];
foreach ($types as $id => [$label, $mode]) {
  $type = NodeType::load($id) ?: NodeType::create(['type' => $id, 'name' => $label]);
  $type->set('new_revision', TRUE);
  $type->set('display_submitted', FALSE);
  $type->setThirdPartySetting('frame_builder', 'enabled', TRUE);
  $type->setThirdPartySetting('frame_builder', 'translation_mode', $mode);
  $type->save();
}

// --- translatable types
foreach (['landing_page', 'e2e_independent'] as $bundle) {
  ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)
    ->setDefaultLangcode('en')
    ->setLanguageAlterable(TRUE)
    ->setThirdPartySetting('content_translation', 'enabled', TRUE)
    ->save();
}

// --- fields and field groups of e2e_grouped
$fields = [
  'field_e2e_lead' => ['Lead', FALSE],
  'field_e2e_story' => ['Story text', FALSE],
  'field_e2e_source' => ['Source', FALSE],
  'field_e2e_must' => ['Must have', TRUE],
];
$form = EntityFormDisplay::load('node.e2e_grouped.default')
  ?: EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => 'e2e_grouped', 'mode' => 'default', 'status' => TRUE]);
$view = EntityViewDisplay::load('node.e2e_grouped.default')
  ?: EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'e2e_grouped', 'mode' => 'default', 'status' => TRUE]);
$weight = 10;
foreach ($fields as $name => [$label, $required]) {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'string'])->save();
  }
  $field = FieldConfig::loadByName('node', 'e2e_grouped', $name)
    ?: FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'e2e_grouped']);
  $field->setLabel($label)->setRequired($required)->save();
  $form->setComponent($name, ['type' => 'string_textfield', 'weight' => $weight]);
  $view->setComponent($name, ['type' => 'string', 'label' => 'above', 'weight' => $weight]);
  $weight++;
}
$group = fn(string $label, string $format, array $children, int $weight, string $icon = ''): array => [
  'children' => $children,
  'label' => $label,
  'parent_name' => '',
  'region' => 'content',
  'weight' => $weight,
  'format_type' => $format,
  'format_settings' => ['classes' => '', 'id' => '', 'description' => '', 'required_fields' => TRUE]
    + ($format === 'details' ? ['open' => TRUE] : [])
    + ($icon ? ['frame_builder_icon' => $icon] : []),
];
$form->setThirdPartySetting('field_group', 'group_e2e_story', $group('Story', 'details', ['field_e2e_story'], 20));
$form->setThirdPartySetting('field_group', 'group_e2e_facts', $group('Facts', 'fieldset', ['field_e2e_source', 'field_e2e_must'], 21, 'list'));
$form->save();
$view->save();

// --- content moderation
$workflow = Workflow::load('e2e_editorial')
  ?: Workflow::create(['id' => 'e2e_editorial', 'label' => 'E2E editorial', 'type' => 'content_moderation']);
$workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'e2e_moderated');
$workflow->save();

// --- users
$role = Role::load('e2e_editor') ?: Role::create(['id' => 'e2e_editor', 'label' => 'E2E editor']);
foreach ([
  'access content',
  'access administration pages',
  'view the administration theme',
  'view own unpublished content',
  'use frame builder',
  'create content translations',
  'update content translations',
  'translate any entity',
  'view latest version',
  'view any unpublished content',
  'use e2e_editorial transition create_new_draft',
  'use e2e_editorial transition publish',
] as $permission) {
  $role->grantPermission($permission);
}
foreach (array_keys($types) as $bundle) {
  foreach (["create $bundle content", "edit any $bundle content", "delete own $bundle content", "view $bundle revisions", "revert $bundle revisions"] as $permission) {
    $role->grantPermission($permission);
  }
}
$role->save();

$admin = User::load(1);
$admin->setUsername('admin')->setPassword('admin')->activate()->save();
$editor = user_load_by_name('editor') ?: User::create(['name' => 'editor', 'mail' => 'editor@example.com']);
$editor->setPassword('editor');
$editor->activate();
$editor->addRole('content_editor');
$editor->addRole('e2e_editor');
$editor->save();

// An author of moderated pages: drafts only.
$role = Role::load('e2e_author') ?: Role::create(['id' => 'e2e_author', 'label' => 'E2E author']);
foreach ([
  'access content',
  'view the administration theme',
  'view own unpublished content',
  'use frame builder',
  'view latest version',
  'create e2e_moderated content',
  'edit own e2e_moderated content',
  'use e2e_editorial transition create_new_draft',
] as $permission) {
  $role->grantPermission($permission);
}
$role->save();
$author = user_load_by_name('author') ?: User::create(['name' => 'author', 'mail' => 'author@example.com']);
$author->setPassword('author');
$author->activate();
$author->addRole('e2e_author');
$author->save();

// Visitors see published pages.
Role::load('anonymous')->grantPermission('access content')->save();
// The admin theme for the pages around the editor, as on a real site.
\Drupal::service('theme_installer')->install(['claro']);
\Drupal::configFactory()->getEditable('system.theme')->set('admin', 'claro')->save();
\Drupal::configFactory()->getEditable('node.settings')->set('use_admin_theme', TRUE)->save();

echo "Content types, fields, field groups, workflow, languages and users are set.\n";
