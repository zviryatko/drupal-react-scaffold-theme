#!/usr/bin/env bash
# Makes a Drupal site ready for the end-to-end tests of the page builder (demo/e2e/*.spec.mjs).
#
#   demo/e2e/setup.sh [--install]
#
# Idempotent: run it again after a change, the specs make their own pages. Needs the theme in
# web/themes/contrib/frame with the editor built (npm run builder:install && npm run builder:build) and the modules
# field_group, paragraphs (with paragraphs_library), entity_reference_revisions and entity_usage in the codebase;
# language, content_translation and content_moderation are in core.
#
# Environment:
#   DRUSH       how to run Drush, from the Drupal project root. Default: vendor/bin/drush in the current folder.
#               In a container: DRUSH="docker exec -w /site/app frame-fg-test vendor/bin/drush"
#   FRAME_PATH  the theme, relative to the docroot. Default: themes/contrib/frame
#   E2E_THEME   default theme of the site: frame or an installed subtheme of it. Default: frame
#   DB_URL      database for --install. Default: sqlite://sites/default/files/.ht.sqlite
#
# --install first installs Drupal anew with the minimal profile, which EMPTIES the site.
set -euo pipefail

DRUSH=${DRUSH:-vendor/bin/drush}
FRAME_PATH=${FRAME_PATH:-themes/contrib/frame}
E2E_THEME=${E2E_THEME:-frame}
DB_URL=${DB_URL:-sqlite://sites/default/files/.ht.sqlite}

if [ "${1:-}" = "--install" ]; then
  $DRUSH site:install minimal --db-url="$DB_URL" --site-name="Frame e2e" --account-name=admin --account-pass=admin -y
fi

$DRUSH theme:install "$E2E_THEME" -y
# The module with media, responsive images, the Landing page type and the Content editor role; then paragraphs with
# the Paragraphs Library, Entity Usage and the "Text block" paragraph type.
$DRUSH recipe "$FRAME_PATH/recipes/frame_builder" --input=frame_builder.theme="$E2E_THEME" -y
$DRUSH recipe "$FRAME_PATH/recipes/frame_builder_paragraphs" -y
$DRUSH pm:install language content_translation content_moderation field_group -y
# Content types, fields, field groups, the workflow, the second language and the users.
$DRUSH php:script "$FRAME_PATH/demo/e2e/setup.php"
$DRUSH cache:rebuild
echo "The site is ready for the end-to-end tests."
