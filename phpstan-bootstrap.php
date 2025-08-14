<?php

/**
 * @file
 * PHPStan bootstrap file for AI Provider LLMD module.
 */

// Define Drupal root if not already defined.
if (!defined('DRUPAL_ROOT')) {
  define('DRUPAL_ROOT', __DIR__ . '/../../core');
}

// Define constants that Drupal expects.
if (!defined('DRUPAL_MINIMUM_PHP')) {
  define('DRUPAL_MINIMUM_PHP', '8.1.0');
}

// Load the Drupal autoloader if available.
$autoloader = DRUPAL_ROOT . '/vendor/autoload.php';
if (file_exists($autoloader)) {
  require_once $autoloader;
}