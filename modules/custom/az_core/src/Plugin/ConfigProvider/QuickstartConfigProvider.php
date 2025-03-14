<?php

namespace Drupal\az_core\Plugin\ConfigProvider;

use Drupal\config_provider\Plugin\ConfigProviderBase;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;

/**
 * Class for providing configuration from a quickstart default directory.
 *
 * @ConfigProvider(
 *   id = \Drupal\az_core\Plugin\ConfigProvider\QuickstartConfigProvider::ID,
 *   weight = 20,
 *   label = @Translation("Quickstart Override"),
 *   description = @Translation("Configuration from Quickstart Overrides."),
 * )
 */
class QuickstartConfigProvider extends ConfigProviderBase {

  /**
   * The configuration provider ID.
   */
  const ID = 'config/quickstart';

  /**
   * List of permissions defined.
   *
   * @var array
   */
  protected $permissionDefinitions = [];

  /**
   * {@inheritdoc}
   */
  public function addConfigToCreate(array &$config_to_create, StorageInterface $storage, $collection, $prefix = '', array $profile_storages = []) {
    // Remove permissions that don't exist.
    $config_to_create = $this->trimPermissions($config_to_create);
  }

  /**
   * Find newly available permissions from profile.
   *
   * @param \Drupal\Core\Extension\Extension[] $extensions
   *   An associative array of Extension objects, keyed by extension name.
   *
   * @return array
   *   A list of the configuration data keyed by configuration object name.
   */
  public function findProfilePermissions(array $extensions = []) {

    $config_names = [];
    if (!empty($extensions)) {
      // Get config provided by extensions. For use in determining dependencies.
      $install_storage = $this->getExtensionInstallStorage(InstallStorage::CONFIG_INSTALL_DIRECTORY);
      $install_config_names = $this->listConfig($install_storage, $extensions);
      $optional_storage = $this->getExtensionInstallStorage(InstallStorage::CONFIG_OPTIONAL_DIRECTORY);
      $optional_config_names = $this->listConfig($optional_storage, $extensions);
      $config_names = array_merge($install_config_names, $optional_config_names);
    }

    // Build list of permissions by providers (eg. module)
    $permissions_definitions = $this->getPermissionDefinitions();
    $new_perms = [];

    // Profile is being enabled.
    if (!empty($extensions['az_quickstart'])) {
      // In the case of the profile being enabled, we get one run with all
      // permissions. This is for modules that were enabled before the profile.
      $new_perms = array_keys($permissions_definitions);
    }
    else {
      foreach ($permissions_definitions as $key => $permission) {
        // Permission's providing module is being enabled.
        if (!empty($extensions[$permission['provider']])) {
          $new_perms[] = $key;
        }
        elseif (!empty($permission['dependencies']['config'])) {
          // We should also have a chance to enable permissions if they
          // depend on a config entity a module provides. This is the case for
          // autogenerated node permissions, e.g. edit any X.
          $dependencies = $permission['dependencies']['config'];
          // Found common factor between config being provided and dependent
          // config of the permission.
          if (is_array($dependencies) && (!empty(array_intersect($dependencies, $config_names)))) {
            $new_perms[] = $key;
          }
        }
      }
    }

    if (empty($new_perms)) {
      return [];
    }
    sort($new_perms);

    // Get active configuration to look for roles.
    $existing_config = $this->getActiveStorages()->listAll();
    // phpcs:ignore
    $existing_roles = array_filter($existing_config, function ($name) {
      return (strpos($name, 'user.role.') === 0);
    });
    $role_config = $this->getActiveStorages()->readMultiple($existing_roles);

    $profile_storages = $this->getProfileStorages();
    // Check to see if the corresponding profile storage has any overrides.
    foreach ($role_config as $key => $data) {
      $current_perms = $data['permissions'] ?? [];
      $label = $data['label'] ?? 'Unnamed Role';
      foreach ($profile_storages as $profile_storage) {
        $profile_data = $profile_storage->read($key);
        if (!empty($profile_data['permissions'])) {
          // Remove permissions that don't exist.
          $profile_perms = array_intersect($profile_data['permissions'], $new_perms);
          // Remove permissions that already are used.
          $profile_perms = array_diff($profile_perms, $current_perms);
          sort($profile_perms);

          // Message about permissions.
          foreach ($profile_perms as $perm) {
            // @todo Use injection on user.permissions.
            // @phpstan-ignore-next-line
            \Drupal::messenger()->addMessage(t("Added permission %perm to %label",
            [
              '%perm' => $perm,
              '%label' => $label,
            ]));
          }
          if (!empty($profile_perms)) {
            $role_config[$key]['permissions'] = array_unique(array_merge($current_perms, $profile_perms));
          }
        }
      }
    }

    return $this->trimPermissions($role_config);
  }

