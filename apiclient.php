<?php

/**
 * Team++ media API client
 * The client for the media API <http://media.plusp.lu>
 *
 * @file apiclient.php
 *
 * @version 1.0.2
 * @author Lukas Bestle <http://lu-x.me>
 * @link https://github.com/TeamPlusPlus/apiclient
 * @copyright Copyright 2013 Lukas Bestle
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

// Set locale (German date formats!)
setlocale(LC_ALL, 'de_DE');

// State definitions
define('STATE_NO',       0);
define('STATE_OVER',     1);
define('STATE_SOON',     2);
define('STATE_LIVE',     3);
define('STATE_RECORDED', 4);

/**
 * Episodes
 * 
 * Episode API client
 */
class Episodes {
	// Cache
	static $newest   = null;
	static $next     = null;
	static $episodes = array();
	
	/**
	 * Load the data from cache
	 */
	public static function loadCache() {
		// Get the data from the file
		$file = @file_get_contents(KIRBY_PROJECT_ROOT_CACHE . '/episodes.ser');
		
		// Did it work (= does the file exist and is it valid)?
		if(!($file && $data = @unserialize($file))) {
			// No, write it first
			static::writeCache();
			return;
		}
		
		static::$newest   = $data[0];
		static::$next     = $data[1];
		static::$episodes = $data[2];
	}
	
	/**
	 * Write API data into cache
	 */
	public static function writeCache() {
		// Get data from API
		$data = json_decode(file_get_contents('http://media.plusp.lu/' . site()->subdomain() . '/all'), true);
		
		// Put the episode data into cache
		static::$episodes  = $data['episodes'];
		
		// Parse newest and next episodes
		static::newest();
		static::next();
		
		// Shall it write the file?
		if(!c::get('cache.episodes', false)) return;
		
		// Write the cache file
		file_put_contents(KIRBY_PROJECT_ROOT_CACHE . '/episodes.ser', serialize(array(
			static::$newest,
			static::$next,
			static::$episodes
		)));
	}
	
	/**
	 * Next episode
	 * 
	 * @return Kirby\CMS\Page The page object with custom data
	 */
	public static function next() {
		// Already in cache?
		if(static::$next) return static::nextData(static::$next);
		
		// Get the next episode
		$result = static::newest()->next();
		if(!$result) return static::nextData(null);
		
		return static::nextData(static::$next = static::title($result, 3));
	}
	
	/**
	 * Current episode
	 * 
	 * @return Kirby\CMS\Page The page object with custom data
	 */
	public static function newest() {
		// Already in cache?
		if(static::$newest) return site()->pages()->find('episodes/' . static::$newest);
		
		// Iterate through all episodes beginning with the latest
		$pages = site()->pages()->find('episodes')->children()->flip();
		foreach($pages as $page) {
			// Analyze the page
			$data = static::infos($page, true);
			
			// Check if it has all valid information to be published
			if(!is_array($data) || !isset($data['files']['media']['mp3']) || !isset($data['files']['cover']['png']) || !$page->text() || !$page->title() || !$page->shownotes()) continue;
			
			// It is published -> Return the page
			return site()->pages()->find('episodes/' . static::$newest = static::title($page, 3));
		}
	}
	
	/**
	 * Get the title of an episode
	 * 
	 * @param Kirby\CMS\Page $episode The episode to get the title from
	 * @param int            $type    What type of title? 
	 *
	 * @return mixed                  The title formatted like $type
	 */
	public static function title($episode, $type=0) {
		// Titles for invalid pages
		if(!is_object($episode) || $episode == new StdClass()) return '';
		
		// Analyze URI
		$episodeComponents = explode('/', $episode->uri());
		
		// Is it an episode?
		if(!isset($episodeComponents[1])) {
			return (string)$episode->title();
		}
		
		// The episode name is the second element of the URI (`episodes/$episode`)
		$episodeString = $episodeComponents[1];
		$episodeID     = (int)$episodeString;
		
		// Return the appropriate type
		switch($type) {
			case 0:
				return "#$episodeID ({$episode->title()})";
			case 1:
				return "#$episodeID <br>({$episode->title()})";
			case 2:
				return $episodeID;
			case 3:
				return $episodeString;
			case 4:
				return site()->title() . " #$episodeID ({$episode->title()})";
		}
	}
	
	/**
	 * Parse information for an episode and add it to the Kirby\CMS\Page object
	 * 
	 * @param Kirby\CMS\Page $episode The episode to parse
	 * @param boolean        $raw     Raw data or Kirby\CMS\Page object?
	 *
	 * @return mixed                  The page object with custom data or just an array with information
	 */
	public static function infos($episode, $raw=false) {
		// Get the episode ID
		$episodeID = static::title($episode, 2);
		if(!isset(static::$episodes[$episodeID])) return ($raw)? array() : static::objectify();
		
		// Get the data from the API cache array
		$episodeData = static::$episodes[$episodeID];
		
		// Either parse it and return the object or just return the array
		return ($raw)? $episodeData : static::objectify($episodeData);
	}
	
