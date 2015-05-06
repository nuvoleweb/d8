<?php

/**
 * Contains \Drupal\config_update\ConfigLister.
 */

namespace Drupal\config_update;

use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Site\Settings;

/**
 * Provides methods related to config listing.
 */
class ConfigLister implements ConfigListInterface {

  /**
   * List of current config entity types, keyed by prefix.
   *
   * This is not set up until ConfigLister::listTypes() has been called.
   *
   * @var string[]
   */
  protected $typesByPrefix = array();

  /**
   * List of current config entity type definitions, keyed by entity type.
   *
   * This is not set up until ConfigLister::listTypes() has been called.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface[]
   */
  protected $definitions = array();

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The active config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeConfigStorage;

  /**
   * The extension config storage.
   *
   * @var \Drupal\Core\Config\ExtensionInstallStorage
   */
  protected $extensionConfigStorage;

  /**
   * Constructs a ConfigLister.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\StorageInterface $active_config_storage
   *   The active config storage.
   * @param \Drupal\Core\Config\ExtensionInstallStorage $extension_config_storage
   *   The extension config storage. This must be a class that has
   *   the methods of StorageInterface, plus getComponentNames() from the
   *   ExtensoinInstallStorage class.
  */
  public function __construct(EntityManagerInterface $entity_manager, StorageInterface $active_config_storage, ExtensionInstallStorage $extension_config_storage) {
    $this->entityManager = $entity_manager;
    $this->activeConfigStorage = $active_config_storage;
    $this->extensionConfigStorage = $extension_config_storage;
  }

  /**
   * Sets up and returns the entity definitions list.
   */
  public function listTypes() {
    if (count($this->definitions)) {
      return $this->definitions;
    }

    foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
        $this->definitions[$entity_type] = $definition;
        $prefix = $definition->getConfigPrefix();
        $this->typesByPrefix[$prefix] = $entity_type;
      }
    }

    ksort($this->definitions);

    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getType($name) {
    $definitions = $this->listTypes();
    return isset($definitions[$name]) ? $definitions[$name] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeByPrefix($prefix) {
    $definitions = $this->listTypes();
    return isset($this->typesByPrefix[$prefix]) ? $definitions[$this->typesByPrefix[$prefix]] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeNameByConfigName($name) {
    $definitions = $this->listTypes();
    foreach ($this->typesByPrefix as $prefix => $entity_type) {
      if (strpos($name, $prefix) === 0) {
        return $entity_type;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  function listConfig($list_type, $name) {
    $active_list = array();
    $install_list = array();
    $definitions = $this->listTypes();

    switch($list_type) {
      case 'type':
        if ($name == 'system.all') {
          $active_list = $this->activeConfigStorage->listAll();
          $install_list = $this->extensionConfigStorage->listAll();
        }
        elseif ($name == 'system.simple') {
          // Listing is done by prefixes, and simple config doesn't have one.
          // So list all and filter out all known prefixes.
          $active_list = $this->omitKnownPrefixes($this->activeConfigStorage->listAll());
          $install_list = $this->omitKnownPrefixes($this->extensionConfigStorage->listAll());
        }
        elseif (isset($this->definitions[$name])) {
          $definition = $this->definitions[$name];
          $prefix = $definition->getConfigPrefix();
          $active_list = $this->activeConfigStorage->listAll($prefix);
          $install_list = $this->extensionConfigStorage->listAll($prefix);
        }
        break;

      case 'profile':
        $name = Settings::get('install_profile');
        // Intentional fall-through here to the 'module' or 'theme' case.

      case 'module':
      case 'theme':
        $active_list = $this->activeConfigStorage->listAll();
        $install_list = $this->listProvidedItems($list_type, $name);
        break;
    }

    return array($active_list, $install_list);
  }

  /**
   * Returns a list of the install storage items for an extension.
   *
   * @param string $type
   *   Type of extension ('module', etc.).
   * @param string $name
   *   Machine name of extension.
   *
   * @return string[]
   *   List of config items provided by this extension.
   */
  protected function listProvidedItems($type, $name) {
    return array_keys($this->extensionConfigStorage->getComponentNames($type, array($name)));
  }

  /**
   * Omits config with known prefixes from a list of config names.
   */
  protected function omitKnownPrefixes($list) {
    $prefixes = array_keys($this->typesByPrefix);
    $list = array_combine($list, $list);
    foreach ($list as $name) {
      foreach ($prefixes as $prefix) {
        if (strpos($name, $prefix) === 0) {
          unset($list[$name]);
        }
      }
    }

    return array_values($list);
  }

}
