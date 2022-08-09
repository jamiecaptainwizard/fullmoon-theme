<?php
/*
* Common PHP File Utils Class for Key.Digital Agency
*
* = Licence =
* Copyright (C) Key Digital Agency Limited - All Rights Reserved
* Unauthorized copying of this file, via any medium is strictly prohibited
* For proprietary use within the Key.Digital web projects, and development.
* 
* = Change Log =
* Creation : Jamie McDonald : 6th Novemeber 2018
* Bug      : Jamie McDonald : 15th Feb 2019 - Protection if two of the common utils are included
*/

/*----------------------------*/
/*--KEY_FILE------------------*/
/*----------------------------*/
if (!class_exists('key_file')) {    
  abstract class key_file {
    public static $VERSION = 6;

    /*-----*/
    private static $keyLockSVG = <<<KEYSVG
    <svg 
      xmlns="http://www.w3.org/2000/svg"
      xmlns:xlink="http://www.w3.org/1999/xlink"
      viewBox="0 0 600 600" width="%d" height="%d">  
      <path d=" M 300 25 C 147.692 25 25 147.692 25 300 L 
                25 575 L 300 575 C 452.308 575 575 452.308 
                575 300 C 575 147.692 452.308 25 300 25 Z  
                M 358.173 442.788 L 228.077 442.788 C 222.788 
                442.788 218.558 437.5 219.615 432.212 L 248.173 
                303.173 C 249.231 296.827 254.519 290.481 
                259.808 288.365 C 241.827 277.788 229.135 257.692 
                229.135 234.423 C 229.135 199.519 257.692 170.962 
                292.596 170.962 C 327.5 170.962 356.058 199.519 
                356.058 234.423 C 356.058 257.692 343.365 277.788 
                325.385 288.365 C 331.731 290.481 335.962 295.769 
                337.019 303.173 L 365.577 432.212 C 367.692 437.5 
                363.462 442.788 358.173 442.788 Z " 
      fill="%s"/>
    </svg>
KEYSVG;
   
    /*-----*/
    public static function keyDigitalWordpressMenuIcon() {
      return self::keyDigitalLockAsSVG(20, '#A5AAAE', true);
    }
  
    /*-----*/
    public static function keyDigitalLockAsSVG($size = 400, $RBGColor = '#DC0A3B', $asBase64 = false) {
     $res = trim(sprintf(self::$keyLockSVG, $size, $size, $RBGColor));  
      if ($asBase64) {
        $res = 'data:image/svg+xml;base64,' . base64_encode($res); ;
      }
    
      return $res;
    }  

    /*-----*/
    public static function createFolder($dir, $denyHTAccess = false) {
      if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
      }
	  
	    if ($denyHTAccess) {
		    $htaccessFile = $dir . '.htaccess';
		    if (!file_exists($htaccessFile)) {
		      file_put_contents($htaccessFile, 'Deny from all');
		    }
	    }
    }

    /*-----*/
    public static function deleteFolder($dir) { 
      if (!file_exists($dir)) {
        return false;
      }
  
      $files = array_diff(scandir($dir), array('.','..')); 
      foreach ($files as $file) { 
        $path = rtrim($dir, '/') . '/' . $file;
        if (is_dir($path)) {
          @self::deleteFolder($path);
		      @rmdir($path); 
        } else {
          @unlink($path);
        } 
      } 
      return rmdir($dir); 
    } 

    /*-----*/
    public static function deleteFile($file) {
      if (file_exists($file)) {
        @unlink($file);
      }    
    }

    /*-----*/
    public static function getFileExtension($fileNameOrPath, $toLowerCase = true) {
      $ext = substr(strrchr($fileNameOrPath, '.'), 1);
      return $toLowerCase ? strtolower($ext) : $ext;
    }

    /*-----*/
    public static function listFilesInDirectory($directory, $extensions = array(), $exclude = array(), $removeRootDirectory = true) {
      $toReturn = array();
   
      $excludeArray = is_array($exclude) ? $exclude : array(strval($exclude));
      $excludeFiles = array_merge(array(".",".."), $excludeArray);

      $allowedExtensionsArray = is_array($extensions) ? $extensions : array(strval($extensions));
      $allowedExtensions = array();
      foreach($allowedExtensionsArray as $extension) {
        $allowedExtensions[] = str_replace('.', '', strtolower($extension));
      }

      $scannedDir = scandir($directory);
      if (($scannedDir !== false) && (is_array($scannedDir))) {
        foreach ($scannedDir as $file) {
          if (!in_array($file, $excludeFiles)) {
            $fullPath = $directory . '/' . $file;
            if (is_dir($fullPath)) {
              $toReturn = array_merge($toReturn, self::listFilesInDirectory($fullPath, $extensions, $exclude, false));
            } else {
              if ((count($allowedExtensions) > 0) && (!in_array(self::getFileExtension($file), $allowedExtensions))) {
                continue;
              } 
              $toReturn[] = $fullPath;
            }
          }
        }
      }

      if ($removeRootDirectory) {
        $newReturn = array();
        foreach($toReturn as $file) {
          $newReturn[] = str_replace($directory . '/', '', $file);
        }
        $toReturn = $newReturn;
      }

      return $toReturn;
   }

    /*-----*/  
  }
}

?>