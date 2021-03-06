<?php

if ( ! class_exists( 'DraftLiveSync' ) ) {

    require_once 'draft-live-sync-settings.php';
    require_once 'draft-live-sync-meta-box-callback.php';

    class DraftLiveSync {

        static $version;
        protected $dir;
        protected $plugin_dir;
        public $content_draft_url;
        protected $api_token;
        protected $init = false;
        protected $short_init = false;
        static $singleton;

        private $pre_term_url;
        private $settings_page;
        private $site_id;

        static function getInstance() {
            if (is_null(DraftLiveSync::$singleton)) {
                throw new Exception('DraftLiveSync not instanciated');
            }
            return DraftLiveSync::$singleton;
        }

        function __construct($dir, $version, $content_draft_url, $api_token, $short_init = false) {

            DraftLiveSync::$version = $version;

            $this->dir = $dir;
            $this->content_draft_url = $content_draft_url;
            $this->api_token = $api_token;
            $this->short_init = $short_init;
            $this->plugin_dir = basename( $this->dir );


            $this->init();

            DraftLiveSync::$singleton = $this;

            $this->settings_page = new DraftLiveSyncSettings($this);

            $this->site_id = $this->settings_page->get_site_id();

            if (!isset($this->site_id) || $this->site_id === '' ) {
                add_action( 'admin_notices', array(&$this, 'show_site_id_missing_warning'));
            }

        }

        public function init() {

            // Disable double initialization.
            if ( $this->init ) {
                return $this;
            }

            $this->init = true;

            if (!$this->short_init) {

                add_action( 'add_meta_boxes', array( &$this, 'meta_box_publish_status') );

                add_filter( 'nav_menu_meta_box_object', array( &$this, 'meta_box_publish_status_nav_menus') ); // Here we know the user is on nav-menu.php page
                add_action( 'publish_status_meta_box_navbox', array( &$this, 'publish_status_meta_box_callback'), 10, 2); // Special case (wrappers calls this action later)

                // We used **save_post** before but even if the post is saved, it seems like WP still doesnt answer
                // to the correct permalink. **wp_insert_post** works better.
				add_action( 'wp_insert_post', array( &$this, 'publish_to_draft'), 10, 2 );

                // All the following hooks where tested for deletion, but none of them worked properly
                // either triggering the delete process too soon or something alike
                // ----------------------------------------------------------------
				// add_action( 'trash_post', array( &$this, 'delete_post'), 10, 1 );
				// add_action( 'trashed_post', array( &$this, 'delete_post'), 10, 1 );
				// add_action( 'delete_post', array( &$this, 'delete_post'), 10, 1 );

                // We use **save_post** for deleting purposes
                add_action( 'save_post', array( &$this, 'delete_post'), 10, 1 );

                add_action( 'create_term', array( &$this, 'publish_term_to_draft'), 10, 3 );
                add_action( 'edit_term', array( &$this, 'publish_term_to_draft'), 10, 3 );

                add_action( 'pre_delete_term', array( &$this, 'pre_publish_term_to_draft'), 1, 3);
                add_action( 'delete_term', array( &$this, 'post_publish_term_to_draft'), 1, 3);
                add_action( 'wp_update_nav_menu', array( &$this, 'publish_menu_to_draft'), 10, 3);
                add_action( 'wp_ajax_publish_to_live', array( &$this, 'ajax_publish_to_live') );
                add_action( 'wp_ajax_unpublish_from_live', array( &$this, 'ajax_unpublish_from_live') );
                add_action( 'wp_ajax_check_sync', array( &$this, 'ajax_check_sync') );
                add_action( 'wp_ajax_reset_tree', array( &$this, 'ajax_reset_tree') );
                add_action( 'wp_ajax_get_all_resources', array( &$this, 'ajax_get_all_resources') );
                add_filter( 'admin_menu', array( &$this, 'add_admin_pages'), 10, 2 );
                add_action( 'parse_request', array( &$this, 'parse_requests'));
                add_filter( 'gettext', array( &$this, 'change_publish_button'), 10, 2 );
                add_filter('get_sample_permalink_html', array( &$this, 'set_correct_permalink'));
                add_action( 'admin_enqueue_scripts', array(&$this, 'enqueue_admin_scripts' ));
                add_action( 'admin_head-post.php', array( &$this, 'hide_publishing_actions'));
                add_action( 'admin_head-post-new.php', array( &$this, 'hide_publishing_actions'));

                // Filter the content so we can replace hosts etc
                add_filter('dls_replace_hosts', array(&$this, 'filter_the_content_replace_hosts'), 100);

				// Add hook to ACFs save action to publish on each save
                add_action('acf/save_post', array( &$this, 'publish_options_to_draft'), 20, 1);

                // This will check if we should redirect normal requests to the admin page
                add_action('template_redirect', array(&$this, 'redirect_to_wp_admin'), 20);

                $this->add_actions_for_options_pages();

            }

            return $this;

        }

        function show_site_id_missing_warning() {
            $class = 'notice notice-error';
            $message = __( 'Please set the site_id in the Draft Sync Plugin settings!', 'dls');
            printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) ); 
        }

        // Rediect if the request is a normal request
        function redirect_to_wp_admin(){


            $auto_redirect = $this->settings_page->get_auto_redirect_to_admin_page();

            if ($auto_redirect) {

                $proxy_name = isset($_SERVER['HTTP_PROXY_SERVICE']) ? $_SERVER['HTTP_PROXY_SERVICE'] : '';
                $force_json = $_GET['json'];

                // If its an api request, or if we have ?json=true, then we just show the normal JSON response
                if ($proxy_name !== 'api' && $force_json !== 'true') {
                    header("Location: /wp-admin");
                    die();
                }

            }

        }


        function add_actions_for_options_pages () {

            // TODO: this value should be moved to the settings page, or read directly from acf
            $options_name = 'resurs';

            $resources = $this->get_other_resources();
            $counter = 0;

            foreach( $resources as $resource) {
                $callbackInstance = new DraftLiveSyncMetaBoxCallback($this, $resource, $counter, $options_name);
                $counter++;
            }

        }
        
        // This filter can be used to add endpoints to be synced that we cant get from wordpress in the normal way
        function get_other_resources() {
            $resources = apply_filters('dls_additional_endpoints', array());
            return $resources;
        }

        function hide_publishing_actions(){
            global $post;
            echo '
                <style type="text/css">
                    #minor-publishing-actions {
                        padding-bottom: .75rem;
                    }
                    #misc-publishing-actions .misc-pub-post-status,
                    #misc-publishing-actions .misc-pub-visibility
                    {
                        display:none;
                    }
                </style>
            ';
        }

        function change_publish_button( $translation, $text ) {
            if ( $text == 'Publish' || $text == 'Update' ) {
                return 'Save to draft';
            }
            return $translation;
        }

        // Show another permalink in the edit page view
        function set_correct_permalink($url) {

            $public_host = $this->settings_page->get_overwrite_viewable_permalink();
            $wordpress_url = get_site_url();

            if ($public_host) {
                return str_replace($wordpress_url, $public_host, $url);
            }

            return $url;

        }

        function enqueue_admin_scripts($hook) {
            wp_enqueue_style( 'dls-css', plugins_url( '../css/style.css', __FILE__ ) );
            wp_enqueue_script( 'dls-diff-script', plugins_url( '../js/libs/diff.js', __FILE__ ) );
            if ( 'draftlive-sync_page_draft-live-sync-check-sync' == $hook ) {
                wp_enqueue_script( 'dls-reset-script', plugins_url( '../js/sync-overview.js', __FILE__ ) );
                wp_enqueue_style( 'dls-css', plugins_url( '../css/style.css', __FILE__ ) );
                return;
            } else if ( 'draftlive-sync_page_draft-live-sync-reset' == $hook ) {
                wp_enqueue_script( 'dls-reset-script', plugins_url( '../js/sync-draft.js', __FILE__ ) );
                wp_enqueue_script( 'dls-tree-script', plugins_url( '../js/sync-tree.js', __FILE__ ) );
                wp_enqueue_style( 'dls-css', plugins_url( '../css/style.css', __FILE__ ) );
                return;
            } else if ( 'draftlive-sync_page_draft-live-sync-publish' == $hook ) {
                wp_enqueue_script( 'dls-reset-script', plugins_url( '../js/sync-draft.js', __FILE__ ) );
                wp_enqueue_script( 'dls-tree-script', plugins_url( '../js/sync-tree.js', __FILE__ ) );
                wp_enqueue_style( 'dls-css', plugins_url( '../css/style.css', __FILE__ ) );
                return;
            }
        }

        function replace_hosts($permalink) {

            $replaced_permalink = $permalink;

            // If we have a comma separated list of hosts, we replace them as well
            if (getenv("REPLACE_HOST_LIST")) {
                $replace_host_list = explode(',', getenv('REPLACE_HOST_LIST'));
            } else {
                $replace_host_list = array();
            }

            $original_host = get_site_url();

            // We always use the wordpress host too
            array_push($replace_host_list, $original_host);

            // Add the list gathered from the options
            $extra_hosts = $this->settings_page->get_replace_hosts();

            // Merge all lists
            $replace_host_list = array_merge($replace_host_list, $extra_hosts);

            // We always use the wordpress host too
            $original_host = get_site_url();
            array_push($replace_host_list, $original_host);

            foreach ($replace_host_list as $host) {
                $replace_string = addcslashes($host, '/');
                $replaced_permalink = str_replace($host, '', $replaced_permalink);
                // error_log(' REPLACE: ' . $replace_string . ' -- AFTER: ' . $replaced_permalink);
            }

            return $replaced_permalink;

        }


        function recreate_tree($target) {

            $this->check_site_id();

            $ch = curl_init();

            $url = $this->content_draft_url . '/content-admin/recreate-tree/' . $target;

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_token,
                'Content-Length: ' . strlen($data_string),
                'x-site-id:' . $this->site_id
            ));

            $response = curl_exec($ch);

			$result = json_decode($response);

            curl_close ($ch);

            return $result;

        }


        function get_content($permalink) {

            $data = new stdclass();

            $url = 'http://localhost' . $permalink;

            // We need this to get hthe content beforehand in multisite
            $host = $_SERVER['HTTP_HOST'];

            $ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "host: $host",
            ));

            $payload_response = curl_exec($ch);
			$payload_data = explode("\n", $payload_response);
            $payload_body = array_pop($payload_data);
            
            // Get all headers
			$payload_headers = array();
			foreach($payload_data as $payload_header_line) {
				$details = explode(':', $payload_header_line, 2);
				if (count($details) == 2) {
					$key   = trim($details[0]);
					$value = trim($details[1]);
					$payload_headers[$key] = $value;
				}
			}
			$data->payload = json_decode($payload_body);
            $data->payload_headers = $payload_headers;

            curl_close ($ch);
            
            return $data;

        }

        function push_to_queue($permalink, $release = 'draft', $async = false, $status = 'publish', $check_only_draft_live = false, $sync_check = true, $sync_tree_and_cache = true) {

            $this->check_site_id();

            $server_url = $this->content_draft_url . '/content-admin';

            if ($release == 'unpublish') {
                $server_url = $server_url . '/unpublish';
            } else if ($release != 'live') {
                $server_url = $server_url . '/queue';
            } else {
                $server_url = $server_url . '/publish';
            }


            // $post = get_post($post_id);
            // Since WP adds "__trashed_[counter]" to the permalink if its trashed, we need to fix it, otherwise, we cant update the content service correclty
            if ($status == 'trash') {
				$re = '/__trashed-\d+/';
                $permalink = preg_replace($re, '', $permalink);
   				$re = '/__trashed/';
                $permalink = preg_replace($re, '', $permalink);
            }

            $data = new stdclass();

            $data->permalink = $this->replace_hosts($permalink);
            $data->sync_check = $sync_check;
            $data->sync_tree_and_cache = $sync_tree_and_cache;

            // Dont publish playstation
            if (strpos(strtolower($data->permalink), '/playstation') > -1) {
                return;
            }

            $data->async = $async;
            $data->release = $release;

            if ($check_only_draft_live) {
                $data->check_only_draft_live = true;
            }

            $data->status = $status == 'trash' ? 'deleted' : $status;



            // Fetch all data from the page
            $content = $this->get_content($data->permalink);
            $data->payload = $content->payload;
            $data->payloadHeaders = $content->payload_headers;


            $data_string = json_encode($data);

            $ch = curl_init($server_url);

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_token,
                'Content-Length: ' . strlen($data_string),
                'x-site-id:' . $this->site_id
            ));

            // receive server response ...
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close ($ch);

            if ($httpcode === 200) {

                $json_result = json_decode($response);

                // This check is in place because when we run this plugin in the normal rawb 
                // setup, with an API proxy, we asume that we get the data in a 'data' key.
                // Without the API, we get it directly in the root.
                if (isset($json_result->data)) {
                    return $json_result->data;
                }

                return $json_result;

            } else {
                error_log('Request to ' . $server_url . ' gave: ' . print_r($response, true));
                error_log('    Data sent: ' . print_r($data, true));
            }

            return json_decode($response);

        }

        // Break if there is another site id
        public function check_site_id() {
            if (!isset($this->site_id) || $this->site_id === '' ) {
                die();
            }
        }

        public function reindex_content($release = 'draft') {

            $this->check_site_id();

            $server_url = $this->content_draft_url . '/content-admin';

            if ($release != 'live') {
                $server_url = $server_url . '/reindex-content';
            } else {
                $server_url = $server_url . '/publish/reindex-content';
            }

            $ch = curl_init($server_url);

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_token,
                'x-site-id:' . $this->site_id
            ));

            // receive server response ...
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close ($ch);

            if ($httpcode === 200) {
                return json_decode($response)->data;
            } else {
                error_log('Request to ' . $server_url . ' gave: ' . print_r($response, true));
            }

            return json_decode($response);

        }

        public function check_sync($resource, $only_draft_sync = false) {

            $this->check_site_id();

            $server_url = $this->content_draft_url . '/content-admin/check-sync';

            if ($only_draft_sync) {
                // Checks only between content service for draft and sync and not back to wp
                $server_url = $this->content_draft_url . '/content-admin/check-draft-sync';
            }


            $data = new stdclass();

            // Either we send a post_id (int) or an api path
            if (is_numeric($resource)) {

                $post_id = $resource;
                $post = get_post($post_id);
                if ($post->post_status == 'draft' || $post->post_status == 'auto-draft') {
                    return;
                }
                $data->permalink = get_permalink($post_id);

            } else {

                $data->permalink = $resource;

            }


            $data->permalink = $this->replace_hosts($data->permalink);

            $data_string = json_encode($data);

            $ch = curl_init($server_url);

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_token,
                'Content-Length: ' . strlen($data_string),
                'x-site-id:' . $this->site_id
            ));

            // receive server response ...
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close ($ch);

            // error_log('check-sync ' . print_r($response, true));
            if ($httpcode === 200) {
                $json = json_decode($response)->data;
            } else {
                error_log('Request to ' . $server_url . ' gave: ' . print_r($response, true));
                error_log('    Data sent: ' . print_r($data, true));
                $error = new stdclass();
                $error->message = $response;
                $error->error = true;
                return $error; // json_decode($error)->data;
            }

            if (is_null($json)) {
                $error = new stdclass();
                $error->message = $response;
                $error->error = true;
                return $error; // json_decode($error)->data;
            }

            return $json;

        }

        public function publish_status_meta_box_callback($post, $meta_box_object, $echo = true) {

            wp_enqueue_script( 'dls-post-script', plugins_url( '../js/sync-post.js', __FILE__ ) );

            $api_path = '';
            if (isset($meta_box_object) && !empty($meta_box_object['args'])) {
                $api_path = $meta_box_object['args']['api_path'];
            }

            $output = <<<EOD

            <script id="dls-post-data" type="application/json">{ "postId": "$post->ID", "apiPath": "$api_path" }</script>
            <div id="publish-to-live-action">
                <div id="dls-percent"></div>
                <div name="publish-to-live-wp-draft-sync" style="" class="dlsc--status" id="status-of-wp-draft">Check draft content...</div>
                <div name="publish-to-live" style="width: 100%;text-align: center;" class="button button-primary button-large button-disabled" id="publish-to-live">Check draft/live sync status...</div>
                <div name="unpublish-from-live" style="width: 100%;text-align: center;" class="button button-secondary button-large button-disabled" id="unpublish-from-live">Check live status...</div>
            </div>

EOD;

			$extra_style = <<<EOD
				<style>
					div#publish-to-live-action {
						padding: 10px 10px 10px 10px;
						background-color: white;
						border: 1px solid #eee;
						margin-bottom: 20px;
						border-color: #ddd;
					}
				</style>
