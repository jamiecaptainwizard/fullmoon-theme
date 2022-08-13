<?php
define('FM_WIDGETS_DIR', ABSPATH . 'widgets/');

/*------------*/
/*------------*/
/*------------*/
class fullmoon_widgets {

  /*------------*/
  public static function init() {
    add_action('acf/save_post', array(static::class, 'afterACFSave'), 20);	
  }

  /*------------*/
  public static function afterACFSave() {
    $screen = get_current_screen();
    if (($screen != null)  && (strpos($screen->id, 'fullmoon-video-widgets') !== false)) {
      static::createWidgets();
    }
  }

  /*------------*/
  public static function createWidgets() {
    key_file::deleteFolder(FM_WIDGETS_DIR);
    key_file::createFolder(FM_WIDGETS_DIR);

    static::createSoon();
    static::createSubscribe();
    static::createSpoilers();
    static::createTop();
    static::createPlayer();
    static::createSide();
  }

  /*------------*/
  public static function saveWidget($key, $content, $includeAnimateCss = false, $bodyAttributes = '') {
    $html = '<html>' . PHP_EOL;
    $html .= '  <head>' . PHP_EOL;
    $html .= '    <title>Fullmoon Gamerz Widget : ' . $key . '</title>' . PHP_EOL;

    if ($includeAnimateCss) {
      $cssFile = sprintf('%sanimate.min.css', KEY_THEME_DIR_STYLE);
      if (file_exists($cssFile)) {
        $cssRaw = file_get_contents($cssFile);
        if ($cssRaw !== false) {
          $html .= '  <style>' . PHP_EOL;
          $html .= $cssRaw . PHP_EOL;
          $html .= '  </style>' . PHP_EOL;
        }
      }
    }

    $cssFile = sprintf('%swidget_%s.min.css', KEY_THEME_DIR_STYLE, $key);
    if (file_exists($cssFile)) {
      $cssRaw = file_get_contents($cssFile);
      if ($cssRaw !== false) {
        $html .= '  <style>' . PHP_EOL;
        $html .= $cssRaw . PHP_EOL;
        $html .= '  </style>' . PHP_EOL;
      }
    }

    $scriptFile = sprintf('%swidget_%s.min.js', KEY_THEME_DIR_SCRIPT, $key);
    if (file_exists($scriptFile)) {
      $scriptRaw = file_get_contents($scriptFile);
      if ($scriptRaw !== false) {
        $html .= '  <script>' . PHP_EOL;
        $html .= $scriptRaw . PHP_EOL;
        $html .= '  </script>' . PHP_EOL;
      }
    }

    $html .= '  </head>' . PHP_EOL;
    $attributes = ($bodyAttributes != '') ? ' ' . $bodyAttributes : '';
    $html .= '  <body' . $attributes .'>' . PHP_EOL;
    $html .= '  ' . $content . PHP_EOL;
    $html .= '  </body>' . PHP_EOL;
    $html .= '</html>' . PHP_EOL;
    $newFolder = sprintf('%s%s/', FM_WIDGETS_DIR, $key);
    key_file::createFolder($newFolder);
    $newFile = $newFolder . 'index.html';
    file_put_contents($newFile, $html); 
  }

  /*------------*/
  public static function createSoon() {
    $copy = fm_strOption('widgets_coming_soon');
    $fontsize = fm_intOption('widget_comingsoon_font');

    $htmlContent = sprintf('<h1 style="font-size:%dpx;">%s</h1>', $fontsize, $copy);
    static::saveWidget('soon', $htmlContent);
  }

  /*------------*/
  public static function createTop() {
    $message = fm_strOption('widgets_top_text');
    $fontsize = fm_intOption('widget_top_text_font');

    $htmlContent = sprintf('<div><h1 style="font-size:%dpx;">%s</h1></div>', $fontsize, $message);
    static::saveWidget('top', $htmlContent);
  }

  /*------------*/
  public static function createPlayer() {
    $whoIsPlaying = fm_strOption('widget_who', 'fullmoon');
    $imageField = 'widgets_tag_' . $whoIsPlaying;
    $imageID = fm_intOption($imageField);
    if ($imageID > 0) {
      $image = imageAsBase64($imageID);
    }

    $htmlContent = '<div><img src="' . $image . '"></div>' . PHP_EOL;
    static::saveWidget('player', $htmlContent);
  }

  /*------------*/
  public static function createSide() {
    $messageType = fm_strOption('field_widget_side', 'letsplay');
    $fontSize = 80;
    $message = "Let's Play With";
    switch ($messageType) {
      case 'walkthrough' : 
        $message = 'Walkthroughs With'; 
        $fontSize = 75;
        break;
      case 'survival' : 
        $message = 'Survival Mode With'; 
        $fontSize = 70;
        break;
      case 'custom' : 
        $message =  fm_strOption('widget_side_custom'); 
        $fontSize =  fm_intOption('widget_side_custom_font'); 
        break;
    }

    $htmlContent = sprintf('<div><h1 style="font-size:%dpx;">%s</h1></div>', $fontSize, $message);
    static::saveWidget('side', $htmlContent);
  }

  /*------------*/
  public static function createSubscribe() {
    $image = '';
    $subscribeID = fm_intOption('widgets_subscribe');
    if ($subscribeID > 0) {
      $image = imageAsBase64($subscribeID);
    }

    $htmlContent = '<div class="subscribe-holder">' . PHP_EOL;
    $htmlContent .= '<img class="subscribe-image" alt="subscribe" src="' . $image . '">' . PHP_EOL;
    $htmlContent .= '</div>' . PHP_EOL;
    static::saveWidget('subscribe', $htmlContent, true, 'onload="subscribeLoad();"');
  }

   /*------------*/
   public static function createSpoilers() {
    $image = '';
    $spoilersID = fm_intOption('widgets_spoilers');
    if ($spoilersID > 0) {
      $image = imageAsBase64($spoilersID);
    }

    $htmlContent = '<img class="spoiler-image" alt="spoilers" src="' . $image . '">' . PHP_EOL;
    static::saveWidget('spoilers', $htmlContent, true, 'onload="spoilersLoad();"');
  }

  /*------------*/
}
?>