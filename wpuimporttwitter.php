<?php

/*
Plugin Name: WPU Import Twitter
Plugin URI: http://github.com/Darklg/WPUtilities
Version: 0.1
Description: Twitter Import
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Required plugins: WPU Post Types & Taxos, WPU Options
*/

class WPUImportTwitter {
    function __construct() {
        add_action('init', array(&$this,
            'init'
        ));
    }

    function init() {
        add_filter('wputh_get_posttypes', array(&$this,
            'create_posttypes'
        ));

        // Options
        add_filter('wpu_options_tabs', array(&$this,
            'set_options_tabs'
        ) , 10, 3);
        add_filter('wpu_options_boxes', array(&$this,
            'set_options_boxes'
        ) , 10, 3);
        add_filter('wpu_options_fields', array(&$this,
            'set_options_fields'
        ) , 10, 3);
    }

    function set_options_tabs($tabs) {
        $tabs['wpuimporttwitter'] = array(
            'name' => '[Plugin] Import Twitter'
        );
        return $tabs;
    }

    function set_options_boxes($boxes) {
        $boxes['wpuimporttwitter_import'] = array(
            'name' => 'Import settings',
            'tab' => 'wpuimporttwitter'
        );
        $boxes['wpuimporttwitter_oauth'] = array(
            'name' => 'Oauth settings',
            'tab' => 'wpuimporttwitter'
        );
        return $boxes;
    }

    function set_options_fields($options) {
        $options['wpuimptwit_screen_name'] = array(
            'label' => 'Screen name',
            'box' => 'wpuimporttwitter_import',
            'type' => 'text'
        );
        $options['wpuimptwit_include_replies'] = array(
            'label' => 'Include replies',
            'box' => 'wpuimporttwitter_import',
            'type' => 'select'
        );
        $options['wpuimptwit_oauth_include_rts'] = array(
            'label' => 'Include RTs',
            'box' => 'wpuimporttwitter_import',
            'type' => 'select'
        );
        $options['wpuimptwit_oauth_access_token'] = array(
            'label' => 'Access token',
            'box' => 'wpuimporttwitter_oauth',
            'type' => 'text'
        );
        $options['wpuimptwit_oauth_access_token_secret'] = array(
            'label' => 'Access token secret',
            'box' => 'wpuimporttwitter_oauth',
            'type' => 'text'
        );
        $options['wpuimptwit_consumer_key'] = array(
            'label' => 'Consumer key',
            'box' => 'wpuimporttwitter_oauth',
            'type' => 'text'
        );
        $options['wpuimptwit_consumer_secret'] = array(
            'label' => 'Consumer secret',
            'box' => 'wpuimporttwitter_oauth',
            'type' => 'text'
        );
        return $options;
    }

    function create_posttypes($post_types) {
        $post_types['tweet'] = array(
            'menu_icon' => 'dashicons-twitter',
            'name' => 'Tweet',
            'plural' => 'Tweets',
            'female' => 0,
            'wputh__hide_front' => 1
        );
        return $post_types;
    }

    function import() {

        // Get last tweets from Twitter
        $last_tweets = $this->get_last_tweets_for_user();

        // Get ids from last imported tweets
        $imported_tweets_ids = $this->get_last_imported_tweets_ids();

        // Exclude tweets already imported
        foreach ($last_tweets as $tweet) {
            if (!in_array($tweet['id'], $imported_tweets_ids)) {

                // Create a post for each new tweet
                $this->create_post_from_tweet($tweet);
            }
        }
    }

