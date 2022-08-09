<?php

/*------*/
function fm_strOption($key, $dft = '') {
  $option = get_field($key, 'options');
  if (($option != null) && ($option !== false) && ($option != '')) {
    return $option;
  }
  return $dft;
}

/*------*/
function fm_intOption($key, $dft = 0) {
  return intval(fm_strOption($key, strval($dft)));
}

/*------*/
function imageAsBase64($id) {
  $path = wp_get_original_image_path($id);
  if ($path !== false) {
    $image = file_get_contents($path);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type  = $finfo->buffer($image);
    return "data:" . $type . ";charset=utf-8;base64," .base64_encode($image);
  }
  return '';
}
?>