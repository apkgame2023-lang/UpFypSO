<?php
/**
 * Y4APK REST API Meta Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'y4apk_register_post_meta');


function y4apk_register_post_meta() {
    $meta_fields = array(
        'wp_download_links' => array(
            'type'              => 'string',
            'description'       => 'Download links used by Y4APK',
            'sanitize_callback' => function ($value) {
                return is_string($value) ? $value : wp_json_encode($value);
            },
        ),
        'wp_version' => array(
            'type'              => 'string',
            'description'       => 'Game/App version',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'wp_size' => array(
            'type'              => 'string',
            'description'       => 'APK file size',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'wp_mod' => array(
            'type'              => 'string',
            'description'       => 'MOD features',
            'sanitize_callback' => 'sanitize_text_field',
        ),
    );

    foreach ($meta_fields as $key => $args) {
        register_post_meta(
            'post',
            $key,
            array_merge(
                $args,
                array(
                    'single'        => true,
                    'show_in_rest'  => true,
                    'auth_callback' => function ($allowed, $meta_key, $post_id) {
                        return current_user_can('edit_post', $post_id);
                    },
                )
            )
        );
    }
}


add_action('rest_api_init', function () {
    register_rest_route('y4apk/v1', '/auth-test', array(
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {

            $auth = isset($_SERVER['HTTP_AUTHORIZATION'])
                ? $_SERVER['HTTP_AUTHORIZATION']
                : '';

            return array(
                'authorization_received' => $auth !== '',
                'authorization_prefix' => $auth !== ''
                    ? substr($auth, 0, 5)
                    : '',
                'user_logged_in' => is_user_logged_in(),
                'current_user_id' => get_current_user_id(),
            );
        },
    ));
});


add_action('rest_api_init', function () {

    register_rest_route('y4apk/v1', '/app-password-status', array(
        'methods'  => 'GET',
        'permission_callback' => '__return_true',

        'callback' => function () {

            $available = function_exists('wp_is_application_passwords_supported')
                ? wp_is_application_passwords_supported()
                : null;

            $auth = isset($_SERVER['HTTP_AUTHORIZATION'])
                ? $_SERVER['HTTP_AUTHORIZATION']
                : '';

            return array(
                'application_password_function_exists' =>
                    function_exists('wp_is_application_passwords_supported'),

                'application_passwords_supported' =>
                    $available,

                'authorization_received' =>
                    $auth !== '',

                'authorization_prefix' =>
                    $auth !== '' ? substr($auth, 0, 5) : '',

                'wp_user' =>
                    wp_get_current_user()->user_login,

                'current_user_id' =>
                    get_current_user_id(),

                'is_logged_in' =>
                    is_user_logged_in(),
            );
        },
    ));

});