EOD;

            if ($echo) {
                echo $output;
                return;
            }

   		    return $extra_style . $output;

        }

        public function meta_box_publish_status() {

			$post_types = $this->settings_page->get_enabled_post_types();

            add_meta_box(
                'publish-status-meta-box',
                'Publish Status',
                array(&$this, 'publish_status_meta_box_callback'),
                $post_types,
                'side',
                'high'
            );

        }

        public function publish_status_meta_box_callback_pass_args_wrapper($arg){

            $screen = get_current_screen();
            $is_menu_admin = $screen->base === 'nav-menus';

            // Dont bother to continue if we dont load a menu
            if (!$is_menu_admin) {
                return;
            }

            // Get the menu id from the query string, since I couldnt find another way to get it
            $post_id = isset($_GET['menu']) ? intval($_GET['menu']) : -1;

            // Dont bother to continue if we dont load a menu
            if ($post_id == -1) {
                $menus = get_terms('nav_menu');
                $post_id = $menus[0]->term_id;
            }

            // Get the menu item, so we can use it to get its location, which is needed to
            // calculate the permalink to send as a key to the content server
            $menu_item = wp_get_nav_menu_items($menu_id);

            // Find the location of the the current menu
            $menu_location = '';
            foreach( get_nav_menu_locations() as $location => $menu_id ) {
                if( $post_id == $menu_id ){
                    $menu_location = $location;
                }
            }

            // If the wordpress installation has WPML, handle that as well
            if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE != 'en' && ICL_LANGUAGE_CODE != '') {
                $menu_permalink = '/json/api/' . ICL_LANGUAGE_CODE . '/general/menu/' . $menu_location;
            } else {
                $menu_permalink = '/json/api/general/menu/' . $menu_location;
            }

            // We want to pass extra arguments jsut like add_meta_box() would do to the callback publish_status_meta_box_callback
            $custom_param = array(
                'args' => array(
                    'api_path' => $menu_permalink
                )
            ); ?>

            <script>
                jQuery(window).ready(function ($){
                    var copyOfNavboxContent = $('#publish-status-meta-box-navbox-wrapper').clone();
                    $('#publish-status-meta-box-navbox-wrapper').empty();
                    copyOfNavboxContent.appendTo('#nav-menu-header');
                });
            </script>

            <style>

                #publish-status-meta-box-navbox-wrapper {
                   padding-bottom: 10px;
                }

                #publish-status-meta-box-navbox-wrapper #publish-to-live-action {
                    border-top: 1px solid #ddd;
                    padding-top: 10px;
                    justify-content: center;
                    align-items: center;
                    width: 100%;
                    display: flex;
                }

                #publish-status-meta-box-navbox-wrapper .dlsc--status {
                    padding: 0;
                }

                #unpublish-from-live {
                    margin-left: 10px;
                    margin-top: 0px;
                }

            </style>
            <div id="publish-status-meta-box-navbox-wrapper">
                <?php do_action('publish_status_meta_box_navbox', null, $custom_param); ?>
            </div>
        <?php
        }

        public function meta_box_publish_status_nav_menus($object) {
            // We must add the meta-box as pure html in the admin footer (fulhacks).
            add_action( 'admin_footer', array($this, 'publish_status_meta_box_callback_pass_args_wrapper'), 10, 2);
            return $object;
        }

        public function publish_menu_to_draft( $post_id, $data = NULL ) {

            $menu_location = '';
            foreach( get_nav_menu_locations() as $location => $menu_id ) {
                if( $post_id == $menu_id ){
                    $menu_location = $location;
                }
            }

            if ($menu_location != '') {
                if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE != 'en' && ICL_LANGUAGE_CODE != '') {
                    $permalink = '/json/api/' . ICL_LANGUAGE_CODE . '/general/menu/' . $menu_location;
                    $this->push_to_queue($permalink, 'draft', false, 'publish');
                } else {
                    $permalink = '/json/api/general/menu/' . $menu_location;
                    $this->push_to_queue($permalink, 'draft', false, 'publish');
                }
            }

        }

		public function delete_post ($post_id) {

            $permalink = get_permalink($post_id);
			$post = get_post($post_id);

            if ($post->post_status != 'trash') {
                return;
            }

            $this->push_to_queue($permalink, 'draft', false, 'trash');

		}

        public function publish_to_draft( $post_id, $post ) {

			if (is_integer($post_id)) {
                $post = get_post($post_id);
			}

        	// If this is just a revision, don't send the email.
            if ( wp_is_post_revision( $post_id)) { // ->ID)) {
                return;
            }

            if ($post->post_type == 'nav_menu_item') {
                return;
            }

            if ($post->post_status == 'draft' || $post->post_status == 'auto-draft') {
                return;
            }

            $permalink = get_permalink($post_id);

            $this->push_to_queue($permalink, 'draft', false, $post->post_status);

        }

        public function publish_term_to_draft($term_id, $tt_id, $taxonomy) {
            $permalink = get_tag_link($term_id);

            // This check is to make sure that no url for a term can pass as the startpage of the site
            // which happens if we save the menus and the permalink is generated with
            // a querystring.
            if (strpos($permalink, '?') !== false) {
                return;
            }

            $this->push_to_queue($permalink, 'draft', false, 'publish');
        }

        public function pre_publish_term_to_draft($term_id, $tt_id, $taxonomy) {
            $this->pre_term_url = get_tag_link($term_id);
        }

        public function post_publish_term_to_draft($term_id, $tt_id, $taxonomy) {

            $this->push_to_queue($this->pre_term_url, 'draft', false, 'publish');
        }

		// Publish to draft footer options page on save. We need to find a good way to make this more flexible
        public function publish_options_to_draft($id) {

            $post = get_post($id);

			if ($post->post_type != '') {
				return;
			}

            $permalinks = $this->get_other_resources();

            foreach ($permalinks as $permalink) {
                $this->push_to_queue($permalink[1], 'draft', false, 'publish');
            };

            unset($value);
        }

        public function ajax_unpublish_from_live() {

            $reponse = array();

            if (!empty($_POST['post_id'])) {
                $id = $_POST['post_id'];
                $post = get_post($post_id);
                $permalink = get_permalink($id);

                // TODO: FIX THIS TO BE MORE DYNAMIC
                if ($post->post_type == 'nav_menu_item') {
                    $permalink = '/json/api/general/menu/header_menu';
                }

                $response = $this->push_to_queue($permalink, 'unpublish', false, 'publish');
            } else if (!empty($_POST['api_path'])){
                $permalink = $_POST['api_path'];
                $response = $this->push_to_queue($permalink, 'unpublish', false, 'publish');
            }

            header( "Content-Type: application/json" );
            echo json_encode($response);

            //Don't forget to always exit in the ajax function.
            exit();

        }


        public function ajax_publish_to_live() {

            $reponse = array();

            if (!empty($_POST['post_id'])) {
                $id = $_POST['post_id'];
                $post = get_post($post_id);
                $permalink = get_permalink($id);

                // TODO: FIX THIS TO BE MORE DYNAMIC
                if ($post->post_type == 'nav_menu_item') {
                    $permalink = '/json/api/general/menu/header_menu';
                }

                $response = $this->push_to_queue($permalink, 'live', false, 'publish');
            } else if (!empty($_POST['api_path'])){
                $permalink = $_POST['api_path'];
                $response = $this->push_to_queue($permalink, 'live', false, 'publish');
            }

            header( "Content-Type: application/json" );
            echo json_encode($response);

            //Don't forget to always exit in the ajax function.
            exit();

        }

        function ajax_get_all_resources() {
            $result = new stdclass();
            $result->list = $this->get_all_resources();
            $json = json_encode($result);
            header('Content-Type: application/json');
            echo $json;
            exit();
        }


        public function ajax_reset_tree() {

            $reponse = array();
            $target = $_POST['target'] === 'draft' ? 'draft' : 'live';

            $response = $this->recreate_tree($target);

            header( "Content-Type: application/json" );
            echo json_encode($response);

            //Don't forget to always exit in the ajax function.
            exit();

        }


        public function ajax_check_sync() {

            $reponse = array();
            $only_draft_sync = $_POST['only_draft_sync'] === 'true';

            if (!empty($_POST['post_id'])) {
                $id = $_POST['post_id'];
                if (!$only_draft_sync) {
                    $response = $this->check_sync($id, $only_draft_sync);
                }
            } else if (!empty($_POST['api_path'])){
                $permalink = $_POST['api_path'];
                $response = $this->check_sync($permalink, $only_draft_sync);
            }

            header( "Content-Type: application/json" );
            echo json_encode($response);

            //Don't forget to always exit in the ajax function.
            exit();

        }



        public function send_json($data){
            header("content-type: application/json");
            echo json_encode($data);
        }


        function add_admin_pages() {

            add_menu_page( 'Draft/Live Sync', 'Draft/Live Sync', 'manage_options', 'draft-live-sync', array( &$this, 'render_admin_page'));

            add_submenu_page('draft-live-sync', 'Sync check', 'Sync check', 'manage_options', 'draft-live-sync-check-sync', array( &$this, 'render_admin_page_check_sync'));
            add_submenu_page('draft-live-sync', 'Sync DRAFT', 'Sync DRAFT', 'manage_options', 'draft-live-sync-reset', array( &$this, 'render_admin_page_reset'));
            add_submenu_page('draft-live-sync', 'Publish LIVE', 'Publish LIVE', 'manage_options', 'draft-live-sync-publish', array( &$this, 'render_admin_page_publish'));

            add_submenu_page('draft-live-sync', 'Reindex DRAFT', 'Re-index DRAFT', 'manage_options', 'draft-live-sync-reindex-draft', array( &$this, 'render_admin_page_reindex_draft'));
            add_submenu_page('draft-live-sync', 'Reindex LIVE', 'Re-index LIVE', 'manage_options', 'draft-live-sync-reindex-live', array( &$this, 'render_admin_page_reindex_live'));

        }

        function render_admin_page() {
            global $title;

            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '</div>';
        }

        function render_admin_page_check_sync() {

            global $title;

            print '<script id="dls-data" type="application/json">{ "api": { "checkSyncUrl": "' . plugins_url( 'ajax/check-sync.php', dirname(__FILE__) ) . '"  } }</script>';
            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '<div> <button id="draft-sync--check-wpdraft-button" class="button button-primary button-large">Start checking the status of wordpress/draft</button> <button id="draft-sync--check-draftlive-button" class="button button-primary button-large">Start checking the status of draft/live</button></div>';

            print '<div id="dls--percent"></div>';
            print '<ul id="dls--resource-list">Loading list of content...</ul>';

            print '</div>';

        }


        function render_admin_page_reset() {

            global $title;

            print '<script id="dls-data" type="application/json">{ "api": { "syncUrl": "' . plugins_url( 'ajax/sync.php', dirname(__FILE__) ) . '"  } }</script>';
            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '<div><button id="draft-sync--reset-button" class="button button-primary button-large">Start reseting everything</button><button id="draft-sync--reset-tree-button" class="button button-primary button-large">Recreate DRAFT tree</button><div id="draft-sync--status-message"></div></div>';

            print '<div id="dls--percent"></div>';

            print '<ul id="dls--resource-list">Loading list of content...</ul>';

            print '</div>';

        }

        function render_admin_page_publish() {
            global $title;

            print '<script id="dls-data" type="application/json">{ "release": "live", "api": { "syncUrl": "' . plugins_url( 'ajax/sync.php', dirname(__FILE__) ) . '"  } }</script>';
            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '<div><button id="draft-sync--reset-button" class="button button-primary button-large">Start publishing everything to LIVE</button><button id="draft-sync--reset-tree-button" class="button button-primary button-large">Recreate LIVE tree</button><div id="draft-sync--status-message"></div></div>';

            print '<div id="dls--percent"></div>';

            print '<ul id="dls--resource-list">Loading list of content...</ul>';

            print '</div>';
        }

        function render_admin_page_reindex_draft() {
            global $title;

            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '<div><a class="button button-primary button-large" target="reset-content" href="/draft-live-sync/reindex-content">Start reindex everything in draft</a></div>';
            print '<p>We notifiy all listeners of content\'s data (e.g. Elasticsearch)</p>';
            print '</div>';
        }

        function render_admin_page_reindex_live() {
            global $title;

            print '<div class="wrap">';
            print "<h1>$title</h1>";

            print '<div><a class="button button-primary button-large" target="reset-content" href="/draft-live-sync/reindex-publish">Start reindex everything in live</a></div>';
            print '<p>We notifiy all listeners of content\'s data (e.g. Elasticsearch)</p>';
            print '</div>';
        }

        // Constructs a list of urls
        function add_to_complete_url_list($type = 'post') {
            $list = array();
            $posts = get_posts(
                array(
                    'numberposts' => 10000,
                    'post_type'   => $type
                )
            );

            foreach ( $posts as $post ) {

                $permalink = get_permalink($post->ID);
                $permalink = $this->replace_hosts($permalink);

                $link_object = new stdclass();
                $link_object->permalink = $permalink;
                $link_object->type = $type;

                array_push($list, $link_object);
            }

            return $list;
        }

        function add_tags_to_complete_url_list() {
            $list = array();
            $tags = get_tags();
            foreach ( $tags as $index => $tag ) {

                $permalink = get_tag_link( $tag->term_id );
                $permalink =  $this->replace_hosts($permalink);

                $link_object = new stdclass();
                $link_object->permalink = $permalink;
                $link_object->type = 'tag';

                array_push($list, $link_object);

            }
            return $list;
        }

        function filter_the_content_replace_hosts ( $input ) {

            // If we have a comma separated list of hosts, we replace them as well
            if (getenv("REPLACE_HOST_LIST")) {
                $replace_host_list = explode(',', getenv('REPLACE_HOST_LIST'));
            } else {
                $replace_host_list = array();
            }

            $original_host = get_site_url();

            // We always use the wordpress host too
            array_push($replace_host_list, $original_host);
            array_push($replace_host_list, addcslashes($original_host, '/'));

            // Remove localhost links
            array_push($replace_host_list, addcslashes($original_host, '/'));

            // Get the list of replacable hosts from the settings
            $extra_hosts = $this->settings_page->get_replace_hosts();

            // Merge all lists
            $replace_host_list = array_merge($replace_host_list, $extra_hosts);

            foreach ($replace_host_list as $host) {
                $replace_string = addcslashes($host, '/');
                $input = str_replace($host, '', $input);
                $input = str_replace($replace_string, '', $input);
            }

            // Remove localhost links
            $input = preg_replace('/http(|s):\\\\\/\\\\\/localhost(|:\d+)\\\\\//', '/', $input);

            // Remove links by IP, since we might not know what domain wordpress uses internally
            $input = preg_replace('/http(|s):\\\\\/\\\\\/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(|:\d+)\\\\\//', '/', $input);

            return $input;

        }

        function get_all_resources() {

            $list = array();

            foreach( get_nav_menu_locations() as $location => $menu_id ) {
                $link_object = new stdclass();
                $link_object->type = 'menu';
                if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE != 'en' && ICL_LANGUAGE_CODE != '') {
                    $link_object->permalink = '/json/api/' . ICL_LANGUAGE_CODE . '/general/menu/' . $location;
                } else {
                    $link_object->permalink = '/json/api/general/menu/' . $location;
                }
                array_push($list, $link_object);
            }

            $option_permalinks = $this->get_other_resources();

			foreach ( $option_permalinks as $option_permalink ) {
                $option = new stdclass();
                $option->type = 'option';
                $option->permalink = $option_permalink[1];
                array_push($list, $option);
			}

            // Add special footer API call
			$post_types = $this->settings_page->get_enabled_post_types();

			foreach ( $post_types as $post_type ) {
				$list = array_merge($list, $this->add_to_complete_url_list($post_type));
			}

            $list = array_merge($list, $this->add_tags_to_complete_url_list());

            return $list;

        }


        // Publish all pages to live
        function init_push($destination = 'draft') {

            global $draft_live_sync;

            $list = $this->get_all_resources();

            if ($destination == 'draft') {
                echo '<h2>Reset content for the following permalinks:</h2>';
            } else {
                echo '<h2>Publish the content for the following permalinks to the live server:</h2>';
            }

            foreach ( $list as $link_object ) {
                $draft_live_sync->push_to_queue($link_object->permalink, $destination); // , false);
                echo ' > ' . $link_object->permalink . '<br/>';
                flush();
            }

            ob_flush();

        }

        function parse_requests ($wp) {

            // if ( is_admin() ) {
            if ($wp->request == 'draft-live-sync/reset') {
                $this->init_push('draft');
                exit();
            }

            if ($wp->request == 'draft-live-sync/publish') {
                $this->init_push('live');
                exit();
            }

            if ($wp->request == 'draft-live-sync/reindex-content') {
                $this->reindex_content('draft');
                exit();
            }

            if ($wp->request == 'draft-live-sync/reindex-publish') {
                $this->reindex_content('live');
                exit();
            }

        }




    }

}
