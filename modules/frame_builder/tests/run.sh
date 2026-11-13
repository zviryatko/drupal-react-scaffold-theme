#!/bin/sh
# Runs the PHPUnit tests of the page builder on a Drupal site that has this theme in web/themes/contrib/frame and
# drupal/core-dev installed. From the root of the site (where vendor/ is):
#
#   web/themes/contrib/frame/modules/frame_builder/tests/run.sh [phpunit arguments or paths]
#
# Functional tests need the site served at SIMPLETEST_BASE_URL (default http://127.0.0.1:8080):
#   (cd web && php -S 127.0.0.1:8080 .ht.router.php)
set -e
here="$(cd "$(dirname "$0")" && pwd)"
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://127.0.0.1:8080}"
export SIMPLETEST_DB="${SIMPLETEST_DB:-sqlite://localhost/sites/default/files/.ht.phpunit.sqlite}"
export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp}"
export SYMFONY_DEPRECATIONS_HELPER="${SYMFONY_DEPRECATIONS_HELPER:-disabled}"
if [ "$#" -eq 0 ]; then set -- "$here"; fi
exec vendor/bin/phpunit -c web/core/phpunit.xml.dist "$@"
