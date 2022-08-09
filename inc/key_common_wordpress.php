<?php
/*----------------------------*/
/*--KEY_WPPLUGIN--------------*/
/*----------------------------*/
if (!class_exists('key_wpplugin')) {
  define('KEY_WPPLUGIN_VERSIONKEY', 'key_version_');

  //define('KEY_COMMON_WPPLUGIN_URL', 'http://dev.test.com/wp-content/themes/updateservice/pluginhook.php?hash=%s&slug=%s');
  define('KEY_COMMON_WPPLUGIN_URL', 'https://repo.keydigital.dev/wp-content/themes/updateservice/pluginhook.php?hash=%s&slug=%s');
  //define('KEY_COMMON_WPKDK_URL',    'http://dev.test.com/wp-content/themes/updateservice/kdkhook.php?hash=%s&slug=%s');
  define('KEY_COMMON_WPKDK_URL',    'https://repo.keydigital.dev/wp-content/themes/updateservice/kdkhook.php?hash=%s&slug=%s');

  class key_wpplugin {
    public static $VERSION = 36;

    public  $apiKey     = '';
    public  $file       = '';
    public  $slug       = '';
    public  $directory  = '';
    public  $url        = '';
    public  $version    = '';
    private $textDomain = '';
    private $authorName = '';
    private $authorWeb  = '';
    public $kdk        = null;

    /*----------------------*/
    public function __construct($pluginFile, $apiKey) {
      if(!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
      }
      $this->apiKey = $apiKey;
      $pluginData = get_plugin_data($pluginFile);
      $this->file = plugin_basename(trim($pluginFile));
      $this->directory = plugin_dir_path($pluginFile);
      $this->url = plugin_dir_url($pluginFile);
      $this->slug = dirname($this->file);
      $this->version    = $pluginData['Version'];
      $this->textDomain = $pluginData['TextDomain'];
      $this->authorName = $pluginData['AuthorName'];
      $this->authorWeb  = $pluginData['AuthorURI'];
      $this->generateKDK();

      add_filter('plugins_api',                    array($this, 'getInfo'),           20, 3);
      add_action("after_plugin_row_{$this->file}", array($this, 'afterPluginRow'),    10, 3);
      add_filter('plugin_action_links',            array($this, 'pluginActionLinks'), 10, 3);
      add_filter('site_transient_update_plugins',  array($this, 'pushUpdate'));
      add_filter('transient_update_plugins',       array($this, 'pushUpdate'));
      add_action('init',                           array($this, 'checkVersionAndInit'));

      key_wpdashboard::enqueueHeader();
    }

    /*----------------------*/
    public function regenerateKDK() {
      $newFromRemote = $this->generateKDK(array('remote'));
      if (!$newFromRemote) {
        $this->generateKDK(array('cache', 'db'));
      }
      return $newFromRemote;
    }

    /*----------------------*/
    private function generateKDK($sources = array('cache', 'db', 'remote')) {
      if ((in_array('cache', $sources)) && ($this->kdkFromCache())) {
        return true;
      }

      if ((in_array('db', $sources)) && ($this->kdkFromDB())) {
        $this->kdkToCache();
        return true;
      }

      if ((in_array('remote', $sources)) && ($this->kdkFromRemote())) {
        $this->kdkToCache();
        $this->kdkToDB();
        return true;
      }

      return false;
    }

    /*----------------------*/
    private function kdkClearLocal() {
      $this->kdkClearCache();
      $this->kdkClearDB();
    }

    /*----------------------*/
    private function kdkFromCache(){
      $rawKDK = '';
      if (file_exists($this->kdkFile())) {
        $raw = file_get_contents($this->kdkFile());
        $rawKDK = ($raw === false) ? '' : strval($raw);
      }

      $this->kdk = new key_wpkdk($rawKDK, $this->slug);
      return $this->kdk->isValid();
    }

    /*----------------------*/
    private function kdkToCache(){
      $this->kdkClearCache();
      $rawKDK = $this->kdk->raw;
      if ($rawKDK != ''){
        file_put_contents($this->kdkFile(), $rawKDK);
      }
    }

    /*----------------------*/
    private function kdkClearCache() {
      if (file_exists($this->kdkFile())) {
        @unlink($this->kdkFile());
      }
    }

    /*----------------------*/
    private function kdkFile() {
      return $this->directory . $this->slug . '.kdk';
    }

    /*----------------------*/
    private function kdkFromDB() {
      $rawKDK = get_option($this->kdkKey(), '');

      $this->kdk = new key_wpkdk($rawKDK, $this->slug);
      return $this->kdk->isValid();
    }

    /*----------------------*/
    private function kdkToDB() {
      $rawKDK = $this->kdk->raw;
      update_option($this->kdkKey(), $rawKDK);
    }

    /*----------------------*/
    private function kdkClearDB() {
      update_option($this->kdkKey(), '');
    }

    /*----------------------*/
    private function kdkKey() {
      return $this->slug . '-kdk';
    }

    /*----------------------*/
    private function kdkFromRemote() {
      $rawKDK = '';

      $url = sprintf(KEY_COMMON_WPKDK_URL, $this->apiKey, $this->slug);
      $remote = wp_remote_get($url, array('timeout' => 10));

      if (!is_wp_error($remote)) {
        $code = isset($remote['response']['code']) ? intval($remote['response']['code']) : -1;
        if (($code == 200) && isset($remote['body']) && ($remote['body'] != '')) {
          $rawKDK = $remote['body'];
        } else if ($code == 401) {
          $this->kdkClearLocal();
        }
      }

      $this->kdk = new key_wpkdk($rawKDK, $this->slug);
      return $this->kdk->isValid();
    }

    /*----------------------*/
    private function getRemoteData(){
      $remote = get_transient('key_upgrade_' . $this->slug);
      if(!$remote) {
        $url = sprintf(KEY_COMMON_WPPLUGIN_URL, $this->apiKey , $this->slug);
        $remote = wp_remote_get($url,
          array(
            'timeout' => 10,
            'headers' => array(
              'Accept' => 'application/json'
            )
          )
        );

        $error = '';
        if (!is_wp_error($remote)) {
          $code = isset($remote['response']['code']) ? intval($remote['response']['code']) : -1;
          if ($code == 200) {
            if (!isset($remote['body']) || empty($remote['body'])) {
              $error = 'Response is empty';
            }
          } else {
            switch($code) {
              case 400 : $error = '400 : Bad request - Missing information when trying to update'; break;
              case 401 : $error = '401 : Unauthorised - You are not authroised to receive updates for this plugin';
                         $this->kdkClearLocal();
                         break;
              case 404 : $error = '404 : Missing - The plugin file cannot be found on the server'; break;
              default  : $error = $code . ' : Unsure what has gone wrong'; break;
            }
          }
        } else {
          $error = 'Unable to download plugin information';
        }

        if ($error != '') {
          set_transient('key_upgrade_error_' . $this->slug, $error, 3600);
          return null;
        } else {
          set_transient('key_upgrade_' . $this->slug, $remote, 21600);
          return $remote;
        }
      }
      return $remote;
    }

    /*----------------------*/
    public function pushUpdate($transient) {
      if (empty($transient->checked)) {
        return $transient;
      }

      $hasError = get_transient('key_upgrade_error_' . $this->slug);
      if ($hasError != null) {
        return $transient;
      }

      $remote = $this->getRemoteData();
      if($remote) {
         $remote = json_decode($remote['body']);

        if($remote && version_compare($this->version, $remote->version, '<') && version_compare($remote->requires, get_bloginfo('version'), '<')) {
          $res = new stdClass();
          $res->slug        = $this->slug;
          $res->plugin      = $this->file ;
          $res->new_version = $remote->version;
          $res->tested      = $remote->tested;
          $res->package     = $remote->download_url;
          $res->url         = $remote->author_homepage;
          $transient->response[$res->plugin] = $res;
          //$transient->checked[$res->plugin] = $remote->version;
        }
      }
      return $transient;
    }

    /*----------------------*/
    public function getInfo($res, $action, $args) {
      if ($action !== 'plugin_information') {
        return false;
      }

      if($args->slug !== $this->slug) {
        return $res;
      }

      $hasError = get_transient('key_upgrade_error_' . $this->slug);
      if ($hasError != null) {
        return false;
      }

      $remote = $this->getRemoteData();
      if($remote) {
        $remote = json_decode( $remote['body'] );
        $res = new stdClass();
        $res->name           = $remote->name;
        $res->slug           = $this->slug;
        $res->version        = $remote->version;
        $res->tested         = $remote->tested;
        $res->requires       = $remote->requires;
        $res->author         = $remote->author;'<a href="' . $this->authorWeb . '">' . $this->authorName . '</a>';
        $res->author_profile = $this->authorWeb;
        $res->download_link  = $remote->download_url;
        $res->trunk          = $remote->download_url;
        $res->last_updated   = $remote->last_updated;
        $res->sections       = array(
          'description' => $remote->sections->description,
          'changelog' => $remote->sections->changelog
        );

        return $res;
      }

      return false;
    }

    /*----------------------*/
    public function clearCurrentVersion() {
      delete_option(KEY_WPPLUGIN_VERSIONKEY . $this->slug);
    }

    /*----------------------*/
    public function checkVersionAndInit() {      
      $oldVersion = get_option(KEY_WPPLUGIN_VERSIONKEY . $this->slug, '0.0.0');
      if (version_compare($oldVersion, $this->version, '!=')) {
        do_action('key_wpplugin_init_' . $this->slug, $oldVersion);
        update_option(KEY_WPPLUGIN_VERSIONKEY . $this->slug, $this->version);
        $this->clearUpdateData();
      }  else {
        do_action('key_wpplugin_init_' . $this->slug, '');
      }
    }

    /*----------------------*/
    public function afterPluginRow($plugin_file, $plugin_data, $status) {
      $pluginError = get_transient('key_upgrade_error_' . $this->slug);
      if (($pluginError != null) && ($pluginError != '')) {
      ?>
      <tr class="plugin-update-tr active">
        <td colspan="3" class="plugin-update colspanchange">
          <div class="update-message notice inline notice-error notice-alt">
            <p><?php echo($pluginError) ?></p>
          </div>
        </td>
      </tr>
      <?php
      }
    }

    /*-------------------------*/
    public function pluginActionLinks($links, $plugin_file, $plugin_data) {
      if ($plugin_data['TextDomain'] === $this->textDomain) {
        $links[] = sprintf('<a href="%s" class="thickbox" title="Details">View details</a>',
          self_admin_url('plugin-install.php?tab=plugin-information&amp;plugin=' . $this->slug . '&amp;TB_iframe=true&amp;width=600&amp;height=550')
        );
      }

      return $links;
    }

    /*-------------------------*/
    public function clearUpdateData() {
      delete_transient('key_upgrade_' . $this->slug);
      delete_transient('key_upgrade_error_' . $this->slug);
    }

    /*-------------------------*/
    public function echoPluginButton($params = array()) {
      $newParams = $params;
      if (isset($newParams['image'])) {
        $ext = isset($newParams['imageExt']) ? strtolower(trim($newParams['imageExt'], '.')) : 'svg';
        $newParams['imageUrl'] = sprintf('%simages/%s.%s?v=%s',
          $this->url,
          $newParams['image'],
          $ext,
          $this->version
        );
      }
      key_wpdashboard::echoDashboardButton($newParams);
    }

    /*-------------------------*/
    public function echoPluginButtons($buttons = array()) {
      if (count($buttons) > 0) {
        echo('<div class="key-dashboard-content key-dashboard-button-group">' . PHP_EOL);
        foreach($buttons as $button) {
          $this->echoPluginButton($button);
        }
        echo('</div>' . PHP_EOL);
      }
    }

    /*-------------------------*/
    public function echoPluginTitle($title){
      echo('<div class="key-dashboard-content key-img-text">' . PHP_EOL);
      echo('  <img class="key-logo" src="' . $this->url . 'images/' . $this->slug . '-logo.png?v=' . $this->version . '" />' . PHP_EOL);
      echo('  <div>' . PHP_EOL);
      echo('    <h1>' . $title . ' </h1>' . PHP_EOL);
      echo('    <p><strong>Version:</strong> ' . $this->version . '</p>' . PHP_EOL);
      echo('  </div>' . PHP_EOL);
      echo('</div>' . PHP_EOL);
    }

    /*-------------------------*/
    /*-LEGACY COMPATIBLITY-----*/
    /*-------------------------*/
    public static function echoCopyright(){
      key_wpdashboard::echoCopyright();
    }
    public static function echoTextArea($key, $title, $description, $label) {
      key_wpdashboard::echoTextArea($key, $title, $description, $label);
    }
    public static function echoInput($key, $title, $description, $label, $type = 'text') {
      key_wpdashboard::echoInput($key, $title, $description, $label, $type);
    }
    public static function echoCheckbox($key, $title, $description, $label) {
      key_wpdashboard::echoCheckbox($key, $title, $description, $label);
    }
    public static function echoSelectBox($key, $options) {
      key_wpdashboard::echoSelectBox($key, $options);
    }
    public static function echoImageSelect($key, $title, $description) {
      key_wpdashboard::echoImageSelect($key, $title, $description);
    }
    public static function echoSettingsFields() {
      key_wpdashboard::echoSettingsFields();
    }
    public static function echoSubmitButton($text = 'Save Changes') {
      key_wpdashboard::echoSubmitButton($text);
    }
    public static function echoMessage($content, $isError = false) {
      key_wpdashboard::echoMessage($content, $isError);
    }
    public static function didSettingsSubmit() {
      return key_wpdashboard::didSettingsSubmit();
    }

    /*-------------------------*/
  }
}

/*----------------------------*/
/*--KEY_WPKDK-----------------*/
/*----------------------------*/
if (!class_exists('key_wpkdk')) {

  class key_wpkdk {
    public $raw = '';
    public $slug = '';
    public $client = '';
    public $permissions = array();
    public $extras= array();

    /*-------------------------*/
    public function __construct($kdkString, $slug) {
      $this->raw = $kdkString;
      $this->slug = $slug;
      if ($kdkString != '') {
        $parts = explode('.', $kdkString);
        if (count($parts) == 3) {
          $x = base64_decode($parts[0]);
          $z = base64_decode($parts[2]);
          $values = json_decode(self::d($parts[1], $x, $z), true);
          $this->permissions = isset($values['permissions']) ? $values['permissions'] : array();
          $this->client = isset($values['client']) ? $values['client'] : '';
          foreach($values as $key => $value) {
            if (($key != 'client') && ($key != 'permissions')) {
              $this->extras[$key] = $value;
            }
          }
        }
      }
    }

    /*-------------------------*/
    public function isValid() {
      return ($this->client != '') && (count($this->permissions) > 0);
    }

    /*-------------------------*/
    public function printInfo() {
      $res = '';
      $res .= '<h3>KDK Information</h3>' . PHP_EOL;
      if ($this->isValid()) {
        $res .= '<p>';
        $res .= '<b>Client</b>: ' . $this->client . '<br/>';

        foreach($this->permissions as $permission) {
          $toPrint = 'Unknown (' . $permission . ')';
          $toPrint = apply_filters('key_wpkdk_permission_' . $this->slug, $toPrint, $permission);
          $res .= '<b>Permission</b>: ' . $toPrint . '<br/>';
        }

        foreach($this->extras as $key => $extra) {
          $toPrint = 'Unknown (' . $key . ')';
          $toPrint = apply_filters('key_wpkdk_extra_' . $this->slug, $toPrint, $key, $extra);
          $res .= '<b>Extra</b>: ' . $toPrint . '<br/>';
        }

        $res .= '</p>';
      } else {
        $res .= '<p style="color:red"><b>KDK Is missing or invalid... Is your API key correct?</p>';
      }
      echo($res);
    }

    /*-------------------------*/
    public function hasPermission($key) {
      return in_array($key, $this->permissions);
    }

    /*-------------------------*/
    public function getExtra($key) {
      return key_exists($key, $this->extras) ? $this->extras[$key] : false;
    }

    /*-------------------------*/
    public static function c($a, $xc, $zc) {
      $b = base64_encode($a);
      $z = substr(hash('sha256', $zc), 0, 16);
      $x = hash('sha256', $xc);
      $o = openssl_encrypt($b, "AES-256-CBC", $x, 0, $z);
      return base64_encode($o);
    }

    /*------------------*/
    public static function d($a, $xc, $zc) {
      $b = base64_decode($a);
      $z = substr(hash('sha256', $zc), 0, 16);
      $x = hash('sha256', $xc);
      $o = openssl_decrypt($b, "AES-256-CBC", $x, 0, $z);
      return base64_decode($o);
    }

    /*------------------*/
  }
}

/*----------------------------*/
/*--KEY_WPPOSTTYPE------------*/
/*----------------------------*/
if (!class_exists('key_wpposttype')) {

  /*-----------------*/
  define('KEY_WPPOSTYPE_CHANGE_POSTDELETE',    'post_delete');
  define('KEY_WPPOSTYPE_CHANGE_POSTTRASHED',   'post_trashed');
  define('KEY_WPPOSTYPE_CHANGE_POSTUNTRASHED', 'post_untrashed');
  define('KEY_WPPOSTYPE_CHANGE_POSTSAVE',      'post_save');
  define('KEY_WPPOSTYPE_CHANGE_TAXEDIT',       'tax_edit');
  define('KEY_WPPOSTYPE_CHANGE_TAXCREATE',     'tax_create');
  define('KEY_WPPOSTYPE_CHANGE_TAXDELETE',     'tax_delete');

  /*-----------------*/
  /*-----------------*/
  /*-----------------*/
  class key_wpposttype {
    private $id = '';

    /*-----------------*/
    public function __construct($id) {
      $this->id = $id;
    }

    /*-------------------------*/
    public function deletedPost($postId) {
      $this->triggerChange($postId, KEY_WPPOSTYPE_CHANGE_POSTDELETE);
    }

    /*-------------------------*/
    public function untrashedPost($postId) {
      $this->triggerChange($postId, KEY_WPPOSTYPE_CHANGE_POSTUNTRASHED);
    }

    /*-------------------------*/
    public function trashedPost($postId) {
      $this->triggerChange($postId, KEY_WPPOSTYPE_CHANGE_POSTTRASHED);
    }

    /*-------------------------*/
    public function savePost($postId, $post, $didUpdate) {
      $this->triggerChange($postId, KEY_WPPOSTYPE_CHANGE_POSTSAVE);
    }    

    /*-----------------*/
    public function savedTax($termId, $tt_id, $taxonomy, $update) {
      if ($taxonomy == $this->id) {
       $this->triggerTaxChange($termId, ($update ? KEY_WPPOSTYPE_CHANGE_TAXEDIT : KEY_WPPOSTYPE_CHANGE_TAXCREATE));
      }
    }   

    /*-----------------*/
    public function deleteTax($termId, $tt_id, $taxonomy, $deleted_term) {
      if ($taxonomy == $this->id) {
        $this->triggerTaxChange($termId, KEY_WPPOSTYPE_CHANGE_TAXDELETE);
      }
    }

    /*-------------------------*/
    private function triggerTaxChange($termId, $action) {
      $term = get_term($termId, $this->id);
      do_action('key_wpposttype_change', $this->id, $action, $term);
    }

    /*-------------------------*/
    private function triggerChange($postId, $action) {
      $post = get_post($postId);
      if ($post->post_type == $this->id) {
        do_action('key_wpposttype_change', $this->id, $action, $post);
      }
    }

    /*-------------------------*/
    public function transitionPost($new, $old, $post){
      if ($post->post_type == $this->id) {
        do_action('key_wpposttype_transition', $this->id, $post, $new, $old);
      }
    }

    /*-------------------------*/
    public static function attachHooksToPostTypes($postTypes = array(), $hooksPriority = 20) {
      foreach($postTypes as $postTypeKey) {
        $postType = new key_wpposttype($postTypeKey);
        add_action('delete_post',            array($postType, 'deletedPost'),    $hooksPriority);
        add_action('trashed_post',           array($postType, 'trashedPost'),    $hooksPriority);
        add_action('untrashed_post',         array($postType, 'untrashedPost'),  $hooksPriority);
        add_action('save_post',              array($postType, 'savePost'),       $hooksPriority, 3);
        add_action('transition_post_status', array($postType, 'transitionPost'), $hooksPriority, 3);
      }
    }

    /*-------------------------*/
    public static function addPostType($id, $singleName, $multipleName, $slug, $argsOverrides = array(), $hooksPriority = 20) {
      $labels = array(
        'name'               => $multipleName,
        'singular_name'      => $singleName,
        'menu_name'          => $multipleName,
        'name_admin_bar'     => $multipleName,
        'add_new'            => 'Add ' . $singleName,
        'add_new_item'       => 'Add New ' . $singleName,
        'new_item'           => 'New ' . $singleName,
        'edit_item'          => 'Edit ' . $singleName,
        'view_item'          => 'View ' . $singleName,
        'all_items'          => 'All ' . $multipleName,
        'search_items'       => 'Search ' . $multipleName,
        'parent_item_colon'  => 'Parent ' . $multipleName . ' :',
        'not_found'          => 'No ' . strtolower($multipleName) . ' found.',
        'not_found_in_trash' => 'No ' . strtolower($multipleName) . ' found in trash.'
      );

      $argumentsToOverride = $argsOverrides;
      if (isset($argumentsToOverride['labels'])) {
        $argumentsToOverride['labels'] = array_merge($labels, $argumentsToOverride['labels']);
      }

      $args = array_merge(array(
        'labels'             => $labels,
        'description'        => 'Key Post Type: ' . $singleName,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'menu_position' 	   => 24,
        'rewrite'			       => array('slug' => $slug, 'with_front' => false),
        'menu_icon' 		     => key_wpdashboard::keyDigitalWordpressMenuIcon(),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'supports'           => array('title', 'editor', 'revisions')
      ), $argumentsToOverride);

      $args = apply_filters('key_wpposttype_postype_args', $args, $id);
      register_post_type($id, $args);

      if (is_admin()) {
        $postType = new key_wpposttype($id);
        add_action('delete_post',            array($postType, 'deletedPost'),    $hooksPriority);
        add_action('trashed_post',           array($postType, 'trashedPost'),    $hooksPriority);
        add_action('untrashed_post',         array($postType, 'untrashedPost'),  $hooksPriority);
        add_action('save_post',              array($postType, 'savePost'),       $hooksPriority, 3);
        add_action('transition_post_status', array($postType, 'transitionPost'), $hooksPriority, 3);
      }
    }

    /*-------------------------*/
    public static function addTaxonomy($id, $singleName, $multipleName, $slug, $postTypes, $argsOverrides = array(), $hooksPriority = 20) {
      $toAttach = is_array($postTypes) ? $postTypes : array($postTypes);

      $labels = array(
        'name'              => $singleName,
        'singular_name'     => $singleName,
        'search_items'      => 'Search ' . $multipleName,
        'all_items'         => 'All ' . $multipleName,
        'parent_item'       => 'Parent ' . $singleName,
        'parent_item_colon' => 'Parent ' . $singleName . ':',
        'edit_item'         => 'Edit ' . $singleName,
        'update_item'       => 'Update ' . $singleName,
        'add_new_item'      => 'Add New ' . $singleName,
        'new_item_name'     => 'New ' . $singleName,
        'menu_name'         => $multipleName
      );

      $argumentsToOverride = $argsOverrides;
      if (isset($argumentsToOverride['labels'])) {
        $argumentsToOverride['labels'] = array_merge($labels, $argumentsToOverride['labels']);
      }

      $args = array_merge(array(
        'hierarchical'      => false,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => $slug, 'with_front' => false),
      ), $argumentsToOverride);

      $args = apply_filters('key_wpposttype_tax_args', $args, $id);
      register_taxonomy($id, $toAttach, $args);

      if (is_admin()){
        $termType = new key_wpposttype($id);
        add_action('saved_term',     array($termType, 'savedTax'),  $hooksPriority, 4);   
        add_action('delete_term',    array($termType, 'deleteTax'), $hooksPriority, 4);
      }
    }
  }
}

/*----------------------------*/
/*--KEY_WPDASHBOARD----------*/
/*----------------------------*/
if (!class_exists('key_wpdashboard')) {

  /*----------------------*/
  define('KEY_SETTINGSKEY', 'key-settings');

  /*----------------------*/
  class key_wpdashboard {

    /*----------------------*/
    private static $hasAddedHead = false;

    /*----------------------*/
    public static function echoCopyright(){
      echo('<p class="key-copyright"><strong>&copy; Key.Digital Agency Limited ' . date('Y') . '</strong></p>' . PHP_EOL);
    }

    /*-------------------------*/
    public static function echoTextArea($key, $title, $description, $label) {
      ?>
      <h3><?php echo($title) ?></h3>
      <p><?php echo($description) ?></p>
      <table>
        <tr valign="top">
          <th scope="row">
            <label for="<?php echo($key); ?>"><?php echo($label) ?></label>
          </th>
          <td>
            <textarea style="width:300px;height:100px;" id="<?php echo($key); ?>" name="<?php echo($key); ?>"><?php echo(get_option($key)); ?></textarea>
          </td>
        </tr>
      </table>
      <?php
    }

    /*-------------------------*/
    public static function echoInput($key, $title, $description, $label, $type = 'text') {
      ?>
      <h3><?php echo($title) ?></h3>
      <p><?php echo($description) ?></p>
      <table>
        <tr valign="top">
          <th scope="row">
            <label for="<?php echo($key); ?>"><?php echo($label) ?></label>
          </th>
          <td>
            <input type="<?php echo($type) ?>" style="width:300px;" id="<?php echo($key); ?>" name="<?php echo($key); ?>" value="<?php echo(get_option($key)); ?>" />
          </td>
        </tr>
      </table>
      <?php
    }

    /*-------------------------*/
    public static function echoCheckbox($key, $title, $description, $label) {
      $optionValue = strval(get_option($key));
      $checked = (($optionValue == '1') || ($optionValue == 'on') || ($optionValue == 'true')) ? ' checked="checked"' : '';
      ?>
      <h3><?php echo($title) ?></h3>
      <p><?php echo($description) ?></p>
      <table>
       <tr valign="top">
         <th scope="row">
           <label for="<?php echo($key); ?>"><?php echo($label) ?></label>
         </th>
         <td>
           <input id="<?php echo($key); ?>"<?php echo($checked) ?> type="checkbox" name="<?php echo($key); ?>" />
         </td>
       </tr>
     </table>
      <?php
    }

    /*-------------------------*/
    public static function echoSelectBox($key, $options){
      $currentValue = get_option($key);
      ?>
      <select id="<?php echo $key ?>" name="<?php echo $key ?>">
        <?php
          foreach($options as $time => $label) {
            ?>
            <option value="<?php echo $time?>" <?php echo $time == $currentValue ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php
          }
        ?>
      </select>
      <?php
    }

    /*-------------------------*/
    public static function echoImageSelect($key, $title, $description) {
      $imgID = intval(get_option($key, -1));
      $imgSrc = ($imgID < 0) ? false : wp_get_attachment_image_src($imgID, 'medium');
      $imgUrl = ($imgSrc == false) ? '' : $imgSrc[0];
      echo('<div id="image_' . $key . '" class="key-image-picker">' . PHP_EOL);
      echo('  <h3>' . $title .'</h3>' . PHP_EOL);
      echo('  <p>' . $description . '</p>' . PHP_EOL);
      echo('  <p class="noimage"' . (($imgUrl != '') ? ' style="display:none;"' : '') . '>No Image Selected</p>' . PHP_EOL);
      echo('  <div' . (($imgUrl == '') ? ' style="display:none"' : '') . '>' . (($imgUrl == '') ? '' : '<img src="' . $imgUrl . '" />') . '</div>' . PHP_EOL);
      echo('  <button onclick="key_selectImage(\'' . $key . '\'); return false;">Select Image</button>' . PHP_EOL);
      echo('  <button class="clear"' . (($imgUrl == '') ? ' style="display:none;"' : '') . ' onclick="key_clearImage(\'' . $key . '\'); return false;">Clear Image</button>' . PHP_EOL);
      echo('  <input type="hidden" value="' . $imgID . '" id="' . $key . '" name="' . $key . '" />' . PHP_EOL);
      echo('</div>' . PHP_EOL);
    }

    /*-------------------------*/
    public static function echoSettingsFormOpen($method = 'post') {
      echo('<form method="' . $method . '">' . PHP_EOL);
      self::echoSettingsFields();
    }

    /*-------------------------*/
    public static function echoSettingsFields() {
      echo('<input type="hidden" name="' . KEY_SETTINGSKEY . '" value="true" />' . PHP_EOL);
      wp_nonce_field(KEY_SETTINGSKEY, 'nonce_' . KEY_SETTINGSKEY);
    }

    /*-------------------------*/
    public static function echoSubmitButton($text = 'Save Changes') {
      submit_button($text);
    }

     /*-------------------------*/
     public static function echoSettingsFormClose($submitText = 'Save Changes') {
      self::echoSubmitButton($submitText);
      echo('</form>' . PHP_EOL);
    }

    /*-------------------------*/
    public static function echoMessage($content, $isError = false) {
      echo('<div class="key-notice' . ($isError ? ' key-error' : '') . '">' . $content . '</div>');
    }

    /*-------------------------*/
    public static function didSettingsSubmit() {
      $wasKeySettings = isset($_POST[KEY_SETTINGSKEY]) && strtolower($_POST[KEY_SETTINGSKEY]) == 'true';
      $wasNonceOK = isset($_POST['nonce_' . KEY_SETTINGSKEY]) && wp_verify_nonce($_POST['nonce_' . KEY_SETTINGSKEY], KEY_SETTINGSKEY);
      return $wasKeySettings && $wasNonceOK;
    }

    /*----------------------*/
    public static function echoDashboardButton($params = array()) {
      $item = array_merge(array(
        'title' => '',
        'description' => '',
        'imageUrl' => '',
        'page' => '',
        'slug' => '',
        'external' => '',
        'requires' => '',
        'capability' => 'edit_pages',
        'issuesText' => 'Issues:',
        'issues' => 0,
        'warningsText' => 'Warnings:',
        'warnings' => 0,
        'condition' => true
      ), $params);

      if (!$item['condition']) {
        return;
      }

      if (!current_user_can($item['capability'])) {
        return;
      }

      if (($item['requires'] != '') && !class_exists($item['requires'])) {
        return;
      }

      $url = '';
      $target = '';
      if ($item['external'] != '') {
        $url = $item['external'];
        $target = ' target="_blank"';
      } else {
        $slug = ($item['page'] != '') ? 'admin.php?page=' . $item['page'] : $item['slug'];
        if ($slug != '') {
          $url = admin_url($slug);
        }
      }
      if ($url == '') {
        return;
      }

      $issues = '';
      if ($item['warnings'] > 0) {
        $issues .= '<div class="issues"><strong>' . $item['warningsText'] . ' </strong> <span class="key-issue-counter warning">' . $item['warnings'] . '</span></div>' . PHP_EOL;
      }
      if ($item['issues'] > 0) {
        $issues .= '<div class="issues"><strong>' . $item['issuesText'] . ' </strong> <span class="key-issue-counter">' . $item['issues'] . '</span></div>' . PHP_EOL;
      }
      ?>
      <a class="key-dashboard-button" href="<?php echo($url) ?>"<?php echo($target) ?>>
        <div class="holder">
          <?php
          if ($item['imageUrl'] != '') {
            echo('<img src="' . $item['imageUrl'] . '" />' . PHP_EOL);
          }
          ?>
          <div>
            <h3><?php echo($item['title']) ?></h3>
            <p><?php echo($item['description']) ?></p>
            <?php echo($issues) ?>
          </div>
        </div>
      </a>
      <?php
    }

    /*----------------------*/
    public static function echoKeyToolsWidget() {
      if (class_exists('key_seo_settings') && (method_exists(key_seo_settings::class, 'renderWidgetForDashboard'))) {
        key_seo_settings::renderWidgetForDashboard();
      } else {
        self::echoMessage('Unable to echo Key.Tools widget on this dashboard. Please update your Key.Tools plugin to 1.4.9 or above.', true);
      }
    }

    /*----------------------*/
    public static function enqueueHeader() {
      if (!self::$hasAddedHead) {
        add_action('wp_head',               array(self::class, 'frontHead'));
        add_action('admin_head',            array(self::class, 'adminHead'));
        add_action('admin_enqueue_scripts', array(self::class, 'adminScripts'));
        self::$hasAddedHead = true;
      }
    }

    /*----------------------*/
    public static function frontHead() {
      $css = <<<CSS
      .key-issue-counter {
        display: inline-block;
        margin: 1px 0 0 2px;
        padding: 0 5px;
        min-width: 7px;
        height: 17px;
        border-radius: 11px;
        background-color: #ca4a1f;
        color: #fff;
        font-size: 9px;
        line-height: 17px;
        text-align: center;
      }
      #wpadminbar .key-issue-counter {
        display: inline;
        padding: 1px 7px 1px 6px!important;
        border-radius: 11px;
        color: #fff;
        background-color: #ca4a1f;
      }
      #wpadminbar .key-issue-counter.warning,
      .key-issue-counter.warning {
        background-color: #fcb214;
        color: #32373c;
      }
CSS;
      echo '<style>' . $css . '</style>';
    }

    /*----------------------*/
    public static function adminHead() {
      self::frontHead();

      $css = <<<CSS
        .key-notice {
          background: #fff;
          border-left: 4px solid #fff;
          -webkit-box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
          box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
          margin: 10px 0;
          padding: 10px 12px;
          border-left-color: #00a0d2;
          font-weight: bold;
        }
        .key-notice.key-error {
          border-left-color: #ca4a1f;
          color: #ca4a1f;
        }
        .key-image-picker h3 {
          margin-bottom: 0.5em;
        }
        .key-image-picker p {
          margin: 0;
        }
        .key-image-picker p.noimage {
          font-weight: bold;
          text-decoration: underline;
          margin-bottom: 0.5em;
        }
        .key-image-picker div {
          margin-top: 0.5em;
        }
        .key-image-picker div img {
          width: 200px;
          padding: 2px;
          border: 1px solid #0073aa;
        }
        .key-dashboard-content {
          margin: 20px;
        }
        .key-img-text {
          display:-webkit-box;
          display:-ms-flexbox;
          display: flex;
          -webkit-box-orient: vertical;
          -webkit-box-direction: normal;
          -ms-flex-direction: column;
          flex-direction: column;
          -webkit-box-align: center;
          -ms-flex-align: center;
          align-items: center;
          -webkit-box-pack: start;
          -ms-flex-pack: start;
          justify-content: flex-start;
        }
        .key-img-text img {
          width: 300px;
        }
        .key-img-text div {
          margin: 10px 20px;
          text-align: center;
        }
        @media all and (min-width: 850px) {
          .key-img-text {
            -webkit-box-orient: horizontal;
            -webkit-box-direction: normal;
            -ms-flex-direction: row;
            flex-direction: row;
            -webkit-box-align: start;
            -ms-flex-align: start;
            align-items: flex-start;
            -webkit-box-pack: start;
            -ms-flex-pack: start;
            justify-content: flex-start;
          }
          .key-img-text div {
          margin: 0 20px;
          text-align: left;
          }
        }
        .key-logo {
          display: block;
          margin: 0;
          width: 300px;
        }
        .key-copyright {
          margin: 0 20px;
        }
        .key-dashboard-footer {
          font-weight: bold;
        }
        .key-dashboard-footer img {
          width: 30px;
          height: 30px;
          margin: 0 5px 0 20px;
          display: inline-block;
        }
        .key-dashboard-button {
          display: inline-block;
          width: 300px;
          height: 150px;
          border: 1px solid #23282d;
          border-radius: 5px;
          padding: 10px;
          text-decoration: none;
          background: rgb(223, 228, 231);
          margin: 10px 10px 0 0;
          transition: all 250ms ease-in-out;
        }
        .key-dashboard-button:hover {
          background: #A5AAAE;
        }
        .key-dashboard-button .holder {
          position: relative;
          display:-webkit-box;
          display:-ms-flexbox;
          display:flex;
          -webkit-box-orient: horizontal;
          -webkit-box-direction: normal;
            -ms-flex-direction: row;
                flex-direction: row;
          -webkit-box-align: start;
            -ms-flex-align: start;
                align-items: flex-start;
          -webkit-box-pack: start;
            -ms-flex-pack: start;
                justify-content: flex-start;
        }
        .key-dashboard-button .holder .issues {
          color: #32373c;
        }
        .key-dashboard-button .holder div {
          -webkit-box-flex: 1;
            -ms-flex-positive: 1;
                flex-grow: 1;
        }
        .key-dashboard-button .holder div h3 {
          margin: 0 0 5px 0;
        }
        .key-dashboard-button:hover .holder div p {
          color: #ffffff;
          transition: all 250ms ease-in-out;
          -webkit-transition: all 250ms ease-in-out;
          -moz-transition: all 250ms ease-in-out;
          -o-transition: all 250ms ease-in-out;
          -ms-transition: all 250ms ease-in-out;
        }
        .key-dashboard-button .holder img {
          width: 60px;
          height: auto;
          margin-right: 10px;
        }
        .key-dashboard-button-group {
          display:-webkit-box;
          display:-ms-flexbox;
          display: flex;
          -webkit-box-pack: center;
          -ms-flex-pack: center;
          justify-content: center;
          -webkit-box-align: start;
          -ms-flex-align: start;
          align-items: flex-start;
          -webkit-box-orient: horizontal;
          -webkit-box-direction: normal;
          -ms-flex-direction: row;
          flex-direction: row;
          -ms-flex-wrap: wrap;
          flex-wrap: wrap;
        }
        @media all and (min-width: 850px) {
          .key-dashboard-button-group {
            -webkit-box-pack: start;
            -ms-flex-pack: start;
            justify-content: flex-start;
          }
        }
CSS;

        echo '<style>' . $css . '</style>';

        $script = <<<SCRIPT
          function keytheme_selectImage(id) {
            var fileFrame = wp.media.frames.file_frame = wp.media({
              multiple: false
            });
            fileFrame.on('select', function() {
              var imageObj = fileFrame.state().get('selection').first().toJSON();
              if (eval("typeof imageObj") != 'undefined') {
                var imgUrl = imageObj.url;
                if (imageObj.hasOwnProperty('sizes') && imageObj.sizes.hasOwnProperty('medium')) {
                  imgUrl = imageObj.sizes.medium.url;
                }
                jQuery('#image_' + id + ' .noimage').hide();
                jQuery('#image_' + id + ' div').html('<img src="' + imgUrl + '" />');
                jQuery('#image_' + id + ' div').show();
                jQuery('#image_' + id + ' .clear').show();
                jQuery('#image_' + id + ' input').val(imageObj.id);
              }
            });
            fileFrame.open();
          }
          function keytheme_clearImage(id) {
            jQuery('#image_' + id + ' .noimage').show();
            jQuery('#image_' + id + ' div').hide();
            jQuery('#image_' + id + ' div').html('');
            jQuery('#image_' + id + ' .clear').hide();
            jQuery('#image_' + id + ' input').val('');
          }
SCRIPT;
        echo '<script>' . $script . '</script>';
    }

    /*----------------------*/
    public static function adminScripts(){
      //Note this MUST be called during or
      //after the admin enqueue hook
      wp_enqueue_media();
    }

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

    /*----------------------*/

  }
}

