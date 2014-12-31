<?php

use Symfony\Component\CssSelector\CssSelector;

define('FEED_URL', '/api/v1/feedjob');
define('POSTJOB_URL', '/api/v1/postjob');
define('POST_URL', '/api/v1/post');
define('POSTVERSION_URL', '/api/v1/post-version');

if (!file_exists('vendor/autoload.php')) {
	print "composer vendor directory/autoloader not found.".PHP_EOL;
	print "This might help:".PHP_EOL;
	print "curl -sS https://getcomposer.org/installer | php" .PHP_EOL;
	print "php composer.phar install".PHP_EOL;
	exit(1);
}

require_once('config.php');
require_once('vendor/autoload.php');

if (file_exists('agents.php')) {
	require_once('agents.php');
	$config['userAgent'] = getUserAgent();
}

print "Setup user agent " . $config['userAgent'] . PHP_EOL;

while (true) {
	feedJob($config['hub'] . FEED_URL);
	postJob($config['hub'] . POSTJOB_URL);
	sleep($config['interval']);
}


function feedJob($url) {
	print "Fetching ". $url . PHP_EOL;

        $data = file_get_contents($url);
        if (!$data) {
		return null;
	}

        $site = json_decode($data,true);
	if (empty($site)) {
		return null;
	}

	print "Got job to do ". $site['rssUrl'] . PHP_EOL;	
	$rss = getUrl($site['rssUrl']);
	isset($site['rssChecksum']) ? $checksum = $site['rssChecksum'] : $checksum = null;

	if (sha1($rss) == $checksum) {
		return null;        
	}
	print "New content detected parsing RSS Feed" . PHP_EOL;
	
	$feed = new SimplePie();
	$feed->set_raw_data($rss);
	$feed->init();
	
	print "Submiting rss items to the hub" . PHP_EOL;

	foreach ($feed->get_items() as $item) {

		postUrl([
			'url' => $item->get_permalink(),
			'title' => $item->get_title(),
			'site' => $site['_id'],
		]);
	}
	print "Done." - PHP_EOL;
}

function PostJob($url) {
        print "Fetching ". $url . PHP_EOL;

        $data = file_get_contents($url);
        if (!$data) {
                return null;
        }

        $post = json_decode($data,true);
        if (empty($post) 
		|| !isset($post['url'])
		|| empty($post['url'])
		|| !isset($post['xpath'])
		|| empty($post['xpath'])) {
                return null;
        }

        print "Got job to do ". $post['url'] . PHP_EOL;

        $fullContent = getUrl($post['url']);
	// $post['xpath'] = '//div[@class="entry-content clearfix"]';
	$data = parseContent($fullContent, $post['xpath']);	
	$content = $data['content'];
	$title = $data['title'];
	$checksum = sha1($content);

        if ($checksum == $post['checksum']) {
                return null;
        }
        print "New content detected parsing RSS Feed" . PHP_EOL;

	submitPostVersion([
		'site' => $post['site'],
		'post' => $post['post'],
		'title' => $title,
		'content' => $content,
		'rawContent' => $fullContent,
		'checksum' => $checksum,
	]);

        print "Done." - PHP_EOL;
}

function parseContent($fullContent, $xpathString) {
	$doc = new DOMDocument();
	@$doc->loadHTML($fullContent);
	$xpath = new DOMXpath($doc);
	$elements = $xpath->query($xpathString);
	$tempDom = new DOMDocument(); 

	foreach($elements as $n) {
		$tempDom->appendChild($tempDom->importNode($n,true));
	}
	$content = $tempDom->saveHTML();
	return [
		'content' => html_entity_decode(strip_tags($content)),
		'title' => $title,
	];
}

function postUrl($siteInfo) {
	global $config; // i kill myself
	$json = json_encode($siteInfo);
	$ch = curl_init($config['hub'] . POST_URL);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_USERAGENT, $config['userAgent']);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Content-Type: application/json',
		'Content-Length: ' . strlen($json)]);
	$result = curl_exec($ch);
	curl_close($ch);
}                       

function submitPostVersion($postVersion) {
        global $config; // i kill myself
        $json = json_encode($postVersion);
        $ch = curl_init($config['hub'] . POSTVERSION_URL);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_USERAGENT, $config['userAgent']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json)]);
        $result = curl_exec($ch);
        curl_close($ch);
}


function getUrl($url) {
	return file_get_contents($url);
}

