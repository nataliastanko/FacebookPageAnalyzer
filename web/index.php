<?php

// web/index.php
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Application\TwigTrait;

$app = new Silex\Application();

$app['debug'] = true;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => dirname(dirname(__FILE__)) . '/src/views',
));

$app->before(function () use ($app) {
    $app['twig']->addGlobal('layout', $app['twig']->loadTemplate('layout.twig'));
});

try {
    $dotenv = new Dotenv\Dotenv(__DIR__, '../.env');
    $dotenv->load();
    $dotenv->required(['FB_APP_ID', 'FB_APP_SECRET', 'FB_DEFAULT_ACCESS_TOKEN', 'FB_PAGE_ID'])->notEmpty();
} catch (\Exception $e) {
    echo __FILE__.':'.__LINE__.' '.$e->getMessage();
    exit;
}

$fb = new \Facebook\Facebook([
    'app_id' => getenv('FB_APP_ID'),
    'app_secret' => getenv('FB_APP_SECRET'),
    'default_graph_version' => 'v2.9',
    'default_access_token' => getenv('FB_DEFAULT_ACCESS_TOKEN')
]);

/**
 * Create a stats array with users types of activity
 * @param  Facebook\FacebookResponse $response
 * @param  array $weights weights of single stats
 * @return array group
 */
function processFbPageActivity($fb, $weights = null) {

    if (!$weights) {
        $weights['like'] = 1;
        $weights['commentslikes'] = 1;
        $weights['comment'] = 2;
        $weights['post'] = 3;
    }

    $url = '/'.getenv('FB_PAGE_ID').'/feed?fields=id,from{name},created_time,comments{from,likes},likes&limit=100';

    try {
        /**
         * @var Facebook\FacebookResponse
         */
        $response = $fb->get($url);

    } catch(\Facebook\Exceptions\FacebookResponseException $e) {
        // When Graph returns an error
        echo __FILE__.':'.__LINE__.' '.'Graph returned an error: ' . $e->getMessage();
        exit;
    } catch(\Facebook\Exceptions\FacebookSDKException $e) {
        // When validation fails or other local issues
        echo __FILE__.':'.__LINE__.' '.'Facebook SDK returned an error: ' . $e->getMessage();
        exit;
    }

    /**
     * Page 1
     * @var Facebook\GraphNodes\GraphEdge
     */
    $feedEdge = $response->getGraphEdge();

    $labels = [];

    $xAgo = new DateTime('now');
    $xAgo->modify('-1 month');

    $addrow = function($name) {
        return [
            'posts' => 0,
            'comments' => 0,
            'likes' => 0,
            'commentslikes' => 0,
            'shares' => 0,
            'user' => $name
        ];
    };

    $i = 0;

    do {

    // foreach ($responseArray['data'] as $post) {
    foreach ($feedEdge as $status) {
        $post = $status->asArray();

        if (!isset($group[$post['from']['id']])) {
            $group[$post['from']['id']] = $addrow($post['from']['name']);
        }

        $group[$post['from']['id']]['posts'] += $weights['post'];

        if (isset($post['likes'])) {
            foreach ($post['likes'] as $like) {
                if (!isset($group[$like['id']])) {
                    $group[$like['id']] = $addrow($like['name']);
                }
                $group[$like['id']]['likes'] += $weights['like'];
            }
        }

        if (isset($post['comments'])) {
            foreach ($post['comments'] as $comment) {
                if (!isset($group[$comment['from']['id']])) {
                    $group[$comment['from']['id']] = $addrow($comment['from']['name']);
                }
                $group[$comment['from']['id']]['comments'] += $weights['comment'];

                if (isset($comment['likes'])) {
                    foreach ($comment['likes'] as $commentslikes) {
                        if (!isset($group[$commentslikes['id']])) {
                        $group[$commentslikes['id']] = $addrow($commentslikes['name']);
                        }
                        $group[$commentslikes['id']]['commentslikes'] += $weights['commentslikes'];
                    }
                }
            }
        }

        $postDate = $post['created_time'];

        // $group[$post['from']['id']]['shares'] += 1;
    }
    $i++;
    } while ($i < 1 && ($postDate > $xAgo) && $feedEdge = $fb->next($feedEdge));

    return $group;

}

/**
 * Sort array by the most active users
 * @param  array $group
 * @return array
 */
function sortResultBySum($group) {

    usort($group, function ($a,$b) {

        $sumA = $a['likes'] + $a['comments'] + $a['posts'] + $a['commentslikes'];
        $sumB = $b['likes'] + $b['comments'] + $b['posts'] + $b['commentslikes'];

        return $sumB - $sumA;

    });

    return $group;
}

/**
 * Make HTTP GET request to the endpoint
 * Get data out of Facebook's platform
 * Requests are passed to the API at graph.facebook.com/graph-video.facebook.com
 *
 * Test here https://developers.facebook.com/tools/explorer
 *
 * @link https://github.com/facebook/php-graph-sdk
 * @link https://github.com/facebook/php-graph-sdk/blob/5.5/docs/reference.md
 * @link https://developers.facebook.com/docs/php/FacebookResponse/5.0.0
 *
 * @return Facebook\FacebookResponse
 */
$app->get('/', function () use ($app, $fb) {

    $url = '/'.getenv('FB_PAGE_ID').'/?fields=id,name,username,picture,cover,link,website,about,fan_count';

    try {
        /**
         * @var Facebook\FacebookResponse
         */
        $page = $fb->get($url);
        $page = $page->getGraphPage()->asArray();

    } catch(\Facebook\Exceptions\FacebookResponseException $e) {
        // When Graph returns an error
        echo __FILE__.':'.__LINE__.' '.'Graph returned an error: ' . $e->getMessage();
        exit;
    } catch(\Facebook\Exceptions\FacebookSDKException $e) {
        // When validation fails or other local issues
        echo __FILE__.':'.__LINE__.' '.'Facebook SDK returned an error: ' . $e->getMessage();
        exit;
    }

    $url = '/'.getenv('FB_PAGE_ID').'/feed?fields=id,from{name},created_time,comments{from,likes},likes&limit=100';

    try {
        /**
         * @var Facebook\FacebookResponse
         */
        $response = $fb->get($url);

    } catch(\Facebook\Exceptions\FacebookResponseException $e) {
        // When Graph returns an error
        echo __FILE__.':'.__LINE__.' '.'Graph returned an error: ' . $e->getMessage();
        exit;
    } catch(\Facebook\Exceptions\FacebookSDKException $e) {
        // When validation fails or other local issues
        echo __FILE__.':'.__LINE__.' '.'Facebook SDK returned an error: ' . $e->getMessage();
        exit;
    }

    $group = processFbPageActivity($fb, $weights = null);

    $group = sortResultBySum($group);

    // select top fans
    $limit = 20;
    $group = array_slice($group, 0, $limit);

    return $app['twig']->render('index.twig', [
        'page' => $page,
        'labels' => array_column($group, 'user'),
        'posts' => array_column($group, 'posts'),
        'likes' => array_column($group, 'likes'),
        'comments' => array_column($group, 'comments'),
        'commentslikes' => array_column($group, 'commentslikes'),
    ]);

});

$app->run();
