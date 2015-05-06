<?php

/**
 * Contains \Drupal\config_update\ConfigReverter.
 */

namespace Drupal\config_update;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Provides methods related to config listing.
 */
class ConfigReverter implements ConfigRevertInterface {

  /**
   * List of current config entity types, keyed by prefix.
   *
   * This is not set up until ConfigReverter::listTypes() has been called.
   *
   * @var string[]
   */
  protected $typesByPrefix = array();

  /**
   * List of current config entity type definitions, keyed by entity type.
   *
   * This is not set up until ConfigReverter::listTypes() has been called.
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
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $extensionConfigStorage;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $dispatcher;

  /**
   * Constructs a ConfigReverter.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\StorageInterface $active_config_storage
   *   The active config storage.
   * @param \Drupal\Core\Config\StorageInterface $extension_config_storage
   *   The extension config storage.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   */
  public function __construct(EntityManagerInterface $entity_manager, StorageInterface $active_config_storage, StorageInterface $extension_config_storage, ConfigFactoryInterface $config_factory, EventDispatcherInterface $dispatcher) {
    $this->entityManager = $entity_manager;
    $this->activeConfigStorage = $active_config_storage;
    $this->extensionConfigStorage = $extension_config_storage;
    $this->configFactory = $config_factory;
    $this->dispatcher = $dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public function import($type, $name) {
    // Read the config from the file.
    $full_name = $this->getFullName($type, $name);
    $value = $this->extensionConfigStorage->read($full_name);

    // Save it as a new config entity or simple config.
    if ($type == 'system.simple') {
      $this->configFactory->getEditable($full_name)->setData($value)->save();
    }
    else {
      $entity_storage = $this->entityManager->getStorage($type);
      $entity = $entity_storage->createFromStorageRecord($value);
      $entity->save();
    }

    // Trigger an event notifying of this change.
    $event = new ConfigRevertEvent($type, $name);
    $this->dispatcher->dispatch(ConfigRevertInterface::IMPORT, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function revert($type, $name) {
    // Read the config from the file.
    $full_name = $this->getFullName($type, $name);
    $value = $this->extensionConfigStorage->read($full_name);

    if ($type == 'system.simple') {
      // Load the current config and replace the value.
      $this->configFactory->getEditable($full_name)->setData($value)->save();
    }
    else {
      // Load the current config entity and replace the value, with the
      // old UUID.
      $definition = $this->entityManager->getDefinition($type);
      $id_key = $definition->getKey('id');

      $id = $value[$id_key];
      $entity_storage = $this->entityManager->getStorage($type);
      $entity = $entity_storage->load($id);
      $uuid = $entity->get('uuid');
      $entity = $entity_storage->updateFromStorageRecord($entity, $value);
      $entity->set('uuid', $uuid);
      $entity->save();
    }

    // Trigger an event notifying of this change.
    $event = new ConfigRevertEvent($type, $name);
    $this->dispatcher->dispatch(ConfigRevertInterface::REVERT, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function getFromActive($type, $name) {
    $full_name = $this->getFullName($type, $name);
    return $this->activeConfigStorage->read($full_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getFromExtension($type, $name) {
    $full_name = $this->getFullName($type, $name);
    return $this->extensionConfigStorage->read($full_name);
  }

  /**
   * Returns the full name of a config item.
   *
   * @param string $type
   *   The config type, or '' to indicate $name is already prefixed.
   * @param string $name
   *   The config name, without prefix.
   *
   * @return string
   *   The config item's full name.
   */
  protected function getFullName($type, $name) {
    if ($type == 'system.simple' || !$type) {
      return $name;
    }

    $definition = $this->entityManager->getDefinition($type);
    $prefix = $definition->getConfigPrefix() . '.';
    return $prefix . $name;
  }

}
