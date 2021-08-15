<?php

/*---------*/
define('FMTHEME_DIR', __DIR__ . '/');
define('FMTHEME_INC_DIR', FMTHEME_DIR . 'inc/');

/*---------*/
require_once(FMTHEME_INC_DIR . 'key_load.php');

/*---------*/
key_wptheme::loadTheme(array(
  'files' => array(
    'logo' => 'YouTubeIcon.png'
  ),
  //Dorchester
  'adminStyle' => array(
    'main' => '#4b3f4e',
    'priAccent' => '#9b889f',
    'secAccent' => '#c0b5c3',
    'themeName' => 'Fullmoon Gamerz'
  ),
  'loginLogo' => array(
    'padding-bottom' => '0',
    'width' => '150px',
    'height' => '150px'
  ),
  'copyright' => array(
    'content' => 'Powered by JAMIE!'
  ),
  'init' => array(
    'hide' => array(
      'bar-wordpress',
      'bar-new',
      //Change these to show comments/appearence if needed
      'themes',
      'comments'
    )
  ),
  'enqueue' => array(
    'preload' => array(
      'font' => array(
        'roboto-light'  => array('file' => 'Roboto-Light.ttf',        'crossorigin' => true),
        'roboto-medium' => array('file' => 'Roboto-Medium.ttf',       'crossorigin' => true),
        'jollylodger'   => array('file' => 'JollyLodger-Regular.ttf', 'crossorigin' => true)
        //Roboto-Medium.ttf
      )
    )
  )
));

/*---------*/
add_action('wp_head', 'fullmoon_head');
function fullmoon_head() {
  echo('<meta charset="utf-8">' . PHP_EOL);
  echo('<meta http-equiv="x-ua-compatible" content="ie=edge">' . PHP_EOL);
  echo('<meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL);
  //TODO: Favicon
}

?>