/*----------------------------*/
/*--KEY_WPSITEMAP--------------*/
/*----------------------------*/
if (!class_exists('key_wpsitemap') && class_exists('WP_Sitemaps_Provider')) {

  /*----------------------------*/
  class key_wpsitemap extends WP_Sitemaps_Provider {
    public $postTypes = array();

    /*---------------------*/
    public function __construct($name, $queryArgs, $objectType = 'post') {
      $this->name        = $name;
      $this->queryArgs   = $queryArgs;
      $this->object_type = $objectType;
    }

    /*---------------------*/
    private function queryArgs(){
      return array_merge(array(
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'post_date',
        'order'          => 'DESC'
      ), $this->queryArgs);
    }

    /*--OVERRIDE-----------*/
    public function get_url_list($page_num, $post_type = '') {
      $query = new WP_Query($this->queryArgs());
      $urlList = array();

      foreach($query->posts as $post) {
        $sitemapEntry = array(
          'changefreq' => 'weekly',
          'priority' => 1.0,
          'loc' => get_permalink($post),
          'lastmod' => get_the_modified_time('Y-m-d H:i:s', $post)
        );

        $sitemapEntry = apply_filters('wp_sitemaps_posts_entry', $sitemapEntry, $post, $post_type);
        $urlList[] = $sitemapEntry;

        $extraEntries = array();
        $extraEntries = apply_filters('key_wpsitemap_extraentries_' . $this->name, $extraEntries, $post);
        foreach ($extraEntries as $entry) {
          $urlList[] = $entry;
        }
      }

      return $urlList;
    }

    /*--OVERRIDE-----------*/
    public function get_max_num_pages($post_type = '') {
      return 1;
    }

    /*---------------------*/
  }
}