	/**
	 * Parse shownotes and add timestamps
	 * 
	 * @param string         $shownotes The parsed HTML for the shownotes
	 * @param Kirby\CMS\Page $episode   The episode to get the timestamps from
	 *
	 * @return string                   The parsed shownotes HTML including timestamps
	 */
	public static function shownotes($shownotes, $episode) {
		// Get the chapters
		$infos = static::infos($episode, true);
		$chapters = $infos['chapters'];
		
		// Sort them by name
		$chaptersAnalyzed = array();
		foreach($chapters as $chapter) {
			// Re-parse the time
			$timeParts = explode('.', $chapter['start']);
			
			// Add to re-indexed array
			$chaptersAnalyzed[$chapter['title']] = $timeParts[0];
		}
		
		// Get all headings (separating the chapters)
		$shownotes = preg_replace_callback('{(?<=<h3>).*?(?=</h3>)}', function($matches) use($chaptersAnalyzed) {
			if(!isset($chaptersAnalyzed[$matches[0]])) {
				// The chapter time is not defined
				return $matches[0];
			}
			
			// Add the timestamp
			return '[' . $chaptersAnalyzed[$matches[0]] . '] ' . $matches[0];
		}, $shownotes);
		
		return $shownotes;
	}
	
	/**
	 * Get the current state of the next episode
	 * 
	 * @param string          $id The episode ID
	 *
	 * @return Kirby\CMS\Page     The page object with custom data
	 */
	private static function nextData($id) {
		$state = STATE_NO;
		$live  = "";
		$page  = new StdClass();
		
		// Is it a valid episode ID?
		if(is_string($id)) {
			// Get the time string
			$page = site()->pages()->find("episodes/$id");
			
			// Parse as time stamp
			$timestamp = @strtotime($page->live());
			
			if($timestamp == false) {
				// No valid time stamp
			} else if($timestamp + 5400 <= time()) {
				// 1.5h after the live date -> Episode is already over
				$state = STATE_RECORDED;
			} else if($timestamp <= time()) {
				// Currently on air
				$state = STATE_LIVE;
			} else {
				// On air soon
				$state = STATE_SOON;
				
				// Dates
				$ymdLive = date('Ymd', $timestamp);
				$ymdToday = date('Ymd');
				
				if($ymdLive > $ymdToday + 6) {
					// More than one week in the future
					if($timestamp % 3600) {
						// It has minutes
						$timestring = '%d. %B %G ~%H:%M Uhr';
					} else {
						// Only hours
						$timestring = '%d. %B %G ~%H Uhr';
					}
				} else if($ymdToday == $ymdLive) {
					// Today
					if($timestamp % 3600) {
						// It has minutes
						$timestring = 'Heute ~%H:%M Uhr';
					} else {
						// Only hours
						$timestring = 'Heute ~%H Uhr';
					}
				} else {
					// This week
					if($timestamp % 3600) {
						// It has minutes
						$timestring = '%A ~%H:%M Uhr';
					} else {
						// Only hours
						$timestring = '%A ~%H Uhr';
					}
				}
				
				// Build the correct date format
				$live = strftime($timestring, $timestamp);
			}
		}
		
		// Add the information to the page
		$page->infos         = new StdClass();
		$page->infos->state  = $state;
		$page->infos->live   = $live;
		$page->infos->id     = ($id)? (int)$id : static::$newest + 1;
		$page->infos->number = '#' . $page->infos->id;
		
		return $page;
	}
	
	/**
	 * Build an object from data
	 * 
	 * @param array     $data Data as array
	 *
	 * @return StdClass       The objectified data
	 */
	private static function objectify($data=array()) {
		$obj = new StdClass();
		
		// Files
		$obj->image = isset($data['files']['cover']['png'])?  $data['files']['cover']['png']  : null;
		$obj->psc   = isset($data['files']['meta']['psc'])?   $data['files']['meta']['psc']   : null;
		$obj->m4a   = isset($data['files']['media']['m4a'])?  $data['files']['media']['m4a']  : null;
		$obj->mp3   = isset($data['files']['media']['mp3'])?  $data['files']['media']['mp3']  : null;
		$obj->ogg   = isset($data['files']['media']['ogg'])?  $data['files']['media']['ogg']  : null;
		$obj->opus  = isset($data['files']['media']['opus'])? $data['files']['media']['opus'] : null;
		
		// Other information
		$obj->infos    = isset($data['infos'])?    $data['infos']    : array();
		$obj->duration = isset($data['duration'])? $data['duration'] : 0;
		$obj->chapters = isset($data['chapters'])? $data['chapters'] : array();
		
		return $obj;
	}
}

// Load the data when the class loads
Episodes::loadCache();