<?php
  /* Auto Generated Load File for Key Common Scripts */

  if (!isset($GLOBALS['key_code_versions'])) {
    $GLOBALS['key_code_versions'] = array();
  }
  if (!isset($GLOBALS['key_code_paths'])) {
    $GLOBALS['key_code_paths'] = array();
  }

  foreach(array(
    'file' => array('version' => 6, 'classes' => array('key_file')),
    'wordpress' => array('version' => 36, 'classes' => array('key_wpplugin','key_wpkdk','key_wpdashboard','key_wptheme','key_wpposttype','key_wpsitemap')),
  ) as $key => $info) {
    if (!isset($GLOBALS['key_code_versions'][$key]) || ($GLOBALS['key_code_versions'][$key] < $info['version'])) {
      $GLOBALS['key_code_versions'][$key] = $info['version'];
      foreach($info['classes'] as $className) {
        $GLOBALS['key_code_paths'][$className] = sprintf('%s/key_common_%s.php', dirname(__FILE__), $key);
      }
    }
  }

  if (!function_exists('key_code_autoloader')) {
    function key_code_autoloader($class) {
      if (isset($GLOBALS['key_code_paths'][$class])) {
        require_once($GLOBALS['key_code_paths'][$class]);
      }
    }
    spl_autoload_register('key_code_autoloader');
  }

  /* Auto Generated Load File for Key Common Scripts */
?>
