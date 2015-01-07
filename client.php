<?php

use Symfony\Component\CssSelector\CssSelector;

define('FEED_URL', '/api/v1/feedjob');
define('POSTJOB_URL', '/api/v1/postjob');
define('POST_URL', '/api/v1/post');
define('POSTVERSION_URL', '/api/v1/post-version');
define('AUTH_URL','/api/v1/auth');

if (!file_exists('vendor/autoload.php')) {
	print "composer vendor directory/autoloader not found.".PHP_EOL;
	print "This might help:".PHP_EOL;
	print "curl -sS https://getcomposer.org/installer | php" .PHP_EOL;
	print "php composer.phar install".PHP_EOL;
	exit(1);
}

if (!file_exists('ca.pem')) {
	print "Missing CA Certificate. please download it. https://hub.vaalikone.eu/ca.pem and store it to this directory." . PHP_EOL;
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
	if (!isset($config['token'])) {
		if (!login()) {
			print "Authentication failed!" . PHP_EOL;
			exit(2);
		}
	}
	try {
		feedJob($config['hub'] . FEED_URL);
		postJob($config['hub'] . POSTJOB_URL);
	} catch (\AuthenticationException $e) {
		print "Authentication required." . PHP_EOL;
		if (login()) {
			print "Authentication success" . PHP_EOL;
		} else {
			print "Authentication failed, please check username/password from the config.php" . PHP_EOL;
		}
	}
	sleep($config['interval']);
}


function feedJob($url) {
	print "Fetching ". $url . PHP_EOL;

        $data = getJob($url);
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

function postJob($url) {
        print "Fetching ". $url . PHP_EOL;

        $data = getJob($url);
        if (!$data) {
                return null;
        }

        $post = json_decode($data,true);
        if (empty($post) 
		|| !isset($post['url'])
		|| empty($post['url'])
		|| !isset($post['xpath'])
		|| empty($post['xpath'])) {
                print "Invalid job. ignoring". PHP_EOL;
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
	$title = null;

	foreach($elements as $n) {
		$tempDom->appendChild($tempDom->importNode($n,true));
	}
	$content = $tempDom->saveHTML();

	$result = $xpath->query('//title');
	if ($result || count($result) > 0) {
		$title = $result->item(0)->textContent;
	}

	return [
		'content' => html_entity_decode(strip_tags($content)),
		'title' => $title,
	];
}

function login() {
        global $config; // i kill myself
        $ch = curl_init($config['hub'] . AUTH_URL);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['username' => $config['username'], 'password' => $config['password']]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_CAINFO, 'ca.pem');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 5);        
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);      
        $result = curl_exec($ch);
    	$info = curl_getinfo($ch);
        curl_close($ch);
	if ($info['http_code'] == 200) {
		$json = json_decode($result, true);
		$config['token'] = $json['token'];
		return true;
	}
	return false;
}

function postUrl($siteInfo) {
	global $config; // i kill myself
	$json = json_encode($siteInfo);
	$ch = curl_init($config['hub'] . POST_URL);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_CAINFO, 'ca.pem');
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $config['token'],
		'Content-Type: application/json',
		'Content-Length: ' . strlen($json)]);
	$result = curl_exec($ch);
	$info = curl_getinfo($ch);
	if ($info['http_code'] == 401) {
		throw new AuthenticationException('Invalid credentials');
	}	
	curl_close($ch);
}                       

function submitPostVersion($postVersion) {
        global $config; // i kill myself

        $json = json_encode($postVersion);
        print $config['hub'] . POSTVERSION_URL . " Sending: " . $postVersion['title'] . '... ';

       	$ch = curl_init($config['hub'] . POSTVERSION_URL);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_CAINFO, 'ca.pem');
	    curl_setopt($ch, CURLOPT_USERAGENT, $config['userAgent']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 5);        
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);      
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Authorization: Bearer ' . $config['token'],
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json)]);
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        if ($info['http_code'] == 401) {
                throw new AuthenticationException('Invalid credentials');
        }
	print $info['http_code'] . " answer." . PHP_EOL;
        curl_close($ch);
}

function getJob($url) {
        global $config; // i kill myself
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 5);        
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);      
        curl_setopt($ch, CURLOPT_CAINFO, 'ca.pem');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $config['token']]);
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        if ($info['http_code'] == 401) {
                throw new AuthenticationException('Invalid credentials');
        }
        curl_close($ch);
	return $result;
}


function getUrl($url) {
        global $config; // i kill myself
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
	    curl_setopt($ch, CURLOPT_CAINFO, 'ca.pem');
        curl_setopt($ch, CURLOPT_USERAGENT, $config['userAgent']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 10); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);  

        $result = curl_exec($ch);
        curl_close($ch);
	return $result;	
}


class AuthenticationException extends \Exception { }