    function get_last_imported_tweets_ids() {
        $ids = array();
        $posts = get_posts(array(
            'posts_per_page' => 100,
            'post_type' => 'tweet',
            'post_status' => array(
                'publish',
                'pending',
                'draft',
                'auto-draft',
                'future',
                'private',
                'inherit',
                'trash'
            )
        ));
        foreach ($posts as $tweet) {
            $id = get_post_meta($tweet->ID, 'wpuimporttwitter_id', 1);
            if (is_numeric($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    function get_last_tweets_for_user($screen_name = false) {
        if ($screen_name == false) {
            $screen_name = get_option('wpuimptwit_screen_name');
        }
        $settings = array(
            'oauth_access_token' => '',
            'oauth_access_token_secret' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
        );
        foreach ($settings as $id => $value) {
            $settings[$id] = get_option('wpuimptwit_' . $id);
        }

        /* Based on http://stackoverflow.com/a/16169848 by @budidino */

        $twitter_url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';

        // Create request
        $request = array(
            'screen_name' => $screen_name,
            'count' => 30
        );

        $request['exclude_replies'] = (get_option('wpuimptwit_include_replies') != 1) ? 'true' : 'false';
        $request['include_rts'] = (get_option('wpuimptwit_include_rts') == 1) ? 'true' : 'false';

        $oauth = array(
            'oauth_consumer_key' => $settings['consumer_key'],
            'oauth_nonce' => md5(mt_rand()) ,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time() ,
            'oauth_token' => $settings['oauth_access_token'],
            'oauth_version' => '1.0'
        );

        $oauth = array_merge($oauth, $request);

        $base_info = $this->buildBaseString($twitter_url, 'GET', $oauth);
        $composite_key = rawurlencode($settings['consumer_secret']) . '&' . rawurlencode($settings['oauth_access_token_secret']);
        $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
        $oauth['oauth_signature'] = $oauth_signature;

        //  make request
        $options = array(
            CURLOPT_HTTPHEADER => array(
                $this->buildAuthorizationHeader($oauth) ,
                'Expect:'
            ) ,
            CURLOPT_HEADER => false,
            CURLOPT_URL => $twitter_url . '?' . http_build_query($request) ,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        );

        $feed = curl_init();
        curl_setopt_array($feed, $options);
        $response = curl_exec($feed);
        curl_close($feed);

        return $this->get_tweets_from_response($response);
    }

    function get_tweets_from_response($json_response) {
        $tweets = array();
        $response = json_decode($json_response);
        if (!is_array($response)) {
            return $response;
        }
        foreach ($response as $tweet) {
            if (!isset($tweet->text)) {
                continue;
            }
            $tweets[$tweet->id] = array(
                'id' => $tweet->id,
                'text' => $tweet->text,
                'screen_name' => $tweet->user->screen_name,
                'time' => strtotime($tweet->created_at)
            );
        }
        return $tweets;
    }

    function create_post_from_tweet($tweet) {

        $tweet_post = array(
            'post_title' => substr($tweet['text'], 0, 50) ,
            'post_content' => $tweet['text'],
            'post_date' => date('Y-m-d H:i:s', $tweet['time']) ,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'tweet'
        );

        // Insert the post into the database
        $post_id = wp_insert_post($tweet_post);

        // Store metas
        add_post_meta($post_id, 'wpuimporttwitter_id', $tweet['id']);
        add_post_meta($post_id, 'wpuimporttwitter_screen_name', $tweet['screen_name']);
    }

    function buildBaseString($baseURI, $method, $params) {
        $r = array();
        ksort($params);
        foreach ($params as $key => $value) {
            $r[] = "$key=" . rawurlencode($value);
        }
        return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
    }

    function buildAuthorizationHeader($oauth) {
        $r = 'Authorization: OAuth ';
        $values = array();
        foreach ($oauth as $key => $value) $values[] = "$key=\"" . rawurlencode($value) . "\"";
        $r.= implode(', ', $values);
        return $r;
    }

    function install() {
        wp_schedule_event(time() , 'hourly', 'wpuimporttwitter__cron_hook');
    }
}

$WPUImportTwitter = new WPUImportTwitter();

register_activation_hook(__FILE__, array(&$WPUImportTwitter,
    'install'
));

add_action('wpuimporttwitter__cron_hook', 'wpuimporttwitter__import');
function wpuimporttwitter__import() {
    global $WPUImportTwitter;
    $WPUImportTwitter->import();
}
