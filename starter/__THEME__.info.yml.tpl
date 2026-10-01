name: '__LABEL__'
description: 'Subtheme of React Scaffold.'
type: theme
base theme: react_scaffold
core_version_requirement: ^10.3 || ^11
package: Custom

# Added to every page, on top of the libraries inherited from React Scaffold.
libraries:
  - __THEME__/global-styling

# Drupal does not inherit regions from a base theme, so they are declared here. This list is copied from react_scaffold.info.yml,
# the base theme's page.html.twig prints exactly these regions.
__REGIONS__