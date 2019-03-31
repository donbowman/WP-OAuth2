<?php

$settings = array(
    'provider' => 'github',
    'client_id' => get_option('wpoa2_github_api_id'),
    'client_secret' => get_option('wpoa2_github_api_secret'),
    'redirect_uri' => rtrim(site_url(), '/') . '/',
    'scope' => 'user:email',
    'auth_type' => 'token',
    'url_auth' => "https://github.com/login/oauth/authorize?",
    'url_token' => "https://github.com/login/oauth/access_token?",
    'url_user' => "https://api.github.com/user?",
    'token_parser' => 'str',
    'user_field' => 'id',
    'wpoa2' => $this
);

$client = new WPOAuth2_client($settings);

$client->auth($_GET);

?>
