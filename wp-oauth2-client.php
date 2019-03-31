<?php

class WPOAuth2_client {

    /* Settings
        provider;
        client_id;
        client_secret;
        redirect_uri;
        scope;
        url_auth;
        url_token;
        url_user;
        wpoa2;
        token_parser;
        id_parser;
        auth_type;
        user_field;
        grant_type;
    */
    private $settings = array(
        'auth_type' => 'Bearer',
        'grant_type' => 'authorization_code',
        'token_parser' => 'json',
        'id_parser' => 'json'
    );
    function __construct($settings) {

        $this->settings = array_merge($this->settings, $settings);

        $_SESSION['WPOA2']['PROVIDER'] = $this->settings['provider'];

        // remember the user's last url so we can redirect them back to there after the login ends:
        if (!array_key_exists('LAST_URL', $_SESSION['WPOA2']) || !$_SESSION['WPOA2']['LAST_URL']) {
            $_SESSION['WPOA2']['LAST_URL'] = array_key_exists('HTTP_REFERER', $_SERVER) ? strtok($_SERVER['HTTP_REFERER'], "?") : "/";
        }
    }

    private function get_oauth_token() {
        $params = array(
            'grant_type' => $this->settings['grant_type'],
            'client_id' => $this->settings['client_id'],
            'client_secret' => $this->settings['client_secret'],
            'code' => $_GET['code'],
            'redirect_uri' => $this->settings['redirect_uri'],
        );
        $url_params = http_build_query($params);
        $url = rtrim($this->settings['url_token'], "?");
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $url_params,
            )
        );
        $context = $context  = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            echo "<pre>\nError retrieving access token";
            echo "\nurl: "; print_r($url);
            echo "\nurl_params: "; print_r($url_params);
            echo "\nresult: "; print_r($result);
            $this->settings['wpoa2']->wpoa2_end_login("Could not retrieve access token via stream context.");
        }

        // Some providers this comes back as a querystring to be parsed
        if ($this->settings['token_parser'] == "str") {
            parse_str($result, $result_obj);
        } elseif ($this->settings['token_parser'] == "json") {
            $result_obj = json_decode($result, true);
        } else {
            // Normalise in user-provided callback, must fill in 'access_token',
            // may fill in expires_in, expires_at
            $result_obj = call_user_func($this->settings['token_parser'], $result);
        }

        $access_token = $result_obj['access_token'];
        if (array_key_exists('expires_in', $result_obj)) {
            $_SESSION['WPOA2']['EXPIRES_IN'] = $result_obj['expires_in'];
        }
        if (array_key_exists('expires_at', $result_obj)) {
            $_SESSION['WPOA2']['EXPIRES_AT'] = $result_obj['expires_at'];
        }
        // handle the result:
        if (!$access_token) {
            // malformed access token result detected:
            $this->settings['wpoa2']->wpoa2_end_login("Malformed access token result detected.");
        }
        else {
            $_SESSION['WPOA2']['ACCESS_TOKEN'] = $access_token;
        }
    }

    private function get_oauth_code() {
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->settings['client_id'],
            'scope' => $this->settings['scope'],
            'state' => uniqid('', true),
            'redirect_uri' => $this->settings['redirect_uri'],
        );
        $_SESSION['WPOA2']['STATE'] = $params['state'];
        $url = $this->settings['url_auth'] . http_build_query($params);
        wp_redirect($url);
        //header("Location: $url");
        exit;
    }

    private function get_oauth_identity() {
        // here we exchange the access token for the user info...
        // set the access token param:
        $params = array(
            'access_token' => $_SESSION['WPOA2']['ACCESS_TOKEN'],
        );
        $url_params = http_build_query($params);
        // perform the http request:
        $url = rtrim($this->settings['url_user'], "?");
        $opts = array('http' =>
            array(
                'method' => 'GET',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'header'  => "Authorization: " . $this->settings['auth_type'] . " " . $_SESSION['WPOA2']['ACCESS_TOKEN'],
            )
        );
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            echo "<pre>\nError retrieving user identity";
            echo "\nurl: "; print_r($url);
            echo "\nurl_params: "; print_r($url_params);
            echo "\nopts: "; print_r($opts);
            echo "\nresult: "; print_r($result);
            $this->settings['wpoa2']->wpoa2_end_login("Could not retrieve user identity via stream context. Please notify the admin or try again later.");
        }

        // Some providers this comes back as a querystring to be parsed
        if ($this->settings['id_parser'] == "str") {
            parse_str($result, $result_obj);
        } elseif ($this->settings['id_parser'] == "json") {
            $result_obj = json_decode($result, true);
        } else {
            // Normalise in user-provided callback, must fill in 'id', 'email', 'first', 'last', 'name'
            $result_obj = call_user_func($this->settings['id_parser'], $_SESSION['WPOA2']['ACCESS_TOKEN'], $result);
        }

        // parse and return the user's oauth identity:
        $oauth_identity = array();
        $oauth_identity['provider'] = $_SESSION['WPOA2']['PROVIDER'];
        $oauth_identity['id'] = $result_obj[$this->settings['user_field']];
        if (array_key_exists('getEmail', $this->settings)) {
            // Some providers don't have the result properly formatted, use callback
            $oauth_identity['email'] = call_user_func($this->settings['getEmail'], $_SESSION['WPOA2']['ACCESS_TOKEN'], $result);
        } else {
            $oauth_identity['email'] = $result_obj['email'];
        }
        if (array_key_exists('getName', $this->settings)) {
            // Some providers don't have the result properly formatted, use callback
            $oauth_identity['name'] = call_user_func($this->settings['getName'], $_SESSION['WPOA2']['ACCESS_TOKEN'], $result);
        } else {
            $oauth_identity['name'] = $result_obj['name'];
        }

        if (!$oauth_identity['id']) {
            $this->settings['wpoa2']->wpoa2_end_login("User identity was not found.");
        }
        return $oauth_identity;
    }

    // AUTHENTICATION FLOW
    public function auth() {
        // the oauth 2.0 authentication flow will start here, and callback here
        // calls to the third-party authentication provider which in turn will make
        // callbacks to this script that we continue to handle until the login
        // completes with a success or failure:
        if (isset($_GET['error_description'])) {
            // do not proceed if an error was detected:
            $this->settings['wpoa2']->wpoa2_end_login($_GET['error_description']);
        }
        elseif (isset($_GET['error_message'])) {
            // do not proceed if an error was detected:
            $this->settings['wpoa2']->wpoa2_end_login($_GET['error_message']);
        }
        elseif (isset($_GET['code'])) {
            // post-auth phase, verify the state:
            if ($_SESSION['WPOA2']['STATE'] == $_GET['state']) {
                // get an access token from the third party provider:
                $this->get_oauth_token();
                // get the user's third-party identity and attempt to login/register a matching wordpress user account:
                $oauth_identity = $this->get_oauth_identity();
                $this->settings['wpoa2']->wpoa2_login_user($oauth_identity);
            }
            else {
                // possible CSRF attack, end the login with a generic message to the user and a detailed message to the admin/logs in case of abuse:
                // TODO: report detailed message to admin/logs here...
                $this->settings['wpoa2']->wpoa2_end_login("No Token/State found.");
            }
        }
        else {
            // pre-auth, start the auth process:
            if ((empty($_SESSION['WPOA2']['EXPIRES_AT'])) || (time() > $_SESSION['WPOA2']['EXPIRES_AT'])) {
                // expired token; clear the state:
                $this->settings['wpoa2']->wpoa2_clear_login_state();
            }
            $this->get_oauth_code();
        }
        // we shouldn't be here, but just in case...
        $this->settings['wpoa2']->wpoa2_end_login("The authentication flow terminated in an unexpected way.");
        # END OF AUTHENTICATION FLOW #
    }
}


?>
