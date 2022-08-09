<?php
/*---------*/
require_once(__DIR__ . '/inc/key_load.php');

/*---------*/
key_wptheme::loadTheme(array(
  'files' => array(
    'logo' => 'YouTubeIcon.png',
    'includeFunctions' => array(
      'widgets',
      'utils'
    )
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
if (is_admin()) {
  fullmoon_widgets::init();

  add_action('init', 'fm_adminInit');
}

/*---------*/
function fm_adminInit() {
  if (function_exists('acf_add_options_page')) {
    acf_add_options_page(array(
      'page_title' 	=> 'Video Widgets',
      'menu_title' 	=> 'Video Widgets',
      'menu_slug' 	=> 'fullmoon-video-widgets',
      'icon_url'   => 'dashicons-video-alt3',
      'position'    => 15
    ));		
  }
}

?>