/*----------------------------*/
/*--KEY_WPTHEME--------------*/
/*----------------------------*/
if (!class_exists('key_wptheme')) {

  define('KEY_WPTHEME_ADMINSTYLES_KEY', 'key-wptheme');

  define('KEY_WPTHEME_ENQUEUECONTEXT_STYLE',        'style');
  define('KEY_WPTHEME_ENQUEUECONTEXT_ADMINSTYLE',   'adminStyle');
  define('KEY_WPTHEME_ENQUEUECONTEXT_SCRIPT',       'script');
  define('KEY_WPTHEME_ENQUEUECONTEXT_ADMIN_SCRIPT', 'adminScript');

  define('KEY_WPTHEME_MENUITEM_DASHBOARD',       'index.php');
  define('KEY_WPTHEME_MENUITEM_SEPARATOR1',      'separator1');
  define('KEY_WPTHEME_MENUITEM_SEPARATOR2',      'separator2');
  define('KEY_WPTHEME_MENUITEM_SEPARATOR3',      'separator-last');
  define('KEY_WPTHEME_MENUITEM_PAGES',           'edit.php?post_type=page');
  define('KEY_WPTHEME_MENUITEM_POSTS',           'edit.php');
  define('KEY_WPTHEME_MENUITEM_COMMENTS',        'edit-comments.php');
  define('KEY_WPTHEME_MENUITEM_MEDIA',           'upload.php');
  define('KEY_WPTHEME_MENUITEM_MENUS',           'nav-menus.php');
  define('KEY_WPTHEME_MENUITEM_USERS',           'users.php');
  define('KEY_WPTHEME_MENUITEM_TOOLS',           'tools.php');
  define('KEY_WPTHEME_MENUITEM_APPEARANCE',      'themes.php');
  define('KEY_WPTHEME_MENUITEM_PLUGINS',         'plugins.php');
  define('KEY_WPTHEME_MENUITEM_SETTINGS',        'options-general.php');
  define('KEY_WPTHEME_MENUITEM_GRAVITYFORMS',    'gf_edit_forms');
  define('KEY_WPTHEME_MENUITEM_YOAST',           'wpseo_dashboard');
  define('KEY_WPTHEME_MENUITEM_WORDFENCE',       'Wordfence');
  define('KEY_WPTHEME_MENUITEM_ACF',             'edit.php?post_type=acf-field-group');
  define('KEY_WPTHEME_MENUITEM_KEYTOOLS',        'key-seo-tools');
  define('KEY_WPTHEME_MENUITEM_KEYFAQS',         'key-faq-api');
  define('KEY_WPTHEME_MENUITEM_KEYCAMPMANAGER',  'key-campmanager');
  define('KEY_WPTHEME_MENUITEM_KEYPROPHET',      'prophet-api');
  define('KEY_WPTHEME_MENUITEM_KEYHOLIDAYMAKER', 'holidaymaker');
  define('KEY_WPTHEME_MENUITEM_KEYGEMAPARK',     'key-gema');
  define('KEY_WPTHEME_MENUITEM_KEYRMS',          'key-rms');

  /*----------------------------*/
  class key_wptheme {
    public static $theme = null;

    public $name = '';
    public $version    = '';
    public $description = '';
    public $author = '';
    public $directory  = '';
    public $url        = '';
    public $themeLogo  = '';
    public $mapsKey = '';

    public $filesParams = array();
    public $initParams = array();
    public $footerParams = array();
    public $dashboardParams = array();
    public $loginLogoParams = array();
    public $adminStylesParams = array();
    public $enqueueParams = array();
    public $headerParams = array();
    public $menuOrderParams = array();
    public $siteMapParams = array();

    private $echoMenu = false;
    private $hasWooCommerce = false;
    public $addedHooks = array();

    /*----------------------*/
    private function __construct($params) {
      $this->hasWooCommerce = class_exists('WooCommerce');

      $newParams = array_merge(array(
        'files'            => array(),
        'init'             => array(),
        'header'           => array(),
        'mapsKey'          => '',
      ), $params);

      $theme = wp_get_theme();

      $this->name        = (($theme != null) && isset($theme->name)) ? $theme->name : 'Unknown Theme';
      $this->version     = (($theme != null) && isset($theme->version)) ? $theme->version : '0.0';
      $this->description = (($theme != null) && isset($theme->description)) ? $theme->description : '';
      $this->author      = (($theme != null) && isset($theme->author)) ? $theme->author : '';
      $this->directory   = get_template_directory() . '/';
      $this->url         = get_template_directory_uri() . '/';

      $this->setupFiles($newParams['files']);
      $this->mapsKey = $newParams['mapsKey'];
      if ($this->mapsKey != '') {
        $this->setupMapsKey();
      }

      $this->setupInit($newParams['init']);
      $this->setupHeaderParams($newParams['header']);
      if (isset($newParams['enqueue']) && is_array($newParams['enqueue'])) {
        $this->setupScriptAndStyleEnqueue($newParams['enqueue']);
      }
      if (isset($newParams['copyright']) && is_array($newParams['copyright'])) {
        $this->setupCopyrightFooter($newParams['copyright']);
      }
      if (isset($newParams['sitemaps']) && is_array($newParams['sitemaps'])) {
        $this->setupSitemaps($newParams['sitemaps']);
      }
      if (isset($newParams['dashboard']) && is_array($newParams['dashboard'])) {
        $this->setupKeyDashboard($newParams['dashboard']);
      }
      if (isset($newParams['loginLogo']) && is_array($newParams['loginLogo'])) {
        $this->setupLoginLogo($newParams['loginLogo']);
      }
      if (isset($newParams['menuOrder']) && is_array($newParams['menuOrder'])) {
        if (isset($newParams['menuDebug']) && $newParams['menuDebug']) {
          $this->echoMenu = true;
        }
        $this->setupMenuOrder($newParams['menuOrder']);
      }
      if (isset($newParams['adminStyle']) && is_array($newParams['adminStyle'])) {
        $this->setupAdminStyles($newParams['adminStyle']);
      }

      key_wpdashboard::enqueueHeader();
    }

    /*----------------------*/
    private function action($tag, $function, $priority = 10, $acceptedArgs = 1) {
      if (!in_array($tag, $this->addedHooks)) {
        $this->addedHooks[] = $tag;
        add_action($tag, $function, $priority, $acceptedArgs);
      }
    }

    /*----------------------*/
    private function filter($tag, $function, $priority = 10, $acceptedArgs = 1) {
      if (!in_array($tag, $this->addedHooks)) {
        $this->addedHooks[] = $tag;
        add_filter($tag, $function, $priority, $acceptedArgs);
      }
    }

    /*----------------------*/
    private function setupFiles($params) {
      $this->filesParams = array_merge(array(
        'image'            => 'images',
        'script'           => 'script',
        'style'            => 'style',
        'inc'              => 'inc',
        'classes'          => 'classes',
        'dynamic'          => 'dynamic',
        'logo'             => 'theme-logo.png',
        'includeFunctions' => array()
      ), $params);

      if (!isset($this->filesParams['font'])) {
        $this->filesParams['font'] = $this->filesParams['style'] . '/font';
      }

      define('KEY_THEME_DIR', $this->directory);
      define('KEY_THEME_URI', $this->url);
      $this->defineFolder('image',   $this->filesParams['image'],   false);
      $this->defineFolder('image',   $this->filesParams['image'],   true);
      $this->defineFolder('style',   $this->filesParams['style'],   false);
      $this->defineFolder('style',   $this->filesParams['style'],   true);
      $this->defineFolder('font',    $this->filesParams['font'],    false);
      $this->defineFolder('font',    $this->filesParams['font'],    true);
      $this->defineFolder('script',  $this->filesParams['script'],  false);
      $this->defineFolder('script',  $this->filesParams['script'],  true);
      $this->defineFolder('inc',     $this->filesParams['inc'],     true);
      $this->defineFolder('classes', $this->filesParams['classes'], true);
      $this->defineFolder('dynamic', $this->filesParams['dynamic'], true);

      $this->themeLogo = KEY_THEME_URI_IMAGE . $this->filesParams['logo'] . '?v=' . $this->version;

      foreach($this->filesParams['includeFunctions'] as $include) {
        $filePath = sprintf('%sfunctions-%s.php', KEY_THEME_DIR_INC, $include);
        if (file_exists($filePath)) {
          include_once($filePath);
        }
      }
    }

    /*----------------------*/
    private function defineFolder($name, $path, $isDir) {
      $key = sprintf('KEY_THEME_%s_%s',
        ($isDir ? 'DIR' : 'URI'),
        strtoupper($name)
      );

      if (!defined($key)) {
        define($key, ($isDir ? $this->directory : $this->url) . strtolower($path) . '/');
      }
    }

    /*----------------------*/
    public const KEY_THEME_DEFAULT_REMOVEHEADER = array(
      'rsd_link' => true,                           //Really Simply Discovery LInk
      'wp_generator' => true,                       //Generator Link
      'wlwmanifest_link' => true,                   //Windows Live writer manifest
      'feed_links' => array('priority' => 2),       //Feed links
      'feed_links_extra' => array('priority' => 3), //Feed links
      'wp_shortlink_wp_head' => true,               //Short link
      'wp_shortlink_header' => true,                //Short link
      'adjacent_posts_rel_link_wp_head' => true,    //Relational adjacent links
      'rest_output_link_wp_head' => true,           //Rest API link
      'rest_output_link_header' => array(           //Rest API link
        'priority' => 11, 
        'action' => 'template_redirect'
      ), 
      'wp_oembed_add_discovery_links' => true,     //oEmbed discovery 
      'wp_resource_hints' => false,                //Prefetches
      'rel_canonical' => false,                    //Canonical Link  
      'wp_site_icon' => array(                     // Favicon
        'remove' => false, 
        'priority' => 99
      )  
    );

    /*----------------------*/
    private function setupHeaderParams($params) {
      $this->headerParams = array_merge(array(
        'remove' => array(),
        'meta' => array(),
        'charset' => true,
        'viewport' => "initial-scale=1.0, width=device-width",
        'formatDetection' => "telephone=no"
      ), $params);

      $this->headerParams['remove'] = array_merge(static::KEY_THEME_DEFAULT_REMOVEHEADER, $this->headerParams['remove']);

      if ($this->headerParams['charset'] !== false) {
        $charset = is_string($this->headerParams['charset']) ? $this->headerParams['charset'] : get_bloginfo('charset');
        if ($charset != '') {
          $this->headerParams['meta']['charset'] = array('name' => '', 'charset' => $charset);
        }
      }

      if (($this->headerParams['viewport'] !== false) && ($this->headerParams['viewport'] != '')) {
        $this->headerParams['meta']['viewport'] = $this->headerParams['viewport'];
      }

      if (($this->headerParams['formatDetection'] !== false) && ($this->headerParams['formatDetection'] != '')) {
        $this->headerParams['meta']['format-detection'] = $this->headerParams['formatDetection'];
      }

      if (count($this->headerParams['meta']) > 0) {
        $this->action('wp_head', array($this, 'frontHead'), 5);
      }
    }

    /*----------------------*/
    private function renderMetaTags() {
      foreach($this->headerParams['meta'] as $name => $value) {
        $metaInfo = array_merge(array(
          'name' => $name
        ), is_array($value) ? $value : array('content' => $value));
        $tagAttributes = '';
        foreach($metaInfo as $attKey => $attVal) {
          if ($attVal != '') {
            $tagAttributes .= sprintf(' %s="%s"', $attKey, $attVal);
          }
        }
        echo(sprintf('<meta %s>' . PHP_EOL, trim($tagAttributes)));

      }
    }

    /*----------------------*/
    private function setupDefaultInitParams($params) {
      $this->initParams = array_merge(array(
        'titleTag' => true,
        'disableEmoji' => true,
        'disableAutoUpdate' => true,
        'removePluginAds' => true,
        'disableGutenberg' => true,
        'ignoreAttachmentPermalinks' => false,
        'imageOptions' => array(),
        'hide' => array(),
        'addMenusToAdmin' => false,
        'postSupport' => array(),
        'postRename' => array(),
        'postHooks' => array(),
        'postTypes' => array(),
        'taxonomies' => array(),
        //'excerptLength' => 0,
        //'excerptMore' => true,
        'themeSupport' => array(),
        'registerMenus' => array(),
        'woocommerce' => array(),
        'acf' => array(),
        'mail' => array()
      ), $params);      

      if ($this->initParams['addMenusToAdmin'] !== false) {
        $this->initParams['addMenusToAdmin'] = array_merge(array(
          'capability' => 'edit_pages',
          'position' => 81,
          'icon' => 'dashicons-list-view',
          'doNotHideThemeingFor' => array('administrator')
        ), (is_array($this->initParams['addMenusToAdmin']) ? $this->initParams['addMenusToAdmin'] : array()));
      }

      if ($this->hasWooCommerce) {
        $this->initParams['woocommerce'] = array_merge(array(
          'removeStyles' => false,
          'removeBreadcrumb' => false,
          'removeRelated' => false,
          'removeProductCount' => true,
          'removeListingCount' => false,
          'removeProductMeta' => false,
          'allowProductTags' => true,
          'removeProductTabs' => array(),
          'productImageExtras' => array(),
          'noProductImage' => 'noproduct.png'
        ), $this->initParams['woocommerce']);
      }

      $this->initParams['imageOptions'] = array_merge(array(
        'sizes' => array(),
        'sizeNames' => array(),
        'removeInlineStyles' => true,
        'removeCaptionWidth' => true,
        'disableDefaultGalleryStyle' => true,
        'featuredImages' => true,
        'optimise' => array(),
        'mimes' => array(),
        'bigImageThreshold' => false,
        'infiniteScrolling' => true
        //'upscale' => false //TODO: This was 'croppedOnly' re add it in
      ), $this->initParams['imageOptions']);      

      //TODO: Fix
      // $upscaleOptions = array('cropped' => true, 'uncropped' => true);
      // if ($this->initParams['imageOptions']['upscale'] === false) {
      //   $upscaleOptions['cropped']   = false;
      //   $upscaleOptions['uncropped'] = false;
      // } else if (is_string($this->initParams['imageOptions']['upscale'])) {
      //   $upscaleOptions['cropped']   = $this->initParams['imageOptions']['upscale'] == 'croppedOnly';
      //   $upscaleOptions['uncropped'] = $this->initParams['imageOptions']['upscale'] == 'uncroppedOnly';
      // }
      // $this->initParams['imageOptions']['upscale'] = $upscaleOptions;

      $this->initParams['imageOptions']['optimise'] = array_merge(array(
        'enabled' => false,
        'jpegQuality' => 82,
        'pngQuality' => -1
      ), $this->initParams['imageOptions']['optimise']);

      $this->initParams['themeSupport'] = array_merge(array(
        'title-tag' => $this->initParams['titleTag'],
        'post-thumbnails' => $this->initParams['imageOptions']['featuredImages'],
        'responsive-embeds' => true,
        'html5' => array('comment-list', 'comment-form', 'search-form', 'gallery', 'caption', 'style', 'script')
      ), $this->initParams['themeSupport']);

      if (isset($this->initParams['postSupport']['disableEditorForNonAdmins'])) {
        if (!current_user_can('manage_options')) {
          $postsTypesToRemove = is_array($this->initParams['postSupport']['disableEditorForNonAdmins']) ? $this->initParams['postSupport']['disableEditorForNonAdmins'] : array('post', 'page');
          if (!isset($this->initParams['postSupport']['remove'])) {
            $this->initParams['postSupport']['remove'] = array();
          }
          if (!isset($this->initParams['postSupport']['remove']['editor'])) {
            $this->initParams['postSupport']['remove']['editor'] = array();
          }

          foreach($postsTypesToRemove as $postType) {
            if (!in_array($postType, $this->initParams['postSupport']['remove']['editor'])) {
              $this->initParams['postSupport']['remove']['editor'][] = $postType;
            }
          }
        }
        unset($this->initParams['postSupport']['disableEditorForNonAdmins']);
      }
    }

    /*----------------------*/
    private function addPostTypeOrTax($key, $params, $isTax) {
      $registerParams = array_merge(array(
        'single' => '',
        'multiple' => '',
        'slug' => '',
        'postTypes' => array(),
        'args' => array(),
        'hooksPriority' => 20
      ), $params);

      if ($isTax) {
        key_wpposttype::addTaxonomy(
          $key,
          $registerParams['single'],
          $registerParams['multiple'],
          $registerParams['slug'],
          $registerParams['postTypes'],
          $registerParams['args'],
          $registerParams['hooksPriority']
        );
      } else {
        key_wpposttype::addPostType(
          $key,
          $registerParams['single'],
          $registerParams['multiple'],
          $registerParams['slug'],
          $registerParams['args'],
          $registerParams['hooksPriority']
        );
      }
    }

    /*----------------------*/
    private function setupInit($params) {
      $this->action('init', array($this, 'init'));
      //This doesn't use the $this->action as we want two different inits
      add_action('init', array($this, 'initLate'), 999);

      $this->setupDefaultInitParams($params);

      if ($this->initParams['imageOptions']['infiniteScrolling']) {
        $this->filter('media_library_infinite_scrolling', '__return_true', 999);
      }

      if ($this->initParams['imageOptions']['disableDefaultGalleryStyle']) {
        $this->filter('use_default_gallery_style', '__return_false', 999);
      }

      $this->filter('big_image_size_threshold', array($this, 'bigImageThreshold'), 999, 4);               

      foreach($this->initParams['themeSupport'] as $feature => $setting) {
        if (is_array($setting)) {
          add_theme_support($feature, $setting);
        } else if ($setting) {
          add_theme_support($feature);
        } else {
          remove_theme_support($feature);
        }
      }

      if ($this->initParams['disableAutoUpdate']) {
        $this->filter('automatic_updater_disabled', '__return_true', 9999);
      }

      if ($this->initParams['disableEmoji']) {
        $this->action('after_setup_theme', array($this, 'afterSetupTheme'), 99);
      }

      if ($this->initParams['removePluginAds'] && is_admin()) {
        $this->action('admin_head', array($this, 'adminHead'), 10);
      }

      if ($this->initParams['disableGutenberg'] && is_admin()) {
        $this->filter('use_block_editor_for_post_type', '__return_false', 100);
      }

      if ($this->initParams['ignoreAttachmentPermalinks']) {
        $this->filter('attachment_link', array($this, 'attachmentLink'), 20, 2);
      }

      if ($this->initParams['addMenusToAdmin'] !== false) {
        $this->action('admin_menu', array($this, 'adminMenu'), 99);
      }

      if (count($this->initParams['hide']) > 0) {
        $this->action('wp_enqueue_scripts', array($this, 'enqueueScriptsAndStyles'), 999);
        $this->action('admin_menu', array($this, 'adminMenu'), 99);
        $this->filter('manage_edit-page_columns', array($this, 'postAndPageColumns'));
        $this->filter('manage_edit-post_columns', array($this, 'postAndPageColumns'));
        $this->action('wp_before_admin_bar_render', array($this, 'beforeAdminBarRender'), 100);             
      }      

      //TODO: Reintroduce once tested
      // if (intval($this->initParams['excerptLength']) > 0) {
      //   $this->filter('excerpt_length', array($this, 'excerptLength'), 999);
      // }

      // if ($this->initParams['excerptMore'] !== false) {
      //   $this->filter('excerpt_more', array($this, 'excerptMore'), 999);
      // }

      if (count($this->initParams['postSupport']) > 0) {
        $this->action('wp_loaded', array($this, 'wpLoaded'));        
      }

      if (count($this->initParams['mail']) > 0) {
        $this->filter('wp_mail_from',         array($this, 'mailFrom'),        999);
        $this->filter('wp_mail_from_name',    array($this, 'mailFromName'),    999);
        $this->filter('wp_mail_content_type', array($this, 'mailContentType'), 999);
      }
     
      if ($this->hasWooCommerce) {
        add_theme_support('woocommerce');

        $gallaryFeatures = array('zoom', 'lightbox', 'slider');
        $wooCommerceToEnable = is_array($this->initParams['woocommerce']['productImageExtras']) ? $this->initParams['woocommerce']['productImageExtras'] : (($this->initParams['woocommerce']['productImageExtras'] === true) ? $gallaryFeatures : array());
        foreach($gallaryFeatures as $feature) {
          if (in_array($feature, $wooCommerceToEnable)) {
            add_theme_support('wc-product-gallery-' . $feature);
          } else {
            remove_theme_support('wc-product-gallery-' . $feature);
          }
        }

        if (!$this->initParams['woocommerce']['allowProductTags']) {
          $this->filter('manage_edit-product_columns', array($this, 'productColumns'));
        }        
      }
    }

    /*----------------------*/
    public function initLate() {
      foreach ($this->initParams['postRename'] as $postKey => $renameParams) {
        $newRenameParams = is_array($renameParams) ? $renameParams : array('single' => strval($renameParams), 'multiple' => strval($renameParams) . 's');
        $newRenameParams = array_merge(array('single' => '', 'multiple' => '', 'icon' => ''), $newRenameParams);
        
        $singular = $newRenameParams['single'];
        $multiple = $newRenameParams['multiple'];
        $multipleLower = strtolower($multiple);

        $postType = get_post_type_object($postKey);
        if ($postType != null) {
          $postType->labels->name = $multiple;
          $postType->labels->singular_name = $singular;
          $postType->labels->add_new = 'Add ' . $singular;
          $postType->labels->add_new_item = 'Add ' . $singular;
          $postType->labels->edit_item = 'Edit ' . $singular;
          $postType->labels->new_item = $singular;
          $postType->labels->view_item = 'View ' . $singular;
          $postType->labels->search_items = 'Search ' . $multiple;
          $postType->labels->not_found = 'No ' . $multipleLower . ' found';
          $postType->labels->not_found_in_trash = 'No ' . $multipleLower . ' found in Trash';
          $postType->labels->all_items = 'All ' . $multiple;
          $postType->labels->menu_name = $multiple;
          $postType->labels->name_admin_bar = $multiple;
        }
        
        if ($newRenameParams['icon'] != '') {
          $postType->menu_icon = $newRenameParams['icon'];
        }
      }

      foreach($this->headerParams['remove'] as $actionHook => $options) {
        $params = array_merge(array(
          'priority' => 10, 
          'action' => 'wp_head',
          'remove' => true
        ), is_array($options) ? $options : array('remove' => $options));

        if ($params['remove']) {
          remove_action($params['action'], $actionHook, $params['priority']);
        }
      }

      if ($this->hasWooCommerce && !$this->initParams['woocommerce']['allowProductTags']) {
        unregister_taxonomy('product_tag');
      }
    }

    /*----------------------*/
    public function init() {
      foreach($this->initParams['postTypes'] as $key => $params) {
        $this->addPostTypeOrTax($key, $params, false);
      }
      foreach($this->initParams['taxonomies'] as $key => $params) {
        $this->addPostTypeOrTax($key, $params, true);
      }
      if (count($this->initParams['postHooks']) > 0) {
        key_wpposttype::attachHooksToPostTypes($this->initParams['postHooks']);
      }

      if (count($this->initParams['imageOptions']['mimes']) > 0) {
        $this->action('upload_mimes', array($this, 'uploadMimes'));
      }

      //TODO: Back in one day
      // if ($this->initParams['imageOptions']['upscale']['cropped'] || $this->initParams['imageOptions']['upscale']['uncropped']) {
      //   $this->filter('image_resize_dimensions', array($this, 'imageResizeDimensions'), 10, 6);
      // }

      if (count($this->initParams['imageOptions']['sizes']) > 0) {
        foreach ($this->initParams['imageOptions']['sizes'] as $key => $size) {
          $sizeDef = array_merge(array(
            'w' => 100,
            'h' => 0,
            'crop' => false,
            'name' => ''
          ), $size);

          add_image_size($key, $sizeDef['w'], $sizeDef['h'], $sizeDef['crop']);
          if ($sizeDef['name'] != '') {
            $this->initParams['imageOptions']['sizeNames'][$key] = $sizeDef['name'];
          }
        }
      }

      if (count($this->initParams['imageOptions']['sizeNames']) > 0) {
        $this->filter('image_size_names_choose', array($this, 'imageSizeNamesChoose'), 999);
      }

      if ($this->initParams['imageOptions']['removeInlineStyles']) {
        $this->filter('post_thumbnail_html', array($this, 'postThumbnailHTML'), 10, 3);
      }

      if ($this->initParams['imageOptions']['removeCaptionWidth']) {
        $this->filter('img_caption_shortcode_width', '__return_false', 10, 3);
      }

      if ($this->initParams['imageOptions']['optimise']['enabled']) {
        $this->filter('wp_handle_upload', array($this, 'wpHandleUpload'));
      }

      if ($this->initParams['registerMenus'] === true) {
        register_nav_menus();
      } else if (is_array($this->initParams['registerMenus']) && (count($this->initParams['registerMenus']) > 0)) {
        register_nav_menus($this->initParams['registerMenus']);
      }      

      if ($this->hasWooCommerce) {
        add_filter('woocommerce_placeholder_img_src', function() {
          return KEY_THEME_URI_IMAGE . trim($this->initParams['woocommerce']['noProductImage'], '/');
        }, 20);

        if ($this->initParams['woocommerce']['removeStyles']) {
          $this->filter('woocommerce_enqueue_styles', '__return_false');
          $this->action('wp_enqueue_scripts', array($this, 'enqueueScriptsAndStyles'), 999);
          $this->action('enqueue_block_assets', array($this, 'enqueueBlockAssets'));
        }

        if ($this->initParams['woocommerce']['removeBreadcrumb']) {
          remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20, 0);
        }

        if ($this->initParams['woocommerce']['removeRelated']) {
          remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        }

        if ($this->initParams['woocommerce']['removeProductCount']) {
          add_filter('woocommerce_subcategory_count_html',  function() {
            return;
          });
        }

        if ($this->initParams['woocommerce']['removeListingCount']) {
          remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20, 0);
        }        
        
        if ($this->initParams['woocommerce']['removeProductMeta']) {
          remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
        }
        
        if (count($this->initParams['woocommerce']['removeProductTabs']) > 0) {
          $this->filter('woocommerce_product_tabs', array($this, 'wooCommerceRemoveProductTabs'), 98);
        }
      }

      if (class_exists('acf_pro')) {
        $this->filter('acf-flexible-content-preview.images_path', array($this, 'acfFlexibleContentImagesPath'));
      }

      if (isset($this->siteMapParams['customSitemaps'])) {
        foreach($this->siteMapParams['customSitemaps'] as $siteMapKey => $siteMapArgs) {
          wp_register_sitemap_provider($siteMapKey, new key_wpsitemap($siteMapKey, $siteMapArgs));
        }
      }
    }

    /*----------------------*/
    public function acfFlexibleContentImagesPath($path) {
      $blocksPath = isset($this->initParams['acf']['blocks']) ? trim($this->initParams['acf']['blocks'], '/') : 'acf';
      return trim($this->filesParams['image'] . '/' . $blocksPath, '/');
    }

    /*----------------------*/
    public function wooCommerceRemoveProductTabs($tabs) {
      $newTabs = $tabs;
      foreach($this->initParams['woocommerce']['removeProductTabs'] as $tabKey) {
        unset($tabs[$tabKey]);
      }
      return $newTabs;
    }

    /*----------------------*/
    public function mailFromName($fromName) {
      if (isset($this->initParams['mail']['fromName']) && ($this->initParams['mail']['fromName'] != '')) {
        return $this->initParams['mail']['fromName'];
      }
      return $fromName;
    }

    /*----------------------*/
    public function mailFrom($fromEmail) {
      if (isset($this->initParams['mail']['fromEmail']) && ($this->initParams['mail']['fromEmail'] != '')) {
        return $this->initParams['mail']['fromEmail'];
      }
      return $fromEmail;
    }

    /*----------------------*/
    public function mailContentType($contentType) {
      if (isset($this->initParams['mail']['contentType']) && ($this->initParams['mail']['contentType'] != '')) {
        return $this->initParams['mail']['contentType'];
      }
      return $contentType;
    }

    //TODO: Check again when have more time
    // /*----------------------*/
    // public function excerptLength($length) {
    //   return intval($this->initParams['excerptLength']);
    // }

    // /*----------------------*/
    // public function excerptMore($suffix) {
    //   if ($this->initParams['excerptMore'] === true) {
    //     return '...';
    //   } 
    //   return strval($this->initParams['excerptMore']);
    // }

    /*----------------------*/
    public function attachmentLink($link, $postID) {
      if ($this->initParams['ignoreAttachmentPermalinks']) {
        return wp_get_attachment_url($postID);
      }

      return $link;
    }

    /*----------------------*/
    public function postThumbnailHTML($html, $postId, $postImageId){
      $html = preg_replace( '/(width|height)=\"\d*\"\s/', "", $html);
      return $html;
    }

    /*----------------------*/
    public function uploadMimes($fileTypes) {
      return array_merge($fileTypes, $this->initParams['imageOptions']['mimes']);
    }

    /*----------------------*/
    public function imageSizeNamesChoose($sizes) {
      $newSizes = $sizes;
      foreach ($this->initParams['imageOptions']['sizeNames'] as $key => $name){
        if (($name === false) && isset($newSizes[$key])) {
          unset($newSizes[$key]);
        } else {
          $newSizes[$key] = strval($name);
        }
      }
      return $newSizes;
    }

    /*----------------------*/
    public function bigImageThreshold($threshold, $imagesize, $file, $attachment_id) {
      if ($this->initParams['imageOptions']['bigImageThreshold'] !== true) {
        return $this->initParams['imageOptions']['bigImageThreshold'];
      }
      return $threshold;      
    }

    //TODO: Back in one day
    /*----------------------
    public function imageResizeDimensions($default, $orig_w, $orig_h, $new_w, $new_h, $crop){
      if (($orig_w != 0) && ($orig_h != 0) && ($new_w != 0) && ($new_h != 0)) {
        if ($crop && $this->initParams['imageOptions']['upscale']['cropped']) {
          $sizeRatio = max(($new_w / $orig_w), ($new_h / $new_h));
          if ($sizeRatio != 0) {
            $crop_w = round($new_w / $sizeRatio);
            $crop_h = round($new_h / $sizeRatio);
  
            $s_x = floor(($orig_w - $crop_w) / 2);
            $s_y = floor(($orig_h - $crop_h) / 2);    
            return array(0, 0, (int)$s_x, (int)$s_y, (int)$new_w, (int)$new_h, (int)$crop_w, (int)$crop_h);        
          }     
        } else if (!$crop && $this->initParams['imageOptions']['upscale']['uncropped']) {
          $crop_w = $orig_w;
          $crop_h = $orig_h;
 
          $s_x = 0;
          $s_y = 0;
          return array(0, 0, (int)$s_x, (int)$s_y, (int)$new_w, (int)$new_h, (int)$crop_w, (int)$crop_h);
        }
      }
      return $default; // let the WordPress default function handle this if other filters have not bee added
    }

    /*----------------------*/
    public function wpHandleUpload($data) {
      $filePath = $data['file'];
      $image = false;

      switch ($data['type']) {
        case 'image/jpeg':
          $image = imagecreatefromjpeg($filePath);
          if ($image !== false) {
            imagejpeg($image, $filePath, $imageOptions['optimise']['jpegQuality']);
            imagedestroy($image);
          }
          break;
        case 'image/png':
          $image = imagecreatefrompng($filePath);
          if ($image !== false) {
            imagepng($image, $filePath, $imageOptions['optimise']['pngQuality']);
            imagedestroy($image);
          }
          break;
      }
      return $data;
    }

    /*----------------------*/
    public function afterSetupTheme() {
      if ($this->initParams['disableEmoji']) {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        $this->removeResourceHint('https://s.w.org/images/core/emoji/13.0.0/svg/', 'dns-prefetch');
      }
    }

    /*----------------------*/
    public static $resourceHintsToIgnore = array();
    public function removeResourceHint($url, $type) {
      $this->filter('wp_resource_hints', array($this, 'wpResourceHints'), 10, 2);
      if (!key_exists($type, static::$resourceHintsToIgnore)) {
        static::$resourceHintsToIgnore[$type] = array();
      }
      static::$resourceHintsToIgnore[$type][] = $url;
    }

    /*----------------------*/
    public function wpResourceHints($urls, $type) {
      if (key_exists($type, static::$resourceHintsToIgnore)) {
        static::$resourceHintsToIgnore['working'] = static::$resourceHintsToIgnore[$type];
        return array_filter($urls, function($url) {
          return !in_array($url, static::$resourceHintsToIgnore['working']);
        });
      }
      return $urls;
    }

    /*----------------------*/
    private function setupMapsKey() {
      if (class_exists('acf_pro')) {
        add_filter('acf/settings/google_api_key', array($this, 'acfSettingsGoogleApiKey'));
      }
    }

    /*----------------------*/
    public function acfSettingsGoogleApiKey($key) {
      return $this->mapsKey;
    }

    /*----------------------*/
    private function setupAdminStyles($params) {
      $this->adminStylesParams = array_merge(array(
        'main' => '#1c2337',
        'priAccent' => '#db0b3b',
        'secAccent' => '#fde3e9',
        'applyToNewUsers' => true,
        'themeName' => $this->name,
        'fileName' => ''
      ), $params);

      $this->action('user_register', array($this, 'userRegister'));
      $this->action('admin_init',    array($this, 'adminInit'));
    }

    /*---------------*/
    public function adminStyleFileName() {
      return isset($this->adminStylesParams['fileName']) ? basename($this->adminStylesParams['fileName']) : '';
    }

    /*----------------------*/
    private function adminStylesExist() {
      $mainColour = isset($this->adminStylesParams['main']) ? $this->adminStylesParams['main'] : '#1c2337';
      $priAccent = isset($this->adminStylesParams['priAccent']) ? $this->adminStylesParams['priAccent'] : '#db0b3b';
      $secAccent = isset($this->adminStylesParams['secAccent']) ? $this->adminStylesParams['secAccent'] : '#fde3e9';

      $fileName = isset($this->adminStylesParams['fileName']) ? $this->adminStylesParams['fileName'] : '';
      if ($fileName == '') {
        $fileName = sprintf(
          '%s|%s|%s|%d',
          $mainColour,
          $priAccent,
          $secAccent,
          key_wpplugin::$VERSION
        );

        $fileName = str_replace(array('+', '/', '='), '',  base64_encode($fileName)) . '_key.min.css';
        $this->adminStylesParams['fileName'] = 'admin/' . $fileName;
      }

      $filePath = KEY_THEME_DIR_STYLE . $this->adminStylesParams['fileName'];
      if (file_exists($filePath)) {
        return true;
      }

      $styleSheet = self::keyAdminCssStyles();
      $styleSheet = str_replace(
        array('#MAINDARK#', '#PRIACCENT#', '#SECACCENT#'),
        array($mainColour, $priAccent, $secAccent),
        $styleSheet
      );

      //REVIEW REMOVE WHEN DEPENDENCY SYSTEM IS IN PLACE
      self::TEMPFUNCTION_FROM_KEYFILE_deleteFolder(KEY_THEME_DIR_STYLE . 'admin/');
      if (!file_exists(KEY_THEME_DIR_STYLE)) {
        mkdir(KEY_THEME_DIR_STYLE, 0777, true);
      }
      mkdir(KEY_THEME_DIR_STYLE . 'admin/', 0777, true);

      //REVIEW ADD BACK IN WHEN DEPENDENCY SYSTEM IS IN PLACE
      // key_file::deleteFolder($folderPath);
      // key_file::createFolder(KEY_THEME_DIR_STYLE);
      // key_file::createFolder($folderPath);

      file_put_contents($filePath, $styleSheet);
      return file_exists($filePath);
    }

    /*-----*/
    //REVIEW REMOVE WHEN DEPENDENCY SYSTEM IS IN PLACE
    public static function TEMPFUNCTION_FROM_KEYFILE_deleteFolder($dir) {
      if (!file_exists($dir)) {
        return false;
      }

      $files = array_diff(scandir($dir), array('.','..'));
      foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
          @self::deleteFolder($path);
          @rmdir($path);
        } else {
          @unlink($path);
        }
      }
      return rmdir($dir);
    }

    /*----------------------*/
    public function adminInit() {
      if ((count($this->adminStylesParams) > 0) && $this->adminStylesExist()) {
        wp_admin_css_color(
          KEY_WPTHEME_ADMINSTYLES_KEY,
          $this->adminStylesParams['themeName'],
          KEY_THEME_URI_STYLE . $this->adminStylesParams['fileName'],
          array(
            $this->adminStylesParams['main'],
            $this->adminStylesParams['priAccent'],
            $this->adminStylesParams['secAccent']
          ),
          array(
            'base' => '#f3f1f1',
            'focus' => $this->adminStylesParams['main'],
            'current' => $this->adminStylesParams['main']
          )
        );
      }
    }

    /*----------------------*/
    public function userRegister($userId) {
      if ($this->adminStylesParams['applyToNewUsers'] && ($this->adminStylesExist())) {
        wp_update_user(array(
          'ID'          => $userId,
          'admin_color' => KEY_WPTHEME_ADMINSTYLES_KEY
        ));
      }
    }

    /*----------------------*/
    private function setupSitemaps($params = array()) {
      add_filter('wp_sitemaps_enabled', '__return_true');

      $this->siteMapParams = array_merge(array(
        'ignoreTax' => array(),
        'ignorePost' => array(),
        'customSitemaps' => array()
      ), $params);

      if (isset($this->initParams['hide'])) {
        if (in_array('categories', $this->initParams['hide']) && !in_array('category', $this->siteMapParams['ignoreTax'])) {
          $this->siteMapParams['ignoreTax'][] = 'category';
        }
        if (in_array('tags', $this->initParams['hide']) && !in_array('post_tag', $this->siteMapParams['ignoreTax'])) {
          $this->siteMapParams['ignoreTax'][] = 'post_tag';
        }
      }

      if (count($this->siteMapParams['ignoreTax']) > 0) {
        add_filter('wp_sitemaps_taxonomies', function($taxonomies) {
          $newTaxs = $taxonomies;
          foreach($this->siteMapParams['ignoreTax'] as $tax) {
            unset($newTaxs[$tax]);
          }
          return $newTaxs;
        });
      }

      if (count($this->siteMapParams['ignorePost']) > 0) {
        add_filter('wp_sitemaps_post_types', function($postTypes) {
          $newPostTypes = $postTypes;
          foreach($this->siteMapParams['ignorePost'] as $postType) {
            unset($newPostTypes[$postType]);
          }
          return $newPostTypes;
        });
      }
    }

    /*----------------------*/
    private function addEnqueuedScriptToPreload($handle) {
      if ($GLOBALS['wp_scripts']->query($handle, 'enqueued')) {
        if (!isset($this->enqueueParams['preload']['script'])) {
          $this->enqueueParams['preload']['script'] = array();
        }

        $scriptDef = isset($GLOBALS['wp_scripts']->registered[$handle]) ? $GLOBALS['wp_scripts']->registered[$handle] : null;
        if ($scriptDef != null) {
          $this->enqueueParams['preload']['script'][$handle] = array(
            'href' => $GLOBALS['wp_scripts']->base_url . $scriptDef->src,
            'ver'  => $scriptDef->ver
          );
        }
      }
    }

    /*----------------------*/
    private function renderPreloads() {
      if (count($this->enqueueParams) > 0) {
        foreach($this->enqueueParams['corePreloads'] as $corePreload) {
          $this->addEnqueuedScriptToPreload($corePreload);
        }      

        if (isset($this->enqueueParams['preload']) && (count($this->enqueueParams['preload']) > 0)) {
          echo(PHP_EOL . '<!-- START : key_wptheme preloads -->' . PHP_EOL);
          foreach($this->enqueueParams['preload'] as $preloadType => $preloads) {
            foreach($preloads as $preloadKey => $preload) {
              $shouldAdd = true;
              $shouldAdd = apply_filters('key_wptheme_preload', $shouldAdd, $preloadKey, $preloadType);
              if ($shouldAdd) {
                $preloadParams = is_array($preload) ? $preload : array('file' => $preload);

                $preloadParams = array_merge(array(
                  'file' => '',
                  'href' => '',
                  'type' => '',
                  'ver' => false,
                  'media' => 'all',
                  'crossorigin' => ''
                ), $preloadParams);

                if (($preloadParams['file'] != '') || ($preloadParams['href'] != '')) {
                  $this->echoPreload($preloadKey, $preloadType, $preloadParams);
                }
              }
            }
          }

          echo('<!-- END : key_wptheme preloads -->' . PHP_EOL . PHP_EOL);
        }
      }
    }

    /*----------------------*/
    private function echoPreload($key, $type, $preload) {
      $versionString = '';
      if (is_string($preload['ver'])) {
        $versionString = $preload['ver'];
      } else if ($preload['ver'] === true) {
        $versionString = $this->version;
      }
      if ($versionString != '') {
        $versionString = 'ver=' . $versionString;
      }

      $href = '';
      if (isset($preload['forceHref']) && $preload['forceHref'] != '') {
        $href = $preload['forceHref'];
      } else {
        if ($preload['href'] == '') {
          $href = constant(sprintf('KEY_THEME_URI_%s', strtoupper($type))) . $preload['file'];
          if ($versionString != '') {
            $href .= '?' . $versionString;
          }
        } else {
          $href = $preload['href'];
          if ($versionString != '') {
            if (strpos($href, '?') !== false) {
              $href .= '&#38;' . $versionString;
            } else {
              $href .= '?' . $versionString;
            }
          }
        }
      }

      $mime = ($preload['type'] != '') ? $preload['type'] : $this->getMimeFromHref($href, $type);
      if ($mime != '') {
        $mime = ' type="' . $mime . '"';
      }

      $crossOrigin = ($preload['crossorigin'] === true) ? 'anonymous' : $preload['crossorigin'];
      if ($crossOrigin != '') {
        $crossOrigin = ' crossorigin="' . $crossOrigin . '"';
      }

      $media = ($type == 'style') ? ' media="' . $preload['media'] . '"' : '';

      echo(sprintf('<link id="preload-%s-%s" rel="preload" href="%s" as="%s"%s%s%s>' . PHP_EOL,
        $type,
        $key,
        $href,
        $type,
        $mime,
        $media,
        $crossOrigin
      ));
    }

    /*----------------------*/
    private function getMimeFromHref($href, $type) {
      if ($type == 'script') {
        return 'text/javascript';
      } else if ($type == 'style') {
        return 'text/css';
      } else {
        $ext = pathinfo(parse_url($href, PHP_URL_PATH), PATHINFO_EXTENSION);
        switch(strtolower($ext)) {
          case 'ttf'   : return 'font/ttf';
          case 'sfnt'  : return 'font/sfnt';
          case 'woff'  : return 'font/woff';
          case 'woff2' : return 'font/woff2';
          case 'otf'   : return 'font/otf';
          case 'eot'   : return 'application/vnd.ms-fontobject';
          case 'svg'   : return 'image/svg+xml';
          case 'png'   : return 'image/png';
          case 'jpeg'  :
          case 'jpg'   : return 'image/jpeg';
          case 'gif'   : return 'image/gif';
          case 'pdf'   : return 'application/pdf';
          case 'webp'  : return 'image/webp';
          case 'webm'  : return 'video/webm';
          case 'weba'  : return 'audio/webm';
          case 'wav'   : return 'audio/wav';
          case 'mp3'   : return 'audio/mpeg';
          case 'mp4'   : return 'video/mp4';
          case 'mpeg'   : return 'video/mpeg';
          case 'aac'   : return 'audio/aac';
          case 'avi'   : return 'video/x-msvideo';
        }
      }
      return '';
    }

    /*----------------------*/
    public function setupScriptAndStyleEnqueue($params = array()) {
      $this->enqueueParams = array_merge(array(        
        'fileAgeVersions' => false,
        'themeStyle' => true,
        'themeStyleNotMinfied' => false,
        'themeStyleNotPreloaded' => false,
        'themeStyleToBottom' => false,
        'dequeueBlockLibrary' => true,
        'customJQuery' => array(),
        'styles' => array(),
        'scripts' => array(),
        'adminStyles' => array(),
        'adminScripts' => array(),
        'asyncScripts' => array(),
        'deregister' => array(),
        'preload' => array(),
        'corePreloads' => array('jquery-core', 'jquery-migrate'),
        'crossOriginKeys' => array(),
        'thickBox' => false        
      ), $params);

      $this->enqueueParams['deregister'] = array_merge(
        array('styles' => array(), 'scripts' => array()),
        $this->enqueueParams['deregister']
      );

      //Add theme style to styles array
      if ($this->enqueueParams['themeStyle']) {
        $minified = $this->enqueueParams['themeStyleNotMinfied'] ? '' : '.min';
        $styleFile = 'style' . $minified . '.css';
        $themeStyleDef = array(
          'href' => KEY_THEME_URI . $styleFile
        );
        if ($this->enqueueParams['themeStyleNotPreloaded']) {
          $themeStyleDef['preload'] = false;
        }
        if ($this->enqueueParams['fileAgeVersions']) {
          $styleFile = KEY_THEME_DIR . $styleFile;
          if (file_exists($styleFile)) {
            $themeStyleDef['ver'] = strval(filemtime($styleFile));
          } 
        }
        $this->enqueueParams['styles'] = array_merge(array('theme-style' => $themeStyleDef), $this->enqueueParams['styles']);
      }

      //Deregister/Add custom JQuery
      $jQueryScriptDef = $this->enqueueParams['customJQuery'];
      if (!is_array($jQueryScriptDef)) {
        $jQueryScriptDef = array('footer' => false);
        if ($this->enqueueParams['customJQuery'] !== true) {
          $jQueryScriptDef['file'] = strval($this->enqueueParams['customJQuery']);
        }
      }

      if (count($jQueryScriptDef) > 0) {   
        $this->enqueueParams['corePreloads'] = array_filter($this->enqueueParams['corePreloads'], function($value){ return in_array($value, array('jquery-core', 'jquery-migrate')); });
        if (!isset($jQueryScriptDef['footer'])){
          $jQueryScriptDef['footer'] = false;
        }
        $jQueryScriptDef['deregister'] = true;
        $this->enqueueParams['scripts'] = array_merge(array('jquery' => $jQueryScriptDef), $this->enqueueParams['scripts']);
      } 

      //Deregister/Add ThickBox
      if (is_array($this->enqueueParams['thickBox']) || ($this->enqueueParams['thickBox'] === true)) {
        $this->action('wp_footer', array($this, 'frontFooter'), 5);

        $this->enqueueParams['thickBox'] = array_merge(array(
          'ver' => $this->version,
          'async' => false,
          'addClassTo' => array(),
          'jsStrings' => array()
        ), is_array($this->enqueueParams['thickBox']) ? $this->enqueueParams['thickBox'] : array());

        $this->enqueueParams['styles']['thickbox'] = array_merge(array(
          'href' => includes_url('js/thickbox/thickbox.css'),
          'deregister' => true,
          //'preload' => false
        ), $this->enqueueParams['thickBox']);

        $this->enqueueParams['scripts']['thickbox'] = array_merge(array(
          'src' => includes_url('js/thickbox/thickbox.js'),
          'deregister' => true,
          'deps' => array('jquery'),
          'footer' => true
        ), $this->enqueueParams['thickBox']);
      }

      if ($this->enqueueParams['fileAgeVersions']) {
        $this->setFileAgeVersions('style');
        $this->setFileAgeVersions('script');
      }

      if ($this->enqueueParams['themeStyleToBottom']) {
        //Shift 'theme-style' to bottom if needed
        $newStyles = $this->enqueueParams['styles'];
        unset($newStyles['theme-style']);
        $newStyles['theme-style'] = $this->enqueueParams['styles']['theme-style'];
        $this->enqueueParams['styles'] = $newStyles;
      }   

      //Find and assign preloads
      $this->collatePreloads('style');
      $this->collatePreloads('script');

      if ((count($this->enqueueParams['asyncScripts']) > 0) || (count($this->enqueueParams['crossOriginKeys']) > 0)) {
        $this->filter('style_loader_tag', array($this, 'styleLoader'), 10, 4);
        $this->filter('script_loader_tag', array($this, 'scriptLoader'), 10, 3);
      }

      if (count($this->enqueueParams['preload']) > 0) {
        $this->action('wp_head', array($this, 'frontHead'), 5);
      }

      //Front and admin script actions
      if ($this->enqueueParams['dequeueBlockLibrary']
          || (count($this->enqueueParams['styles']) > 0 )
          || (count($this->enqueueParams['scripts']) > 0)){
        $this->action('wp_enqueue_scripts', array($this, 'enqueueScriptsAndStyles'), 999);
      }
      if ((count($this->enqueueParams['adminStyles']) > 0 ) || (count($this->enqueueParams['adminScripts']) > 0 )){
        $this->action('admin_enqueue_scripts', array($this, 'enqueueAdminScriptsAndStyles'));
      }
    }

    /*----------------------*/
    private function setFileAgeVersions($type) {
      $newDefs = $this->enqueueParams[$type . 's'];
      foreach($this->enqueueParams[$type . 's'] as $key => $def) {
        if (!is_array($def) || (isset($def['file']) && ($def['file'] != ''))) {
          $newDef = is_array($def) ? $def : array('file' => $def);
          $filePath = constant(sprintf('KEY_THEME_DIR_%s', strtoupper($type))) . $newDef['file'];      
          if (file_exists($filePath)) {
            $newDef['ver'] = strval(filemtime($filePath));
          } 
          $newDefs[$key] = $newDef;
        }
      }
      $this->enqueueParams[$type . 's'] = $newDefs;
    }

    /*----------------------*/
    private function collatePreloads($type) {
      foreach($this->enqueueParams[$type . 's'] as $key => $def) {
        if (is_array($def) && isset($def['preload']) && ($def['preload'] === false)) {
          continue;
        }

        if (!isset($this->enqueueParams['preload'][$type])) {
          $this->enqueueParams['preload'][$type] = array();
        }
        $preloadSpec = is_array($def) && isset($def['preload']) ? $def['preload'] : array();
        if (is_array($def)) {
          if (isset($def['file'])) {
            $preloadSpec['file'] = $def['file'];
          } else if (isset($def['href'])) {
            $preloadSpec['href'] = $def['href'];
          } else if (isset($def['src'])) {
            $preloadSpec['href'] = $def['src'];
          }

          if (!isset($preloadSpec['ver'])) {
            if (isset($def['ver'])) {
              $preloadSpec['ver'] = $def['ver'];
            } else {
              $preloadSpec['ver'] = $this->version;
            }
          }

          if (isset($preloadSpec['crossorigin'])) {
            $this->enqueueParams['crossOriginKeys'][$type . '-' . $key] = $preloadSpec['crossorigin'];
          }

          if (!isset($preloadSpec['media'])) {            
            $preloadSpec['media'] = isset($def['media']) ? $def['media'] : 'all';
          }
        } else {
          $preloadSpec['file'] = $def;
          $preloadSpec['ver'] = $this->version;
        }

        $this->enqueueParams['preload'][$type][$key] = $preloadSpec;
      }
    }

    /*----------------------*/
    public function enqueueScriptsAndStyles() {
      if (count($this->enqueueParams) > 0) {
        if ($this->enqueueParams['dequeueBlockLibrary']) {
          wp_dequeue_style('wp-block-library');
        }           

        foreach($this->enqueueParams['styles'] as $key => $value) {
          if (($key == 'thickbox') && (!isset($this->enqueueParams['deregister']['styles']['dashicons']))) {
            wp_enqueue_style('dashicons');
          }
          $this->enqueueStyle($key, $value);
        }

        foreach($this->enqueueParams['scripts'] as $key => $value) {
          $this->enqueueScript($key, $value);
        }   

        //Deregister gutenburg styles
        if ($this->initParams['disableGutenberg'] && !isset($this->enqueueParams['deregister']['styles']['global-styles'])) {
          $this->enqueueParams['deregister']['styles']['global-styles'] = true;
        }

        foreach ($this->enqueueParams['deregister']['styles'] as $key => $deregister) {
          $this->deregisterStyleOrScript($key, $deregister, true);
        }

        foreach ($this->enqueueParams['deregister']['scripts'] as $key => $deregister) {
          $this->deregisterStyleOrScript($key, $deregister, false);
        }        

        if (count($this->enqueueParams['asyncScripts']) > 0) {
          $this->filter('script_loader_tag', array($this, 'scriptLoader'), 10, 3);
        }
      }

      if ($this->hasWooCommerce && ($this->initParams['woocommerce']['removeStyles'])) {
        wp_deregister_style('wc-block-style');
        wp_deregister_style('wc-blocks-style');
        wp_deregister_style('wc-block-vendors-style');
        wp_deregister_style('wc-blocks-vendors-style');
        wp_deregister_style('woocommerce-inline');
      }

      if (in_array('bar-yoast', $this->initParams['hide'])) {
        wp_dequeue_style('yoast-seo-adminbar');
      }
    }

    /*----------------------*/
    public function deregisterStyleOrScript($key, $deregister, $isStyle){
      $shouldDeregister = true;
      if (is_string($deregister)) {
        if ($deregister == 'notIfLoggedIn') {
          $shouldDeregister = !is_user_logged_in();
        } else if ($deregister == 'onlyIfLoggedIn') {
          $shouldDeregister = is_user_logged_in();
        }
      } else {
        $shouldDeregister = $deregister;
      }

      if ($shouldDeregister) {
        if ($isStyle) {
          wp_deregister_style($key);
        } else {
          wp_deregister_script($key);
        }
      }
    }

    /*----------------------*/
    public function enqueueAdminScriptsAndStyles($adminPage) {
      foreach($this->enqueueParams['adminStyles'] as $key => $value) {
        $this->enqueueStyle($key, $value, true);
      }
      foreach($this->enqueueParams['adminScripts'] as $key => $value) {
        $this->enqueueScript($key, $value, true);
      }

      if (count($this->enqueueParams['asyncScripts']) > 0) {
        $this->filter('script_loader_tag', array($this, 'scriptLoader'), 10, 3);
      }
    }

    /*----------------------*/
    public function enqueueBlockAssets() {
      if ($this->hasWooCommerce && ($this->initParams['woocommerce']['removeStyles'])) {
        wp_deregister_style('wc-block-editor');
        wp_deregister_style('wc-blocks-editor');
        wp_deregister_style('wc-block-style');
        wp_deregister_style('wc-blocks-style');
        wp_deregister_style('wc-block-vendors-style');
        wp_deregister_style('wc-blocks-vendors-style');
        wp_deregister_style('woocommerce-inline');
      }
    }

    /*----------------------*/
    public function enqueueStyle($key, $value, $isAdmin = false) {
      $styleArray = array_merge(array(
        'file' => '',
        'href' => '',
        'deps' => array(),
        'ver' => $this->version,
        'media' => 'all',
        'deregister' => false
      ), (is_array($value) ? $value : array('file' => $value)));

      if ($styleArray['deregister']) {
        wp_deregister_style($key);
      }

      $href = ($styleArray['href'] != '') ? $styleArray['href'] : (($styleArray['file']  != '') ? KEY_THEME_URI_STYLE . $styleArray['file'] : '');

      if ($href != '') {
        $shouldEnqueue = true; //REVIEW Maybe some logic from array in future
        $shouldEnqueue = apply_filters('key_wptheme_enqueue', $shouldEnqueue, $key, ($isAdmin ? 'adminStyle' : 'style'));
        if ($shouldEnqueue) {
          if ($styleArray['deregister']) {
            wp_register_style($key, $href, $styleArray['deps'], $styleArray['ver'], $styleArray['media']);
            wp_enqueue_style($key);
          } else {
            wp_enqueue_style($key, $href, $styleArray['deps'], $styleArray['ver'], $styleArray['media']);
          }
        }
      }
    }

    /*----------------------*/
    public function enqueueScript($key, $value, $isAdmin = false) {
      $scriptArray = array_merge(array(
        'file' => '',
        'src' => '',
        'deps' => array(),
        'ver' => $this->version,
        'footer' => true,
        'async' => false,
        'deregister' => false
      ), (is_array($value) ? $value : array('file' => $value)));

      if ($scriptArray['deregister']) {
        wp_deregister_script($key);
      }

      $src = ($scriptArray['src'] != '') ? $scriptArray['src'] : (($scriptArray['file']  != '') ? KEY_THEME_URI_SCRIPT . $scriptArray['file'] : '');

      if ($src != '') {
        $shouldEnqueue = true; //REVIEW Maybe some logic from array in future
        $shouldEnqueue = apply_filters('key_wptheme_enqueue', $shouldEnqueue, $key, ($isAdmin ? 'adminScript' : 'script'));
        if ($shouldEnqueue) {
          if ($scriptArray['async'] && !in_array($key, $this->enqueueParams['asyncScripts'])) {
            $this->enqueueParams['asyncScripts'][] = $key;
          }

          if ($scriptArray['deregister']) {
            wp_register_script($key, $src, $scriptArray['deps'], $scriptArray['ver'], $scriptArray['footer']);
            wp_enqueue_script($key);
          } else {
            wp_enqueue_script($key, $src, $scriptArray['deps'], $scriptArray['ver'], $scriptArray['footer']);
          }
        }
      }
    }

    /*----------*/
    function styleLoader($tag, $handle, $href, $media) {
      if (isset($this->enqueueParams['crossOriginKeys']['style-' . $handle])){
        $crossOrigin = ($this->enqueueParams['crossOriginKeys']['style-' . $handle] === true) ? "anonymous" : $this->enqueueParams['crossOriginKeys']['style-' . $handle];
        return sprintf('<link rel="stylesheet" id="%s-css"  href="%s" type="text/css" media="%s" crossorigin="%s">' . PHP_EOL,
          $handle,
          $href,
          $media,
          $crossOrigin
        );
      }
      return $tag;
    }

    /*----------*/
    function scriptLoader($tag, $handle, $src) {
      if (in_array($handle, $this->enqueueParams['asyncScripts']) ||  isset($this->enqueueParams['crossOriginKeys']['script-' . $handle])) {        
        $crossOrigin = '';
        if (isset($this->enqueueParams['crossOriginKeys']['script-' . $handle])){
          $crossOrigin = ' crossorigin="' . (($this->enqueueParams['crossOriginKeys']['script-' . $handle] === true) ? 'anonymous' : $this->enqueueParams['crossOriginKeys']['script-' . $handle]) . '"';
        }
        $async = in_array($handle, $this->enqueueParams['asyncScripts']) ? ' async="async"' : '';
        return sprintf('<script type="text/javascript" src="%s" id="%s-js"%s%s></script>' . PHP_EOL,
          $src,
          $handle,
          $async,
          $crossOrigin
        );
      }
      return $tag;
    }

    /*----------------------*/
    public function setupCopyrightFooter($params = array()) {
      $this->footerParams = array_merge(array(
        'content' => '',
        'holidaymakerReady' => false,
        'poweredByHolidaymaker' => false
      ), $params);
      $this->filter('admin_footer_text', array($this, 'adminFooterText'));
    }

    /*----------------------*/
    public function adminFooterText($orginalText) {
      $newText = '<span class="key-dashboard-footer">';
      $newText .= (($this->footerParams['content'] != '') ? $this->footerParams['content'] : 'Built by <a href="https://key.digital" target="_blank">Key.Digital</a> | &copy; Key.Digital Agency Limited ' . date('Y'));

      if ($this->footerParams['holidaymakerReady']) {
        $newText .= '<img src="' . self::holidaymakerSunSrc() . '" />holidaymaker ready! - <a href="http://holidaymakerapp.co.uk" target="_blank">more info</a>';
      }

      if ($this->footerParams['poweredByHolidaymaker']) {
        $newText .= '<img src="' . self::holidaymakerSunSrc() . '" />Powered by holidaymaker - <a href="http://holidaymakerapp.co.uk" target="_blank">more info</a>';
      }

      $newText .= '</span>';
      return $newText;
    }

    /*----------------------*/
    public function setupKeyDashboard($params = array()) {
      $this->dashboardParams = array_merge(array(
        'slug' => 'key-dashboard',
        'renderFile' => 'key-dashboard.php',
        'overrideDashboard' => false,
        'redirectAfterLogin' =>true,
        'icon' => '',
        'title' => 'Key.Digital',
        'position' => 3,
        'capability' => 'read'
      ), $params);

      $this->action('admin_menu', array($this, 'adminMenu'), 99);

      if ($this->dashboardParams['overrideDashboard']) {
        $this->action('load-index.php', array($this, 'redirectDashboard'));
      } else if ($this->dashboardParams['redirectAfterLogin']) {
        $this->filter('login_redirect', array($this, 'afterLoginPage'));
      }
    }

    /*-------------------------*/
    public function redirectDashboard() {
      if(is_admin()) {
        $screen = get_current_screen();
        if ($screen->base == 'dashboard') {
          wp_redirect(admin_url('index.php?page=' . $this->dashboardParams['slug']));
        }
      }
    }

    /*-------------------------*/
    public function afterLoginPage() {
      return admin_url('index.php?page=' . $this->dashboardParams['slug']);
    }

    /*----------*/
    public function beforeAdminBarRender() {
      global $wp_admin_bar;

      if (isset($this->initParams['hide'])) {
        if (in_array('comments', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('comments');
        }

        if (in_array('themes', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('customize');
          $wp_admin_bar->remove_menu('themes');
        }

        if (in_array('bar-wordpress', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('wp-logo');
        }

        if (in_array('bar-search', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('search');
        }

        if (in_array('bar-yoast', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('wpseo-menu');
        }

        if (in_array('bar-new', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('new-content');
        }

        if (in_array('bar-forms', $this->initParams['hide'])) {
          $wp_admin_bar->remove_menu('gform-forms');
        }
      }
    }

    /*----------*/
    public function postAndPageColumns($columns) {
      if (isset($this->initParams['hide'])) {
        if (in_array('comments', $this->initParams['hide'])) {
          unset($columns['comments']);
        }
        if (in_array('categories', $this->initParams['hide'])) {
          unset($columns['categories']);
        }
        if (in_array('tags', $this->initParams['hide'])) {
          unset($columns['tags']);
        }
      }
      return $columns;
    }

    /*----------*/
    public function productColumns($columns) {
      if (!$this->initParams['woocommerce']['allowProductTags']) {        
        unset($columns['product_tag']);
      }
      return $columns;
    }   

    /*-------------------------*/
    public function adminHead() {
      $this->commonHead();

      if (($this->initParams['removePluginAds']) && (!current_user_can('manage_options'))) {
        echo('<style>#advertID, .advertClass{ display: none !important; }</style>' . PHP_EOL);
      }
    }

    /*-------------------------*/
    public function frontHead() {
      $this->commonHead();

      $this->renderPreloads();
      $this->renderMetaTags();
    }

    /*-------------------------*/
    public function frontFooter() {
      if (is_array($this->enqueueParams['thickBox'])) {
        $thickBoxLocalised = array_merge(array(
          'next' => __('Next &gt;'),
          'prev' => __('&lt; Prev'),
          'image' => __('Image'),
          'of' => __('of'),
          'close' => __('Close'),
          'noiframes' => __('This feature requires inline frames. You have iframes disabled or your browser does not support them.'),
          'loadingAnimation' => includes_url('js/thickbox/loadingAnimation.gif')
        ), $this->enqueueParams['thickBox']['jsStrings']);
        echo('<script type="text/javascript" id="thickbox-js-extra">' . PHP_EOL);
        echo('  //key_wptheme : ThickBox Localisation Strings' . PHP_EOL);
        echo('  var thickboxL10n = ' . json_encode($thickBoxLocalised) . ';' . PHP_EOL);

        $addClassTo = array();
        if (is_array($this->enqueueParams['thickBox']['addClassTo'])) {
          $addClassTo = $this->enqueueParams['thickBox']['addClassTo'];
        } else if (is_string($this->enqueueParams['thickBox']['addClassTo'])) {
          $addClassTo = array($this->enqueueParams['thickBox']['addClassTo']);
        }

        if (count($addClassTo) > 0) {
          $classes = implode(',', $addClassTo);
          echo('  //key_wptheme : ThickBox Add class to selectors' . PHP_EOL);
          echo('  jQuery(document).ready(function() {' . PHP_EOL);
          echo('    jQuery("' . $classes . '").addClass("thickbox");' . PHP_EOL);
          echo('  });' . PHP_EOL);
        }

        echo('</script>' . PHP_EOL);
      }
    }

    /*-----------*/
    public function wpLoaded() {
      if (isset($this->initParams['postSupport']['remove']) && is_array($this->initParams['postSupport']['remove'])) {
        foreach ($this->initParams['postSupport']['remove'] as $feature => $postTypes) {
          if (is_array($postTypes)) {
            foreach($postTypes as $postType) {
              remove_post_type_support($postType, $feature);
            }
          } else {
            remove_post_type_support($postTypes, $feature);
          }
        }
      }

      if (isset($this->initParams['postSupport']['add']) && is_array($this->initParams['postSupport']['add'])) {
        foreach ($this->initParams['postSupport']['add'] as $feature => $postTypes) {
          if (is_array($postTypes)) {
            foreach($postTypes as $postType) {
              add_post_type_support($postType, $feature);
            }
          } else {
            add_post_type_support($postTypes, $feature);
          }
        }
      }
    }

    /*-------------------------*/
    private function commonHead() {
      //Currently empty
    }

    /*-------------------------*/
    public function adminMenu() {
      if (isset($this->initParams['hide'])) {
        if (in_array('comments', $this->initParams['hide'])) {
          remove_menu_page('edit-comments.php');
        }
        if (in_array('themes', $this->initParams['hide'])) {
          remove_menu_page('themes.php');
        }
        if (in_array('tools', $this->initParams['hide'])) {
          remove_menu_page('tools.php');
        }
        if (in_array('categories', $this->initParams['hide'])) {
          remove_submenu_page('edit.php', 'edit-tags.php?taxonomy=category');
          remove_meta_box('categorydiv', 'post', 'normal');
        }
        if (in_array('tags', $this->initParams['hide'])) {
          remove_submenu_page('edit.php', 'edit-tags.php?taxonomy=post_tag');
          remove_meta_box('tagsdiv-post_tag', 'post', 'normal');
        }
        if (in_array('posts', $this->initParams['hide'])) {
          remove_menu_page('edit.php');
        }
        if (in_array('pages', $this->initParams['hide'])) {
          remove_menu_page('edit.php?post_type=page');
        }
      }

      if ($this->initParams['addMenusToAdmin'] !== false) {
        $shouldKeepThemeingMenu = false;
        $currentUser = wp_get_current_user();
        foreach($currentUser->roles as $role) {
          if (in_array($role, $this->initParams['addMenusToAdmin']['doNotHideThemeingFor'])) {
            $shouldKeepThemeingMenu = true;
            break;
          }
        }

        if (!$shouldKeepThemeingMenu) {
          remove_menu_page('themes.php');
        }

        add_menu_page('Menus', 'Menus', $this->initParams['addMenusToAdmin']['capability'], 'nav-menus.php', null, $this->initParams['addMenusToAdmin']['icon'], $this->initParams['addMenusToAdmin']['position']);
      }

      if (count($this->dashboardParams) > 0) {
        global $menu;

        $icon = ($this->dashboardParams['icon'] != '') ? $this->dashboardParams['icon'] : key_wpdashboard::keyDigitalWordpressMenuIcon();

        if ($this->dashboardParams['overrideDashboard']) {
          add_submenu_page('index.php', $this->dashboardParams['title'], $this->dashboardParams['title'], $this->dashboardParams['capability'], $this->dashboardParams['slug'], array($this, 'renderDashboard'));

          $indexToReplace = -1;
          if (isset($menu) && (is_array($menu))) {
            foreach($menu as $index => $menuItem) {
              if (is_array($menuItem) && (count($menuItem) > 6) && ($menuItem[2] == 'index.php')) {
                $indexToReplace = $index;
                break;
              }
            }
          }

          if ($indexToReplace != -1) {
            $menu[$indexToReplace][4] = str_replace('menu-icon-dashboard', 'menu-icon-dashboard1', $menu[$indexToReplace][4]);
            $menu[$indexToReplace][6] = $icon;
          }
        } else {
          add_menu_page($this->dashboardParams['title'], $this->dashboardParams['title'], $this->dashboardParams['capability'], $this->dashboardParams['slug'], array($this, 'renderDashboard'), $icon, $this->dashboardParams['position']);
        }
      }
    }

    /*-------------------------*/
    public function renderDashboard() {
      $renderFile = KEY_THEME_DIR . trim($this->dashboardParams['renderFile'], '/');
      if (file_exists($renderFile)) {
        include($renderFile);
      } else {
        echo('<p>Unable to find file <strong>' . $this->dashboardParams['renderFile'] . '</strong> in the theme root</p>');
      }
    }

    /*-------------------------*/
    public function setupLoginLogo($params = array()) {
      $this->loginLogoParams = array_merge(array(
        'image' => '',
        'url' => true,
        'title' => true,
        'allowLanguageSelection' => false,
        'width' => '300px',
        'height' => '220px',
        'background-size' => '100% 100%',
        'background-repeat' => 'no-repeat',
        'padding-bottom' => '30px',        
      ), $params);

      if (!$this->loginLogoParams['allowLanguageSelection']) {
        $this->filter('login_display_language_dropdown', '__return_false', 999);
      }      

      if ($this->loginLogoParams['url'] !== false) {
        $this->filter('login_headerurl', array($this, 'loginHeaderUrl'), 999);
      }

      if ($this->loginLogoParams['title'] !== false) {
        $this->filter('login_headertext', array($this, 'loginHeaderTitle'), 999);
      }      

      $this->action('login_enqueue_scripts', array($this, 'loginScripts'));
    }

    /*-------------------------*/
    public function loginScripts() {
      $imageUrl = ($this->loginLogoParams['image'] == '') ? $this->themeLogo : KEY_THEME_URI_IMAGE . $this->loginLogoParams['image'];
      echo('<style>#login h1 a, .login h1 a {');
      if (!isset($this->loginLogoParams['background-image'])) {
        echo('background-image: url("' . $imageUrl . '");');
      }

      foreach($this->loginLogoParams as $key => $value) {
        $keysToIgnore = array('image', 'url', 'title', 'allowLanguageSelection');
        if (($key != '') && !in_array($key, $keysToIgnore) && ($value != '')) {
          echo(sprintf('%s: %s;', $key, $value));
        }
      }
      echo('} </style>');
    }
    
    /*----------------------*/
    public function loginHeaderTitle($title) {
      if ($this->loginLogoParams['title'] === true) {
        return get_bloginfo('name');
      } else if ($this->loginLogoParams['title'] != '') {
        return $this->loginLogoParams['title'];
      }
      return $title;
    }

    /*----------------------*/
    public function loginHeaderUrl($url) {
      if ($this->loginLogoParams['url'] === true) {
        return get_site_url();
      } else if ($this->loginLogoParams['url'] != '') {
        return $this->loginLogoParams['url'];
      }
      return $url;
    }

    /*----------------------*/
    private function setupMenuOrder($params) {
      $this->menuOrderParams = $params;

      $this->filter('custom_menu_order', array($this, 'menuOrder'), 999, 1);
      $this->filter('menu_order',        array($this, 'menuOrder'), 999, 1);
    }

    /*----------------------*/
    public function menuOrder($menuOrder) {
      if (!$menuOrder) {
        return true;
      }

      if (!is_array($menuOrder)) {
        return $menuOrder;
      }

      if ($this->echoMenu) {
        die('<h1>MENU ORDER</h1>' . PHP_EOL . '<pre>' . print_r($menuOrder, true) . '</pre>' . PHP_EOL);
      }

      $newMenu = $this->menuOrderParams;
      foreach($menuOrder as $menu) {
        if (!in_array($menu, $newMenu)) {
          $newMenu[] = $menu;
        }
      }

      return $newMenu;
    }

    /*----------------------*/
    public function triggerOneOffProcessess() {
      $this->addOrRemoveMenusRole();
    }

    /*----------------------*/
    public function addOrRemoveMenusRole() {
      global $wp_roles;

      if ($this->initParams['addMenusToAdmin'] !== false) {
        //REVIEW: This uses 'list_users' to define if it should have 'edit_theme_options'
        $menusCapability = isset($this->initParams['addMenusToAdmin']) ? $this->initParams['addMenusToAdmin']['capability'] : 'list_users';
        foreach($wp_roles->role_objects as $role) {
          if ($role->has_cap($menusCapability)) {
            $role->add_cap('edit_theme_options');
          } else {
            $role->remove_cap('edit_theme_options');
          }
        }
      }
    }

    /*----------------------*/
    /*--STATICS-------------*/
    /*----------------------*/
    public static function theme() {
      if (self::$theme == null) {
        self::loadTheme();
      }
      return self::$theme;
    }

    /*----------------------*/
    public static function hasTheme() {
      return self::$theme != null;
    }

    /*----------------------*/
    public static function loadTheme($params = array()) {
      self::$theme = new key_wptheme($params);
    }

    /*----------------------*/
    public static function version() {
      return self::theme()->version;
    }

    /*----------------------*/
    public static function mapsKey() {
      return self::theme()->mapsKey;
    }

    /*----------------------*/
    /*--DRAWING-------------*/
    /*----------------------*/
    public static function getImage($name, $extension, $version = '') {
      $theme = self::theme();

      $ext = $extension;
      if ($version !== false) {
        $ext .= '?ver=' . (($version == '') ? $theme->version() : $version);
      }

      return sprintf('%s%s.%s', KEY_THEME_URI_IMAGE, $name, $ext);
    }

    /*----------------------*/
    public static function image($name, $extension, $version = '') {
      echo(self::getImage($name, $extension, $version));
    }

    /*----------------------*/
    public static function getPng($name, $version = '') {
      return self::getImage($name, 'png', $version);
    }

    /*----------------------*/
    public static function png($name, $version = '') {
      echo(self::getPng($name, $version));
    }

    /*----------------------*/
    public static function getJpg($name, $version = '') {
      return self::getImage($name, 'jpg', $version);
    }

    /*----------------------*/
    public static function jpg($name, $version = '') {
      echo(self::getJpg($name, $version));
    }

    /*----------------------*/
    public static function getGif($name, $version = '') {
      return self::getImage($name, 'gif', $version);
    }

    /*----------------------*/
    public static function gif($name, $version = '') {
      echo(self::getGif($name, $version));
    }

    /*----------------------*/
    public static function getSvg($name, $version = '') {
      return self::getImage($name, 'svg', $version);
    }

    /*----------------------*/
    public static function svg($name, $version = '') {
      echo(self::getSvg($name, $version));
    }

    /*----------------------*/
    public static function echoThemeButton($params = array()) {
      $newParams = $params;
      if (isset($newParams['image'])) {
        $ext = isset($newParams['imageExt']) ? strtolower(trim($newParams['imageExt'], '.')) : 'svg';

        $newParams['imageUrl'] = sprintf('%s%s.%s?v=%s',
          KEY_THEME_URI_IMAGE,
          $newParams['image'],
          $ext,
          self::version()
        );
      }
      key_wpdashboard::echoDashboardButton($newParams);
    }

    /*-------------------------*/
    public static function echoThemeButtons($buttons = array()) {
      if (count($buttons) > 0) {
        echo('<div class="key-dashboard-content key-dashboard-button-group">' . PHP_EOL);
        foreach($buttons as $button) {
          self::echoThemeButton($button);
        }
        echo('</div>' . PHP_EOL);
      }
    }

    /*-------------------------*/
    public static function echoThemeTitle($titleOverride = '') {
      if (self::$theme == null) {
        return;
      }

      $title = ($titleOverride != '') ? $titleOverride : self::$theme->name;
      echo('<div class="key-dashboard-content key-img-text">' . PHP_EOL);
      echo('  <img class="key-logo" src="' . self::$theme->themeLogo . '" />' . PHP_EOL);
      echo('  <div>' . PHP_EOL);
      echo('    <h1>' . $title . ' </h1>' . PHP_EOL);
      echo('    <p><strong>Version:</strong> ' . self::$theme->version . '</p>' . PHP_EOL);
      echo('  </div>' . PHP_EOL);
      echo('</div>' . PHP_EOL);
    }

    /*----------------------*/
    public static function echoPoweredByHolidaymaker($titleOverride = '', $messageOverride = '') {
      $title = ($titleOverride != '') ? $titleOverride : 'Powered by holidaymaker';
      $message = ($messageOverride != '') ? $messageOverride : 'For more information on what holidaymaker has to offer, and to keep up-to-date with new additions visit <strong><a href="https://www.holidaymakerapp.co.uk/">holidaymakerapp.co.uk</a></strong>';

      echo('<hr />' . PHP_EOL);
      echo('<div class="key-dashboard-content key-img-text">' . PHP_EOL);
      echo('  <img class="key-logo" src="' . key_wptheme::poweredByHolidayMakerImageSrc() . '" />' . PHP_EOL);
      echo('  <div>' . PHP_EOL);
      echo('    <h1>' . $title . ' </h1>' . PHP_EOL);
      echo('    <p>' . $message . '</p>' . PHP_EOL);
      echo('  </div>' . PHP_EOL);
      echo('</div>' . PHP_EOL);
    }

    /*----------------------*/
    public static function echoHolidaymakerReady($titleOverride = '', $messageOverride = '') {
      $title = ($titleOverride != '') ? $titleOverride : 'Holidaymaker ready!';
      $message = ($messageOverride != '') ? $messageOverride : 'This website can be converted to the holidaymaker infrastructure to display your data in mobile apps, digital signage, kiosks, and other surfaces. For more information on what holidaymaker has to offer, and to keep up-to-date with new additions visit <b><a href="https://www.holidaymakerapp.co.uk/">holidaymakerapp.co.uk</a></b>';

      echo('<hr />' . PHP_EOL);
      echo('<div class="key-dashboard-content key-img-text">' . PHP_EOL);
      echo('  <img class="key-logo" src="' . key_wptheme::holidayMakerReadyImageSrc() . '" />' . PHP_EOL);
      echo('  <div>' . PHP_EOL);
      echo('    <h1>' . $title . ' </h1>' . PHP_EOL);
      echo('    <p>' . $message . '</p>' . PHP_EOL);
      echo('  </div>' . PHP_EOL);
      echo('</div>' . PHP_EOL);
    }

    /*----------------------*/
    public static function holidaymakerSunSrc() {
      $image = "CiAgICAgIDxzdmcgd2lkdGg9IjU1cHgiIGhlaWdodD0iNTRweCIgdmlld0JveD0iMCAwIDU1cHggNTRweCIgeD0iMHB4IiB5PSIwcHgiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2Z" .
"yI+PGcgZmlsbD0iI2Y1YTE0YSIgZmlsbC1ydWxlPSJldmVub2RkIj48cGF0aCBkPSJNMzYuNyAyNS41bC0uNS0xLjRjLS4yLS40LS40LS45LS43LTEuMkwzNCAyMC44YTEgMSAwIDAwLS4zLS40Yy0" .
"uNS0uNC0xLS43LTEuNi0uOS0uMi0uMi0uNC0uNC0uOC0uNWwtLjgtLjJIMzBhOCA4IDAgMDAtLjUtLjFsLS43LS4yaC0uNGMtLjggMC0xLjctLjItMi41IDAtMSAuMi0xLjguNi0yLjcgMS0xIC41L" .
"TEuOCAxLjItMi43IDEuOWwtMS4xIDFjLS4zLjQtLjYuNi0xIC44LTEuOCAxLjItMi42IDMuMy0zLjIgNS4zYTExLjMgMTEuMyAwIDAwLS42IDQuNWMwIC42LjMgMS4xLjUgMS43bC43IDEuNi43Ljd" .
"zLS40LS40LS4xIDBsLjUuNGMxIC43IDEuNyAxLjMgMi43IDEuNC44LjQgMS42LjcgMi42LjguOSAwIDIgMCAyLjktLjRsMS4xLS41Yy4zLS4zLjctLjIgMS0uNGEzIDMgMCAwMDEuNi0xLjJjLjMtL" .
"jQuNS0xIC41LTEuNSAwLTEuMS0uNi0yLTEuNS0yLS4zIDAtLjYuMS0uOC4zYTIuNSAyLjUgMCAwMC0uOC41bC0uOS40Yy0uMS4xLS4xLjEgMCAwbC0uMy4yLS41LjEtMS4xLjJoLTFhMTEuNSAxMS4" .
"1IDAgMDAtLjggMGgtLjNsLS40LS4xaC0uM2MwLS4yLS40LS4zLS40LS4zbC0uNS0uNWMtLjItLjMtLjYtLjYtLjctLjl2LS4zLS4xbC0uMi0xLjF2LTFsLjEtLjZ2LS4zbC4xLS4yLjEtLjN2LS4zb" .
"C4xLS4yVjI5bC4xLS4xLjYtMSAxLTEuNiAxLjYtMS4zIDEuMy0xLjEgMS0uOC4xLS4yLjItLjJhNiA2IDAgMDExLjctLjljLjktLjMgMS44LS40IDIuNy0uNGwuMy4xaC4xcy4xIDAgMCAwaC41bC4" .
"3LjVjLjIgMCAuNS4xLjcuM2wuNi4zLjYuNmMuMy4zLjYuNi43IDFsLjYgMS4xLjUgMWMuMy43LjUgMS42LjUgMi40YTggOCAwIDAxLS4zIDIuNHYuM2E0IDQgMCAwMS0uNCAxLjhsLS40LjktLjQuO" .
"S0uNC43LS43LjhjLS4zLjQtLjYgMS0xLjEgMS4yLS40LjEtLjMuNiAwIC42aC40bC4yLS4yaC4xbC41LS41LjctMWMuNC0uNSAxLTEgMS4yLTEuNmwuNi0xIC41LTEgLjUtMSAuMS0uNi4yLS42di0" .
"uNWwuMi0uNHYtMS4zYzAtMSAuMS0yLjEtLjEtMy4xTTE2LjcgMThsLS4yLS41LS4zLTEuMi0uNi0xLjEtLjgtMS0uOC0uOC0uOC0uNy0xLTEtLjMtLjItLjItLjJjLS4xLS40LS42LS44LTEtLjgtL" .
"jYtLjItMS4xIDAtMS4zLjYtLjMuNi0uNCAxLjItLjUgMS44IDAgLjYgMCAxLjIuNSAxLjdsLjcuNi44LjhjLjQuNC44LjggMS40IDFsMiAuNyAxIC41LjQuMy4zLjMuMi4yYy4yIDAgLjQgMCAuNS0" .
"uMlYxOE0yOC45IDEuMmMtLjYtLjUtMS4yLS41LTItLjMtLjUuMi0xLjEuNC0xLjUgMS0uMy42LS4zIDEuMi0uNCAxLjh2My44Yy4xLjcuNCAxLjMuNiAxLjggMCAuNCAwIC44LjIgMS4ybC4yLjkuM" .
"i4yVjE0Yy4xLjIuNC4yLjUuMmwuOC0uOS42LTEuNC44LTMuM1Y1LjhsLjItMS40VjMuMmwuMS0uMWEyIDIgMCAwMC0uMy0xLjlNNDUuMSAxMy42bC0uNy0uN2MtLjEtLjItLjMtLjQtLjUtLjRsLS4" .
"xLS4xYy0uMy0uNC0uNy0uMy0xLS4ybC0uNi4yLS4zLjItLjQuM2ExIDEgMCAwMC0uMi4zbC0uMy4yLS4zLjMtLjIuNS0uNC43LS4yLjYtLjEuMS0uMS4zLS4yLjMtLjIuNGMtLjIuMy0uNi42LTEgL" .
"jctLjMuMS0uNS41LS4yLjdsLjguMmguN2MuNSAwIDEgMCAxLjQtLjMuNC0uMSAxLS4yIDEuNC0uNWwxLjEtLjggMS4zLS43Yy40LS4yLjgtLjQuOC0xIDAtLjQtLjItMS0uNS0xLjNNNTMuOSAyOC4" .
"zYy0uMi0uMy0uMy0uNC0uNi0uNS0uNS0uMi0xLjEtLjMtMS42LS4zLTEtLjItMS45LS4yLTIuOCAwLS41IDAtMSAwLTEuNC4zLS40LjMtMSAuNC0xLjUuNi0xLjEuMi0yLjIuMy0zLjQuMy0uMiAwL" .
"S4zLjMtLjMuNXYuMWwuNC41LjUuNC45LjdhMTMuNyAxMy43IDAgMDA1LjkuOWgzYy45LjEgMS40LS4zIDEuNS0xLjEgMC0uOS0uMi0xLjctLjYtMi40TTQ0IDQzLjdhNC45IDQuOSAwIDAwLTEuNi0" .
"xLjZjLS4yIDAtLjMtLjItLjUtLjMtLjQtLjMtMS0uNS0xLjQtLjVsLS42LS4yLS45LS40Yy0uMiAwLS40LS4zLS42LS41bC0uNy0uM2MtLjEtLjItLjUtLjEtLjUuMnYxLjFhNS4xIDUuMSAwIDAwL" .
"jcgMmMuMS40LjMuNi42LjhhMjEuNCAyMS40IDAgMDAyLjMgMi42bC4zLjJjMCAuMy4zLjQuNi42LjQuMy44LjUgMS4zLjNzLjgtLjUuOS0xbC4yLS43LjItLjdjMC0uNyAwLTEtLjMtMS42TTI4LjQ" .
"gNDguNGMwLS41IDAtMS0uMi0xLjVsLS41LTEuNS0uMi0xLjVjMC0uNSAwLTEtLjMtMS4zLS4yLS4zLS41LS4yLS42IDBsLS43LjktLjUgMS4yLS44IDMtLjIgMi40LS4yIDIuNmMwIC44LjYgMS40I" .
"DEuNCAxLjQuMiAwIC4zIDAgLjUtLjJsLjUtLjFjLjQtLjEuNy0uNCAxLS42LjMtLjMuNS0uNC42LS44bC4xLTEuNC4xLTEuNHYtMS4yTTE0LjIgNDEuN2gtLjNhNC44IDQuOCAwIDAwLTIuNi44bC0" .
"uOC41LS42LjgtLjguN2MtLjUuNC0xIDEtMS4zIDEuNS0uNC41LS4xIDEuMy4zIDEuNy40LjQuOC41IDEuMy42aC4xbC4yLjFoLjFsLjMuMWMuMi4xLjQuMi42LjFsLjMtLjEuMy0uMmMuMiAwIC40L" .
"S4yLjYtLjRsLjItLjMuMi0uMS40LS42LjMtLjZjLjMtLjUuNC0xLjEuNS0xLjYgMC0uMy4yLS41LjMtLjdsLjQtMSAuMi0uNHYtLjRjLjItLjIuMS0uNS0uMi0uNU0xMS4xIDMwLjJWMzBjLS4zLS4" .
"yLS4zLS42LS42LS44bC0xLjItLjZBOCA4IDAgMDA3IDI4SDQuNWMtLjkgMC0xLjctLjItMi41LS4zLS43IDAtMS40IDAtMS44Ljd2MWMwIC41LjEgMSAuNCAxLjMuMi4zLjMuOC42IDEgLjUuMiAxI" .
"C4yIDEuNC4yLjkuMiAxLjcuMSAyLjYuMS42IDAgMSAwIDEuNi0uMkw4IDMxYy40LS4yIDEtLjIgMS41LS4zSDEwLjhjLjMtLjEuNC0uNS4zLS42Ii8+PC9nPjwvc3ZnPg==";
return 'data:image/svg+xml;base64,' . $image;
    }

    /*----------------------*/
    public static function poweredByHolidayMakerImageSrc() {
      $image = "PHN2ZyBoZWlnaHQ9IjExM3B0IiB2aWV3Qm94PSIwIDAgMzMwIDExMyIgd2lkdGg9IjMzMHB0IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd" .
      "3d3LnczLm9yZy8xOTk5L3hsaW5rIj48Y2xpcFBhdGggaWQ9ImEiPjxwYXRoIGQ9Im0wIDBoMzMwdjExM2gtMzMweiIvPjwvY2xpcFBhdGg+PGcgY2xpcC1wYXRoPSJ1cmwoI2EpIj48cGF0aCBkPSJ" .
      "tMTMuMjgzIDBoMzAzLjQzNGM3LjMzMSAwIDEzLjI4MyA1Ljk1MiAxMy4yODMgMTMuMjgzdjg2LjQzNGMwIDcuMzMxLTUuOTUyIDEzLjI4My0xMy4yODMgMTMuMjgzaC0zMDMuNDM0Yy03LjMzMSAwL" .
      "TEzLjI4My01Ljk1Mi0xMy4yODMtMTMuMjgzdi04Ni40MzRjMC03LjMzMSA1Ljk1Mi0xMy4yODMgMTMuMjgzLTEzLjI4M3oiIGZpbGw9IiNmNWExNGEiLz48ZyBmaWxsPSIjZmVmZWZlIiBmaWxsLXJ" .
      "1bGU9ImV2ZW5vZGQiPjxwYXRoIGQ9Im0yNjEuMjc4IDY5LjI3OWMwLTEuNDY1Ljc3Mi0yLjIzOCAyLjIzNS0yLjIzOGgyLjI3NmMxLjQ2MyAwIDIuMjM1Ljc3MyAyLjIzNSAyLjIzOHYxLjMwMmMwI" .
      "C42OTItLjA4MSAxLjMwMy0uMDgxIDEuMzAzaC4wODFjLjY5MS0yLjE1NyAyLjkyNy01LjA0NiA1LjU2OC01LjA0NiAxLjQyMyAwIDEuOTExLjc3MiAxLjkxMSAyLjIzN3YyLjI3OWMwIDEuNDY1LS4" .
      "3NzIgMi4yMzktMi4yMzYgMi4yMzktMy4zMzIgMC00Ljk1OCAyLjg0OC00Ljk1OCA2LjI2NnY1LjkwMWMwIDEuNDY1LS43NzIgMi4yMzgtMi4yMzUgMi4yMzhoLTIuNTYxYy0xLjQ2MyAwLTIuMjM1L" .
      "S43NzMtMi4yMzUtMi4yMzh6bS0xNS43MjUgNS41NzVjLjM2Ni0xLjY2OCAxLjM0Mi0zLjEzMyAzLjM3My0zLjEzMyAxLjc4OSAwIDIuODg2IDEuNDI0IDIuODg2IDMuMTMzem0xMy4yMDkgMS43MDl" .
      "jMC01LjYxNS0zLjQ1NC0xMC4wMS05LjY3My0xMC4wMS02Ljc0NyAwLTEwLjg5MiA0LjgwMS0xMC44OTIgMTAuOTQ2IDAgNS41NzUgNC4wMjMgMTAuOTg3IDExLjUwMiAxMC45ODcgMy4yMTEgMCA1L" .
      "jY5LTEuMDE3IDcuMTk0LTEuODcxIDEuMjE5LS42OTMgMS40MjItMS43OTIuNzcyLTMuMDUzbC0uNTY5LTEuMDU4Yy0uNjkxLTEuMzAyLTEuNjY3LTEuNDY1LTMuMDA4LS44NTQtMS4wNTYuNTI5LTI" .
      "uMzk3Ljk3Ni0zLjgyLjk3Ni0yLjIzNiAwLTQuMzQ5LTEuMTgtNC44NzgtMy43NDNoMTEuMDE1YzEuMzgyIDAgMi4zNTctMS4yMjEgMi4zNTctMi4zMnptLTQxLjE3NS0xNS40MjJjMC0xLjQ2NS43N" .
      "zItMi4yMzkgMi4yMzUtMi4yMzloMi41NmMxLjQ2NCAwIDIuMjM2Ljc3NCAyLjIzNiAyLjIzOXYxMi44MThoMS45OTFsMy4yNTItNS40MTJjLjYxLTEuMDU5IDEuNDIyLTEuNTA2IDIuNjQxLTEuNTA" .
      "2aDIuNjgzYzEuODI5IDAgMi40MzkgMS4xOCAxLjQ2MyAyLjY4NmwtNC4zODkgNi44MzZ2LjA4Mmw1LjMyNCA4LjY2N2MuOTM1IDEuNTQ3LjMyNSAyLjY4Ni0xLjUwNCAyLjY4NmgtMy4wNDhjLTEuM" .
      "jIgMC0yLjAzMi0uNDg5LTIuNjQyLTEuNTg3bC0zLjU3Ny02LjU1MmgtMi4xOTR2NS45MDFjMCAxLjQ2NS0uNzcyIDIuMjM4LTIuMjM2IDIuMjM4aC0yLjU2Yy0xLjQ2MyAwLTIuMjM1LS43NzMtMi4" .
      "yMzUtMi4yMzh6bS0xMC4zOTcgMTguMzkzYzAgMS43MDgtMS41NDUgMy43MDMtMy41NzcgMy43MDMtMS4zIDAtMS45OTItLjgxNC0xLjk5Mi0xLjc5MSAwLTEuOTEyIDIuNzY0LTIuNDgyIDQuOTE4L" .
      "TIuNDgyaC42NTF6bS0yLjM5OC0xMi45ODFjLTMuMDA4IDAtNS40NDcuODk1LTcuMDMyIDEuNjI4LTEuMjYuNjUtMS41MDMgMS43NDktLjg5NCAzLjAxbC40ODggMS4wMThjLjY1MSAxLjMwMiAxLjY" .
      "2NiAxLjU0NiAzLjAwOC45NzcgMS4wNTYtLjQ4OSAyLjQzOC0uOTM2IDMuNzgtLjkzNiAxLjU0NCAwIDIuOTY3LjU2OSAyLjk2NyAyLjMydi4zNjVoLS42MWMtNS4xNjIgMC0xMS44MjcgMS41NDctM" .
      "TEuODI3IDcuMTYyIDAgMy42MjIgMi44MDQgNi4zODkgNi44MjggNi4zODkgNC4xODYgMCA2LjE3Ny0zLjMzNyA2LjE3Ny0zLjMzN2guMDgycy0uMDQxLjE2My0uMDQxLjQwN3YuMTYzYzAgMS41NDY" .
      "uNzczIDIuMjc5IDIuMjM2IDIuMjc5aDEuOTVjMS40NjQgMCAyLjIzNi0uNzczIDIuMjM2LTIuMjM4di0xMC42NjJjMC01LjMzLTMuNTc3LTguNTQ1LTkuMzQ4LTguNTQ1em0tNDQuNTc0IDIuNzI2Y" .
      "zAtMS40NjUuNzcyLTIuMjM4IDIuMjM1LTIuMjM4aDIuMjc2YzEuNDY0IDAgMi4xOTUuNzczIDIuMTk1IDIuMjM4di4zNjdjMCAuMjAzLS4wNDEuNTI4LS4wNDEuNTI4aC4wODJjLjk3NS0xLjQ2NSA" .
      "yLjkyNi0zLjYyMSA2LjIxOC0zLjYyMSAyLjY0MiAwIDQuNzU1IDEuMTggNS44NTIgMy41NGguMDgyYzEuMDk3LTEuNzUgMy41NzctMy41NCA2LjcwNy0zLjU0IDMuODIgMCA2Ljc4NyAyLjA3NSA2L" .
      "jc4NyA3Ljg1M3YxMS4zNTRjMCAxLjQ2NS0uNzcyIDIuMjM4LTIuMjM2IDIuMjM4aC0yLjU2Yy0xLjQ2MyAwLTIuMjM2LS43NzMtMi4yMzYtMi4yMzh2LTEwLjE3NGMwLTEuNTQ2LS4yODQtMi42NDQ" .
      "tMS42MjYtMi42NDQtMy4wNDcgMC00LjAyMyAzLjA1Mi00LjAyMyA2LjE4NXY2LjYzM2MwIDEuNDY1LS43NzIgMi4yMzgtMi4yMzUgMi4yMzhoLTIuNTYxYy0xLjQ2MyAwLTIuMjM2LS43NzMtMi4yM" .
      "zYtMi4yMzh2LTEwLjE3NGMwLTEuNTQ2LS4yODQtMi42NDQtMS42MjUtMi42NDQtMy4yMTEgMC00LjAyNCAzLjI5NS00LjAyNCA2LjE4NXY2LjYzM2MwIDEuNDY1LS43NzIgMi4yMzgtMi4yMzUgMi4" .
      "yMzhoLTIuNTYxYy0xLjQ2MyAwLTIuMjM1LS43NzMtMi4yMzUtMi4yMzh6bS0xOS4yNzMgMjEuMzIzYy4zMjUuMDgyLjczMi4yMDQgMS4yMTkuMjA0IDEuMjYgMCAyLjM5OC0xLjAxOCAyLjg4Ni0yL" .
      "jIzOGwuNDA2LS45NzctNy43NjMtMTcuOTQ1Yy0uNjktMS41NDcgMC0yLjYwNSAxLjcwNy0yLjYwNWgzLjE3MWMxLjM0MSAwIDIuMTU0LjYxIDIuNTYxIDEuODMxbDIuNDc5IDcuODEzYy4zNjUgMS4" .
      "xNC43NzIgMy4yNTUuNzcyIDMuMjU1aC4wODFzLjQwNy0xLjk5My42OTEtMy4xMzNsMi4xMTQtNy44NTRjLjM2NS0xLjMwMiAxLjE3OC0xLjkxMiAyLjUyLTEuOTEyaDIuOTY2YzEuNjI2IDAgMi4zN" .
      "TggMS4wMTggMS43ODkgMi41NjRsLTcuNjQxIDIwLjYzMWMtMS43NDggNC42NzktNS40MDYgNi4zMDctOC43MzkgNi4zMDctMS4wOTcgMC0yLjExMy0uMjQ0LTIuOTI2LS41MjktMS4zNDEtLjQ0Ny0" .
      "xLjY2Ni0xLjYyNy0xLjA5Ny0yLjkyOWwuNTI4LTEuMThjLjU2OS0xLjI2MiAxLjQyMy0xLjQ2NSAyLjI3Ni0xLjMwM3ptLTEwLjg4OC0xMS4wNjhjMCAxLjcwOC0xLjU0NSAzLjcwMy0zLjU3NyAzL" .
      "jcwMy0xLjMgMC0xLjk5Mi0uODE0LTEuOTkyLTEuNzkxIDAtMS45MTIgMi43NjQtMi40ODIgNC45MTgtMi40ODJoLjY1MXptLTIuMzk4LTEyLjk4MWMtMy4wMDggMC01LjQ0Ny44OTUtNy4wMzEgMS4" .
      "2MjgtMS4yNi42NS0xLjUwNCAxLjc0OS0uODk1IDMuMDFsLjQ4OCAxLjAxOGMuNjUgMS4zMDIgMS42NjYgMS41NDYgMy4wMDcuOTc3IDEuMDU4LS40ODkgMi40MzktLjkzNiAzLjc4LS45MzYgMS41N" .
      "DUgMCAyLjk2Ny41NjkgMi45NjcgMi4zMnYuMzY1aC0uNjFjLTUuMTYxIDAtMTEuODI3IDEuNTQ3LTExLjgyNyA3LjE2MiAwIDMuNjIyIDIuODA1IDYuMzg5IDYuODI5IDYuMzg5IDQuMTg2IDAgNi4" .
      "xNzctMy4zMzcgNi4xNzctMy4zMzdoLjA4MXMtLjA0LjE2My0uMDQuNDA3di4xNjNjMCAxLjU0Ni43NzIgMi4yNzkgMi4yMzYgMi4yNzloMS45NWMxLjQ2NCAwIDIuMjM1LS43NzMgMi4yMzUtMi4yM" .
      "zh2LTEwLjY2MmMwLTUuMzMtMy41NzYtOC41NDUtOS4zNDctOC41NDV6bS0yMy4zMDEgMTYuMTE0Yy0yLjQzOSAwLTQuMTA1LTIuMDM1LTQuMTA1LTUuMTY4IDAtMy4yMTUgMS45MS01LjAwNSA0LjE" .
      "wNS01LjAwNSAyLjc2MyAwIDQuMTA0IDIuNDgyIDQuMTA0IDUuMDA1IDAgMy42MjEtMS45OTEgNS4xNjgtNC4xMDQgNS4xNjh6bTguNjk3LTIzLjc2NWgtMi41NjFjLTEuNDYyIDAtMi4yMzUuNzc0L" .
      "TIuMjM1IDIuMjM5djYuNTUxYzAgLjUyOS4wNDEuOTM2LjA0MS45MzZoLS4wODJzLTEuMjYtMi4wNzUtNS40ODYtMi4wNzVjLTUuNTY5IDAtOS41NTEgNC4zMTMtOS41NTEgMTAuOTQ2IDAgNi41MTE" .
      "gMy43MzkgMTAuOTg3IDkuNDI5IDEwLjk4NyA0LjMwOCAwIDYuMDU2LTMuMDUyIDYuMDU2LTMuMDUyaC4wODFzLS4wNC4yMDQtLjA0LjI4NXYuMjQ0YzAgMS4zNDMuNzcyIDIuMDM1IDIuMjM1IDIuM" .
      "DM1aDIuMTEzYzEuNDYzIDAgMi4yMzYtLjc3MyAyLjIzNi0yLjIzOHYtMjQuNjE5YzAtMS40NjUtLjc3My0yLjIzOS0yLjIzNi0yLjIzOXptLTI5LjM2OCAzLjI1NnYtMS4wMTdjMC0xLjQ2NS43NzI" .
      "tMi4yMzkgMi4yMzUtMi4yMzloMi4zOThjMS40NjMgMCAyLjIzNi43NzQgMi4yMzYgMi4yMzl2MS4wMTdjMCAxLjQ2NS0uNzczIDIuMjM4LTIuMjM2IDIuMjM4aC0yLjM5OGMtMS40NjMgMC0yLjIzN" .
      "S0uNzczLTIuMjM1LTIuMjM4em0tLjA4MiA3LjEyMWMwLTEuNDY1Ljc3My0yLjIzOCAyLjIzNi0yLjIzOGgyLjU2YzEuNDY0IDAgMi4yMzYuNzczIDIuMjM2IDIuMjM4djE2LjQ4MWMwIDEuNDY1LS4" .
      "3NzIgMi4yMzgtMi4yMzYgMi4yMzhoLTIuNTZjLTEuNDYzIDAtMi4yMzYtLjc3My0yLjIzNi0yLjIzOHptLTEyLjAxOC04LjEzOGMwLTEuNDY1Ljc3Mi0yLjIzOSAyLjIzNi0yLjIzOWgyLjU2YzEuN" .
      "DYzIDAgMi4yMzYuNzc0IDIuMjM2IDIuMjM5djE4Ljc5OWMwIDEuNTg4LjUyOCAxLjk5NSAxLjE3OCAyLjA3Ni45NzUuMTIyIDEuNjI2LjY5MiAxLjYyNiAxLjkxM3YxLjk5M2MwIDEuMzg0LS42NTE" .
      "gMi4yOC0yLjI3NiAyLjI4LTMuNjE3IDAtNy41Ni0uODk2LTcuNTYtNy45MzZ6bS0xNC4xNjggMjEuNDg1Yy0yLjU2IDAtNC43NTUtMS45MTItNC43NTUtNS4wNDYgMC0zLjE3NCAyLjE5NS01LjE2O" .
      "CA0Ljc1NS01LjE2OCAyLjU2MSAwIDQuNzU2IDEuOTk0IDQuNzU2IDUuMTY4IDAgMy4xMzQtMi4xOTUgNS4wNDYtNC43NTYgNS4wNDZ6bS0uMDQtMTYuMDczYy02LjU0NCAwLTExLjgyOCA0LjQzNi0" .
      "xMS44MjggMTEuMDI3IDAgNi41NTIgNS4yODQgMTAuOTA2IDExLjg2OCAxMC45MDZzMTEuODY4LTQuMzU0IDExLjg2OC0xMC45MDZjMC02LjU5MS01LjI4NC0xMS4wMjctMTEuOTA4LTExLjAyN3ptL" .
      "TM0Ljk3LTUuNDEyYzAtMS40NjUuNzcyLTIuMjM5IDIuMjM1LTIuMjM5aDIuNTYxYzEuNDYzIDAgMi4yMzUuNzc0IDIuMjM1IDIuMjM5djYuOTU4YzAgMS4wNTgtLjA4MSAxLjc5MS0uMDgxIDEuNzk" .
      "xaC4wODFjMS4xNzktMi4wNzYgMy42MTctMy4zMzcgNi4zODEtMy4zMzcgNC4yMjcgMCA3LjUxOSAxLjk1MyA3LjUxOSA3Ljg1M3YxMS4zNTRjMCAxLjQ2NS0uNzcyIDIuMjM4LTIuMjM1IDIuMjM4a" .
      "C0yLjU2MWMtMS40NjMgMC0yLjIzNS0uNzczLTIuMjM1LTIuMjM4di0xMC4wNTJjMC0xLjk1My0uNzcyLTIuNzY2LTIuMzE3LTIuNzY2LTMuMDQ4IDAtNC41NTIgMi40LTQuNTUyIDUuNjE1djcuMjA" .
      "zYzAgMS40NjUtLjc3MiAyLjIzOC0yLjIzNSAyLjIzOGgtMi41NjFjLTEuNDYzIDAtMi4yMzUtLjc3My0yLjIzNS0yLjIzOHoiLz48cGF0aCBkPSJtMzAyLjI1OCA1Ni41NzdjLS4wODYtLjM1NS0uM" .
      "i0uNjQ4LS4zNS0uOTc4LS4xNDUtLjMxNy0uMjYyLS42MjMtLjQ5LS44OTQtLjM4OC0uNDY0LS42NTgtMS4wNDgtMS4xMDEtMS40NjUtLjA0NS0uMTAxLS4xMTEtLjE5Mi0uMi0uMjY2LS4zNTItLjI" .
      "5Mi0uNzAxLS40ODMtMS4xMDItLjY0NS0uMTcxLS4xNTgtLjMyNi0uMzAyLS41ODktLjM3LS4xOTgtLjA1Mi0uNDAzLS4wODItLjYwOS0uMTA5LS4xMTgtLjAzNC0uMjM5LS4wNTUtLjM2NC0uMDY2L" .
      "S4xMjEtLjAyNy0uMjQxLS4wNS0uMzYtLjA2OC0uMTI3LS4wNjktLjI3OC0uMTEzLS40NTctLjExMy0uMDg5IDAtLjE4NC0uMDA0LS4yOC0uMDA4LS42MTctLjAzMi0xLjIwNi0uMTQyLTEuODI4LS4" .
      "wMDktLjY4OS4xNDctMS4yNzguNDM2LTEuOTI1Ljc0MS0uNzEyLjMzNS0xLjI2Mi44NS0xLjg4IDEuMzM0LS4zMi4yNS0uNTU1LjQ3LS44MTcuNzc4LS4yMDcuMjQzLS40NDEuMzUxLS43MDEuNTI5L" .
      "TEuMjcuODcxLTEuODM5IDIuMzU4LTIuMzI0IDMuNzU1LS4yNjcuNzY5LS4zNjEgMS41OTktLjM4MiAyLjQxLS4wMDcuMjk0LS4wMDYuNjQ4IDAgLjc3OS4wMjYuNDUzLjE3My44MjEuMzMzIDEuMjM" .
      "5LjE1Ny40MDcuMjY5Ljc0OC41MzEgMS4xMDYuMTQyLjE5NS4yNzEuMzQ5LjQ1NC41MDktLjA0NC0uMDM4LS4yNDEtLjI0Ny0uMDU5LS4wMjEuMTEuMTM3LjI0My4yNDYuMzg3LjM0Ny42MjMuNDM4I" .
      "DEuMTg1Ljg4MyAxLjkzMy45OS41Ny4yOTUgMS4xMzYuNTA1IDEuNzk5LjU1NC42NDEuMDQ3IDEuNTAxLS4wMzIgMi4wOTYtLjI3NC4yNzUtLjExMS41NTgtLjIzMy44MDItLjQwNC4yMjktLjE2MS4" .
      "0NjUtLjE0MS43Ni0uMjQ0LjQyOC0uMTQ5LjgzMy0uNDk0IDEuMDgyLS44OTIuMjQtLjI1NS4zOTctLjYzNS4zOTctMS4wNjYgMC0uNzY3LS40ODctMS4zODktMS4wODktMS4zODktLjIyNSAwLS40M" .
      "zQuMDg3LS42MDcuMjM2LS4xMDQuMDQxLS4yMDUuMDg4LS4yOTkuMTQ4LS4wMzMuMDItLjI1OC4xODQtLjI3OS4yMDUtLjE2OS4xMDItLjMzMS4xMzYtLjU5OS4yNzItLjEwMS4wNTEtLjExNS4wNjM" .
      "tLjA0Mi4wMzgtLjA2My4wMjYtLjEyNy4wNDgtLjE5My4wNjYtLjExNy4wMzUtLjI0LjA2MS0uMzU5LjA4OS0uMzI3LjA3Ny0uNDkyLjExNS0uODEuMTMxLS4yMjguMDExLS40NTkuMDM3LS42NjIuM" .
      "DQtLjA4MS0uMDAyLS4xNjItLjAwNC0uMjQ0LS4wMDQtLjA0NC0uMDA2LS4xMDMtLjAxNC0uMTkxLS4wMjUtLjAyNS0uMDAyLS4wODUtLjAwMy0uMTI3LS4wMDItLjA4LS4wMi0uMTYxLS4wMzktLjI" .
      "0NS0uMDQtLjAyMy0uMDAxLS4wMzktLjAwMi0uMDUyLS4wMDMtLjA3NS0uMDE0LS4xODctLjAzNS0uMjA2LS4wMzktLjAyMi0uMDA1LS4xMzItLjAzLS4yMjQtLjA1MS0uMDc3LS4wNTEtLjI4OC0uM" .
      "TU3LS4zMzEtLjE4Mi0uMjI1LS4xMzQtLjIwNi0uMTE0LS4zNDctLjMxNS0uMTU2LS4yMjQtLjM4My0uNDM5LS40OTUtLjYyNi0uMDAzLS4wMDUtLjAwMy0uMDA3LS4wMDYtLjAxMi0uMDA4LS4wODY" .
      "tLjAxNy0uMTcyLS4wMzItLjI1OC0uMDAxLS4wMTktLjAwMy0uMDQtLjAwNS0uMDcxLS4wNDEtLjMzNS0uMTEyLS41MTEtLjEyNS0uNzYxLS4wMTQtLjI1Ny0uMDAzLS41MDQuMDQyLS43NDYuMDI0L" .
      "S4xMjcuMDQ4LS4yNTQuMDcxLS4zODEuMDA5LS4wNy4wMjEtLjEzOC4wMzctLjIwNi0uMDc5LjE1LS4wNjEuMDk5LjA1NS0uMTUzLjAyOC0uMDguMDUyLS4xNTUuMDY3LS4yNC4wMTQtLjA2My4wMTU" .
      "tLjEyNi4wMi0uMTg5LjAyNy0uMDUzLjA0NC0uMTA4LjA1Ny0uMTY1LjAwNy0uMDE1LjAxOC0uMDM2LjAzMi0uMDYzLjAxMy0uMDI4LjAxOC0uMDUxLjAyOS0uMDc3LjE1OC0uMjI4LjI3LS40OTYuM" .
      "zk4LS43MzEuMjItLjQwNS40NDUtLjc2OC43NC0xLjA4MS4zNTgtLjM3OS43NS0uNjQ4IDEuMTUxLS45NzQuMzE3LS4yNTcuNTc3LS41NjEuOTIxLS43ODIuMjI3LS4xNDYuNDYyLS4zLjY0My0uNTA" .
      "yLjAxNi0uMDE4LjA3Ny0uMTIyLjEyOS0uMTk0LjA0MS0uMDMyLjA4Mi0uMDY0LjEyMy0uMDk2LjM3LS4yNjkuNzUyLS41MTggMS4yMTUtLjY2Ni42NTItLjIxMSAxLjI4Mi0uMzA4IDEuOTI5LS4yN" .
      "jYuMDg4LjAxNS4xNzcuMDI2LjI2NC4wNDcuMDIyLjAwNi4wNDIuMDA2LjA2My4wMS4wMzUuMDEuMDU1LjAxNS4wMzQuMDA1LjAyMS4wMDMuMDQzLjAwNy4wNjQuMDA5LjA3LjAxNi4xNC4wMjMuMjE" .
      "uMDE4LjAxOC4wMDQuMDM1LjAwNC4wNTIuMDA3LjEyOS4xMTYuMjU4LjIxNy40NTEuMjkzLjE4NC4wNzMuMzU4LjEyMS41MTcuMjUzLjEyNC4xMDIuMjY1LjE3NS40MTEuMjIuMTU3LjEwOS4zMDcuM" .
      "jI2LjQ2MS40MTYuMTcyLjIxMy4zODkuNDIxLjUxOC42NjMuMTQxLjI2Ni4yNC41NjEuNDA4LjgxMy4xNS4yMjQuMjkxLjQwNi4zODQuNjY1LjE5OS41NTkuMzEzIDEuMTY4LjMxNyAxLjc2LjAwNS4" .
      "1NTQtLjAzMyAxLjE3OC0uMTk5IDEuNzA1LS4wMjguMDg3LS4wMTguMTcuMDA3LjI0OC0uMDA3LjQyOC0uMDgxLjkwNy0uMjg4IDEuMjgtLjEwOS4xOTUtLjIxNC4zOTEtLjMyOS41ODUtLjEyMi4yM" .
      "DctLjE3NC40NC0uMjc5LjY1NC0uMDkuMTg0LS4xNTYuMzkyLS4yOTYuNTQ0LS4xNTkuMTcyLS4zMTEuMzQ0LS40NS41MzMtLjIyNS4zMDctLjQ0Mi42OTctLjgxNC44My0uMjYzLjA5My0uMTgyLjQ" .
      "4MS4wNTQuNDczLjA1Ni4wMTguMTE5LjAxMi4xODQtLjAzNS4wMTktLjAxMy4wNDEtLjAyMi4wNjItLjAzMy4wNTEtLjAxOC4xMDItLjAzOS4xNDgtLjA3LjAxMS0uMDA4LjAyLS4wMTkuMDMxLS4wM" .
      "jguMDIyLS4wMTIuMDQ2LS4wMjIuMDY2LS4wMzcuMTE2LS4wOS4xOTUtLjIxNy4yOTgtLjMyMS4yMTEtLjIxMS4zNTMtLjQ0Ni41MzgtLjY3Ny4zMDMtLjM3Ni42MzgtLjc2NC44NzctMS4xODIuMTM" .
      "0LS4yMzUuMjc1LS40NDQuMzgxLS42OTUuMTE0LS4yNzEuMjQxLS41MzIuNDA3LS43NzQuMTM4LS4yMDQuMjMxLS40NS4zMDMtLjY4OC4wNDEtLjEzNS4wNzgtLjI3NS4xMS0uNDE2LjA3Ni0uMTE1L" .
      "jEzNC0uMjM3LjE2LS40MDIuMDE5LS4xMTQuMDMxLS4yMjEuMDI1LS4zMjQuMDI1LS4xMi4wNDUtLjIzOC4wNTMtLjM0Mi4wMjYtLjMwOS4wNjEtLjYyMS4wNjEtLjkzMiAwLS43MjguMDYyLTEuNDg" .
      "tLjExLTIuMTg5eiIvPjxwYXRoIGQ9Im0yODcuOTYzIDUxLjI3MmMtLjAyOC0uMTM4LS4xMDMtLjI1OC0uMTM5LS4zOTMtLjA3NC0uMjgxLS4xMjEtLjU2MS0uMjI3LS44MzQtLjEwNC0uMjY4LS4yN" .
      "S0uNTY5LS40MDYtLjgxMy0uMTUtLjIzNC0uMzktLjQzNi0uNTgzLS42MzUtLjE5Ni0uMjAzLS4zNS0uNDM3LS41NjUtLjYyNC0uMTk3LS4xNzItLjM2OS0uMzY0LS41ODYtLjUxNy0uMjYzLS4xODg" .
      "tLjQ0OS0uNDMzLS42NzktLjY1OC0uMDc0LS4wNzItLjE5LS4xMzItLjI1Ni0uMTk3LS4wNDEtLjA0MS0uMDgtLjA5MS0uMTE5LS4xNDEtLjA4Ni0uMjk4LS40MTQtLjUxOC0uNzA2LS41NzgtLjQwM" .
      "S0uMDg0LS44MS4wNzQtLjk2Ni40NjUtLjE2LjQwMy0uMjMuODM2LS4yOTggMS4yNjItLjA3NS40NjYtLjAyLjg2My4zMTkgMS4yMTEuMTY1LjE3LjM1OS4yOTkuNTI4LjQ2LjE4NC4xNzUuMzU0LjM" .
      "2NS41MzkuNTQuMjkuMjcyLjYwNC41NTQuOTg5LjY2OC41MjQuMTU3IDEuMDA3LjI5OSAxLjQ4OS41NjQuMjA0LjExMS40NDMuMTc4LjYzNS4zMS4xMDIuMDY5LjIwMS4xNTMuMy4yMjguMDcuMDUzL" .
      "jE5MS4xMDkuMjI0LjE5NC4wMzguMDk2LjExNi4xNDIuMTk4LjE1NS4xMi4wNDMuMjYzLjAwNi4yOTItLjE1NS4wMjktLjE2OS4wNTItLjM0Mi4wMTctLjUxMnoiLz48cGF0aCBkPSJtMjk2LjY3NSA" .
      "zOS4zMTFjLS4zODYtLjQtLjg1OC0uMzQ5LTEuMzUtLjIwNi0uNDIxLjEyNC0uODc4LjI4Mi0xLjExNi42NzUtLjI1LjQxLS4yNDcuODMyLS4yOTcgMS4yOTItLjA0Ni40MjctLjA2Ni44NDItLjA2N" .
      "iAxLjI3MiAwIC41LS4wMzYuOTg4LjA1NyAxLjQ4MS4wODIuNDQuMjY3Ljg0LjQwNSAxLjI1OS4wODguMjYzLjA3My41NDQuMTcxLjgwOC4wNzkuMjE3LjExOS40MDYuMTc1LjYzMS4wMjEuMDgzLjA" .
      "1Ny4xNDUuMTAyLjE5Ny4wODcuMjYxLjA2Ni41NzUuMDQ4LjgzNi0uMDExLjE2NC0uMDIuMzE5LS4wNDUuNDgyLS4wMTkuMTI3LS4wMjEuMjA4LjA0NC4zMjIuMDY3LjExOS4yMTMuMTQ0LjMyOC4wO" .
      "DcuMjE2LS4xMDYuNDEtLjM4My41MjctLjU4OC4xODItLjMxOS4zNTMtLjY4Mi40NDQtMS4wNC4xOTgtLjc3My40NTMtMS41NDIuNTU4LTIuMzM2LjA4Ni0uNjU0LjAyNS0xLjI5OC4wODUtMS45NTE" .
      "uMDMtLjMyOC4wNC0uNjYuMDYxLS45ODkuMDE4LS4yODEuMDY0LS41NjEuMDg3LS44NDIgMC0uMDAxIDAtLjAwMSAwLS4wMDEuMDEzLS4wMjMuMDI4LS4wNDMuMDM3LS4wNjkuMTUtLjM5My4wMzgtM" .
      "S4wMTUtLjI1NS0xLjMyeiIvPjxwYXRoIGQ9Im0zMDguMzAzIDQ4LjEzOWMtLjE2LS4yMTMtLjM2My0uMzYxLS41Ni0uNTIyLS4wNjYtLjE0NS0uMTgyLS4yNTItLjMyOS0uMjg4LS4wMDgtLjAwNS0" .
      "uMDE3LS4wMDktLjAyNS0uMDE1LS4wMTItLjAwNy0uMDItLjAxOC0uMDMzLS4wMjQtLjAxMi0uMDA3LS4wNDgtLjAyOC0uMDQ4LS4wMjguMDI0LjAyLS4wMzQtLjA0My0uMDMtLjAzOS0uMTg0LS4yM" .
      "jktLjQ1Mi0uMTc0LS42OTQtLjA5Mi0uMTIxLjA0MS0uMjUuMDY1LS4zNjUuMTIxLS4wOTIuMDQ1LS4xNTkuMTMxLS4yNC4xODMtLjA5My4wNi0uMTg4LjEwOS0uMjc4LjE3NC0uMDc1LjA1NC0uMTM" .
      "uMTI0LS4xOC4xOTgtLjA2OC4wNi0uMTM2LjEyLS4yLjE5MS0uMDY0LjA3LS4xMy4xMzktLjE4NC4yMTctLjA2Ny4wOTUtLjEwNS4yMDctLjE3Mi4zMDMtLjExNi4xNjgtLjIwNy4zMzItLjI2Ni41M" .
      "y0uMDQxLjEzOS0uMDc3LjI4MS0uMTQ4LjQwMy0uMDAyLjAwMi0uMDAzLjAwNS0uMDA0LjAwOC0uMDI1LjA0MS0uMDU0LjA3OS0uMDg5LjExNC0uMDU3LjA1Ny0uMDc1LjEyMy0uMDY3LjE4NS0uMDQ" .
      "1LjA3OS0uMDkxLjE1Ny0uMTI5LjIzOC0uMDQ3LjEwMS0uMDk4LjE1Ny0uMTY1LjI0OC0uMTQ3LjItLjM4NS40NS0uNjQuNTA3LS4wMzEuMDA2LS4wNTguMDE4LS4wOC4wMzMtLjE5My4wNDMtLjMwM" .
      "y4zMjMtLjA4Mi40MzIuMTc0LjA4NS4zMjkuMTguNTI3LjE5NS4xNzcuMDEzLjM1OC0uMDAyLjUzNi4wMDEuMzQ0LjAwOS42NTQtLjA4My45NzEtLjIwNy4zMy0uMTI5LjY3MS0uMTk2Ljk4NC0uMzY" .
      "0LjI5OS0uMTYuNTY3LS4zOTEuODM0LS42LjI3Mi0uMjE0LjU1Ni0uMzU3Ljg3OC0uNDg2LjMwNS0uMTIyLjU3OS0uMzA0LjU4OS0uNjY0LjAwOS0uMzE5LS4xMjMtLjcwMS0uMzExLS45NTJ6Ii8+P" .
      "HBhdGggZD0ibTMxNC41MjYgNTguNTgzYy0uMTI5LS4yMDgtLjE3NS0uMzE2LS40MjMtLjM5MS0uMzY4LS4xMTItLjc2MS0uMTUxLTEuMTQxLS4yMDMtLjYzOC0uMDg3LTEuMzI1LS4xMTYtMS45NTc" .
      "uMDA4LS4zNTkuMDcxLS42NzguMDgxLTEuMDA4LjI1NS0uMzIyLjE2Ny0uNzA2LjI5My0xLjA2MS4zNjYtLjgxMS4xNjYtMS42MS4yNjktMi40MzguMjY5LS4xOCAwLS4yNTMuMTY0LS4yMjIuMzAxL" .
      "jAwMy4wMzYuMDEzLjA3NC4wNDIuMTEzLjA4My4xMTUuMTI1LjI0Mi4yMjUuMzQ3LjEwNi4xMTIuMjM5LjE4OS4zNTIuMjg5LjIxOS4xOTUuMzM5LjM3Ni42MjIuNDgzLjY4Mi4yNiAxLjM1OC40Njk" .
      "gMi4wODEuNTc1LjcwMy4xMDMgMS40MzYuMTIxIDIuMTQ3LjA3Ny43MzQtLjA0NiAxLjQ2NS0uMDE3IDIuMTk1LjAzNC41OTEuMDQuOTk4LS4yMzYgMS4wNTEtLjgzLjA1NC0uNjEyLS4xNDUtMS4xN" .
      "zgtLjQ2NS0xLjY5M3oiLz48cGF0aCBkPSJtMzA3LjQ3NSA2OS41MzRjLS4xODItLjMzMy0uNDg1LS42MjQtLjc2Ny0uODczLS4xMTktLjEwNi0uMjY0LS4xOTQtLjQwMi0uMjctLjA4OC0uMDQ5LS4" .
      "yMDMtLjE1MS0uMzItLjIyOC0uMzE0LS4yMDUtLjY3NS0uMzA3LTEuMDQxLS4zNi0uMTU3LS4wMjItLjI2NS0uMDQtLjQwNi0uMTExLS4yLS4xLS40MDUtLjE3OC0uNjA4LS4yNjMtLjE2OS0uMDcxL" .
      "S4zMDUtLjI0NS0uNDU2LS4zNTQtLjEzMi0uMDk1LS4zLS4xODQtLjQ2Mi0uMjQ5LS4xMjktLjEzNS0uNDE4LS4wODQtLjQxNy4xNTcuMDAyLjI2OS4wNjIuNTI1LjA3OC43OTEuMDE1LjI1Mi4wNzY" .
      "uNTExLjE0NC43NTQuMDY2LjIzNy4xNzMuNDcyLjI4Mi42OTIuMTExLjIyNi4yNjguMzc3LjQxOS41NzMuMzMuNDMzLjY3OC44NjUgMS4wNTQgMS4yNi4xODUuMTkzLjM4OC4zOTYuNTkzLjU2Ny4wN" .
      "zguMDY2LjE3NC4xMDQuMjYyLjE1NS4wNDIuMTg5LjIwMy4yODMuMzk3LjQzMS4yODcuMjIuNjE0LjM0Ni45NjcuMTg1LjMwNy0uMTM5LjUxNy0uMzY5LjU4Ny0uNjk4LjAzNy0uMTczLjEwMy0uMzI" .
      "xLjE2OC0uNDg2LjA3LS4xNzcuMDk4LS4zNjkuMTI4LS41NTcuMDcxLS40MjguMDA2LS43MzgtLjItMS4xMTZ6Ii8+PHBhdGggZD0ibTI5Ni4zNjIgNzIuOTE5Yy0uMDU0LS4zNjYtLjA3LS43NTctL" .
      "jE4Ni0xLjExLS4xMTMtLjM1LS4yNzYtLjcwOC0uMzQ4LTEuMDY5LS4wNzEtLjM1OC0uMTM5LS43MDktLjE1Ni0xLjA3My0uMDE1LS4zMTEtLjAxNy0uNjYxLS4xODktLjkzNC0uMTIyLS4xOTEtLjM" .
      "3NS0uMTExLS40MzguMDQ4LS4wMTUuMDExLS4wMzEuMDE4LS4wNDUuMDM1LS4xNjMuMTk1LS4zMzcuMzU3LS40NjUuNTc2LS4xNjIuMjc1LS4yMzguNTYxLS4zNDEuODU5LS4yNDQuNzA2LS40NDcgM" .
      "S40MzctLjU2NyAyLjE3Ny0uMDkxLjU2Mi0uMDkxIDEuMTM5LS4xNTYgMS43MDYtLjA2OS41OTUtLjE2NSAxLjIxMi0uMTI4IDEuODE0LjAzNy42LjM5NCAxLjAwNCAxLjAyLjk3Ni4xMjgtLjAwNi4" .
      "yMS0uMDM0LjMyMi0uMDg2LjExMy0uMDUxLjIyNC0uMDczLjM0NS0uMTEyLjI5MS0uMDk0LjUxMy0uMjQ4Ljc0OS0uNDM4LjIxMy0uMTcyLjM1Mi0uMjguMzk1LS41NTkuMDUtLjMyNC4wOTItLjY0O" .
      "S4xMDQtLjk3NHMuMDcyLS42NDcuMDgxLS45NzNjLjAwOC0uMjg0LjA0NC0uNTgxLjAwMy0uODYzeiIvPjxwYXRoIGQ9Im0yODYuMjEzIDY4LjExM2MtLjAxMyAwLS4wMjUuMDAxLS4wMzguMDAxLS4" .
      "wNTMtLjAxOC0uMTExLS4wMTktLjE2OC4wMDUtLjQxOC4wMTQtLjgzLjA4OC0xLjIxOC4yNDgtLjIyNi4wOTQtLjQzOS4xOTQtLjY0MS4zMzMtLjE4Mi4xMjUtLjQuMjI2LS41NjYuMzcyLS4xNzUuM" .
      "TUzLS4yOTMuMzctLjQ1Ni41MzYtLjE3OS4xODMtLjM2MS4zNTgtLjU2NS41MTQtLjM3Ni4yODgtLjY2My42NS0uOTM1IDEuMDM2LS4yODYuNDA3LS4wODYuOTM5LjIyOCAxLjI2OC4yMzEuMjQxLjU" .
      "1OC4zNC44NzkuNDExLjAzLjAxNS4wNjYuMDI3LjEwNy4wMjcuMDUzLjAwMS4xLjAxNC4xNDcuMDI2LjAwOS4wMDIuMDE4LjAwNC4wMjYuMDA2LjAxNC4wMDMuMDI4LjAwNS4wNDEuMDA4LjA2NS4wM" .
      "jEuMTI5LjA0Ny4xOTUuMDcyLjE1Ni4wNTkuMjc4LjEzNC40NTUuMDg4LjA4Mi0uMDIyLjE1Mi0uMDczLjIyOC0uMTA5LjA3LS4wMzMuMTQtLjA2Mi4yMDYtLjEwMy4xNDItLjA4Ny4yNjgtLjIwNy4" .
      "zODYtLjMyNS4wNTUtLjA1NC4xLS4xMTYuMTU2LS4xNy4wNDgtLjA0Ny4xMDQtLjA4Ni4xNS0uMTM2LjExMS0uMTE5LjE3My0uMjc1LjI2Ni0uNDA3LjA4OS0uMTI3LjE4OC0uMjguMjU1LS40NC4yM" .
      "DktLjMyNS4yNTQtLjc1OC4zMS0xLjE0OC4wODktLjE2Mi4xNy0uMzMuMjUtLjQ4OS4xMTEtLjIyLjE5Ny0uNDQ4LjI4NC0uNjc5LjAzNy0uMDk3LjA5Ni0uMTguMTQtLjI3NC4wNDMtLjA5My4wNTY" .
      "tLjE4Ni4wNjEtLjI4NS4wOTgtLjEzNy4wMzktLjM4Mi0uMTgzLS4zODZ6Ii8+PHBhdGggZD0ibTI4My45NzIgNTkuOTExYy0uMDA0LS4wMzEtLjAxNC0uMDY0LS4wMzYtLjA5Ni0uMTQxLS4yLS4xO" .
      "TUtLjQ3NS0uNDE5LS42MDYtLjI1OC0uMTUtLjUxNy0uMjk5LS43OTItLjQyLS41NTMtLjI0My0xLjE2OC0uMzUxLTEuNzYzLS40MDktLjU1Ny0uMDU2LTEuMTU2LS4xMTctMS43MTQtLjA1Ny0uNjA" .
      "xLjA2Ni0xLjE5NC0uMDctMS43ODMtLjE1Ni0uNDk5LS4wNzMtMS4wMjEuMDA1LTEuMjQ2LjUxNC0uMDg4LjItLjA4NC41Mi0uMDcyLjczNy4wMTcuMzI4LjE0NC41OTMuMzE2Ljg2NC4xNDEuMjI0L" .
      "jIxOS41NTYuNDYzLjY5MS4zMDIuMTY3LjY0OC4xNDYuOTc3LjIwMy42MjYuMTExIDEuMjMxLjA2MyAxLjg2NS4wNjMuNDE4IDAgLjc3Mi0uMDEyIDEuMTY0LS4xNzQuMjkxLS4xMi41MjctLjMzMy4" .
      "4MDktLjQ2NC4yOTEtLjEzNi43NTctLjIwMSAxLjA3Ni0uMjA3LjE3NC0uMDAzLjM0NS0uMDE4LjUxOC0uMDI1LjE2LS4wMDUuMjk5LS4wMzUuNDU0LS4wNTMuMjE4LS4wMjQuMjc4LS4yNzYuMTgzL" .
      "S40MDV6Ii8+PC9nPjxwYXRoIGQ9Im0xMjUuNzIxIDQxLjU1MmMwIC42NzIuMzYgMS4wMDggMS4wMDggMS4wMDhoLjgxNmMuNjcyIDAgMS4wMDgtLjMzNiAxLjAwOC0uODY0di0uMzg0YzAtLjMzNi0" .
      "uMDQ4LS41MjgtLjA0OC0uNTI4aC4wNDhzLjk4NCAyLjA2NCAzLjc0NCAyLjA2NGMzLjE2OCAwIDUuNTkyLTIuNTIgNS41OTItNi40MzIgMC0zLjc2OC0yLjE2LTYuMzg0LTUuNC02LjM4NC0yLjY2N" .
      "CAwLTMuNzQ0IDEuOTY4LTMuNzQ0IDEuOTY4aC0uMDQ4cy4wNzItLjQzMi4wNzItMS4wMzJ2LTQuNDRjMC0uNjQ4LS4zMzYtMS4wMDgtMS4wMDgtMS4wMDhoLTEuMDMyYy0uNjQ4IDAtMS4wMDguMzY" .
      "tMS4wMDggMS4wMDh6bTIuOTUyLTUuMTEyYzAtMi42MTYgMS41MTItMy43OTIgMy4wOTYtMy43OTIgMS43NzYgMCAzLjAyNCAxLjUzNiAzLjAyNCAzLjg0IDAgMi40MjQtMS4zOTIgMy43OTItMy4wO" .
      "TYgMy43OTItMS45OTIgMC0zLjAyNC0xLjg0OC0zLjAyNC0zLjg0em0xMC41MTIgOC45NzYtLjIxNi41MjhjLS4yNjQuNTUyLS4xMiAxLjA4LjQ1NiAxLjI5Ni40MzIuMTY4IDEuMDMyLjM2IDEuNzA" .
      "0LjM2IDEuOCAwIDMuNTc2LS45ODQgNC40MTYtMy4yMTZsNS4wNC0xMi45MTJjLjI0LS42OTYtLjA3Mi0xLjE1Mi0uODE2LTEuMTUyaC0xLjJjLS42IDAtLjk2LjI2NC0xLjEyOC44NGwtMi4xNiA2L" .
      "jI2NGMtLjE5Mi42NDgtLjQwOCAxLjYwOC0uNDA4IDEuNjA4aC0uMDQ4cy0uMjQtMS4wMDgtLjQ1Ni0xLjY1NmwtMi4zMDQtNi4yNGMtLjE5Mi0uNTUyLS41MjgtLjgxNi0xLjEyOC0uODE2aC0xLjI" .
      "5NmMtLjc2OCAwLTEuMDguNDgtLjc2OCAxLjE3Nmw0LjY1NiAxMC44NzItLjQ1NiAxLjA4Yy0uMzYuODY0LTEuMDU2IDEuNjMyLTEuOTQ0IDEuNjMyLS4zNiAwLS42NDgtLjEyLS44NjQtLjIxNi0uN" .
      "DA4LS4wOTYtLjgxNiAwLTEuMDguNTUyem0tMTE2LjgwOC0zLjg2NGMwIC42NzIuMzM2IDEuMDA4Ljk4NCAxLjAwOGgxLjEwNGMuNjQ4IDAgLjk4NC0uMzM2Ljk4NC0xLjAwOHYtNC44NzJoMy40OGM" .
      "zLjE5MiAwIDUuNDI0LTIuMzA0IDUuNDI0LTUuNjE2cy0yLjIzMi01LjU0NC01LjQyNC01LjU0NGgtNS41NjhjLS42NDggMC0uOTg0LjM2LS45ODQgMS4wMDh6bTMuMDcyLTcuNTZ2LTUuODA4aDIuO" .
      "TUyYzEuNzc2IDAgMi44MDggMS4xMjggMi44MDggMi44OCAwIDEuNzc2LTEuMDMyIDIuOTI4LTIuODU2IDIuOTI4em05Ljk4NCAyLjQ0OGMwIDMuNzY4IDMgNi40MDggNi43MiA2LjQwOHM2Ljc0NC0" .
      "yLjY0IDYuNzQ0LTYuNDA4YzAtMy43NDQtMy4wMjQtNi40MDgtNi43NDQtNi40MDhzLTYuNzIgMi42NjQtNi43MiA2LjQwOHptMy4wOTYgMGMwLTIuMjU2IDEuNjMyLTMuODE2IDMuNjI0LTMuODE2c" .
      "zMuNjQ4IDEuNTYgMy42NDggMy44MTZjMCAyLjI4LTEuNjU2IDMuODE2LTMuNjQ4IDMuODE2cy0zLjYyNC0xLjUzNi0zLjYyNC0zLjgxNnptMTQuNzEyIDUuMjhjLjE2OC41NzYuNTI4Ljg0IDEuMTA" .
      "0Ljg0aDEuNjU2Yy42IDAgLjk2LS4yODggMS4xMDQtLjg2NGwxLjg0OC02LjEyYy4xOTItLjY0OC4zMTItMS4zMi4zMTItMS4zMmguMDQ4cy4wOTYuNjcyLjI4OCAxLjMybDEuODQ4IDYuMTJjLjE0N" .
      "C41NzYuNTI4Ljg2NCAxLjEyOC44NjRoMS42MDhjLjU3NiAwIC45Ni0uMjY0IDEuMTI4LS44NGwzLjE0NC0xMC4yNzJjLjE5Mi0uNjcyLS4xMi0xLjEyOC0uODQtMS4xMjhoLTEuMTA0Yy0uNiAwLS4" .
      "5Ni4yODgtMS4xMDQuODg4bC0xLjk0NCA3LjE1MmMtLjE2OC42NDgtLjI2NCAxLjI5Ni0uMjY0IDEuMjk2aC0uMDQ4cy0uMDcyLS42NDgtLjI2NC0xLjI5NmwtMi4wNC03LjE1MmMtLjE0NC0uNTc2L" .
      "S41MDQtLjg2NC0xLjEwNC0uODY0aC0uOTEyYy0uNiAwLS45ODQuMjg4LTEuMTA0Ljg2NGwtMi4wNjQgNy4xNTJjLS4xNjguNjQ4LS4yNjQgMS4yOTYtLjI2NCAxLjI5NmgtLjA0OHMtLjA5Ni0uNjQ" .
      "4LS4yNC0xLjI5NmwtMS45NjgtNy4xNTJjLS4xMi0uNi0uNDgtLjg4OC0xLjA4LS44ODhoLTEuMTUyYy0uNjk2IDAtMS4wMzIuNDU2LS44NCAxLjEyOHptMTYuNDQtNS4yOGMwIDMuNDggMi41MiA2L" .
      "jQwOCA2LjU3NiA2LjQwOCAxLjkyIDAgMy4zNi0uNzIgNC4xNTItMS4yMjQuNTI4LS4zMTIuNjI0LS43OTIuMzM2LTEuMzQ0bC0uMjg4LS40OGMtLjMxMi0uNTUyLS43NDQtLjYyNC0xLjM0NC0uMzM" .
      "2LS41NzYuMzYtMS41MTIuNzkyLTIuNjQuNzkyLTEuODQ4IDAtMy40OC0xLjE1Mi0zLjY3Mi0zLjM2aDcuNDY0Yy42IDAgMS4wNTYtLjUwNCAxLjA1Ni0xLjAzMiAwLTMuMzYtMS45NDQtNS44MzItN" .
      "S40MjQtNS44MzItMy42NzIgMC02LjIxNiAyLjY0LTYuMjE2IDYuNDA4em0zLjE5Mi0xLjM5MmMuMjg4LTEuNjMyIDEuMzkyLTIuNzEyIDIuOTUyLTIuNzEyIDEuMzkyIDAgMi40MjQgMS4wMDggMi4" .
      "0NzIgMi43MTJ6bTEwLjY1NiA2LjUwNGMwIC42NzIuMzM2IDEuMDA4Ljk4NCAxLjAwOGgxLjA1NmMuNjQ4IDAgLjk4NC0uMzM2Ljk4NC0xLjAwOHYtMy45MzZjMC0yLjIzMiAxLjA4LTQuNDQgMy4zM" .
      "TItNC40NC42NDggMCAxLjAzMi0uMzYgMS4wMzItMS4wMDh2LS45MzZjMC0uNjcyLS4yNC0xLjAwOC0uOTEyLTEuMDA4LTEuNzI4IDAtMy4wNzIgMS42MzItMy41NTIgMy4wOTZoLS4wNDhzLjA3Mi0" .
      "uMzg0LjA3Mi0uODR2LTEuMTUyYzAtLjY0OC0uMzYtMS4wMDgtMS4wMDgtMS4wMDhoLS45MzZjLS42NDggMC0uOTg0LjM2LS45ODQgMS4wMDh6bTcuODcyLTUuMTEyYzAgMy40OCAyLjUyIDYuNDA4I" .
      "DYuNTc2IDYuNDA4IDEuOTIgMCAzLjM2LS43MiA0LjE1Mi0xLjIyNC41MjgtLjMxMi42MjQtLjc5Mi4zMzYtMS4zNDRsLS4yODgtLjQ4Yy0uMzEyLS41NTItLjc0NC0uNjI0LTEuMzQ0LS4zMzYtLjU" .
      "3Ni4zNi0xLjUxMi43OTItMi42NC43OTItMS44NDggMC0zLjQ4LTEuMTUyLTMuNjcyLTMuMzZoNy40NjRjLjYgMCAxLjA1Ni0uNTA0IDEuMDU2LTEuMDMyIDAtMy4zNi0xLjk0NC01LjgzMi01LjQyN" .
      "C01LjgzMi0zLjY3MiAwLTYuMjE2IDIuNjQtNi4yMTYgNi40MDh6bTMuMTkyLTEuMzkyYy4yODgtMS42MzIgMS4zOTItMi43MTIgMi45NTItMi43MTIgMS4zOTIgMCAyLjQyNCAxLjAwOCAyLjQ3MiA" .
      "yLjcxMnptOS45MzYgMS4zOTJjMCAzLjc5MiAyLjEzNiA2LjQwOCA1LjQ0OCA2LjQwOCAyLjgwOCAwIDMuODQtMi4xMTIgMy44NC0yLjExMmguMDQ4cy0uMDQ4LjE5Mi0uMDQ4LjQzMnYuNDhjMCAuN" .
      "i4zMzYuOTEyLjk4NC45MTJoLjg4OGMuNjQ4IDAgLjk4NC0uMzM2Ljk4NC0xLjAwOHYtMTUuMDI0YzAtLjY0OC0uMzM2LTEuMDA4LS45ODQtMS4wMDhoLTEuMDU2Yy0uNjQ4IDAtLjk4NC4zNi0uOTg" .
      "0IDEuMDA4djQuNTZjMCAuMzg0LjA0OC42OTYuMDQ4LjY5NmgtLjA0OHMtLjg4OC0xLjc1Mi0zLjU3Ni0xLjc1MmMtMy4yNCAwLTUuNTQ0IDIuNTItNS41NDQgNi40MDh6bTMuMDcyIDBjMC0yLjQyN" .
      "CAxLjQxNi0zLjc5MiAzLjA3Mi0zLjc5MiAyLjA2NCAwIDMuMDQ4IDEuODcyIDMuMDQ4IDMuNzY4IDAgMi43MTItMS40ODggMy44NjQtMy4wNzIgMy44NjQtMS44IDAtMy4wNDgtMS41MTItMy4wNDg" .
      "tMy44NHoiIGZpbGw9IiNmZmYiLz48L2c+PC9zdmc+";
      return 'data:image/svg+xml;base64,' . $image;
    }

    /*----------------------*/
    public static function holidayMakerReadyImageSrc() {
      $image = "PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiBzdHlsZT0iaXNvbGF0aW9uOmlzb2xhdGUiIHZpZ" .
"XdCb3g9IjAgMCAzMzAgMTI0IiB3aWR0aD0iMzMwcHQiIGhlaWdodD0iMTI0cHQiPjxkZWZzPjxjbGlwUGF0aCBpZD0iX2NsaXBQYXRoX1pUemVoMkdyWkl3WGVpOW13SVZ3Sm85dVlsN2VTYjZPIj4" .
"8cmVjdCB3aWR0aD0iMzMwIiBoZWlnaHQ9IjEyNCIvPjwvY2xpcFBhdGg+PC9kZWZzPjxnIGNsaXAtcGF0aD0idXJsKCNfY2xpcFBhdGhfWlR6ZWgyR3JaSXdYZWk5bXdJVndKbzl1WWw3ZVNiNk8pI" .
"j48cGF0aCBkPSJNIDEzLjkxNSAwIEwgMzE2LjA4NSAwIEMgMzIzLjc2NSAwIDMzMCA2LjIzNSAzMzAgMTMuOTE1IEwgMzMwIDExMC4wODUgQyAzMzAgMTE3Ljc2NSAzMjMuNzY1IDEyNCAzMTYuMDg" .
"1IDEyNCBMIDEzLjkxNSAxMjQgQyA2LjIzNSAxMjQgMCAxMTcuNzY1IDAgMTEwLjA4NSBMIDAgMTMuOTE1IEMgMCA2LjIzNSA2LjIzNSAwIDEzLjkxNSAwIFoiIHN0eWxlPSJzdHJva2U6bm9uZTtma" .
"WxsOiNGNUExNEE7c3Ryb2tlLW1pdGVybGltaXQ6MTA7Ii8+PGc+PGc+PGc+PGc+PHBhdGggZD0iIE0gMjYxLjI0NiA0MS4yOTEgQyAyNjEuMjQ2IDM5LjgyNiAyNjIuMDE4IDM5LjA1NCAyNjMuNDg" .
"xIDM5LjA1NCBMIDI2NS43NTcgMzkuMDU0IEMgMjY3LjIyIDM5LjA1NCAyNjcuOTkyIDM5LjgyNiAyNjcuOTkyIDQxLjI5MSBMIDI2Ny45OTIgNDIuNTk0IEMgMjY3Ljk5MiA0My4yODUgMjY3LjkxM" .
"SA0My44OTYgMjY3LjkxMSA0My44OTYgTCAyNjcuOTkyIDQzLjg5NiBDIDI2OC42ODMgNDEuNzM5IDI3MC45MTkgMzguODUgMjczLjU2IDM4Ljg1IEMgMjc0Ljk4MyAzOC44NSAyNzUuNDcxIDM5LjY" .
"yMyAyNzUuNDcxIDQxLjA4OCBMIDI3NS40NzEgNDMuMzY3IEMgMjc1LjQ3MSA0NC44MzIgMjc0LjY5OSA0NS42MDUgMjczLjIzNSA0NS42MDUgQyAyNjkuOTAzIDQ1LjYwNSAyNjguMjc3IDQ4LjQ1M" .
"yAyNjguMjc3IDUxLjg3MSBMIDI2OC4yNzcgNTcuNzcyIEMgMjY4LjI3NyA1OS4yMzcgMjY3LjUwNSA2MC4wMSAyNjYuMDQyIDYwLjAxIEwgMjYzLjQ4MSA2MC4wMSBDIDI2Mi4wMTggNjAuMDEgMjY" .
"xLjI0NiA1OS4yMzcgMjYxLjI0NiA1Ny43NzIgTCAyNjEuMjQ2IDQxLjI5MSBaICBNIDI0NS41MjEgNDYuODY2IEMgMjQ1Ljg4NyA0NS4xOTggMjQ2Ljg2MyA0My43MzMgMjQ4Ljg5NCA0My43MzMgQ" .
"yAyNTAuNjgzIDQzLjczMyAyNTEuNzggNDUuMTU3IDI1MS43OCA0Ni44NjYgTCAyNDUuNTIxIDQ2Ljg2NiBaICBNIDI1OC43MyA0OC41NzUgQyAyNTguNzMgNDIuOTYgMjU1LjI3NSAzOC41NjUgMjQ" .
"5LjA1NyAzOC41NjUgQyAyNDIuMzEgMzguNTY1IDIzOC4xNjUgNDMuMzY3IDIzOC4xNjUgNDkuNTEyIEMgMjM4LjE2NSA1NS4wODYgMjQyLjE4OCA2MC40OTggMjQ5LjY2NyA2MC40OTggQyAyNTIuO" .
"Dc4IDYwLjQ5OCAyNTUuMzU3IDU5LjQ4MSAyNTYuODYxIDU4LjYyNyBDIDI1OC4wOCA1Ny45MzUgMjU4LjI4MyA1Ni44MzYgMjU3LjYzMyA1NS41NzQgTCAyNTcuMDY0IDU0LjUxNyBDIDI1Ni4zNzM" .
"gNTMuMjE0IDI1NS4zOTcgNTMuMDUyIDI1NC4wNTYgNTMuNjYyIEMgMjUzIDU0LjE5MSAyNTEuNjU5IDU0LjYzOSAyNTAuMjM2IDU0LjYzOSBDIDI0OCA1NC42MzkgMjQ1Ljg4NyA1My40NTkgMjQ1L" .
"jM1OCA1MC44OTUgTCAyNTYuMzczIDUwLjg5NSBDIDI1Ny43NTUgNTAuODk1IDI1OC43MyA0OS42NzQgMjU4LjczIDQ4LjU3NSBaICBNIDIxNy41NTUgMzMuMTUzIEMgMjE3LjU1NSAzMS42ODggMjE" .
"4LjMyNyAzMC45MTUgMjE5Ljc5IDMwLjkxNSBMIDIyMi4zNSAzMC45MTUgQyAyMjMuODE0IDMwLjkxNSAyMjQuNTg2IDMxLjY4OCAyMjQuNTg2IDMzLjE1MyBMIDIyNC41ODYgNDUuOTcxIEwgMjI2L" .
"jU3NyA0NS45NzEgTCAyMjkuODI5IDQwLjU1OSBDIDIzMC40MzkgMzkuNTAxIDIzMS4yNTEgMzkuMDU0IDIzMi40NyAzOS4wNTQgTCAyMzUuMTUzIDM5LjA1NCBDIDIzNi45ODIgMzkuMDU0IDIzNy4" .
"1OTIgNDAuMjMzIDIzNi42MTYgNDEuNzM5IEwgMjMyLjIyNyA0OC41NzUgTCAyMzIuMjI3IDQ4LjY1NyBMIDIzNy41NTEgNTcuMzI1IEMgMjM4LjQ4NiA1OC44NzEgMjM3Ljg3NiA2MC4wMSAyMzYuM" .
"DQ3IDYwLjAxIEwgMjMyLjk5OSA2MC4wMSBDIDIzMS43NzkgNjAuMDEgMjMwLjk2NyA1OS41MjIgMjMwLjM1NyA1OC40MjMgTCAyMjYuNzggNTEuODcxIEwgMjI0LjU4NiA1MS44NzEgTCAyMjQuNTg" .
"2IDU3Ljc3MiBDIDIyNC41ODYgNTkuMjM3IDIyMy44MTQgNjAuMDEgMjIyLjM1IDYwLjAxIEwgMjE5Ljc5IDYwLjAxIEMgMjE4LjMyNyA2MC4wMSAyMTcuNTU1IDU5LjIzNyAyMTcuNTU1IDU3Ljc3M" .
"iBMIDIxNy41NTUgMzMuMTUzIFogIE0gMjA3LjE1OCA1MS41NDYgQyAyMDcuMTU4IDUzLjI1NSAyMDUuNjEzIDU1LjI0OSAyMDMuNTgxIDU1LjI0OSBDIDIwMi4yODEgNTUuMjQ5IDIwMS41ODkgNTQ" .
"uNDM1IDIwMS41ODkgNTMuNDU5IEMgMjAxLjU4OSA1MS41NDYgMjA0LjM1MyA1MC45NzYgMjA2LjUwNyA1MC45NzYgTCAyMDcuMTU4IDUwLjk3NiBMIDIwNy4xNTggNTEuNTQ2IFogIE0gMjA0Ljc2I" .
"DM4LjU2NSBDIDIwMS43NTIgMzguNTY1IDE5OS4zMTMgMzkuNDYgMTk3LjcyOCA0MC4xOTMgQyAxOTYuNDY4IDQwLjg0NCAxOTYuMjI1IDQxLjk0MiAxOTYuODM0IDQzLjIwNCBMIDE5Ny4zMjIgNDQ" .
"uMjIyIEMgMTk3Ljk3MyA0NS41MjQgMTk4Ljk4OCA0NS43NjcgMjAwLjMzIDQ1LjE5OCBDIDIwMS4zODYgNDQuNzEgMjAyLjc2OCA0NC4yNjIgMjA0LjExIDQ0LjI2MiBDIDIwNS42NTQgNDQuMjYyI" .
"DIwNy4wNzcgNDQuODMyIDIwNy4wNzcgNDYuNTgyIEwgMjA3LjA3NyA0Ni45NDcgTCAyMDYuNDY3IDQ2Ljk0NyBDIDIwMS4zMDUgNDYuOTQ3IDE5NC42NCA0OC40OTQgMTk0LjY0IDU0LjExIEMgMTk" .
"0LjY0IDU3LjczMiAxOTcuNDQ0IDYwLjQ5OCAyMDEuNDY4IDYwLjQ5OCBDIDIwNS42NTQgNjAuNDk4IDIwNy42NDUgNTcuMTYxIDIwNy42NDUgNTcuMTYxIEwgMjA3LjcyNyA1Ny4xNjEgQyAyMDcuN" .
"zI3IDU3LjE2MSAyMDcuNjg2IDU3LjMyNSAyMDcuNjg2IDU3LjU2OSBMIDIwNy42ODYgNTcuNzMyIEMgMjA3LjY4NiA1OS4yNzggMjA4LjQ1OSA2MC4wMSAyMDkuOTIyIDYwLjAxIEwgMjExLjg3MiA" .
"2MC4wMSBDIDIxMy4zMzYgNjAuMDEgMjE0LjEwOCA1OS4yMzcgMjE0LjEwOCA1Ny43NzIgTCAyMTQuMTA4IDQ3LjExIEMgMjE0LjEwOCA0MS43OCAyMTAuNTMxIDM4LjU2NSAyMDQuNzYgMzguNTY1I" .
"FogIE0gMTYwLjE4NiA0MS4yOTEgQyAxNjAuMTg2IDM5LjgyNiAxNjAuOTU4IDM5LjA1NCAxNjIuNDIxIDM5LjA1NCBMIDE2NC42OTcgMzkuMDU0IEMgMTY2LjE2MSAzOS4wNTQgMTY2Ljg5MiAzOS4" .
"4MjYgMTY2Ljg5MiA0MS4yOTEgTCAxNjYuODkyIDQxLjY1OCBDIDE2Ni44OTIgNDEuODYxIDE2Ni44NTEgNDIuMTg2IDE2Ni44NTEgNDIuMTg2IEwgMTY2LjkzMyA0Mi4xODYgQyAxNjcuOTA4IDQwL" .
"jcyMiAxNjkuODU5IDM4LjU2NSAxNzMuMTUxIDM4LjU2NSBDIDE3NS43OTMgMzguNTY1IDE3Ny45MDYgMzkuNzQ1IDE3OS4wMDMgNDIuMTA1IEwgMTc5LjA4NSA0Mi4xMDUgQyAxODAuMTgyIDQwLjM" .
"1NiAxODIuNjYyIDM4LjU2NSAxODUuNzkyIDM4LjU2NSBDIDE4OS42MTIgMzguNTY1IDE5Mi41NzkgNDAuNjQxIDE5Mi41NzkgNDYuNDE5IEwgMTkyLjU3OSA1Ny43NzIgQyAxOTIuNTc5IDU5LjIzN" .
"yAxOTEuODA3IDYwLjAxIDE5MC4zNDMgNjAuMDEgTCAxODcuNzgzIDYwLjAxIEMgMTg2LjMyIDYwLjAxIDE4NS41NDcgNTkuMjM3IDE4NS41NDcgNTcuNzcyIEwgMTg1LjU0NyA0Ny41OTkgQyAxODU" .
"uNTQ3IDQ2LjA1MyAxODUuMjYzIDQ0Ljk1NCAxODMuOTIxIDQ0Ljk1NCBDIDE4MC44NzQgNDQuOTU0IDE3OS44OTggNDguMDA2IDE3OS44OTggNTEuMTM5IEwgMTc5Ljg5OCA1Ny43NzIgQyAxNzkuO" .
"Dk4IDU5LjIzNyAxNzkuMTI2IDYwLjAxIDE3Ny42NjMgNjAuMDEgTCAxNzUuMTAyIDYwLjAxIEMgMTczLjYzOSA2MC4wMSAxNzIuODY2IDU5LjIzNyAxNzIuODY2IDU3Ljc3MiBMIDE3Mi44NjYgNDc" .
"uNTk5IEMgMTcyLjg2NiA0Ni4wNTMgMTcyLjU4MiA0NC45NTQgMTcxLjI0MSA0NC45NTQgQyAxNjguMDMgNDQuOTU0IDE2Ny4yMTcgNDguMjUgMTY3LjIxNyA1MS4xMzkgTCAxNjcuMjE3IDU3Ljc3M" .
"iBDIDE2Ny4yMTcgNTkuMjM3IDE2Ni40NDUgNjAuMDEgMTY0Ljk4MiA2MC4wMSBMIDE2Mi40MjEgNjAuMDEgQyAxNjAuOTU4IDYwLjAxIDE2MC4xODYgNTkuMjM3IDE2MC4xODYgNTcuNzcyIEwgMTY" .
"wLjE4NiA0MS4yOTEgWiAgTSAxNDAuOTEzIDYyLjYxNSBDIDE0MS4yMzggNjIuNjk2IDE0MS42NDUgNjIuODE4IDE0Mi4xMzIgNjIuODE4IEMgMTQzLjM5MiA2Mi44MTggMTQ0LjUzIDYxLjgwMSAxN" .
"DUuMDE4IDYwLjU4IEwgMTQ1LjQyNCA1OS42MDQgTCAxMzcuNjYxIDQxLjY1OCBDIDEzNi45NzEgNDAuMTExIDEzNy42NjEgMzkuMDU0IDEzOS4zNjggMzkuMDU0IEwgMTQyLjUzOSAzOS4wNTQgQyA" .
"xNDMuODggMzkuMDU0IDE0NC42OTMgMzkuNjY0IDE0NS4xIDQwLjg4NCBMIDE0Ny41NzkgNDguNjk3IEMgMTQ3Ljk0NCA0OS44MzcgMTQ4LjM1MSA1MS45NTMgMTQ4LjM1MSA1MS45NTMgTCAxNDguN" .
"DMyIDUxLjk1MyBDIDE0OC40MzIgNTEuOTUzIDE0OC44MzkgNDkuOTU5IDE0OS4xMjMgNDguODIgTCAxNTEuMjM3IDQwLjk2NiBDIDE1MS42MDIgMzkuNjY0IDE1Mi40MTUgMzkuMDU0IDE1My43NTc" .
"gMzkuMDU0IEwgMTU2LjcyMyAzOS4wNTQgQyAxNTguMzQ5IDM5LjA1NCAxNTkuMDgxIDQwLjA3MSAxNTguNTEyIDQxLjYxNyBMIDE1MC44NzEgNjIuMjQ4IEMgMTQ5LjEyMyA2Ni45MjggMTQ1LjQ2N" .
"SA2OC41NTYgMTQyLjEzMiA2OC41NTYgQyAxNDEuMDM1IDY4LjU1NiAxNDAuMDE5IDY4LjMxMiAxMzkuMjA2IDY4LjAyNyBDIDEzNy44NjUgNjcuNTc5IDEzNy41NCA2Ni4zOTkgMTM4LjEwOSA2NS4" .
"wOTcgTCAxMzguNjM3IDYzLjkxNyBDIDEzOS4yMDYgNjIuNjU2IDE0MC4wNiA2Mi40NTIgMTQwLjkxMyA2Mi42MTUgWiAgTSAxMzAuMDI1IDUxLjU0NiBDIDEzMC4wMjUgNTMuMjU1IDEyOC40OCA1N" .
"S4yNDkgMTI2LjQ0OCA1NS4yNDkgQyAxMjUuMTQ4IDU1LjI0OSAxMjQuNDU2IDU0LjQzNSAxMjQuNDU2IDUzLjQ1OSBDIDEyNC40NTYgNTEuNTQ2IDEyNy4yMiA1MC45NzYgMTI5LjM3NCA1MC45NzY" .
"gTCAxMzAuMDI1IDUwLjk3NiBMIDEzMC4wMjUgNTEuNTQ2IFogIE0gMTI3LjYyNyAzOC41NjUgQyAxMjQuNjE5IDM4LjU2NSAxMjIuMTggMzkuNDYgMTIwLjU5NiA0MC4xOTMgQyAxMTkuMzM2IDQwL" .
"jg0NCAxMTkuMDkyIDQxLjk0MiAxMTkuNzAxIDQzLjIwNCBMIDEyMC4xODkgNDQuMjIyIEMgMTIwLjgzOSA0NS41MjQgMTIxLjg1NSA0NS43NjcgMTIzLjE5NiA0NS4xOTggQyAxMjQuMjU0IDQ0Ljc" .
"xIDEyNS42MzUgNDQuMjYyIDEyNi45NzYgNDQuMjYyIEMgMTI4LjUyMSA0NC4yNjIgMTI5Ljk0MyA0NC44MzIgMTI5Ljk0MyA0Ni41ODIgTCAxMjkuOTQzIDQ2Ljk0NyBMIDEyOS4zMzMgNDYuOTQ3I" .
"EMgMTI0LjE3MiA0Ni45NDcgMTE3LjUwNiA0OC40OTQgMTE3LjUwNiA1NC4xMSBDIDExNy41MDYgNTcuNzMyIDEyMC4zMTEgNjAuNDk4IDEyNC4zMzUgNjAuNDk4IEMgMTI4LjUyMSA2MC40OTggMTM" .
"wLjUxMiA1Ny4xNjEgMTMwLjUxMiA1Ny4xNjEgTCAxMzAuNTkzIDU3LjE2MSBDIDEzMC41OTMgNTcuMTYxIDEzMC41NTMgNTcuMzI1IDEzMC41NTMgNTcuNTY5IEwgMTMwLjU1MyA1Ny43MzIgQyAxM" .
"zAuNTUzIDU5LjI3OCAxMzEuMzI1IDYwLjAxIDEzMi43ODkgNjAuMDEgTCAxMzQuNzM5IDYwLjAxIEMgMTM2LjIwMyA2MC4wMSAxMzYuOTc0IDU5LjIzNyAxMzYuOTc0IDU3Ljc3MiBMIDEzNi45NzQ" .
"gNDcuMTEgQyAxMzYuOTc0IDQxLjc4IDEzMy4zOTggMzguNTY1IDEyNy42MjcgMzguNTY1IFogIE0gMTA0LjMyNiA1NC42OCBDIDEwMS44ODcgNTQuNjggMTAwLjIyMSA1Mi42NDUgMTAwLjIyMSA0O" .
"S41MTIgQyAxMDAuMjIxIDQ2LjI5NyAxMDIuMTMxIDQ0LjUwNiAxMDQuMzI2IDQ0LjUwNiBDIDEwNy4wODkgNDQuNTA2IDEwOC40MyA0Ni45ODggMTA4LjQzIDQ5LjUxMiBDIDEwOC40MyA1My4xMzM" .
"gMTA2LjQzOSA1NC42OCAxMDQuMzI2IDU0LjY4IFogIE0gMTEzLjAyMyAzMC45MTUgTCAxMTAuNDYyIDMwLjkxNSBDIDEwOSAzMC45MTUgMTA4LjIyNyAzMS42ODggMTA4LjIyNyAzMy4xNTMgTCAxM" .
"DguMjI3IDM5LjcwNCBDIDEwOC4yMjcgNDAuMjMzIDEwOC4yNjggNDAuNjQxIDEwOC4yNjggNDAuNjQxIEwgMTA4LjE4NiA0MC42NDEgQyAxMDguMTg2IDQwLjY0MSAxMDYuOTI2IDM4LjU2NSAxMDI" .
"uNyAzOC41NjUgQyA5Ny4xMzEgMzguNTY1IDkzLjE0OSA0Mi44NzkgOTMuMTQ5IDQ5LjUxMiBDIDkzLjE0OSA1Ni4wMjMgOTYuODg4IDYwLjQ5OCAxMDIuNTc4IDYwLjQ5OCBDIDEwNi44ODYgNjAuN" .
"Dk4IDEwOC42MzQgNTcuNDQ3IDEwOC42MzQgNTcuNDQ3IEwgMTA4LjcxNSA1Ny40NDcgQyAxMDguNzE1IDU3LjQ0NyAxMDguNjc1IDU3LjY1IDEwOC42NzUgNTcuNzMyIEwgMTA4LjY3NSA1Ny45NzY" .
"gQyAxMDguNjc1IDU5LjMxOCAxMDkuNDQ3IDYwLjAxIDExMC45MSA2MC4wMSBMIDExMy4wMjMgNjAuMDEgQyAxMTQuNDg2IDYwLjAxIDExNS4yNTkgNTkuMjM3IDExNS4yNTkgNTcuNzcyIEwgMTE1L" .
"jI1OSAzMy4xNTMgQyAxMTUuMjU5IDMxLjY4OCAxMTQuNDg2IDMwLjkxNSAxMTMuMDIzIDMwLjkxNSBaICBNIDgzLjY1NSAzNC4xNyBMIDgzLjY1NSAzMy4xNTMgQyA4My42NTUgMzEuNjg4IDg0LjQ" .
"yNyAzMC45MTUgODUuODkgMzAuOTE1IEwgODguMjg4IDMwLjkxNSBDIDg5Ljc1MSAzMC45MTUgOTAuNTI0IDMxLjY4OCA5MC41MjQgMzMuMTUzIEwgOTAuNTI0IDM0LjE3IEMgOTAuNTI0IDM1LjYzN" .
"SA4OS43NTEgMzYuNDA4IDg4LjI4OCAzNi40MDggTCA4NS44OSAzNi40MDggQyA4NC40MjcgMzYuNDA4IDgzLjY1NSAzNS42MzUgODMuNjU1IDM0LjE3IFogIE0gODMuNTczIDQxLjI5MSBDIDgzLjU" .
"3MyAzOS44MjYgODQuMzQ2IDM5LjA1NCA4NS44MDkgMzkuMDU0IEwgODguMzY5IDM5LjA1NCBDIDg5LjgzMyAzOS4wNTQgOTAuNjA1IDM5LjgyNiA5MC42MDUgNDEuMjkxIEwgOTAuNjA1IDU3Ljc3M" .
"iBDIDkwLjYwNSA1OS4yMzcgODkuODMzIDYwLjAxIDg4LjM2OSA2MC4wMSBMIDg1LjgwOSA2MC4wMSBDIDg0LjM0NiA2MC4wMSA4My41NzMgNTkuMjM3IDgzLjU3MyA1Ny43NzIgTCA4My41NzMgNDE" .
"uMjkxIFogIE0gNzEuNTU1IDMzLjE1MyBDIDcxLjU1NSAzMS42ODggNzIuMzI3IDMwLjkxNSA3My43OTEgMzAuOTE1IEwgNzYuMzUxIDMwLjkxNSBDIDc3LjgxNCAzMC45MTUgNzguNTg3IDMxLjY4O" .
"CA3OC41ODcgMzMuMTUzIEwgNzguNTg3IDUxLjk1MyBDIDc4LjU4NyA1My41NCA3OS4xMTUgNTMuOTQ3IDc5Ljc2NSA1NC4wMjkgQyA4MC43NCA1NC4xNTEgODEuMzkxIDU0LjcyIDgxLjM5MSA1NS4" .
"5NDEgTCA4MS4zOTEgNTcuOTM1IEMgODEuMzkxIDU5LjMxOCA4MC43NCA2MC4yMTQgNzkuMTE1IDYwLjIxNCBDIDc1LjQ5OCA2MC4yMTQgNzEuNTU1IDU5LjMxOCA3MS41NTUgNTIuMjc4IEwgNzEuN" .
"TU1IDMzLjE1MyBaICBNIDU3LjM4NyA1NC42MzkgQyA1NC44MjcgNTQuNjM5IDUyLjYzMiA1Mi43MjYgNTIuNjMyIDQ5LjU5MyBDIDUyLjYzMiA0Ni40MTkgNTQuODI3IDQ0LjQyNSA1Ny4zODcgNDQ" .
"uNDI1IEMgNTkuOTQ4IDQ0LjQyNSA2Mi4xNDMgNDYuNDE5IDYyLjE0MyA0OS41OTMgQyA2Mi4xNDMgNTIuNzI2IDU5Ljk0OCA1NC42MzkgNTcuMzg3IDU0LjYzOSBaICBNIDU3LjM0NyAzOC41NjUgQ" .
"yA1MC44MDMgMzguNTY1IDQ1LjUxOSA0My4wMDEgNDUuNTE5IDQ5LjU5MyBDIDQ1LjUxOSA1Ni4xNDUgNTAuODAzIDYwLjQ5OCA1Ny4zODcgNjAuNDk4IEMgNjMuOTcxIDYwLjQ5OCA2OS4yNTUgNTY" .
"uMTQ1IDY5LjI1NSA0OS41OTMgQyA2OS4yNTUgNDMuMDAxIDYzLjk3MSAzOC41NjUgNTcuMzQ3IDM4LjU2NSBaICBNIDIyLjM3NyAzMy4xNTMgQyAyMi4zNzcgMzEuNjg4IDIzLjE0OSAzMC45MTUgM" .
"jQuNjEyIDMwLjkxNSBMIDI3LjE3MyAzMC45MTUgQyAyOC42MzYgMzAuOTE1IDI5LjQwOCAzMS42ODggMjkuNDA4IDMzLjE1MyBMIDI5LjQwOCA0MC4xMTEgQyAyOS40MDggNDEuMTY5IDI5LjMyNyA" .
"0MS45MDIgMjkuMzI3IDQxLjkwMiBMIDI5LjQwOCA0MS45MDIgQyAzMC41ODcgMzkuODI2IDMzLjAyNSAzOC41NjUgMzUuNzg5IDM4LjU2NSBDIDQwLjAxNiAzOC41NjUgNDMuMzA4IDQwLjUxOSA0M" .
"y4zMDggNDYuNDE5IEwgNDMuMzA4IDU3Ljc3MiBDIDQzLjMwOCA1OS4yMzcgNDIuNTM2IDYwLjAxIDQxLjA3MyA2MC4wMSBMIDM4LjUxMiA2MC4wMSBDIDM3LjA0OSA2MC4wMSAzNi4yNzcgNTkuMjM" .
"3IDM2LjI3NyA1Ny43NzIgTCAzNi4yNzcgNDcuNzIxIEMgMzYuMjc3IDQ1Ljc2NyAzNS41MDUgNDQuOTU0IDMzLjk2IDQ0Ljk1NCBDIDMwLjkxMiA0NC45NTQgMjkuNDA4IDQ3LjM1NSAyOS40MDggN" .
"TAuNTY5IEwgMjkuNDA4IDU3Ljc3MiBDIDI5LjQwOCA1OS4yMzcgMjguNjM2IDYwLjAxIDI3LjE3MyA2MC4wMSBMIDI0LjYxMiA2MC4wMSBDIDIzLjE0OSA2MC4wMSAyMi4zNzcgNTkuMjM3IDIyLjM" .
"3NyA1Ny43NzIgTCAyMi4zNzcgMzMuMTUzIFogIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGZpbGw9InJnYigyNTQsMjU0LDI1NCkiLz48L2c+PHBhdGggZD0iIE0gMzAyLjIyNiAyOC41ODkgQyAzMDIuM" .
"TQgMjguMjM1IDMwMi4wMjYgMjcuOTQyIDMwMS44NzYgMjcuNjExIEMgMzAxLjczMSAyNy4yOTQgMzAxLjYxNCAyNi45ODggMzAxLjM4NiAyNi43MTcgQyAzMDAuOTk4IDI2LjI1NCAzMDAuNzI4IDI" .
"1LjY2OSAzMDAuMjg1IDI1LjI1MyBDIDMwMC4yNCAyNS4xNTIgMzAwLjE3NCAyNS4wNiAzMDAuMDg1IDI0Ljk4NiBDIDI5OS43MzMgMjQuNjk1IDI5OS4zODQgMjQuNTAzIDI5OC45ODMgMjQuMzQxI" .
"EMgMjk4LjgxMiAyNC4xODMgMjk4LjY1NyAyNC4wNCAyOTguMzk0IDIzLjk3MiBDIDI5OC4xOTYgMjMuOTIgMjk3Ljk5IDIzLjg4OSAyOTcuNzg1IDIzLjg2MyBDIDI5Ny42NjcgMjMuODI4IDI5Ny4" .
"1NDYgMjMuODA3IDI5Ny40MjEgMjMuNzk2IEMgMjk3LjMgMjMuNzY5IDI5Ny4xOCAyMy43NDcgMjk3LjA2MSAyMy43MjggQyAyOTYuOTM0IDIzLjY1OSAyOTYuNzgzIDIzLjYxNiAyOTYuNjA0IDIzL" .
"jYxNiBDIDI5Ni41MTUgMjMuNjE2IDI5Ni40MiAyMy42MTEgMjk2LjMyNCAyMy42MDggQyAyOTUuNzA3IDIzLjU3NSAyOTUuMTE4IDIzLjQ2NSAyOTQuNDk2IDIzLjU5OCBDIDI5My44MDcgMjMuNzQ" .
"1IDI5My4yMTggMjQuMDM1IDI5Mi41NzEgMjQuMzM5IEMgMjkxLjg1OSAyNC42NzUgMjkxLjMwOSAyNS4xOSAyOTAuNjkxIDI1LjY3MyBDIDI5MC4zNzEgMjUuOTIzIDI5MC4xMzYgMjYuMTQzIDI4O" .
"S44NzQgMjYuNDUxIEMgMjg5LjY2NyAyNi42OTUgMjg5LjQzMyAyNi44MDMgMjg5LjE3MyAyNi45OCBDIDI4Ny45MDMgMjcuODUxIDI4Ny4zMzQgMjkuMzM4IDI4Ni44NDkgMzAuNzM1IEMgMjg2LjU" .
"4MiAzMS41MDQgMjg2LjQ4OCAzMi4zMzQgMjg2LjQ2NyAzMy4xNDYgQyAyODYuNDYgMzMuNDM5IDI4Ni40NjEgMzMuNzk0IDI4Ni40NjcgMzMuOTI1IEMgMjg2LjQ5MyAzNC4zNzcgMjg2LjY0IDM0L" .
"jc0NiAyODYuOCAzNS4xNjMgQyAyODYuOTU3IDM1LjU3IDI4Ny4wNjkgMzUuOTExIDI4Ny4zMzEgMzYuMjcgQyAyODcuNDczIDM2LjQ2NSAyODcuNjAyIDM2LjYxOCAyODcuNzg1IDM2Ljc3OSBDIDI" .
"4Ny43NDEgMzYuNzQgMjg3LjU0NCAzNi41MzIgMjg3LjcyNiAzNi43NTcgQyAyODcuODM2IDM2Ljg5NCAyODcuOTY5IDM3LjAwMyAyODguMTEzIDM3LjEwNSBDIDI4OC43MzYgMzcuNTQzIDI4OS4yO" .
"TggMzcuOTg3IDI5MC4wNDYgMzguMDk1IEMgMjkwLjYxNiAzOC4zODkgMjkxLjE4MiAzOC41OTkgMjkxLjg0NSAzOC42NDggQyAyOTIuNDg2IDM4LjY5NiAyOTMuMzQ2IDM4LjYxNiAyOTMuOTQxIDM" .
"4LjM3NSBDIDI5NC4yMTYgMzguMjYzIDI5NC40OTkgMzguMTQxIDI5NC43NDMgMzcuOTcgQyAyOTQuOTcyIDM3LjgxIDI5NS4yMDggMzcuODI5IDI5NS41MDMgMzcuNzI2IEMgMjk1LjkzMSAzNy41N" .
"zggMjk2LjMzNiAzNy4yMzIgMjk2LjU4NSAzNi44MzQgQyAyOTYuODI1IDM2LjU3OSAyOTYuOTgyIDM2LjIgMjk2Ljk4MiAzNS43NjkgQyAyOTYuOTgyIDM1LjAwMiAyOTYuNDk1IDM0LjM3OSAyOTU" .
"uODkzIDM0LjM3OSBDIDI5NS42NjggMzQuMzc5IDI5NS40NTkgMzQuNDY2IDI5NS4yODYgMzQuNjE2IEMgMjk1LjE4MiAzNC42NTYgMjk1LjA4MSAzNC43MDMgMjk0Ljk4NyAzNC43NjMgQyAyOTQuO" .
"TU0IDM0Ljc4MyAyOTQuNzI5IDM0Ljk0NyAyOTQuNzA4IDM0Ljk2OCBDIDI5NC41MzkgMzUuMDcgMjk0LjM3NyAzNS4xMDQgMjk0LjEwOSAzNS4yNCBDIDI5NC4wMDggMzUuMjkxIDI5My45OTQgMzU" .
"uMzA0IDI5NC4wNjcgMzUuMjc4IEMgMjk0LjAwNCAzNS4zMDQgMjkzLjk0IDM1LjMyNiAyOTMuODc0IDM1LjM0NSBDIDI5My43NTcgMzUuMzggMjkzLjYzNCAzNS40MDYgMjkzLjUxNSAzNS40MzQgQ" .
"yAyOTMuMTg4IDM1LjUxIDI5My4wMjMgMzUuNTQ4IDI5Mi43MDUgMzUuNTY0IEMgMjkyLjQ3NyAzNS41NzYgMjkyLjI0NiAzNS42MDEgMjkyLjA0MyAzNS42MDUgQyAyOTEuOTYyIDM1LjYwMiAyOTE" .
"uODgxIDM1LjYwMSAyOTEuNzk5IDM1LjYwMSBDIDI5MS43NTUgMzUuNTk0IDI5MS42OTYgMzUuNTg3IDI5MS42MDggMzUuNTc1IEMgMjkxLjU4MyAzNS41NzMgMjkxLjUyMyAzNS41NzMgMjkxLjQ4M" .
"SAzNS41NzQgQyAyOTEuNDAxIDM1LjU1MyAyOTEuMzIgMzUuNTM0IDI5MS4yMzYgMzUuNTMzIEMgMjkxLjIxMyAzNS41MzIgMjkxLjE5NyAzNS41MzEgMjkxLjE4NCAzNS41MzEgQyAyOTEuMTA5IDM" .
"1LjUxNiAyOTAuOTk3IDM1LjQ5NSAyOTAuOTc4IDM1LjQ5MSBDIDI5MC45NTYgMzUuNDg3IDI5MC44NDYgMzUuNDYxIDI5MC43NTQgMzUuNDQxIEMgMjkwLjY3NyAzNS4zODkgMjkwLjQ2NiAzNS4yO" .
"DQgMjkwLjQyMyAzNS4yNTggQyAyOTAuMTk4IDM1LjEyNSAyOTAuMjE3IDM1LjE0NCAyOTAuMDc2IDM0Ljk0MyBDIDI4OS45MiAzNC43MiAyODkuNjkzIDM0LjUwNSAyODkuNTgxIDM0LjMxNyBDIDI" .
"4OS41NzggMzQuMzEyIDI4OS41NzggMzQuMzExIDI4OS41NzUgMzQuMzA2IEMgMjg5LjU2NyAzNC4yMiAyODkuNTU4IDM0LjEzMyAyODkuNTQzIDM0LjA0OCBDIDI4OS41NDIgMzQuMDI4IDI4OS41N" .
"CAzNC4wMDcgMjg5LjUzOCAzMy45NzYgQyAyODkuNDk3IDMzLjY0MSAyODkuNDI2IDMzLjQ2NiAyODkuNDEzIDMzLjIxNSBDIDI4OS4zOTkgMzIuOTU4IDI4OS40MSAzMi43MTEgMjg5LjQ1NSAzMi4" .
"0NyBDIDI4OS40NzkgMzIuMzQzIDI4OS41MDMgMzIuMjE2IDI4OS41MjYgMzIuMDg4IEMgMjg5LjUzNSAzMi4wMTkgMjg5LjU0NyAzMS45NSAyODkuNTYzIDMxLjg4MiBDIDI4OS40ODQgMzIuMDMzI" .
"DI4OS41MDIgMzEuOTgyIDI4OS42MTggMzEuNzI5IEMgMjg5LjY0NiAzMS42NDkgMjg5LjY3IDMxLjU3NCAyODkuNjg1IDMxLjQ4OSBDIDI4OS42OTkgMzEuNDI3IDI4OS43IDMxLjM2MyAyODkuNzA" .
"1IDMxLjMgQyAyODkuNzMyIDMxLjI0OCAyODkuNzQ5IDMxLjE5MiAyODkuNzYyIDMxLjEzNSBDIDI4OS43NjkgMzEuMTIgMjg5Ljc4IDMxLjA5OSAyODkuNzk0IDMxLjA3MiBDIDI4OS44MDcgMzEuM" .
"DQ1IDI4OS44MTIgMzEuMDIxIDI4OS44MjMgMzAuOTk2IEMgMjg5Ljk4MSAzMC43NjcgMjkwLjA5MyAzMC40OTkgMjkwLjIyMSAzMC4yNjQgQyAyOTAuNDQxIDI5Ljg2IDI5MC42NjYgMjkuNDk2IDI" .
"5MC45NjEgMjkuMTg0IEMgMjkxLjMxOSAyOC44MDUgMjkxLjcxMSAyOC41MzUgMjkyLjExMiAyOC4yMSBDIDI5Mi40MjkgMjcuOTUyIDI5Mi42ODkgMjcuNjQ4IDI5My4wMzMgMjcuNDI3IEMgMjkzL" .
"jI2IDI3LjI4MSAyOTMuNDk1IDI3LjEyNyAyOTMuNjc2IDI2LjkyNSBDIDI5My42OTIgMjYuOTA3IDI5My43NTMgMjYuODA0IDI5My44MDUgMjYuNzMxIEMgMjkzLjg0NiAyNi42OTkgMjkzLjg4NyA" .
"yNi42NjcgMjkzLjkyOCAyNi42MzYgQyAyOTQuMjk4IDI2LjM2NiAyOTQuNjggMjYuMTE4IDI5NS4xNDMgMjUuOTY5IEMgMjk1Ljc5NSAyNS43NTkgMjk2LjQyNSAyNS42NjEgMjk3LjA3MiAyNS43M" .
"DQgQyAyOTcuMTYgMjUuNzE4IDI5Ny4yNDkgMjUuNzI5IDI5Ny4zMzYgMjUuNzUxIEMgMjk3LjM1OCAyNS43NTcgMjk3LjM3OCAyNS43NTcgMjk3LjM5OSAyNS43NiBDIDI5Ny40MzQgMjUuNzcxIDI" .
"5Ny40NTQgMjUuNzc1IDI5Ny40MzMgMjUuNzY1IEMgMjk3LjQ1NCAyNS43NjkgMjk3LjQ3NiAyNS43NzIgMjk3LjQ5NyAyNS43NzUgQyAyOTcuNTY3IDI1Ljc5MSAyOTcuNjM3IDI1Ljc5NyAyOTcuN" .
"zA3IDI1Ljc5MiBDIDI5Ny43MjUgMjUuNzk2IDI5Ny43NDEgMjUuNzk2IDI5Ny43NTkgMjUuNzk5IEMgMjk3Ljg4OCAyNS45MTUgMjk4LjAxNyAyNi4wMTYgMjk4LjIxIDI2LjA5MyBDIDI5OC4zOTQ" .
"gMjYuMTY2IDI5OC41NjggMjYuMjEzIDI5OC43MjcgMjYuMzQ1IEMgMjk4Ljg1MSAyNi40NDggMjk4Ljk5MiAyNi41MiAyOTkuMTM4IDI2LjU2NSBDIDI5OS4yOTUgMjYuNjc0IDI5OS40NDUgMjYuN" .
"zkxIDI5OS41OTkgMjYuOTgxIEMgMjk5Ljc3MSAyNy4xOTUgMjk5Ljk4OCAyNy40MDIgMzAwLjExNyAyNy42NDQgQyAzMDAuMjU4IDI3LjkxIDMwMC4zNTcgMjguMjA2IDMwMC41MjUgMjguNDU4IEM" .
"gMzAwLjY3NSAyOC42ODEgMzAwLjgxNiAyOC44NjMgMzAwLjkwOSAyOS4xMjIgQyAzMDEuMTA4IDI5LjY4MSAzMDEuMjIyIDMwLjI5IDMwMS4yMjYgMzAuODgzIEMgMzAxLjIzMSAzMS40MzcgMzAxL" .
"jE5MyAzMi4wNjEgMzAxLjAyNyAzMi41ODcgQyAzMDAuOTk5IDMyLjY3NCAzMDEuMDA5IDMyLjc1OCAzMDEuMDM0IDMyLjgzNiBDIDMwMS4wMjcgMzMuMjYzIDMwMC45NTMgMzMuNzQzIDMwMC43NDY" .
"gMzQuMTE1IEMgMzAwLjYzNyAzNC4zMTEgMzAwLjUzMiAzNC41MDYgMzAwLjQxNyAzNC43MDEgQyAzMDAuMjk1IDM0LjkwOCAzMDAuMjQzIDM1LjE0IDMwMC4xMzggMzUuMzU0IEMgMzAwLjA0OCAzN" .
"S41MzkgMjk5Ljk4MiAzNS43NDYgMjk5Ljg0MiAzNS44OTggQyAyOTkuNjgzIDM2LjA3IDI5OS41MzEgMzYuMjQyIDI5OS4zOTIgMzYuNDMxIEMgMjk5LjE2NyAzNi43MzggMjk4Ljk1IDM3LjEyOSA" .
"yOTguNTc4IDM3LjI2MSBDIDI5OC4zMTUgMzcuMzU0IDI5OC4zOTYgMzcuNzQzIDI5OC42MzIgMzcuNzM0IEMgMjk4LjY4OCAzNy43NTMgMjk4Ljc1MSAzNy43NDYgMjk4LjgxNiAzNy43IEMgMjk4L" .
"jgzNSAzNy42ODYgMjk4Ljg1NyAzNy42NzggMjk4Ljg3OCAzNy42NjYgQyAyOTguOTI5IDM3LjY0OCAyOTguOTggMzcuNjI3IDI5OS4wMjYgMzcuNTk3IEMgMjk5LjAzNyAzNy41ODggMjk5LjA0NiA" .
"zNy41NzcgMjk5LjA1NyAzNy41NjggQyAyOTkuMDc5IDM3LjU1NiAyOTkuMTAzIDM3LjU0NyAyOTkuMTIzIDM3LjUzMSBDIDI5OS4yMzkgMzcuNDQxIDI5OS4zMTggMzcuMzE1IDI5OS40MjEgMzcuM" .
"jExIEMgMjk5LjYzMiAzNi45OTkgMjk5Ljc3NCAzNi43NjUgMjk5Ljk1OSAzNi41MzMgQyAzMDAuMjYyIDM2LjE1NyAzMDAuNTk3IDM1Ljc3IDMwMC44MzYgMzUuMzUxIEMgMzAwLjk3IDM1LjExNiA" .
"zMDEuMTExIDM0LjkwOCAzMDEuMjE3IDM0LjY1NiBDIDMwMS4zMzEgMzQuMzg1IDMwMS40NTggMzQuMTI1IDMwMS42MjQgMzMuODgzIEMgMzAxLjc2MiAzMy42NzkgMzAxLjg1NSAzMy40MzIgMzAxL" .
"jkyNyAzMy4xOTUgQyAzMDEuOTY4IDMzLjA1OSAzMDIuMDA1IDMyLjkxOSAzMDIuMDM3IDMyLjc3OSBDIDMwMi4xMTMgMzIuNjYzIDMwMi4xNzEgMzIuNTQxIDMwMi4xOTcgMzIuMzc2IEMgMzAyLjI" .
"xNiAzMi4yNjIgMzAyLjIyOCAzMi4xNTUgMzAyLjIyMiAzMi4wNTIgQyAzMDIuMjQ3IDMxLjkzMyAzMDIuMjY3IDMxLjgxNCAzMDIuMjc1IDMxLjcxIEMgMzAyLjMwMSAzMS40MDEgMzAyLjMzNiAzM" .
"S4wODkgMzAyLjMzNiAzMC43NzggQyAzMDIuMzM2IDMwLjA1IDMwMi4zOTggMjkuMjk5IDMwMi4yMjYgMjguNTg5IFogIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGZpbGw9InJnYigyNTQsMjU0LDI1NCk" .
"iLz48cGF0aCBkPSIgTSAyODcuOTMxIDIzLjI4NSBDIDI4Ny45MDMgMjMuMTQ2IDI4Ny44MjggMjMuMDI2IDI4Ny43OTIgMjIuODkxIEMgMjg3LjcxOCAyMi42MSAyODcuNjcxIDIyLjMzMSAyODcuN" .
"TY1IDIyLjA1NyBDIDI4Ny40NjEgMjEuNzg5IDI4Ny4zMTUgMjEuNDg4IDI4Ny4xNTkgMjEuMjQ1IEMgMjg3LjAwOSAyMS4wMSAyODYuNzY5IDIwLjgwOCAyODYuNTc2IDIwLjYwOSBDIDI4Ni4zOCA" .
"yMC40MDYgMjg2LjIyNiAyMC4xNzMgMjg2LjAxMSAxOS45ODUgQyAyODUuODE0IDE5LjgxMyAyODUuNjQyIDE5LjYyMiAyODUuNDI1IDE5LjQ2OCBDIDI4NS4xNjIgMTkuMjgxIDI4NC45NzYgMTkuM" .
"DM1IDI4NC43NDYgMTguODExIEMgMjg0LjY3MiAxOC43MzggMjg0LjU1NiAxOC42NzkgMjg0LjQ5IDE4LjYxMyBDIDI4NC40NDkgMTguNTcyIDI4NC40MSAxOC41MjMgMjg0LjM3MSAxOC40NzIgQyA" .
"yODQuMjg1IDE4LjE3NCAyODMuOTU3IDE3Ljk1NCAyODMuNjY1IDE3Ljg5NCBDIDI4My4yNjQgMTcuODExIDI4Mi44NTUgMTcuOTY4IDI4Mi42OTkgMTguMzU5IEMgMjgyLjUzOSAxOC43NjMgMjgyL" .
"jQ2OSAxOS4xOTYgMjgyLjQwMSAxOS42MjIgQyAyODIuMzI2IDIwLjA4OCAyODIuMzgxIDIwLjQ4NCAyODIuNzIgMjAuODMzIEMgMjgyLjg4NSAyMS4wMDIgMjgzLjA3OSAyMS4xMzEgMjgzLjI0OCA" .
"yMS4yOTIgQyAyODMuNDMyIDIxLjQ2NyAyODMuNjAyIDIxLjY1NyAyODMuNzg3IDIxLjgzMiBDIDI4NC4wNzcgMjIuMTA1IDI4NC4zOTEgMjIuMzg2IDI4NC43NzYgMjIuNTAxIEMgMjg1LjMgMjIuN" .
"jU3IDI4NS43ODMgMjIuOCAyODYuMjY1IDIzLjA2NCBDIDI4Ni40NjkgMjMuMTc2IDI4Ni43MDggMjMuMjQyIDI4Ni45IDIzLjM3NCBDIDI4Ny4wMDIgMjMuNDQ0IDI4Ny4xMDEgMjMuNTI4IDI4Ny4" .
"yIDIzLjYwMyBDIDI4Ny4yNyAyMy42NTUgMjg3LjM5MSAyMy43MTIgMjg3LjQyNCAyMy43OTYgQyAyODcuNDYyIDIzLjg5MiAyODcuNTQgMjMuOTM4IDI4Ny42MjIgMjMuOTUxIEMgMjg3Ljc0MiAyM" .
"y45OTUgMjg3Ljg4NSAyMy45NTggMjg3LjkxNCAyMy43OTYgQyAyODcuOTQzIDIzLjYyOCAyODcuOTY2IDIzLjQ1NSAyODcuOTMxIDIzLjI4NSBaICIgZmlsbC1ydWxlPSJldmVub2RkIiBmaWxsPSJ" .
"yZ2IoMjU0LDI1NCwyNTQpIi8+PHBhdGggZD0iIE0gMjk2LjY0MyAxMS4zMjQgQyAyOTYuMjU3IDEwLjkyMyAyOTUuNzg1IDEwLjk3NCAyOTUuMjkzIDExLjExOCBDIDI5NC44NzIgMTEuMjQxIDI5N" .
"C40MTUgMTEuNCAyOTQuMTc3IDExLjc5MiBDIDI5My45MjcgMTIuMjAyIDI5My45MyAxMi42MjQgMjkzLjg4IDEzLjA4NCBDIDI5My44MzQgMTMuNTEyIDI5My44MTQgMTMuOTI2IDI5My44MTQgMTQ" .
"uMzU2IEMgMjkzLjgxNCAxNC44NTYgMjkzLjc3OCAxNS4zNDQgMjkzLjg3MSAxNS44MzcgQyAyOTMuOTUzIDE2LjI3NyAyOTQuMTM4IDE2LjY3OCAyOTQuMjc2IDE3LjA5NyBDIDI5NC4zNjQgMTcuM" .
"zU5IDI5NC4zNDkgMTcuNjQxIDI5NC40NDcgMTcuOTA1IEMgMjk0LjUyNiAxOC4xMjEgMjk0LjU2NiAxOC4zMSAyOTQuNjIyIDE4LjUzNSBDIDI5NC42NDMgMTguNjE4IDI5NC42NzkgMTguNjgxIDI" .
"5NC43MjQgMTguNzMyIEMgMjk0LjgxMSAxOC45OTQgMjk0Ljc5IDE5LjMwNyAyOTQuNzcyIDE5LjU2OCBDIDI5NC43NjEgMTkuNzMyIDI5NC43NTIgMTkuODg4IDI5NC43MjcgMjAuMDUgQyAyOTQuN" .
"zA4IDIwLjE3NyAyOTQuNzA2IDIwLjI1OCAyOTQuNzcxIDIwLjM3MyBDIDI5NC44MzggMjAuNDkxIDI5NC45ODQgMjAuNTE2IDI5NS4wOTkgMjAuNDU5IEMgMjk1LjMxNSAyMC4zNTMgMjk1LjUwOSA" .
"yMC4wNzYgMjk1LjYyNiAxOS44NzIgQyAyOTUuODA4IDE5LjU1MyAyOTUuOTc5IDE5LjE4OSAyOTYuMDcgMTguODMxIEMgMjk2LjI2OCAxOC4wNTggMjk2LjUyMyAxNy4yODkgMjk2LjYyOCAxNi40O" .
"TUgQyAyOTYuNzE0IDE1Ljg0MSAyOTYuNjUzIDE1LjE5NyAyOTYuNzEzIDE0LjU0NSBDIDI5Ni43NDMgMTQuMjE2IDI5Ni43NTMgMTMuODg0IDI5Ni43NzQgMTMuNTU2IEMgMjk2Ljc5MiAxMy4yNzU" .
"gMjk2LjgzOCAxMi45OTQgMjk2Ljg2MSAxMi43MTMgQyAyOTYuODYxIDEyLjcxMyAyOTYuODYxIDEyLjcxMyAyOTYuODYxIDEyLjcxMiBDIDI5Ni44NzQgMTIuNjg5IDI5Ni44ODkgMTIuNjY5IDI5N" .
"i44OTggMTIuNjQzIEMgMjk3LjA0OCAxMi4yNTEgMjk2LjkzNiAxMS42MjkgMjk2LjY0MyAxMS4zMjQgWiAiIGZpbGwtcnVsZT0iZXZlbm9kZCIgZmlsbD0icmdiKDI1NCwyNTQsMjU0KSIvPjxwYXR" .
"oIGQ9IiBNIDMwOC4yNzEgMjAuMTUyIEMgMzA4LjExMSAxOS45MzggMzA3LjkwOCAxOS43OTEgMzA3LjcxMSAxOS42MjkgQyAzMDcuNjQ1IDE5LjQ4NSAzMDcuNTI5IDE5LjM3NyAzMDcuMzgyIDE5L" .
"jM0MSBDIDMwNy4zNzQgMTkuMzM3IDMwNy4zNjUgMTkuMzMyIDMwNy4zNTcgMTkuMzI3IEMgMzA3LjM0NSAxOS4zMiAzMDcuMzM3IDE5LjMwOSAzMDcuMzI0IDE5LjMwMiBDIDMwNy4zMTIgMTkuMjk" .
"2IDMwNy4yNzYgMTkuMjc1IDMwNy4yNzYgMTkuMjc0IEMgMzA3LjMgMTkuMjk0IDMwNy4yNDIgMTkuMjMxIDMwNy4yNDYgMTkuMjM2IEMgMzA3LjA2MiAxOS4wMDYgMzA2Ljc5NCAxOS4wNjEgMzA2L" .
"jU1MiAxOS4xNDMgQyAzMDYuNDMxIDE5LjE4NCAzMDYuMzAyIDE5LjIwOCAzMDYuMTg3IDE5LjI2NCBDIDMwNi4wOTUgMTkuMzEgMzA2LjAyOCAxOS4zOTYgMzA1Ljk0NyAxOS40NDggQyAzMDUuODU" .
"0IDE5LjUwNyAzMDUuNzU5IDE5LjU1NiAzMDUuNjY5IDE5LjYyMSBDIDMwNS41OTQgMTkuNjc2IDMwNS41MzkgMTkuNzQ1IDMwNS40ODkgMTkuODIgQyAzMDUuNDIxIDE5Ljg3OSAzMDUuMzUzIDE5L" .
"jkzOSAzMDUuMjg5IDIwLjAxIEMgMzA1LjIyNSAyMC4wOCAzMDUuMTU5IDIwLjE0OSAzMDUuMTA1IDIwLjIyOCBDIDMwNS4wMzggMjAuMzIzIDMwNSAyMC40MzQgMzA0LjkzMyAyMC41MyBDIDMwNC4" .
"4MTcgMjAuNjk4IDMwNC43MjYgMjAuODYzIDMwNC42NjcgMjEuMDYxIEMgMzA0LjYyNiAyMS4xOTkgMzA0LjU5IDIxLjM0MiAzMDQuNTE5IDIxLjQ2MyBDIDMwNC41MTcgMjEuNDY2IDMwNC41MTYgM" .
"jEuNDY4IDMwNC41MTUgMjEuNDcxIEMgMzA0LjQ5IDIxLjUxMiAzMDQuNDYxIDIxLjU1MSAzMDQuNDI2IDIxLjU4NiBDIDMwNC4zNjkgMjEuNjQzIDMwNC4zNTEgMjEuNzA5IDMwNC4zNTkgMjEuNzc" .
"xIEMgMzA0LjMxNCAyMS44NDkgMzA0LjI2OCAyMS45MjcgMzA0LjIzMSAyMi4wMDggQyAzMDQuMTgzIDIyLjExIDMwNC4xMzIgMjIuMTY2IDMwNC4wNjUgMjIuMjU3IEMgMzAzLjkxOCAyMi40NTYgM" .
"zAzLjY4IDIyLjcwNyAzMDMuNDI1IDIyLjc2MyBDIDMwMy4zOTQgMjIuNzcgMzAzLjM2NyAyMi43ODIgMzAzLjM0NSAyMi43OTcgQyAzMDMuMTUyIDIyLjg0IDMwMy4wNDIgMjMuMTIgMzAzLjI2MyA" .
"yMy4yMjggQyAzMDMuNDM3IDIzLjMxNCAzMDMuNTkyIDIzLjQwOCAzMDMuNzkgMjMuNDI0IEMgMzAzLjk2NyAyMy40MzcgMzA0LjE0OCAyMy40MjEgMzA0LjMyNiAyMy40MjUgQyAzMDQuNjcgMjMuN" .
"DMzIDMwNC45OCAyMy4zNDEgMzA1LjI5NyAyMy4yMTcgQyAzMDUuNjI3IDIzLjA4OCAzMDUuOTY4IDIzLjAyMSAzMDYuMjgxIDIyLjg1NCBDIDMwNi41OCAyMi42OTMgMzA2Ljg0OCAyMi40NjMgMzA" .
"3LjExNSAyMi4yNTMgQyAzMDcuMzg3IDIyLjA0IDMwNy42NzEgMjEuODk2IDMwNy45OTMgMjEuNzY3IEMgMzA4LjI5OCAyMS42NDYgMzA4LjU3MiAyMS40NjMgMzA4LjU4MiAyMS4xMDMgQyAzMDguN" .
"TkxIDIwLjc4NSAzMDguNDU5IDIwLjQwMyAzMDguMjcxIDIwLjE1MiBaICIgZmlsbC1ydWxlPSJldmVub2RkIiBmaWxsPSJyZ2IoMjU0LDI1NCwyNTQpIi8+PHBhdGggZD0iIE0gMzE0LjQ5NCAzMC4" .
"1OTUgQyAzMTQuMzY1IDMwLjM4NyAzMTQuMzE5IDMwLjI3OSAzMTQuMDcxIDMwLjIwNCBDIDMxMy43MDMgMzAuMDkyIDMxMy4zMSAzMC4wNTQgMzEyLjkzIDMwLjAwMiBDIDMxMi4yOTIgMjkuOTE0I" .
"DMxMS42MDUgMjkuODg1IDMxMC45NzMgMzAuMDA5IEMgMzEwLjYxNCAzMC4wOCAzMTAuMjk1IDMwLjA5MSAzMDkuOTY1IDMwLjI2NCBDIDMwOS42NDMgMzAuNDMyIDMwOS4yNTkgMzAuNTU3IDMwOC4" .
"5MDQgMzAuNjMgQyAzMDguMDkzIDMwLjc5NyAzMDcuMjk0IDMwLjkgMzA2LjQ2NyAzMC45IEMgMzA2LjI4NiAzMC45IDMwNi4yMTMgMzEuMDY0IDMwNi4yNDQgMzEuMiBDIDMwNi4yNDcgMzEuMjM3I" .
"DMwNi4yNTcgMzEuMjc1IDMwNi4yODYgMzEuMzE0IEMgMzA2LjM2OSAzMS40MjggMzA2LjQxMSAzMS41NTUgMzA2LjUxMSAzMS42NiBDIDMwNi42MTcgMzEuNzcyIDMwNi43NSAzMS44NDkgMzA2Ljg" .
"2MyAzMS45NSBDIDMwNy4wODIgMzIuMTQ1IDMwNy4yMDIgMzIuMzI2IDMwNy40ODUgMzIuNDMzIEMgMzA4LjE2NyAzMi42OTIgMzA4Ljg0MyAzMi45MDEgMzA5LjU2NiAzMy4wMDcgQyAzMTAuMjY5I" .
"DMzLjExMSAzMTEuMDAyIDMzLjEyOSAzMTEuNzEzIDMzLjA4NCBDIDMxMi40NDcgMzMuMDM4IDMxMy4xNzggMzMuMDY4IDMxMy45MDggMzMuMTE4IEMgMzE0LjQ5OSAzMy4xNTkgMzE0LjkwNiAzMi4" .
"4ODIgMzE0Ljk1OSAzMi4yODkgQyAzMTUuMDEzIDMxLjY3NiAzMTQuODE0IDMxLjExIDMxNC40OTQgMzAuNTk1IFogIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGZpbGw9InJnYigyNTQsMjU0LDI1NCkiL" .
"z48cGF0aCBkPSIgTSAzMDcuNDQzIDQxLjU0NyBDIDMwNy4yNjEgNDEuMjEzIDMwNi45NTggNDAuOTIzIDMwNi42NzYgNDAuNjczIEMgMzA2LjU1NyA0MC41NjcgMzA2LjQxMiA0MC40NzkgMzA2LjI" .
"3NCA0MC40MDMgQyAzMDYuMTg2IDQwLjM1NCAzMDYuMDcxIDQwLjI1MiAzMDUuOTU0IDQwLjE3NiBDIDMwNS42NCAzOS45NzEgMzA1LjI3OSAzOS44NjkgMzA0LjkxMyAzOS44MTYgQyAzMDQuNzU2I" .
"DM5Ljc5MyAzMDQuNjQ4IDM5Ljc3NSAzMDQuNTA3IDM5LjcwNSBDIDMwNC4zMDcgMzkuNjA0IDMwNC4xMDIgMzkuNTI3IDMwMy44OTkgMzkuNDQxIEMgMzAzLjczIDM5LjM3MSAzMDMuNTk0IDM5LjE" .
"5NyAzMDMuNDQzIDM5LjA4OCBDIDMwMy4zMTEgMzguOTkyIDMwMy4xNDMgMzguOTAzIDMwMi45ODEgMzguODM4IEMgMzAyLjg1MiAzOC43MDQgMzAyLjU2MyAzOC43NTQgMzAyLjU2NCAzOC45OTUgQ" .
"yAzMDIuNTY2IDM5LjI2NSAzMDIuNjI2IDM5LjUyMSAzMDIuNjQyIDM5Ljc4NiBDIDMwMi42NTcgNDAuMDM4IDMwMi43MTggNDAuMjk4IDMwMi43ODYgNDAuNTQxIEMgMzAyLjg1MiA0MC43NzcgMzA" .
"yLjk1OSA0MS4wMTIgMzAzLjA2OCA0MS4yMzMgQyAzMDMuMTc5IDQxLjQ1OCAzMDMuMzM2IDQxLjYwOSAzMDMuNDg3IDQxLjgwNiBDIDMwMy44MTcgNDIuMjM4IDMwNC4xNjYgNDIuNjcxIDMwNC41N" .
"DEgNDMuMDY1IEMgMzA0LjcyNiA0My4yNTkgMzA0LjkyOSA0My40NjIgMzA1LjEzNCA0My42MzMgQyAzMDUuMjEyIDQzLjY5OCAzMDUuMzA4IDQzLjczNyAzMDUuMzk2IDQzLjc4NyBDIDMwNS40Mzg" .
"gNDMuOTc3IDMwNS41OTkgNDQuMDcgMzA1Ljc5MyA0NC4yMTggQyAzMDYuMDggNDQuNDM4IDMwNi40MDcgNDQuNTY1IDMwNi43NiA0NC40MDQgQyAzMDcuMDY3IDQ0LjI2NCAzMDcuMjc3IDQ0LjAzN" .
"SAzMDcuMzQ3IDQzLjcwNSBDIDMwNy4zODQgNDMuNTMyIDMwNy40NSA0My4zODQgMzA3LjUxNSA0My4yMTkgQyAzMDcuNTg1IDQzLjA0MiAzMDcuNjEzIDQyLjg1MSAzMDcuNjQzIDQyLjY2MiBDIDM" .
"wNy43MTQgNDIuMjM1IDMwNy42NDkgNDEuOTI0IDMwNy40NDMgNDEuNTQ3IFogIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGZpbGw9InJnYigyNTQsMjU0LDI1NCkiLz48cGF0aCBkPSIgTSAyOTYuMzMgN" .
"DQuOTMyIEMgMjk2LjI3NiA0NC41NjYgMjk2LjI2IDQ0LjE3NSAyOTYuMTQ0IDQzLjgyMiBDIDI5Ni4wMzEgNDMuNDcyIDI5NS44NjggNDMuMTEzIDI5NS43OTYgNDIuNzUyIEMgMjk1LjcyNSA0Mi4" .
"zOTQgMjk1LjY1NyA0Mi4wNDQgMjk1LjY0IDQxLjY4IEMgMjk1LjYyNSA0MS4zNjggMjk1LjYyMyA0MS4wMTggMjk1LjQ1MSA0MC43NDUgQyAyOTUuMzI5IDQwLjU1NSAyOTUuMDc2IDQwLjYzNCAyO" .
"TUuMDEzIDQwLjc5MyBDIDI5NC45OTggNDAuODA0IDI5NC45ODIgNDAuODEyIDI5NC45NjggNDAuODI4IEMgMjk0LjgwNSA0MS4wMjQgMjk0LjYzMSA0MS4xODUgMjk0LjUwMyA0MS40MDQgQyAyOTQ" .
"uMzQxIDQxLjY3OSAyOTQuMjY1IDQxLjk2NSAyOTQuMTYyIDQyLjI2MyBDIDI5My45MTggNDIuOTY5IDI5My43MTUgNDMuNyAyOTMuNTk1IDQ0LjQ0IEMgMjkzLjUwNCA0NS4wMDIgMjkzLjUwNCA0N" .
"S41NzkgMjkzLjQzOSA0Ni4xNDcgQyAyOTMuMzcgNDYuNzQxIDI5My4yNzQgNDcuMzU4IDI5My4zMTEgNDcuOTYxIEMgMjkzLjM0OCA0OC41NjEgMjkzLjcwNSA0OC45NjQgMjk0LjMzMSA0OC45MzY" .
"gQyAyOTQuNDU5IDQ4LjkzIDI5NC41NDEgNDguOTAzIDI5NC42NTMgNDguODUxIEMgMjk0Ljc2NiA0OC44IDI5NC44NzcgNDguNzc3IDI5NC45OTggNDguNzM4IEMgMjk1LjI4OSA0OC42NDQgMjk1L" .
"jUxMSA0OC40OSAyOTUuNzQ3IDQ4LjMwMSBDIDI5NS45NiA0OC4xMjggMjk2LjA5OSA0OC4wMiAyOTYuMTQyIDQ3Ljc0MSBDIDI5Ni4xOTIgNDcuNDE3IDI5Ni4yMzQgNDcuMDkzIDI5Ni4yNDYgNDY" .
"uNzY3IEMgMjk2LjI1OCA0Ni40NDMgMjk2LjMxOCA0Ni4xMiAyOTYuMzI3IDQ1Ljc5NSBDIDI5Ni4zMzUgNDUuNTEgMjk2LjM3MSA0NS4yMTMgMjk2LjMzIDQ0LjkzMiBaICIgZmlsbC1ydWxlPSJld" .
"mVub2RkIiBmaWxsPSJyZ2IoMjU0LDI1NCwyNTQpIi8+PHBhdGggZD0iIE0gMjg2LjE4MSA0MC4xMjYgQyAyODYuMTY4IDQwLjEyNSAyODYuMTU2IDQwLjEyNyAyODYuMTQzIDQwLjEyNyBDIDI4Ni4" .
"wOSA0MC4xMDkgMjg2LjAzMiA0MC4xMDggMjg1Ljk3NSA0MC4xMzIgQyAyODUuNTU3IDQwLjE0NiAyODUuMTQ1IDQwLjIxOSAyODQuNzU3IDQwLjM3OSBDIDI4NC41MzEgNDAuNDczIDI4NC4zMTggN" .
"DAuNTczIDI4NC4xMTYgNDAuNzEyIEMgMjgzLjkzNCA0MC44MzggMjgzLjcxNiA0MC45MzggMjgzLjU1IDQxLjA4NCBDIDI4My4zNzUgNDEuMjM4IDI4My4yNTcgNDEuNDU0IDI4My4wOTQgNDEuNjI" .
"xIEMgMjgyLjkxNSA0MS44MDMgMjgyLjczMyA0MS45NzggMjgyLjUyOSA0Mi4xMzQgQyAyODIuMTUzIDQyLjQyMyAyODEuODY2IDQyLjc4NCAyODEuNTk0IDQzLjE3MSBDIDI4MS4zMDggNDMuNTc3I" .
"DI4MS41MDggNDQuMTA5IDI4MS44MjIgNDQuNDM4IEMgMjgyLjA1MyA0NC42NzkgMjgyLjM4IDQ0Ljc3OCAyODIuNzAxIDQ0Ljg0OSBDIDI4Mi43MzEgNDQuODY1IDI4Mi43NjcgNDQuODc2IDI4Mi4" .
"4MDggNDQuODc2IEMgMjgyLjg2MSA0NC44NzcgMjgyLjkwOCA0NC44OTEgMjgyLjk1NSA0NC45MDMgQyAyODIuOTY0IDQ0LjkwNSAyODIuOTczIDQ0LjkwNyAyODIuOTgxIDQ0LjkwOCBDIDI4Mi45O" .
"TUgNDQuOTExIDI4My4wMDkgNDQuOTEzIDI4My4wMjIgNDQuOTE2IEMgMjgzLjA4NyA0NC45MzcgMjgzLjE1MSA0NC45NjQgMjgzLjIxNyA0NC45ODkgQyAyODMuMzczIDQ1LjA0NyAyODMuNDk1IDQ" .
"1LjEyMyAyODMuNjcyIDQ1LjA3NiBDIDI4My43NTQgNDUuMDU0IDI4My44MjQgNDUuMDA0IDI4My45IDQ0Ljk2OCBDIDI4My45NyA0NC45MzUgMjg0LjA0IDQ0LjkwNSAyODQuMTA2IDQ0Ljg2NCBDI" .
"DI4NC4yNDggNDQuNzc3IDI4NC4zNzQgNDQuNjU3IDI4NC40OTIgNDQuNTM5IEMgMjg0LjU0NyA0NC40ODUgMjg0LjU5MiA0NC40MjMgMjg0LjY0OCA0NC4zNyBDIDI4NC42OTYgNDQuMzIzIDI4NC4" .
"3NTIgNDQuMjgzIDI4NC43OTggNDQuMjM0IEMgMjg0LjkwOSA0NC4xMTQgMjg0Ljk3MSA0My45NTggMjg1LjA2NCA0My44MjYgQyAyODUuMTUzIDQzLjY5OSAyODUuMjUyIDQzLjU0NyAyODUuMzE5I" .
"DQzLjM4NyBDIDI4NS41MjggNDMuMDYyIDI4NS41NzMgNDIuNjI4IDI4NS42MjkgNDIuMjM5IEMgMjg1LjcxOCA0Mi4wNzYgMjg1Ljc5OSA0MS45MDggMjg1Ljg3OSA0MS43NSBDIDI4NS45OSA0MS4" .
"1MjkgMjg2LjA3NiA0MS4zMDIgMjg2LjE2MyA0MS4wNzEgQyAyODYuMiA0MC45NzMgMjg2LjI1OSA0MC44OSAyODYuMzAzIDQwLjc5NiBDIDI4Ni4zNDYgNDAuNzA0IDI4Ni4zNTkgNDAuNjEgMjg2L" .
"jM2NCA0MC41MTEgQyAyODYuNDYyIDQwLjM3NSAyODYuNDAzIDQwLjEyOSAyODYuMTgxIDQwLjEyNiBaICIgZmlsbC1ydWxlPSJldmVub2RkIiBmaWxsPSJyZ2IoMjU0LDI1NCwyNTQpIi8+PHBhdGg" .
"gZD0iIE0gMjgzLjk0IDMxLjkyMyBDIDI4My45MzYgMzEuODkyIDI4My45MjYgMzEuODYgMjgzLjkwNCAzMS44MjggQyAyODMuNzYzIDMxLjYyOCAyODMuNzA5IDMxLjM1MyAyODMuNDg1IDMxLjIyM" .
"iBDIDI4My4yMjcgMzEuMDcxIDI4Mi45NjggMzAuOTIyIDI4Mi42OTMgMzAuODAyIEMgMjgyLjE0IDMwLjU1OCAyODEuNTI1IDMwLjQ1MSAyODAuOTMgMzAuMzkyIEMgMjgwLjM3MyAzMC4zMzYgMjc" .
"5Ljc3NCAzMC4yNzUgMjc5LjIxNiAzMC4zMzUgQyAyNzguNjE1IDMwLjQwMSAyNzguMDIyIDMwLjI2NiAyNzcuNDMzIDMwLjE3OSBDIDI3Ni45MzQgMzAuMTA2IDI3Ni40MTIgMzAuMTg0IDI3Ni4xO" .
"DcgMzAuNjkzIEMgMjc2LjA5OSAzMC44OTQgMjc2LjEwMyAzMS4yMTMgMjc2LjExNSAzMS40MzEgQyAyNzYuMTMyIDMxLjc1OCAyNzYuMjU5IDMyLjAyNCAyNzYuNDMxIDMyLjI5NSBDIDI3Ni41NzI" .
"gMzIuNTE5IDI3Ni42NSAzMi44NSAyNzYuODk0IDMyLjk4NSBDIDI3Ny4xOTYgMzMuMTUyIDI3Ny41NDIgMzMuMTMxIDI3Ny44NzEgMzMuMTg5IEMgMjc4LjQ5NyAzMy4yOTkgMjc5LjEwMiAzMy4yN" .
"TIgMjc5LjczNiAzMy4yNTIgQyAyODAuMTU0IDMzLjI1MiAyODAuNTA4IDMzLjI0IDI4MC45IDMzLjA3OCBDIDI4MS4xOTEgMzIuOTU4IDI4MS40MjcgMzIuNzQ1IDI4MS43MDkgMzIuNjEzIEMgMjg" .
"yIDMyLjQ3NyAyODIuNDY2IDMyLjQxMiAyODIuNzg1IDMyLjQwNiBDIDI4Mi45NTkgMzIuNDAzIDI4My4xMyAzMi4zODggMjgzLjMwMyAzMi4zODIgQyAyODMuNDYzIDMyLjM3NiAyODMuNjAyIDMyL" .
"jM0NiAyODMuNzU3IDMyLjMyOSBDIDI4My45NzUgMzIuMzA1IDI4NC4wMzUgMzIuMDUzIDI4My45NCAzMS45MjMgWiAiIGZpbGwtcnVsZT0iZXZlbm9kZCIgZmlsbD0icmdiKDI1NCwyNTQsMjU0KSI" .
"vPjwvZz48L2c+PC9nPjxwYXRoIGQ9Ik0gNDEgNzMuNTU2IEwgOTYgNzMuNTU2IEMgMTA3LjAzOCA3My41NTYgMTE2IDgyLjEwOSAxMTYgOTIuNjQ1IEwgMTE2IDkyLjY0NSBDIDExNiAxMDMuMTggM" .
"TA3LjAzOCAxMTEuNzM0IDk2IDExMS43MzQgTCA0MSAxMTEuNzM0IEMgMjkuOTYyIDExMS43MzQgMjEgMTAzLjE4IDIxIDkyLjY0NSBMIDIxIDkyLjY0NSBDIDIxIDgyLjEwOSAyOS45NjIgNzMuNTU" .
"2IDQxIDczLjU1NiBaIiBzdHlsZT0ic3Ryb2tlOm5vbmU7ZmlsbDojRkZGRkZGO3N0cm9rZS1taXRlcmxpbWl0OjEwOyIvPjxwYXRoIGQ9IiBNIDM1Ljg3NiA5Ny45NDQgQyAzNS44NzYgOTguODggM" .
"zYuMzcgOTkuMzc0IDM3LjMwNiA5OS4zNzQgTCAzOC45NDQgOTkuMzc0IEMgMzkuODggOTkuMzc0IDQwLjM3NCA5OC44OCA0MC4zNzQgOTcuOTQ0IEwgNDAuMzc0IDk0LjE3NCBDIDQwLjM3NCA5MS4" .
"5OSA0MS40MTQgOTAuMTcgNDMuNTQ2IDkwLjE3IEMgNDQuNDgyIDkwLjE3IDQ0Ljk3NiA4OS42NzYgNDQuOTc2IDg4Ljc0IEwgNDQuOTc2IDg3LjI4NCBDIDQ0Ljk3NiA4Ni4zNDggNDQuNjY0IDg1L" .
"jg1NCA0My43NTQgODUuODU0IEMgNDIuMDY0IDg1Ljg1NCA0MC42MzQgODcuNyA0MC4xOTIgODkuMDc4IEwgNDAuMTQgODkuMDc4IEMgNDAuMTQgODkuMDc4IDQwLjE5MiA4OC42ODggNDAuMTkyIDg" .
"4LjI0NiBMIDQwLjE5MiA4Ny40MTQgQyA0MC4xOTIgODYuNDc4IDM5LjY5OCA4NS45ODQgMzguNzYyIDg1Ljk4NCBMIDM3LjMwNiA4NS45ODQgQyAzNi4zNyA4NS45ODQgMzUuODc2IDg2LjQ3OCAzN" .
"S44NzYgODcuNDE0IEwgMzUuODc2IDk3Ljk0NCBaICBNIDQ1LjUyMiA5Mi42NjYgQyA0NS41MjIgOTYuMjI4IDQ4LjA5NiA5OS42ODYgNTIuODggOTkuNjg2IEMgNTQuOTM0IDk5LjY4NiA1Ni41MiA" .
"5OS4wMzYgNTcuNDgyIDk4LjQ5IEMgNTguMjYyIDk4LjA0OCA1OC4zOTIgOTcuMzQ2IDU3Ljk3NiA5Ni41NCBMIDU3LjYxMiA5NS44NjQgQyA1Ny4xNyA5NS4wMzIgNTYuNTQ2IDk0LjkyOCA1NS42O" .
"DggOTUuMzE4IEMgNTUuMDEyIDk1LjY1NiA1NC4xNTQgOTUuOTQyIDUzLjI0NCA5NS45NDIgQyA1MS44MTQgOTUuOTQyIDUwLjQ2MiA5NS4xODggNTAuMTI0IDkzLjU1IEwgNTcuMTcgOTMuNTUgQyA" .
"1OC4wNTQgOTMuNTUgNTguNjc4IDkyLjc3IDU4LjY3OCA5Mi4wNjggQyA1OC42NzggODguNDggNTYuNDY4IDg1LjY3MiA1Mi40OSA4NS42NzIgQyA0OC4xNzQgODUuNjcyIDQ1LjUyMiA4OC43NCA0N" .
"S41MjIgOTIuNjY2IFogIE0gNTAuMjI4IDkwLjk3NiBDIDUwLjQ2MiA4OS45MSA1MS4wODYgODguOTc0IDUyLjM4NiA4OC45NzQgQyA1My41MyA4OC45NzQgNTQuMjMyIDg5Ljg4NCA1NC4yMzIgOTA" .
"uOTc2IEwgNTAuMjI4IDkwLjk3NiBaICBNIDU5LjYxNCA5NS42MDQgQyA1OS42MTQgOTcuOTE4IDYxLjQwOCA5OS42ODYgNjMuOTgyIDk5LjY4NiBDIDY2LjY2IDk5LjY4NiA2Ny45MzQgOTcuNTU0I" .
"DY3LjkzNCA5Ny41NTQgTCA2Ny45ODYgOTcuNTU0IEMgNjcuOTg2IDk3LjU1NCA2Ny45NiA5Ny42NTggNjcuOTYgOTcuODE0IEwgNjcuOTYgOTcuOTE4IEMgNjcuOTYgOTguOTA2IDY4LjQ1NCA5OS4" .
"zNzQgNjkuMzkgOTkuMzc0IEwgNzAuNjM4IDk5LjM3NCBDIDcxLjU3NCA5OS4zNzQgNzIuMDY4IDk4Ljg4IDcyLjA2OCA5Ny45NDQgTCA3Mi4wNjggOTEuMTMyIEMgNzIuMDY4IDg3LjcyNiA2OS43O" .
"CA4NS42NzIgNjYuMDg4IDg1LjY3MiBDIDY0LjE2NCA4NS42NzIgNjIuNjA0IDg2LjI0NCA2MS41OSA4Ni43MTIgQyA2MC43ODQgODcuMTI4IDYwLjYyOCA4Ny44MyA2MS4wMTggODguNjM2IEwgNjE" .
"uMzMgODkuMjg2IEMgNjEuNzQ2IDkwLjExOCA2Mi4zOTYgOTAuMjc0IDYzLjI1NCA4OS45MSBDIDYzLjkzIDg5LjU5OCA2NC44MTQgODkuMzEyIDY1LjY3MiA4OS4zMTIgQyA2Ni42NiA4OS4zMTIgN" .
"jcuNTcgODkuNjc2IDY3LjU3IDkwLjc5NCBMIDY3LjU3IDkxLjAyOCBMIDY3LjE4IDkxLjAyOCBDIDYzLjg3OCA5MS4wMjggNTkuNjE0IDkyLjAxNiA1OS42MTQgOTUuNjA0IFogIE0gNjQuMDYgOTU" .
"uMTg4IEMgNjQuMDYgOTMuOTY2IDY1LjgyOCA5My42MDIgNjcuMjA2IDkzLjYwMiBMIDY3LjYyMiA5My42MDIgTCA2Ny42MjIgOTMuOTY2IEMgNjcuNjIyIDk1LjA1OCA2Ni42MzQgOTYuMzMyIDY1L" .
"jMzNCA5Ni4zMzIgQyA2NC41MDIgOTYuMzMyIDY0LjA2IDk1LjgxMiA2NC4wNiA5NS4xODggWiAgTSA3My44MSA5Mi42NjYgQyA3My44MSA5Ni44MjYgNzYuMjAyIDk5LjY4NiA3OS44NDIgOTkuNjg" .
"2IEMgODIuNTk4IDk5LjY4NiA4My43MTYgOTcuNzM2IDgzLjcxNiA5Ny43MzYgTCA4My43NjggOTcuNzM2IEMgODMuNzY4IDk3LjczNiA4My43NDIgOTcuODY2IDgzLjc0MiA5Ny45MTggTCA4My43N" .
"DIgOTguMDc0IEMgODMuNzQyIDk4LjkzMiA4NC4yMzYgOTkuMzc0IDg1LjE3MiA5OS4zNzQgTCA4Ni41MjQgOTkuMzc0IEMgODcuNDYgOTkuMzc0IDg3Ljk1NCA5OC44OCA4Ny45NTQgOTcuOTQ0IEw" .
"gODcuOTU0IDgyLjIxNCBDIDg3Ljk1NCA4MS4yNzggODcuNDYgODAuNzg0IDg2LjUyNCA4MC43ODQgTCA4NC44ODYgODAuNzg0IEMgODMuOTUgODAuNzg0IDgzLjQ1NiA4MS4yNzggODMuNDU2IDgyL" .
"jIxNCBMIDgzLjQ1NiA4Ni40IEMgODMuNDU2IDg2LjczOCA4My40ODIgODYuOTk4IDgzLjQ4MiA4Ni45OTggTCA4My40MyA4Ni45OTggQyA4My40MyA4Ni45OTggODIuNjI0IDg1LjY3MiA3OS45MiA" .
"4NS42NzIgQyA3Ni4zNTggODUuNjcyIDczLjgxIDg4LjQyOCA3My44MSA5Mi42NjYgWiAgTSA3OC4zMzQgOTIuNjY2IEMgNzguMzM0IDkwLjYxMiA3OS41NTYgODkuNDY4IDgwLjk2IDg5LjQ2OCBDI" .
"DgyLjcyOCA4OS40NjggODMuNTg2IDkxLjA1NCA4My41ODYgOTIuNjY2IEMgODMuNTg2IDk0Ljk4IDgyLjMxMiA5NS45NjggODAuOTYgOTUuOTY4IEMgNzkuNCA5NS45NjggNzguMzM0IDk0LjY2OCA" .
"3OC4zMzQgOTIuNjY2IFogIE0gOTAuMDg2IDEwMS44NyBMIDg5Ljc0OCAxMDIuNjI0IEMgODkuMzg0IDEwMy40NTYgODkuNTkyIDEwNC4yMSA5MC40NSAxMDQuNDk2IEMgOTAuOTcgMTA0LjY3OCA5M" .
"S42MiAxMDQuODM0IDkyLjMyMiAxMDQuODM0IEMgOTQuNDU0IDEwNC44MzQgOTYuNzk0IDEwMy43OTQgOTcuOTEyIDEwMC44MDQgTCAxMDIuOCA4Ny42MjIgQyAxMDMuMTY0IDg2LjYzNCAxMDIuNjk" .
"2IDg1Ljk4NCAxMDEuNjU2IDg1Ljk4NCBMIDk5Ljc1OCA4NS45ODQgQyA5OC45IDg1Ljk4NCA5OC4zOCA4Ni4zNzQgOTguMTQ2IDg3LjIwNiBMIDk2Ljc5NCA5Mi4yMjQgQyA5Ni42MTIgOTIuOTUyI" .
"Dk2LjM1MiA5NC4yMjYgOTYuMzUyIDk0LjIyNiBMIDk2LjMgOTQuMjI2IEMgOTYuMyA5NC4yMjYgOTYuMDQgOTIuODc0IDk1LjgwNiA5Mi4xNDYgTCA5NC4yMiA4Ny4xNTQgQyA5My45NiA4Ni4zNzQ" .
"gOTMuNDQgODUuOTg0IDkyLjU4MiA4NS45ODQgTCA5MC41NTQgODUuOTg0IEMgODkuNDYyIDg1Ljk4NCA4OS4wMiA4Ni42NiA4OS40NjIgODcuNjQ4IEwgOTQuNDI4IDk5LjExNCBMIDk0LjE2OCA5O" .
"S43MzggQyA5My44NTYgMTAwLjUxOCA5My4xMjggMTAxLjE2OCA5Mi4zMjIgMTAxLjE2OCBDIDkyLjAxIDEwMS4xNjggOTEuNzUgMTAxLjA5IDkxLjU0MiAxMDEuMDM4IEMgOTAuOTk2IDEwMC45MzQ" .
"gOTAuNDUgMTAxLjA2NCA5MC4wODYgMTAxLjg3IFogIiBmaWxsPSJyZ2IoMjQ1LDE2MSw3NCkiLz48L2c+PC9zdmc+";
      return 'data:image/svg+xml;base64,' . $image;
    }

    /*----------------------*/
    public static function keyAdminCssStyles() {
      $keyAdminCssStyles = "body{background:#f1f1f1}a{color:#MAINDARK#}a:active,a:focus,a:hover{color:#PRIACCENT#}#post-body #visibility:before,#post-body .misc-pub-post-status:b" .
      "efore,#post-body .misc-pub-revisions:before,.curtime #timestamp:before,span.wp-media-buttons-icon:before{color:currentColor}.wp-core-ui .button-link{c" .
      "olor:#0073aa}.wp-core-ui .button-link:active,.wp-core-ui .button-link:focus,.wp-core-ui .button-link:hover{color:#0096dd}.media-modal .delete-attachme" .
      "nt,.media-modal .trash-attachment,.media-modal .untrash-attachment,.wp-core-ui .button-link-delete{color:#a00}.media-modal .delete-attachment:focus,.m" .
      "edia-modal .delete-attachment:hover,.media-modal .trash-attachment:focus,.media-modal .trash-attachment:hover,.media-modal .untrash-attachment:focus,." .
      "media-modal .untrash-attachment:hover,.wp-core-ui .button-link-delete:focus,.wp-core-ui .button-link-delete:hover{color:#dc3232}input[type=checkbox]:c" .
      "hecked::before{content:url(data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3C" .
      "path%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%237e8993%27%2F%3E%3C%2Fsvg%3E)}input[type=rad" .
      "io]:checked::before{background:#7e8993}.wp-core-ui input[type=reset]:active,.wp-core-ui input[type=reset]:hover{color:#0096dd}input[type=checkbox]:foc" .
      "us,input[type=color]:focus,input[type=date]:focus,input[type=datetime-local]:focus,input[type=datetime]:focus,input[type=email]:focus,input[type=month" .
      "]:focus,input[type=number]:focus,input[type=password]:focus,input[type=radio]:focus,input[type=search]:focus,input[type=tel]:focus,input[type=text]:fo" .
      "cus,input[type=time]:focus,input[type=url]:focus,input[type=week]:focus,select:focus,textarea:focus{border-color:#PRIACCENT#;-webkit-box-shadow:0 0 0 " .
      "1px #PRIACCENT#;box-shadow:0 0 0 1px #PRIACCENT#}.wp-core-ui .button{border-color:#7e8993;color:#32373c}.wp-core-ui .button.focus,.wp-core-ui .button." .
      "hover,.wp-core-ui .button:focus,.wp-core-ui .button:hover{border-color:#717c87;color:#262a2e}.wp-core-ui .button.focus,.wp-core-ui .button:focus{borde" .
      "r-color:#7e8993;color:#262a2e;-webkit-box-shadow:0 0 0 1px #32373c;box-shadow:0 0 0 1px #32373c}.wp-core-ui .button:active{border-color:#7e8993;color:" .
      "#262a2e;-webkit-box-shadow:none;box-shadow:none}.wp-core-ui .button.active,.wp-core-ui .button.active:focus,.wp-core-ui .button.active:hover{border-co" .
      "lor:#PRIACCENT#;color:#262a2e;-webkit-box-shadow:inset 0 2px 5px -3px #PRIACCENT#;box-shadow:inset 0 2px 5px -3px #PRIACCENT#}.wp-core-ui .button.acti" .
      "ve:focus{-webkit-box-shadow:0 0 0 1px #32373c;box-shadow:0 0 0 1px #32373c}.wp-core-ui .button,.wp-core-ui .button-secondary{color:#MAINDARK#;border-c" .
      "olor:#MAINDARK#}.wp-core-ui .button-secondary:hover,.wp-core-ui .button.hover,.wp-core-ui .button:hover{background:#f0f0f0;color:#MAINDARK#}.wp-core-u" .
      "i .button-secondary:focus,.wp-core-ui .button.focus,.wp-core-ui .button:focus{border-color:#MAINDARK#;color:#MAINDARK#;-webkit-box-shadow:0 0 0 1px #M" .
      "AINDARK#;box-shadow:0 0 0 1px #MAINDARK#}.wp-core-ui .button-primary:hover{color:#fff}.wp-core-ui .button-primary{background:#PRIACCENT#;border-color:" .
      "#PRIACCENT#;color:#fff}.wp-core-ui .button-primary:focus,.wp-core-ui .button-primary:hover{background:#PRIACCENT#;border-color:#PRIACCENT#;color:#fff}" .
      ".wp-core-ui .button-primary:focus{-webkit-box-shadow:0 0 0 1px #fff,0 0 0 3px #PRIACCENT#;box-shadow:0 0 0 1px #fff,0 0 0 3px #PRIACCENT#}.wp-core-ui " .
      ".button-primary:active{background:#PRIACCENT#;border-color:#PRIACCENT#;color:#fff}.wp-core-ui .button-primary.active,.wp-core-ui .button-primary.activ" .
      "e:focus,.wp-core-ui .button-primary.active:hover{background:#PRIACCENT#;color:#fff;border-color:#PRIACCENT#;-webkit-box-shadow:inset 0 2px 5px -3px #1" .
      "50b04;box-shadow:inset 0 2px 5px -3px #150b04}.wp-core-ui .button-group>.button.active{border-color:#PRIACCENT#}.wp-core-ui .wp-ui-primary{color:#fff;" .
      "background-color:#MAINDARK#}.wp-core-ui .wp-ui-text-primary{color:#MAINDARK#}.wp-core-ui .wp-ui-highlight{color:#fff;background-color:#PRIACCENT#}.wp-" .
      "core-ui .wp-ui-text-highlight{color:#PRIACCENT#}.wp-core-ui .wp-ui-notification{color:#fff;background-color:#4d4d4d}.wp-core-ui .wp-ui-text-notificati" .
      "on{color:#4d4d4d}.wp-core-ui .wp-ui-text-icon{color:#f3f1f1}.wrap .page-title-action,.wrap .page-title-action:active{border:1px solid #MAINDARK#;color" .
      ":#MAINDARK#}.wrap .page-title-action:hover{color:#MAINDARK#}.wrap .page-title-action:focus{border-color:#PRIACCENT#;color:#MAINDARK#;-webkit-box-shado" .
      "w:0 0 0 1px #PRIACCENT#;box-shadow:0 0 0 1px #PRIACCENT#}.view-switch a.current:before{color:#MAINDARK#}.view-switch a:hover:before{color:#4d4d4d}#adm" .
      "inmenu,#adminmenuback,#adminmenuwrap{background:#MAINDARK#}#adminmenu a{color:#fff}#adminmenu div.wp-menu-image:before{color:#f3f1f1}#adminmenu a:hove" .
      "r,#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li>a.menu-top:focus{color:#MAINDARK#;background-color:#PRIACCENT#}#adminmen" .
      "u li.menu-top:hover div.wp-menu-image:before,#adminmenu li.opensub>a.menu-top div.wp-menu-image:before{color:#MAINDARK#}.about-wrap .nav-tab-active,.n" .
      "av-tab-active,.nav-tab-active:hover{background-color:#f1f1f1;border-bottom-color:#f1f1f1}#adminmenu .wp-has-current-submenu .wp-submenu,#adminmenu .wp" .
      "-has-current-submenu.opensub .wp-submenu,#adminmenu .wp-submenu,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu,.folded #adminmenu .wp-has-curre" .
      "nt-submenu .wp-submenu{background:#SECACCENT#}#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after{border-right-color:#SECACCENT#}#" .
      "adminmenu .wp-submenu .wp-submenu-head{color:#f7f7f7}#adminmenu .wp-has-current-submenu .wp-submenu a,#adminmenu .wp-has-current-submenu.opensub .wp-s" .
      "ubmenu a,#adminmenu .wp-submenu a,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu a,.folded #adminmenu .wp-has-current-submenu .wp-submenu a{col" .
      "or:#MAINDARK#}#adminmenu .wp-has-current-submenu .wp-submenu a:focus,#adminmenu .wp-has-current-submenu .wp-submenu a:hover,#adminmenu .wp-has-current" .
      "-submenu.opensub .wp-submenu a:focus,#adminmenu .wp-has-current-submenu.opensub .wp-submenu a:hover,#adminmenu .wp-submenu a:focus,#adminmenu .wp-subm" .
      "enu a:hover,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu a:focus,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu a:hover,.folded #admin" .
      "menu .wp-has-current-submenu .wp-submenu a:focus,.folded #adminmenu .wp-has-current-submenu .wp-submenu a:hover{color:#MAINDARK#;background-color:#PRI" .
      "ACCENT#}#adminmenu .wp-has-current-submenu.opensub .wp-submenu li.current a,#adminmenu .wp-submenu li.current a,#adminmenu a.wp-has-current-submenu:fo" .
      "cus+.wp-submenu li.current a{color:#MAINDARK#}#adminmenu .wp-has-current-submenu.opensub .wp-submenu li.current a:focus,#adminmenu .wp-has-current-sub" .
      "menu.opensub .wp-submenu li.current a:hover,#adminmenu .wp-submenu li.current a:focus,#adminmenu .wp-submenu li.current a:hover,#adminmenu a.wp-has-cu" .
      "rrent-submenu:focus+.wp-submenu li.current a:focus,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu li.current a:hover{color:#MAINDARK#}ul#adminm" .
      "enu a.wp-has-current-submenu:after,ul#adminmenu>li.current>a.current:after{border-right-color:#f1f1f1}#adminmenu li.current a.menu-top,#adminmenu li.w" .
      "p-has-current-submenu .wp-submenu .wp-submenu-head,#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,.folded #adminmenu li.current.menu-to" .
      "p{color:#MAINDARK#;background:#PRIACCENT#}#adminmenu a.current:hover div.wp-menu-image:before,#adminmenu .current div.wp-menu-image:before, #adminmenu li a:focus div.wp-menu-image:before,#adminmen" .
      "u li.opensub div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu a:focus div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu div.w" .
      "p-menu-image:before,#adminmenu li.wp-has-current-submenu.opensub div.wp-menu-image:before,#adminmenu li:hover div.wp-menu-image:before{color:#MAINDARK" .
      "#}#adminmenu .awaiting-mod,#adminmenu .update-plugins{color:#fff;background:#f55a5a}#adminmenu li a.wp-has-current-submenu .update-plugins,#adminmenu " .
      "li.current a .awaiting-mod,#adminmenu li.menu-top:hover>a .update-plugins,#adminmenu li:hover a .awaiting-mod{color:#fff;background:#f55a5a}#collapse-" .
      "button{color:#f3f1f1}#collapse-button:focus,#collapse-button:hover{color:#f7f7f7}#wpadminbar{color:#fff;background:#MAINDARK#}#wpadminbar .ab-item,#wp" .
      "adminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon{color:#fff}#wpadminbar .ab-icon,#wpadminbar .ab-icon:bef" .
      "ore,#wpadminbar .ab-item:after,#wpadminbar .ab-item:before{color:#f3f1f1}#wpadminbar .ab-top-menu>li.menupop.hover>.ab-item,#wpadminbar.nojq .quicklin" .
      "ks .ab-top-menu>li>.ab-item:focus,#wpadminbar.nojs .ab-top-menu>li.menupop:hover>.ab-item,#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,#wpa" .
      "dminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus{color:#f7f7f7;background:#23262a}#wpadminbar:not(.mobile)>#wp-toolbar a:focus span.ab-label,#wpadm" .
      "inbar:not(.mobile)>#wp-toolbar li.hover span.ab-label,#wpadminbar:not(.mobile)>#wp-toolbar li:hover span.ab-label{color:#f7f7f7}#wpadminbar:not(.mobil" .
      "e) li:hover #adminbarsearch:before,#wpadminbar:not(.mobile) li:hover .ab-icon:before,#wpadminbar:not(.mobile) li:hover .ab-item:after,#wpadminbar:not(" .
      ".mobile) li:hover .ab-item:before{color:#fff}#wpadminbar .menupop .ab-sub-wrapper{background:#23262a}#wpadminbar .quicklinks .menupop ul.ab-sub-second" .
      "ary,#wpadminbar .quicklinks .menupop ul.ab-sub-secondary .ab-submenu{background:#cf6b67}#wpadminbar .ab-submenu .ab-item,#wpadminbar .quicklinks .menu" .
      "pop ul li a,#wpadminbar .quicklinks .menupop.hover ul li a,#wpadminbar.nojs .quicklinks .menupop:hover ul li a{color:#f7f7f7}#wpadminbar .menupop .men" .
      "upop>.ab-item:before,#wpadminbar .quicklinks li .blavatar{color:#f3f1f1}#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover>a,#wpadminbar .quicklin" .
      "ks .menupop ul li a:focus,#wpadminbar .quicklinks .menupop ul li a:focus strong,#wpadminbar .quicklinks .menupop ul li a:hover,#wpadminbar .quicklinks" .
      " .menupop ul li a:hover strong,#wpadminbar .quicklinks .menupop.hover ul li a:focus,#wpadminbar .quicklinks .menupop.hover ul li a:hover,#wpadminbar l" .
      "i #adminbarsearch.adminbar-focused:before,#wpadminbar li .ab-item:focus .ab-icon:before,#wpadminbar li .ab-item:focus:before,#wpadminbar li a:focus .a" .
      "b-icon:before,#wpadminbar li.hover .ab-icon:before,#wpadminbar li.hover .ab-item:before,#wpadminbar li:hover #adminbarsearch:before,#wpadminbar li:hov" .
      "er .ab-icon:before,#wpadminbar li:hover .ab-item:before,#wpadminbar.nojs .quicklinks .menupop:hover ul li a:focus,#wpadminbar.nojs .quicklinks .menupo" .
      "p:hover ul li a:hover{color:#f7f7f7}#wpadminbar .menupop .menupop>.ab-item:hover:before,#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover>a .blav" .
      "atar,#wpadminbar .quicklinks li a:focus .blavatar,#wpadminbar .quicklinks li a:hover .blavatar,#wpadminbar.mobile .quicklinks .ab-icon:before,#wpadmin" .
      "bar.mobile .quicklinks .ab-item:before{color:#f7f7f7}#wpadminbar.mobile .quicklinks .hover .ab-icon:before,#wpadminbar.mobile .quicklinks .hover .ab-i" .
      "tem:before{color:#f3f1f1}#wpadminbar #adminbarsearch:before{color:#f3f1f1}#wpadminbar>#wp-toolbar>#wp-admin-bar-top-secondary>#wp-admin-bar-search #ad" .
      "minbarsearch input.adminbar-input:focus{color:#fff;background:#d66560}#wpadminbar #wp-admin-bar-recovery-mode{color:#fff;background-color:#4d4d4d}#wpa" .
      "dminbar #wp-admin-bar-recovery-mode .ab-item,#wpadminbar #wp-admin-bar-recovery-mode a.ab-item{color:#fff}#wpadminbar .ab-top-menu>#wp-admin-bar-recov" .
      "ery-mode.hover>.ab-item,#wpadminbar.nojq .quicklinks .ab-top-menu>#wp-admin-bar-recovery-mode>.ab-item:focus,#wpadminbar:not(.mobile) .ab-top-menu>#wp" .
      "-admin-bar-recovery-mode:hover>.ab-item,#wpadminbar:not(.mobile) .ab-top-menu>#wp-admin-bar-recovery-mode>.ab-item:focus{color:#fff;background-color:#" .
      "PRIACCENT#}#wpadminbar .quicklinks li#wp-admin-bar-my-account.with-avatar>a img{border-color:#d66560;background-color:#d66560}#wpadminbar #wp-admin-ba" .
      "r-user-info .display-name{color:#fff}#wpadminbar #wp-admin-bar-user-info a:hover .display-name{color:#f7f7f7}#wpadminbar #wp-admin-bar-user-info .user" .
      "name{color:#f7f7f7}.wp-pointer .wp-pointer-content h3{background-color:#PRIACCENT#;border-color:#PRIACCENT#}.wp-pointer .wp-pointer-content h3:before{" .
      "color:#PRIACCENT#}.wp-pointer.wp-pointer-top .wp-pointer-arrow,.wp-pointer.wp-pointer-top .wp-pointer-arrow-inner,.wp-pointer.wp-pointer-undefined .wp" .
      "-pointer-arrow,.wp-pointer.wp-pointer-undefined .wp-pointer-arrow-inner{border-bottom-color:#PRIACCENT#}.media-item .bar,.media-progress-bar div{backg" .
      "round-color:#PRIACCENT#}.details.attachment{-webkit-box-shadow:inset 0 0 0 3px #fff,inset 0 0 0 7px #PRIACCENT#;box-shadow:inset 0 0 0 3px #fff,inset " .
      "0 0 0 7px #PRIACCENT#}.attachment.details .check{background-color:#PRIACCENT#;-webkit-box-shadow:0 0 0 1px #fff,0 0 0 2px #PRIACCENT#;box-shadow:0 0 0" .
      " 1px #fff,0 0 0 2px #PRIACCENT#}.media-selection .attachment.selection.details .thumbnail{-webkit-box-shadow:0 0 0 1px #fff,0 0 0 3px #PRIACCENT#;box-" .
      "shadow:0 0 0 1px #fff,0 0 0 3px #PRIACCENT#}.theme-browser .theme.active .theme-name,.theme-browser .theme.add-new-theme a:focus:after,.theme-browser " .
      ".theme.add-new-theme a:hover:after{background:#PRIACCENT#}.theme-browser .theme.add-new-theme a:focus span:after,.theme-browser .theme.add-new-theme a" .
      ":hover span:after{color:#PRIACCENT#}.theme-filter.current,.theme-section.current{border-bottom-color:#MAINDARK#}body.more-filters-opened .more-filters" .
      "{color:#fff;background-color:#MAINDARK#}body.more-filters-opened .more-filters:before{color:#fff}body.more-filters-opened .more-filters:focus,body.mor" .
      "e-filters-opened .more-filters:hover{background-color:#PRIACCENT#;color:#fff}body.more-filters-opened .more-filters:focus:before,body.more-filters-ope" .
      "ned .more-filters:hover:before{color:#fff}.widgets-chooser li.widgets-chooser-selected{background-color:#PRIACCENT#;color:#fff}.widgets-chooser li.wid" .
      "gets-chooser-selected:before,.widgets-chooser li.widgets-chooser-selected:focus:before{color:#fff}div#wp-responsive-toggle a:before{color:#f3f1f1}.wp-" .
      "responsive-open div#wp-responsive-toggle a{border-color:transparent;background:#PRIACCENT#}.wp-responsive-open #wpadminbar #wp-admin-bar-menu-toggle a" .
      "{background:#23262a}.wp-responsive-open #wpadminbar #wp-admin-bar-menu-toggle .ab-icon:before{color:#f3f1f1}.mce-container.mce-menu .mce-menu-item-nor" .
      "mal.mce-active,.mce-container.mce-menu .mce-menu-item-preview.mce-active,.mce-container.mce-menu .mce-menu-item.mce-selected,.mce-container.mce-menu ." .
      "mce-menu-item:focus,.mce-container.mce-menu .mce-menu-item:hover{background:#PRIACCENT#}#customize-controls .control-section .accordion-section-title:" .
      "focus,#customize-controls .control-section .accordion-section-title:hover,#customize-controls .control-section.open .accordion-section-title,#customiz" .
      "e-controls .control-section:hover>.accordion-section-title{color:#PRIACCENT#;border-left-color:#PRIACCENT#}.customize-controls-close:focus,.customize-" .
      "controls-close:hover,.customize-controls-preview-toggle:focus,.customize-controls-preview-toggle:hover{color:#PRIACCENT#;border-top-color:#PRIACCENT#}" .
      ".customize-panel-back:focus,.customize-panel-back:hover,.customize-section-back:focus,.customize-section-back:hover{color:#PRIACCENT#;border-left-colo" .
      "r:#PRIACCENT#}#customize-controls .customize-info.open.active-menu-screen-options .customize-help-toggle:active,#customize-controls .customize-info.op" .
      "en.active-menu-screen-options .customize-help-toggle:focus,#customize-controls .customize-info.open.active-menu-screen-options .customize-help-toggle:" .
      "hover,.active-menu-screen-options .customize-screen-options-toggle,.customize-screen-options-toggle:active,.customize-screen-options-toggle:focus,.cus" .
      "tomize-screen-options-toggle:hover{color:#PRIACCENT#}#available-menu-items .item-add:focus:before,#customize-controls .customize-info .customize-help-" .
      "toggle:focus:before,.customize-screen-options-toggle:focus:before,.menu-delete:focus,.menu-item-bar .item-delete:focus:before,.wp-customizer .menu-ite" .
      "m .submitbox .submitdelete:focus,.wp-customizer button:focus .toggle-indicator:before{-webkit-box-shadow:0 0 0 1px #e59e66,0 0 2px 1px #PRIACCENT#;box" .
      "-shadow:0 0 0 1px #e59e66,0 0 2px 1px #PRIACCENT#}#customize-controls .customize-info .customize-help-toggle:focus,#customize-controls .customize-info" .
      " .customize-help-toggle:hover,#customize-controls .customize-info.open .customize-help-toggle{color:#PRIACCENT#}.control-panel-themes .customize-theme" .
      "s-section-title:focus,.control-panel-themes .customize-themes-section-title:hover{border-left-color:#PRIACCENT#;color:#PRIACCENT#}.control-panel-theme" .
      "s .theme-section .customize-themes-section-title.selected:after{background:#PRIACCENT#}.control-panel-themes .customize-themes-section-title.selected{" .
      "color:#PRIACCENT#}#customize-outer-theme-controls .control-section .accordion-section-title:focus:after,#customize-outer-theme-controls .control-secti" .
      "on .accordion-section-title:hover:after,#customize-outer-theme-controls .control-section.open .accordion-section-title:after,#customize-outer-theme-co" .
      "ntrols .control-section:hover>.accordion-section-title:after,#customize-theme-controls .control-section .accordion-section-title:focus:after,#customiz" .
      "e-theme-controls .control-section .accordion-section-title:hover:after,#customize-theme-controls .control-section.open .accordion-section-title:after," .
      "#customize-theme-controls .control-section:hover>.accordion-section-title:after{color:#PRIACCENT#}.customize-control .attachment-media-view .button-ad" .
      "d-media:focus{background-color:#fbfbfc;border-color:#PRIACCENT#;border-style:solid;-webkit-box-shadow:0 0 0 1px #PRIACCENT#;box-shadow:0 0 0 1px #PRIA" .
      "CCENT#;outline:2px solid transparent}.wp-full-overlay-footer .devices button.active:hover,.wp-full-overlay-footer .devices button:focus{border-bottom-" .
      "color:#PRIACCENT#}.wp-core-ui .wp-full-overlay .collapse-sidebar:focus,.wp-core-ui .wp-full-overlay .collapse-sidebar:hover{color:#PRIACCENT#}.wp-full" .
      "-overlay .collapse-sidebar:focus .collapse-sidebar-arrow,.wp-full-overlay .collapse-sidebar:hover .collapse-sidebar-arrow{-webkit-box-shadow:0 0 0 1px" .
      " #e59e66,0 0 2px 1px #PRIACCENT#;box-shadow:0 0 0 1px #e59e66,0 0 2px 1px #PRIACCENT#}.wp-full-overlay-footer .devices button:focus:before,.wp-full-ov" .
      "erlay-footer .devices button:hover:before{color:#PRIACCENT#}";
      return $keyAdminCssStyles;
    }

    /*----------------------*/
  }

  /*----------------------------*/
}
?>
