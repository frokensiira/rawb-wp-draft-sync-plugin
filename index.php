<?php

/*
Plugin Name: Draft/Live Sync for Content Service
Plugin URI: http://24hr.se
Description: Saves content to a Draft Content Service and gives the possibility to push the content to live
Version: 0.5.4
Author: Camilo Tapia <camilo.tapia@24hr.se>
*/

// don't load directly
if ( !defined( 'ABSPATH' ) ) {

    die( '-1' );

} else {

    $dir = dirname( __FILE__ );

    define('DraftLiveSyncVERSION', '0.5.4');

    require_once( $dir . '/lib/draft-live-sync-class.php' );

    function draft_live_sync_init() {

        // Init or use instance of the manager.
        $dir = dirname( __FILE__ );
        $content_draft_url = getenv('CONTENT_DRAFT_URL');
        $api_token = getenv('API_TOKEN');

        if(class_exists( 'DraftLiveSync' )){
            global $draft_live_sync;
            $draft_live_sync = new DraftLiveSync($dir, DraftLiveSyncVERSION, $content_draft_url, $api_token);
            // $draft_live_sync->init();
        }

    }

    add_action( 'init', 'draft_live_sync_init');

}

