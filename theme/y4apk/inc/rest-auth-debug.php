<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {

    register_rest_route('y4apk/v1', '/auth-test', array(
        'methods'  => 'GET',
        'permission_callback' => '__return_true',

        'callback' => function () {

            $auth = '';

            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }

            return array(
                'authorization_received' => !empty($auth),
                'authorization_prefix' => $auth
                    ? substr($auth, 0, 5)
                    : '',
            );
        },
    ));

});