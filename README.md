# Composer Importer

_Composer Importer_ is a Composer plugin that can import all contrib modules from an existing Drupal site already using composer into the composer file. This is useful when the composer file doesn't get used properly and modules end up being installed outside composer.

This project was created based on the work by grasmash/composerize-drupal

## Installation

```
composer global require bap72190/composer-importer
```

## Usage:
```
cd path/to/drupal/project/repo
composer ci --composer-root=[repo-root] --drupal-root=[drupal-root]
```

The `[composer-root]` should be the root directory of your project, where existing composer.json file is located.

The `[drupal-root]` should be the Drupal root, where `index.php` is located.

## Options

* `--composer-root`: Specifies the root directory of your project where `composer.json` will be generated. This should be the root of your Git repository, where `.git` is located.
* `--drupal-root`: Specifies the Drupal root directory where `index.php` is located.
* `--no-update`: Prevents `composer update` from being automatically run after `composer.json` is generated.
* `--exact-versions`: Will cause Drupal core and contributed projects (modules, themes, profiles) to be be required with exact verions constraints in `composer.json`, rather than using the default caret operator. E.g., a `drupal/core` would be required as `8.4.4` rather than `^8.4.4`. This prevents projects from being updated. It is not recommended as a long-term solution, but may help you convert to using Composer more easily by reducing the size of the change to your project.
