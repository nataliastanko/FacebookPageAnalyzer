<?php

// web/index.php
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Application\TwigTrait;

$app = new Silex\Application();

$app['debug'] = true;

try {
    $dotenv = new Dotenv\Dotenv(__DIR__, '../.env');
    $dotenv->load();
    $dotenv->required(['FB_APP_ID', 'FB_APP_SECRET', 'FB_DEFAULT_ACCESS_TOKEN', 'FB_PAGE_ID'])->notEmpty();
} catch (\Exception $e) {
    echo $e->getMessage();
    exit;
}

$app->get('/', function () {

    // https://github.com/facebook/php-graph-sdk/blob/5.5/docs/reference.md

    $fb = new \Facebook\Facebook([
      'app_id' => getenv('FB_APP_ID'),
      'app_secret' => getenv('FB_APP_SECRET'),
      'default_graph_version' => 'v2.9',
      'default_access_token' => getenv('FB_DEFAULT_ACCESS_TOKEN')
    ]);

    $url = '/'.getenv('FB_PAGE_ID').'?fields=id,name,picture,about,website,cover,fan_count,has_added_app,username,link';

    try {
      // Get the \Facebook\GraphNodes\GraphUser object for the current user.
      // If you provided a 'default_access_token', the '{access-token}' is optional.
      $response = $fb->get($url);
    } catch(\Facebook\Exceptions\FacebookResponseException $e) {
      // When Graph returns an error
      echo 'Graph returned an error: ' . $e->getMessage();
      exit;
    } catch(\Facebook\Exceptions\FacebookSDKException $e) {
      // When validation fails or other local issues
      echo 'Facebook SDK returned an error: ' . $e->getMessage();
      exit;
    }

    // $page = $response->getGraphUser();

    $responseArray = $response->getDecodedBody();

    return new JsonResponse($responseArray);

});

$app->run();
