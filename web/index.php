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

$fbconfig = [
    'app_id' => getenv('FB_APP_ID'),
    'app_secret' => getenv('FB_APP_SECRET'),
    'default_graph_version' => 'v2.9',
    'default_access_token' => getenv('FB_DEFAULT_ACCESS_TOKEN')
];

/**
 * Create a stats array with users types of activity
 * @param  Facebook\FacebookResponse $response
 * @param  integer $pageId numer ID
 * @param  DateTime $xAgo what period of time
 * @param  array $weights weights of single stats
 * @return array group
 */
function processFbPageActivity($fbconfig, $pageId, \DateTime $xAgo, $weights = null) {

    $url = '/'.getenv('FB_PAGE_ID').'/feed?fields=id,from{name},created_time,comments{from,likes},likes&limit=100';

    $fb = new \Facebook\Facebook($fbconfig);

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

            // get likes from this post
            if (isset($post['likes'])) {
                foreach ($post['likes'] as $like) {
                    if (!isset($group[$like['id']])) {
                        $group[$like['id']] = $addrow($like['name']);
                    }
                    $group[$like['id']]['likes'] += $weights['like'];
                }
            }

            // get comments from this post
            if (isset($post['comments'])) {
                foreach ($post['comments'] as $comment) {
                    if (!isset($group[$comment['from']['id']])) {
                        $group[$comment['from']['id']] = $addrow($comment['from']['name']);
                    }
                    $group[$comment['from']['id']]['comments'] += $weights['comment'];

                    // get likes from this post's comment
                    if (isset($comment['likes'])) {
                        foreach ($comment['likes'] as $commentslikes) {
                            if (!isset($group[$commentslikes['id']])) {
                            $group[$commentslikes['id']] = $addrow($commentslikes['name']);
                            }
                            $group[$commentslikes['id']]['commentslikes'] += $weights['commentslike'];
                        }
                    }
                }
            }

            $postDate = $post['created_time'];

            // get shares of this post
            if (isset($post['shares'])) {

                $urlShares = '/'.$pageId.'_'.$post['id'].'/sharedposts?fields=from,created_time';

                try {
                    $fbShares = new \Facebook\Facebook($fbconfig);

                    /**
                     * @var Facebook\FacebookResponse
                     */
                    $sharesResponse = $fbShares->get($urlShares);

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
                $postSharesEdge = $sharesResponse->getGraphEdge();

                $i = 0;

                do {

                    // foreach ($responseArray['data'] as $post) {
                    foreach ($postSharesEdge as $shareStatus) {
                        $share = $shareStatus->asArray();

                        if (!isset($group[$share['from']['id']])) {
                            $group[$share['from']['id']] = $addrow($share['from']['name']);
                        }

                        $group[$share['from']['id']]['shares'] += $weights['share'];
                    }

                    $i += 25; // share pagination

                } while ($i < $post['shares']['count'] && $postSharesEdge = $fbShares->next($postSharesEdge));
            }
        }

        $i++;

    } while ($i < 10 && ($postDate > $xAgo) && $feedEdge = $fb->next($feedEdge));

    return $group;

}

/**
 * Sort array by the most active users
 * @param  array $group
 * @return array
 */
function sortResultBySum($group) {

    usort($group, function ($a,$b) {

        $sumA = $a['likes'] + $a['comments'] + $a['posts'] + $a['commentslikes'] + $a['shares'];
        $sumB = $b['likes'] + $b['comments'] + $b['posts'] + $b['commentslikes'] + $b['shares'];

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
$app->match('/', function (Request $request) use ($app, $fbconfig) {

    $xAgo = $request->get('xAgo');

    $xAgo = \DateTime::createFromFormat('Y-m-d', $xAgo);

    if (!$xAgo) {
        // default value
        $xAgo = new DateTime('now');
        $xAgo->modify('-1 month');
    }
    $xAgo->setTime(00, 00);

    $weights['like'] = $request->get('likeWeight') && (is_numeric($request->get('likeWeight'))) ? (int) $request->get('likeWeight') : 1;
    $weights['commentslike'] = $request->get('commentslikeWeight') && (is_numeric($request->get('commentslikeWeight'))) ? (int) $request->get('commentslikeWeight') : 1;
    $weights['comment'] = $request->get('commentWeight') && (is_numeric($request->get('commentWeight'))) ? (int) $request->get('commentWeight') : 2;
    $weights['post'] = $request->get('postWeight') && (is_numeric($request->get('postWeight'))) ? (int) $request->get('postWeight') : 3;
    $weights['share'] = $request->get('shareWeight') && (is_numeric($request->get('shareWeight'))) ? (int) $request->get('shareWeight') : 3;

    $url = '/'.getenv('FB_PAGE_ID').'/?fields=id,name,username,picture,cover,link,website,about,fan_count';

    $fb = new \Facebook\Facebook($fbconfig);

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

    $url = '/'.getenv('FB_PAGE_ID').'/feed?fields=id,from{name},created_time,comments{from,likes},likes,shares&limit=100';

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

    $group = processFbPageActivity($fbconfig, $page['id'], $xAgo, $weights);

    $stats = sortResultBySum($group);

    // select top fans
    $limit = 20;
    $stats = array_slice($stats, 0, $limit);

    $fp = fopen('../data/page_info.json', 'w');
    fwrite($fp, json_encode($page));
    fclose($fp);

    $fp = fopen('../data/grouped_page_results.json', 'w');
    fwrite($fp, json_encode($stats));
    fclose($fp);

    return $app['twig']->render('index.twig', [
        'page' => $page,
        'labels' => array_column($stats, 'user'),
        'posts' => array_column($stats, 'posts'),
        'likes' => array_column($stats, 'likes'),
        'comments' => array_column($stats, 'comments'),
        'commentslikes' => array_column($stats, 'commentslikes'),
        'shares' => array_column($stats, 'shares'),
        'xAgo' => $xAgo,
        'weights' => $weights,
    ]);

});

$app->match('/dev', function (Request $request) use ($app) {

    $xAgo = $request->get('xAgo');

    $xAgo = \DateTime::createFromFormat('Y-m-d', $xAgo);

    if (!$xAgo) {
        // default value
        $xAgo = new DateTime('now');
        $xAgo->modify('-1 month');
    }
    $xAgo->setTime(00, 00);

    $weights['like'] = 1;
    $weights['commentslike'] = 1;
    $weights['comment'] = 2;
    $weights['post'] = 3;
    $weights['share'] = 3;

    $string = file_get_contents('../data/page_info.json');
    $page = json_decode($string, true);

    $string = file_get_contents('../data/grouped_page_results.json');
    $stats = json_decode($string, true);

    return $app['twig']->render('index.twig', [
        'page' => $page,
        'labels' => array_column($stats, 'user'),
        'posts' => array_column($stats, 'posts'),
        'likes' => array_column($stats, 'likes'),
        'comments' => array_column($stats, 'comments'),
        'commentslikes' => array_column($stats, 'commentslikes'),
        'shares' => array_column($stats, 'shares'),
        'xAgo' => $xAgo,
        'weights' => $weights,
    ]);

});

$app->run();
