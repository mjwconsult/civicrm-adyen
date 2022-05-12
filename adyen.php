<?php

require_once 'adyen.civix.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

// phpcs:disable
use CRM_Adyen_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function adyen_civicrm_config(&$config) {
  _adyen_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function adyen_civicrm_install() {
  _adyen_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function adyen_civicrm_postInstall() {
  _adyen_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function adyen_civicrm_uninstall() {
  _adyen_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function adyen_civicrm_enable() {
  _adyen_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function adyen_civicrm_disable() {
  _adyen_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function adyen_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _adyen_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function adyen_civicrm_entityTypes(&$entityTypes) {
  _adyen_civix_civicrm_entityTypes($entityTypes);
}


/**
 * Implements hook_civicrm_check().
 *
 * @throws \CiviCRM_API3_Exception
 */
function adyen_civicrm_check(&$messages) {
  $checks = new CRM_Adyen_Check($messages);
  $messages = $checks->checkRequirements();
}