  /**
   * Trim invalid permissions from configuration data.
   *
   * @param array $config
   *   A list of the configuration data keyed by configuration object name.
   *
   * @return array
   *   A list of the configuration data keyed by configuration object name.
   */
  protected function trimPermissions(array $config) {
    // Get permissions defined.
    $permission_definitions = $this->getPermissionDefinitions();
    $permissions = array_keys($permission_definitions);

    // Add the configuration changes.
    foreach ($config as $name => &$value) {
      // Is this a permission configuration file?
      if (strpos($name, 'user.role.') === 0) {
        // Dependencies TBD based on permissions.
        $value['dependencies'] = [];
        // Trim active permissions list to what's expected.
        if (!empty($value['permissions'])) {
          $value['permissions'] = array_intersect($permissions, $value['permissions']);
          sort($value['permissions']);
        }

        // Add dependencies based on permissions.
        foreach ($value['permissions'] as $perm) {
          $value['dependencies']['module'][] = $permission_definitions[$perm]['provider'];
          if (!empty($permission_definitions[$perm]['dependencies'])) {
            foreach ($permission_definitions[$perm]['dependencies'] as $dependency_type => $list) {
              foreach ($list as $dependency) {
                $value['dependencies'][$dependency_type][] = $dependency;
              }
            }
          }
        }
        // Make sure dependencies are unique.
        foreach ($value['dependencies'] as $dependency_type => &$dependencies) {
          $dependencies = array_values(array_unique($dependencies));
          sort($dependencies);
        }
        ksort($value['dependencies']);
      }

      // Add transformed config hash.
      $value = $this->addDefaultConfigHash($value);
    }

    return $config;
  }

  /**
   * Get permissions defined.
   *
   * @return array
   *   An array of permission definitions.
   */
  protected function getPermissionDefinitions() {
    // @todo Use injection on user.permissions. and add caching.
    // @phpstan-ignore-next-line
    return \Drupal::service('user.permissions')->getPermissions();
  }

  /**
   * Returns a list of immediate overrides available at module install time.
   *
   * @param \Drupal\Core\Extension\Extension[] $extensions
   *   An associative array of Extension objects, keyed by extension name.
   * @param \Drupal\Core\Extension\Extension[] $old_extensions
   *   Already loaded Extension objects, keyed by extension name.
   *
   * @return array
   *   A list of the configuration data keyed by configuration object name.
   */
  public function getOverrideConfig(array $extensions = [], array $old_extensions = []) {

    // Find the direct overrides for use at module install time.
    $storage = $this->getExtensionInstallStorage(static::ID);
    $config_names = $this->listConfig($storage, $extensions);
    $data = $storage->readMultiple($config_names);

    // Get active configuration to check dependencies with.
    $existing_config = $this->getActiveStorages()->listAll();
    $all_config = $this->getActiveStorages()->readMultiple($existing_config) + $data;
    $enabled_extensions = $this->getEnabledExtensions();

    // Get the install configuration present for the specified modules.
    // We need to check if an already-enabled module contained passive override.
    $install_storage = $this->getExtensionInstallStorage(InstallStorage::CONFIG_INSTALL_DIRECTORY);
    $install_config_names = $this->listConfig($install_storage, $extensions);

    // Now compare to quickstart config of already-loaded modules;
    // We are checking to see if an already loaded module contained a change
    // that couldn't be loaded previously for dependency reasons.
    $override_storage = $this->getExtensionInstallStorage(static::ID);
    $override_config_names = $this->listConfig($override_storage, $old_extensions);
    $intersect = array_intersect($override_config_names, $install_config_names);
    $overrides = $storage->readMultiple($intersect);

    // Merge passive overrides, eg. overrides to a new module from a module that
    // had already been loaded.
    $data = array_merge($data, $overrides);

    // Add default config hash to overrides.
    foreach ($data as $name => &$value) {
      $value = $this->addDefaultConfigHash($value);
      if (!$this->validateDependencies($name, $data, $enabled_extensions, $all_config)) {
        // Couldn't validate dependency.
        unset($data[$name]);
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateDependencies($config_name, array $data, array $enabled_extensions, array $all_config) {

    // Parent version does not account for simple config module dependencies.
    if (!isset($data['dependencies'])) {
      // Simple config or a config entity without dependencies.
      [$provider] = explode('.', $config_name, 2);
      return in_array($provider, $enabled_extensions, TRUE);
    }
    return parent::validateDependencies($config_name, $data, $enabled_extensions, $all_config);
  }

  /**
   * {@inheritdoc}
   */
  public function addInstallableConfig(array $extensions = []) {

    // Get the install configuration present for the specified modules.
    $storage = $this->getExtensionInstallStorage(InstallStorage::CONFIG_INSTALL_DIRECTORY);
    $config_names = $this->listConfig($storage, $extensions);
    $profile_storages = $this->getProfileStorages();
    $data = $storage->readMultiple($config_names);

    // Check for quickstart defaults for the same configuration names.
    $override_storage = $this->getExtensionInstallStorage(static::ID);
    $override_data = $override_storage->readMultiple($config_names);

    // Merge the two configs, with preference for the quickstart defaults.
    $data = array_merge($data, $override_data);

    // Check to see if the corresponding profile storage has any overrides.
    foreach ($profile_storages as $profile_storage) {
      $data = $profile_storage->readMultiple(array_keys($data)) + $data;
    }

    // Remove permissions that don't exist.
    $data = $this->trimPermissions($data);

    // Add the configuration changes.
    foreach ($data as $name => $value) {
      $value = $this->addDefaultConfigHash($value);
      $this->providerStorage->write($name, $value);
    }

  }

}
