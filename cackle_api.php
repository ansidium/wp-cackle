<?php

class CackleAPI
{
    private $siteId;
    private $accountApiKey;
    private $siteApiKey;
    private $cackle_last_modified;
    private $get_url;
    private $get_url2;
    private $update_url;
    private $last_error;

    function to_i($number_to_format)
    {
        return number_format($number_to_format, 0, '', '');
    }

    function __construct()
    {
        $this->siteId = $siteId = get_option('cackle_apiId');
        $this->accountApiKey = $accountApiKey = get_option('cackle_accountApiKey');
        $this->siteApiKey = $siteApiKey = get_option('cackle_siteApiKey');
        $this->cackle_last_modified = $this->cackle_get_param('cackle_last_modified', 0);
        $this->get_url = "https://cackle.me/api/3.0/comment/list.json?id=$siteId&accountApiKey=$accountApiKey&siteApiKey=$siteApiKey";
        $this->get_url2 = "https://cackle.me/api/2.0/site/info.json?id=$siteId&accountApiKey=$accountApiKey&siteApiKey=$siteApiKey";
        $this->update_url = "https://cackle.me/api/wp115/setup?accountApiKey=$accountApiKey&siteApiKey=$siteApiKey";
        $this->last_error = null;
    }

    function cackle_set_param($param, $value)
    {
        $beg = "/";
        $value = $beg . $value;
        $eof = "/";
        $value .= $eof;
        return update_option($param, $value);
    }

    function cackle_get_param($param, $default)
    {
        $res = get_option($param, $default);
        if (!is_string($res)) {
            $res = (string)$res;
        }
        $res = str_replace("/", "", $res);
        return $res;
    }

    function get_comments($criteria, $cackle_last, $post_id, $cackle_page = 0){
        $host = '';

        if ($criteria == 'last_comment') {
            $host = $this->get_url . "&commentId=" . $cackle_last . "&size=100&chan=" . $post_id;
        }
        if ($criteria == 'last_modified') {
            $host = $this->get_url . "&modified=" . $cackle_last . "&page=" . $cackle_page . "&size=100&chan=" . $post_id;
        }

        if ($host === '') {
            $this->last_error = 'Unsupported criteria';
            return null;
        }

        $response = wp_remote_get(
            $host,
            array(
                'timeout' => 5,
                'redirection' => 0,
                'headers' => array(
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ),
            )
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $this->last_error = 'HTTP ' . $status_code;
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    function get_last_comment_by_channel($channel, $default)
    {
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare("
                            SELECT last_comment
                            FROM {$wpdb->prefix}cackle_channel
                            WHERE id = %s
                            ORDER BY ID ASC
                            LIMIT 1
                            ", $channel));
        if (sizeof($result) > 0) {
            $result = $result[0]->last_comment;
            if (is_null($result)) {
                return $default;
            } else {
                return $result;
            }
        }
    }

    function set_last_comment_by_channel($channel, $last_comment)
    {
        global $wpdb;
        $sql = "UPDATE {$wpdb->prefix}cackle_channel SET last_comment = %s  WHERE id = %s";
        $sql = $wpdb->prepare($sql, $last_comment, $channel);
        $wpdb->query($sql);
    }

    function set_monitor_status($status)
    {
    }

    function get_last_modified_by_channel($channel, $default)
    {
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare("
                            SELECT modified
                            FROM {$wpdb->prefix}cackle_channel
                            WHERE id = %s
                            ORDER BY ID ASC
                            LIMIT 1
                            ", $channel));
        if (sizeof($result) > 0) {
            $result = $result[0]->modified;
            if (is_null($result)) {
                return $default;
            } else {
                return $result;
            }
        }
    }

    function set_last_modified_by_channel($channel, $last_modified)
    {
        global $wpdb;
        $sql = "UPDATE {$wpdb->prefix}cackle_channel SET modified = %s  WHERE id = %s";
        $sql = $wpdb->prepare($sql, $last_modified, $channel);
        $wpdb->query($sql);
    }

    function update_comments($update_request)
    {
        $blog_url = get_bloginfo('wpurl');
        $response = wp_remote_post(
            $this->update_url,
            array(
                'timeout' => 5,
                'headers' => array(
                    'Content-type' => 'application/x-www-form-urlencoded',
                    'Referer' => $blog_url,
                ),
                'body' => $update_request,
            )
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $this->last_error = 'HTTP ' . $status_code;
        }
    }

    function key_validate($api, $site, $account)
    {
        $key_url = "https://cackle.me/api/2.0/site/info.json?id=$api&accountApiKey=$account&siteApiKey=$site";
        $response = wp_remote_get(
            $key_url,
            array(
                'timeout' => 5,
                'headers' => array('Referer' => get_bloginfo('wpurl')),
            )
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $this->last_error = 'HTTP ' . $status_code;
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    function get_all_channels($size,$page,$modified=0){
        $siteId = $this->siteId;
        $accountApiKey = $this->accountApiKey;
        $siteApiKey = $this->siteApiKey;
        $url_base = "https://cackle.me/api/3.0/comment/chan/list.json?id=$siteId&siteApiKey=$siteApiKey&accountApiKey=$accountApiKey&size=$size&page=$page";
        if($modified){
            $url = $url_base . "&gtModify=$modified";
        }
        else{
            $url = $url_base;
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 5,
                'redirection' => 0,
                'headers' => array(
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ),
            )
        );

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $this->last_error = 'HTTP ' . $status_code;
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    function import_wordpress_comments($comments, $post_id, $eof = true){
        $permalink = wp_get_shortlink($post_id->ID);
        if (!$permalink) {
            $permalink = get_permalink($post_id->ID);
        }

        $data = array(
            'chan' => $post_id->ID,
            'url' => rawurlencode((string) $permalink),
            'title' => $post_id->post_title,
            'comments' => $comments);
        $postfields = wp_json_encode($data);
        $params = array(
            'id' => $this->siteId,
            'accountApiKey' => $this->accountApiKey,
            'siteApiKey' => $this->siteApiKey
        );

        $response = wp_remote_post(
            'https://cackle.me/api/3.0/comment/post.json?' . http_build_query($params),
            array(
                'timeout' => 5,
                'body' => $postfields,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            )
        );

        if (is_wp_error($response)){
            $this->last_error = $response->get_error_message();
            $arr = array();
            $arr['responseApi']['status']= 'fail';
            $arr['responseApi']['error']='Cackle not responded';
            return wp_json_encode($arr);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $this->last_error = 'HTTP ' . $status_code;
            $arr = array();
            $arr['responseApi']['status']= 'fail';
            $arr['responseApi']['error']='Cackle not responded';
            return wp_json_encode($arr);
        }

        return wp_remote_retrieve_body($response);
    }

    function get_last_error(){
        if (empty($this->last_error)) return;
        if (!is_string($this->last_error)) {
            return var_export($this->last_error, true);
        }
        return $this->last_error;
    }

}

?>
