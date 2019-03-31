<?php

/*
 * call 'https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))'
 * Get
 * {
 * "elements" : [ {
 *   "handle" : "urn:li:emailAddress:XXXXXXXXXX",
 *   "handle~" : {
 *     "emailAddress" : "XX@emample.com"
 *   }
 * } ]
*/
function _getEmail($token, $result) {

    $url = 'https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))';
    $opts = array('http' =>
        array(
            'method' => 'GET',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'header'  => "Authorization: Bearer " . $token
        )
    );
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        echo "<pre>\nError retrieving email Token";
        echo "\nurl: "; print_r($url);
        echo "\nopts: "; print_r($opts);
        echo "\nresult: "; print_r($result);
        return '';
    }
    $result_obj = json_decode($result, true);
    $email = $result_obj['elements'][0]['handle~']['emailAddress'];
    return $email;
}

/*
 * Linkedin doesn't use openid, we need 2 api calls and
 * some normalisation
 */
function _parse($token, $result) {
    $result = json_decode($result, true);
    $result_obj = $result;
    $result_obj['id'] = $result['id'];
    $result_obj['email'] = _getEmail($token, $result);

    $country = $result['firstName']['preferredLocale']['country'];
    $lang = $result['firstName']['preferredLocale']['language'];
    $locale = $lang . '_' . $country;
    $first = $result['firstName']['localized'][$locale];
    $last = $result['lastName']['localized'][$locale];

    $result_obj['name'] = $first . " " . $last;

    return $result_obj;
}

$settings = array(
    'provider' => 'linkedin',
    'client_id' => get_option('wpoa2_linkedin_api_id'),
    'client_secret' => get_option('wpoa2_linkedin_api_secret'),
    'redirect_uri' => rtrim(site_url(), '/') . '/',
    'scope' => 'r_emailaddress r_liteprofile',
    'auth_type' => 'Bearer',
    'url_auth' => "https://www.linkedin.com/oauth/v2/authorization?",
    'url_token' =>"https://www.linkedin.com/oauth/v2/accessToken?",
    'url_user' => "https://api.linkedin.com/v2/me",
    'grant_type' => 'authorization_code',
    'token_parser' => 'json',
    'id_parser' => '_parse',
    'user_field' => 'id',
    'wpoa2' => $this
);

$client = new WPOAuth2_client($settings);
$client->auth($_GET);

?>
