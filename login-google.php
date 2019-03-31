<?php

$settings = array(
    'provider' => 'google',
    'client_id' => get_option('wpoa2_google_api_id'),
    'client_secret' => get_option('wpoa2_google_api_secret'),
    'redirect_uri' => rtrim(site_url(), '/') . '/',
    'scope' => 'email openid',
    'auth_type' => 'Bearer',
    'url_auth' => "https://accounts.google.com/o/oauth2/auth?",
    'url_token' => "https://accounts.google.com/o/oauth2/token?",
    'url_user' => "https://openidconnect.googleapis.com/v1/userinfo?",
    'token_parser' => 'json',
    'user_field' => 'sub',
    'wpoa2' => $this
);

$client = new WPOAuth2_client($settings);

$client->auth($_GET);

?>
