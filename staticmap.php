<?php

/**
 * staticMap 0.0.1
 *
 * Copyright 2015 Denis Pitzalis
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Based on original code of: @author Gerhard Koch <gerhard.koch AT ymail.com>
 * @author Denis Pitzalis <denis.pitzalis AT gmail.com>
 *
 * USAGE:
 *
 *  staticmap.php?center=44.3097,8.481017&zoom=5&size=512x256&maptype=unescog&markers=44.307975,8.481017,0075d1|40.718217,-73.998284,red
 *  center=x.x,y.y
 *  zoom= [1..9] (UNESCO's map)
 *  size= XxY
 *  maptype= mapnik | unesco
 *  markers= [x.x,y.y,color,icon|x.x,y.y,color,icon|...] 
 *  where 
 *      color: green, red, blue, white, 000, FFF, 0075D1
 *      icon: the unicode value of fontawesome
 *
 */

error_reporting(5);
ini_set('display_errors', 'on');
define('DEBUG',5);

Class staticUnescoMap {

  const DEBUGLOG = '/tmp/map_log';

  protected $maxWidth = 512;
  protected $maxHeight = 512;
  protected $maxZoom = 9;
  protected $minZoom = 0;
  protected $quality = 7;

  protected $proxy = "";

  protected $tileSize = 256;
  protected $tileSrcUrl = array(
    'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',
    'unesco' => 'http://{LANG}.unesco.org/tiles/{Z}/{X}/{Y}.png',
    'unescog' => 'http://{LANG}.unesco.org/tiles/geodata/{Z}/{X}/{Y}.png',
  );

  protected $tileDefaultSrc = 'unesco';

  protected $Logo = 'images/logo.png';

  protected $useTileCache = 1;
  protected $tileCacheBaseDir = './cache/tiles';

  #TODO
  protected $useMarkerCache = 0;
  protected $markerBaseDir = './images/markers';
  protected $markerFilename = 'marker.png';
  protected $markerCacheBaseDir = './cache/markers';

  #TODO
  protected $useMapCache = 0;
  protected $mapCacheBaseDir = './cache/maps';
  protected $mapCacheID = '';
  protected $mapCacheFile = '';
  protected $mapCacheExtension = 'png';

  protected $fontsBaseDir = './fonts';
  protected $font = '/Font-Awesome/fonts/fontawesome-webfont.ttf';

  protected $zoom, $lat, $lon, $width, $height, $markers, $image, $maptype;
  protected $centerX, $centerY, $offsetX, $offsetY;

  public function __construct(){
    $this->zoom = 1;
    $this->lat = 0;
    $this->lon = 0;
    $this->lang = 'en';
    $this->width = 500;
    $this->height = 350;
    $this->markers = array();
    $this->maptype = $this->tileDefaultSrc;
  }

  /*
   * _parseParams Parse params from URL
   */
  private function _parseParams(){
    global $_GET;
    (DEBUG > 2) ? error_log("_parseParams \n", 3, self::DEBUGLOG) : print "";

    // get zoom from GET paramter
    $this->zoom = $_GET['zoom'] ? intval($_GET['zoom']) : 1;
    if ($this->zoom > $this->maxZoom) {
      $this->zoom = $this->maxZoom;
    } elseif ($this->zoom < $this->minZoom) {
      $this->zoom = $this->minZoom;
    }

    // get lat and lon from GET paramter
    list($this->lat, $this->lon) = explode(',', $_GET['center']);
    $this->lat = floatval($this->lat);
    $this->lon = floatval($this->lon);

    // get size from GET paramter
    if ($_GET['size']) {
      list($this->width, $this->height) = explode('x', $_GET['size']);
      $this->width = intval($this->width);
      if ($this->width > $this->maxWidth) $this->width = $this->maxWidth;
      $this->height = intval($this->height);
      if ($this->height > $this->maxHeight) $this->height = $this->maxHeight;
    }
    if (!empty($_GET['markers'])) {
      $markers = explode('|', $_GET['markers']);
      foreach ($markers as $marker) {
        list($markerLat, $markerLon, $markerColor, $markerIcon) = explode(',', $marker);
        $markerLat = floatval($markerLat);
        $markerLon = floatval($markerLon);
        $markerColor = basename($markerColor);
        $markerIcon = basename($markerIcon);
        $this->markers[] = array('lat' => $markerLat, 'lon' => $markerLon, 'color' => $markerColor, 'icon' => $markerIcon);
      }
    }
    if ($_GET['maptype']) {
      if (array_key_exists($_GET['maptype'], $this->tileSrcUrl)) $this->maptype = $_GET['maptype'];
    }

    (DEBUG > 0) ? error_log(" Zoom: " . $this->zoom . ",\n Lat,Lon " . $this->lat . "," . $this->lon . ",\n Size: " . $this->width . "x" . $this->height . ",\n Markers: " . $this->markers . ",\n Maptype: " . $this->maptype . "\n", 3, self::DEBUGLOG) : print "";
    (DEBUG > 0) ? error_log(" Map Cache: " . $this->useMapCache . ",\n Tile Cache " . $this->useTileCache . ",\n Marker Cache: " . $this->useMarkerCache . "\n", 3, self::DEBUGLOG) : print "";
  }

  private function _lonToTile($long, $zoom){
    return (($long + 180) / 360) * pow(2, $zoom);
  }

  private function _latToTile($lat, $zoom){
    return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
  }

  /*
   * initCoords translate lat long into Tiles
   */
  private function _initCoords(){
    (DEBUG > 2) ? error_log("   _initCoords \n", 3, self::DEBUGLOG) : print "";
    $this->centerX = $this->_lonToTile($this->lon, $this->zoom);
    $this->centerY = $this->_latToTile($this->lat, $this->zoom);
    $this->offsetX = floor((floor($this->centerX) - $this->centerX) * $this->tileSize);
    $this->offsetY = floor((floor($this->centerY) - $this->centerY) * $this->tileSize);
  }

  /*
   * generate the background image
   */
  private function _createBaseMap(){
    (DEBUG > 2) ? error_log("   Creating Base Map \n", 3, self::DEBUGLOG) : print "";
    $this->image = imagecreatetruecolor($this->width, $this->height);
    $startX = floor($this->centerX - ($this->width / $this->tileSize) / 2);
    $startY = floor($this->centerY - ($this->height / $this->tileSize) / 2);
    $endX = ceil($this->centerX + ($this->width / $this->tileSize) / 2);
    $endY = ceil($this->centerY + ($this->height / $this->tileSize) / 2);
    $this->offsetX = -floor(($this->centerX - floor($this->centerX)) * $this->tileSize);
    $this->offsetY = -floor(($this->centerY - floor($this->centerY)) * $this->tileSize);
    $this->offsetX += floor($this->width / 2);
    $this->offsetY += floor($this->height / 2);
    $this->offsetX += floor($startX - floor($this->centerX)) * $this->tileSize;
    $this->offsetY += floor($startY - floor($this->centerY)) * $this->tileSize;

    for ($x = $startX; $x <= $endX; $x++) {
      for ($y = $startY; $y <= $endY; $y++) {
        $url = str_replace(array('{LANG}', '{Z}', '{X}', '{Y}'), array($this->lang, $this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
        (DEBUG > 4) ? error_log("    Source tile: " . $url . "\n", 3, self::DEBUGLOG) : print "";
        $tileData = $this->_fetchTile($url);
        if ($tileData) {
          $tileImage = imagecreatefromstring($tileData);
        } else {
          // display an error message if image is missing
          $tileImage = imagecreate($this->tileSize, $this->tileSize);
          $color = imagecolorallocate($tileImage, 255, 255, 255);
          @imagestring($tileImage, 1, 127, 127, 'err', $color);
        }
        $destX = ($x - $startX) * $this->tileSize + $this->offsetX;
        $destY = ($y - $startY) * $this->tileSize + $this->offsetY;
        imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
      }
    }
    (DEBUG > 2) ? error_log("   Base Map Created \n", 3, self::DEBUGLOG) : print "";
  }

  /*
   * _makeMarker generate image to use for markers
   */
  private function _makeMarker($markerColor,$markerIcon){
    (DEBUG > 3) ? error_log("    _makeMarker \n", 3, self::DEBUGLOG) : print "";
    //read our base icon
    $markerImage = imagecreatefrompng($this->markerBaseDir . '/' . $this->markerFilename);
    imagesavealpha($markerImage, true);

    // if a color is passed we change the original color into the new one
    if ($markerColor){
      (DEBUG > 3) ? error_log("    _makeMarker new color: " . $markerColor . "\n", 3, self::DEBUGLOG) : print "";
      /* RGB of your inside color */
      $rgb = $this->hex2RGB($markerColor);

      /* Negative values, don't edit */
      $rgb = array(255-$rgb[0],255-$rgb[1],255-$rgb[2]);
      imagefilter($markerImage, IMG_FILTER_NEGATE);
      imagefilter($markerImage, IMG_FILTER_COLORIZE, $rgb[0], $rgb[1], $rgb[2]);
      imagefilter($markerImage, IMG_FILTER_NEGATE);
    }

    // if an awesome icon is passed we center it into our map
    if ($markerIcon){
      $white = imagecolorallocate($markerImage, 255, 255, 255);
      $text = '&#x' . $markerIcon . ';';
      (DEBUG > 3) ? error_log("    _makeMarker new icon: " . $text . "\n", 3, self::DEBUGLOG) : print "";
      list($x0, $y0, , , $x1, $y1) = imagettfbbox(10, 0, $this->fontsBaseDir . $this->font, $text);
      $imwide = imagesx($markerImage);
      $imtall = imagesy($markerImage);
      $bbwide = abs($x1 - $x0);
      $bbtall = abs($y1 - $y0) - 12;
      $tlx = ($imwide - $bbwide) >> 1; $tlx -= 1;        // top-left x of the box
      $tly = ($imtall - $bbtall) >> 1; $tly -= 1;        // top-left y of the box
      $bbx = $tlx - $x0;                                 // top-left x to bottom left x + adjust base point
      $bby = $tly + $bbtall - $y0;                       // top-left y to bottom left y + adjust base point
      $white = imagecolorallocate($markerImage, 255, 255, 255);
      imagettftext($markerImage, 10, 0, $bbx, $bby, $white, $this->fontsBaseDir . $this->font, $text);
    }
    imagealphablending( $markerImage, false );
    imagesavealpha($markerImage, true);
    return $markerImage;
  }

  /*
   * _placeMarkers position markers and define style
   */
  private function _placeMarkers(){
    (DEBUG > 2) ? error_log("   Placing Markers \n", 3, self::DEBUGLOG) : print "";
    // loop thru marker array
    foreach ($this->markers as $marker) {
      // set some local variables
      $markerLat = $marker['lat'];
      $markerLon = $marker['lon'];
      $markerColor = $marker['color'];
      $markerIcon = $marker['icon'];
      $matches = false;
      (DEBUG > 4) ? error_log("    Marker: " . $markerLat . "," . $markerLon . ", Color: " . $markerColor . ", Icon: " . $markerIcon . "\n", 3, self::DEBUGLOG) : print "";

      $markerIndex++;
      $markerImageOffsetX = -16;
      $markerImageOffsetY = -32;

      // calc position
      $destX = floor(($this->width / 2) - $this->tileSize * ($this->centerX - $this->_lonToTile($markerLon, $this->zoom)));
      $destY = floor(($this->height / 2) - $this->tileSize * ($this->centerY - $this->_latToTile($markerLat, $this->zoom)));

      // create img resource
      if ($markerColor != '' || $markerIcon != ''){
        $markerColor = $markerColor ?: 'fff';
        $markerIcon = $markerIcon ?: 'f19c';
        $markerImagename = "marker_" . $markerColor . "_" . $markerIcon . ".png";
        if ( $this->useMarkerCache ){
          if (!file_exists($this->markerCacheBaseDir . '/' . $markerImagename) ){
            $this->_writeToCache($this->markerCacheBaseDir . '/' . $markerImagename, imagepng($this->_makeMarker($markerColor,$markerIcon),null,$quality));
            (DEBUG > 3) ? error_log("    _placeMarkers: wrinting marker to cache: " . $this->markerCacheBaseDir . '/' . $markerImagename ."\n", 3, self::DEBUGLOG) : print "";
          }
          (DEBUG > 3) ? error_log("    _placeMarkers: reading marker from cache: " . $this->markerCacheBaseDir . '/' . $markerImagename . "\n", 3, self::DEBUGLOG) : print "";
          $markerImg = imagecreatefrompng($this->markerCacheBaseDir . '/' . $markerImagename);
        } else {
          (DEBUG > 3) ? error_log("    _placeMarkers: creating marker \n", 3, self::DEBUGLOG) : print "";
          $markerImg = $this->_makeMarker($markerColor,$markerIcon);
        }
      } else {
        (DEBUG > 3) ? error_log("    _placeMarkers: use standard marker \n", 3, self::DEBUGLOG) : print "";
        $markerImg = imagecreatefrompng($this->markerBaseDir . '/' . $this->markerFilename);
      }

      // copy marker on basemap above shadow
      imagecopy($this->image, $markerImg, $destX + intval($markerImageOffsetX), $destY + intval($markerImageOffsetY), 0, 0, imagesx($markerImg), imagesy($markerImg));
      imagedestroy($markerImg);
    };
    (DEBUG > 2) ? error_log("   Markers in position \n", 3, self::DEBUGLOG) : print "";
  }

  private function hex2RGB($hexStr) {
    $hexStr = preg_replace("/[^0-9A-Fa-f]/", '', $hexStr); // Gets a proper hex string
    $rgbArray = array();
    if (strlen($hexStr) == 6) { //If a proper hex code, convert using bitwise operation. No overhead... faster
      $colorVal = hexdec($hexStr);
      $rgbArray[0] = 0xFF & ($colorVal >> 0x10);
      $rgbArray[1] = 0xFF & ($colorVal >> 0x8);
      $rgbArray[2] = 0xFF & $colorVal;
    } elseif (strlen($hexStr) == 3) { //if shorthand notation, need some string manipulations
      $rgbArray[0] = hexdec(str_repeat(substr($hexStr, 0, 1), 2));
      $rgbArray[1] = hexdec(str_repeat(substr($hexStr, 1, 1), 2));
      $rgbArray[2] = hexdec(str_repeat(substr($hexStr, 2, 1), 2));
    } else {
      return "0,0,0"; //Invalid hex color code
    }
    return $rgbArray; // returns the rgb string or the associative array
  }

  /*
   * Add copyright notice
   */
  private function _copyrightNotice(){
    $logoImg = imagecreatefrompng($this->Logo);
    imagecopy($this->image, $logoImg, 0, imagesy($this->image) - imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));
    imagedestroy($logoImg);
  }

  /*
   * _tileUrlToFilename
   * $url
   * RETURN
   * name of the tile
   */
  private function _tileUrlToFilename($url){
    (DEBUG > 4) ? error_log("    _tileUrlToFilename: " . $this->tileCacheBaseDir . "/" . str_replace(array('http://'), '', $url) . "\n", 3, self::DEBUGLOG) : print "";
    return $this->tileCacheBaseDir . "/" . str_replace(array('http://'), '', $url);
  }

  /*
   * _checkTileCache
   * $url
   * RETURN
   * file image if it exists
   */
  private function _checkTileCache($url){
    $filename = $this->_tileUrlToFilename($url);
    if (file_exists($filename)) {
      (DEBUG > 3) ? error_log("    _checkTileCache: " . $filename . "\n", 3, self::DEBUGLOG) : print "";
      return file_get_contents($filename);
    } else (DEBUG > 3) ? error_log("    _checkTileCache: not in cache \n", 3, self::DEBUGLOG) : print "";
  }

  /*
   * checkMapCache return TRUE if the map file exists in cache
   */
  private function _checkMapCache(){
    $this->mapCacheID = md5($this->_serializeParams());
    $filename = $this->_mapCacheIDToFilename();
    if (file_exists($filename)) {
      (DEBUG > 3) ? error_log("   _checkMapCache: yes \n", 3, self::DEBUGLOG) : print "";
      return true;
    } else (DEBUG > 3) ? error_log("   _checkMapCache: no \n", 3, self::DEBUGLOG) : print "";
  }

  private function _serializeParams(){
    return join("&", array($this->zoom, $this->lat, $this->lon, $this->width, $this->height, serialize($this->markers), $this->maptype));
  }

  /*
   * mapCacheIDToFilename return the name of the file cache to be used
   */
  private function _mapCacheIDToFilename(){
    (DEBUG > 3) ? error_log("   _mapCacheIDToFilename \n", 3, self::DEBUGLOG) : print "";
    if (!$this->mapCacheFile) {
      $this->mapCacheFile = $this->mapCacheBaseDir . "/" . $this->maptype . "/" . $this->zoom . "/cache_" . substr($this->mapCacheID, 0, 2) . "/" . substr($this->mapCacheID, 2, 2) . "/" . substr($this->mapCacheID, 4);
    }
    (DEBUG > 4) ? error_log("    _mapCacheIDToFilename: " . $this->mapCacheFile . "." . $this->mapCacheExtension . "\n", 3, self::DEBUGLOG) : print "";
    return $this->mapCacheFile . "." . $this->mapCacheExtension;
  }

  /*
   * mkdir_recursive create cache structure
   */
  private function _mkdir_recursive($pathname, $mode){
    is_dir(dirname($pathname)) || $this->_mkdir_recursive(dirname($pathname), $mode);
    return is_dir($pathname) || @mkdir($pathname, $mode);
  }

  /*
   * _writeToCache write the png file in our structured cache for reuse
   * $url: the url of the tile
   * $data: the png itself
   */
  private function _writeToCache($filename, $data){
    $this->_mkdir_recursive(dirname($filename), 0755);
    file_put_contents($filename, $data);
  }

  /*
   * _fetchTile fetch tile from tileserver
   * tile full url
   * RETURN
   * tile image
   */
  private function _fetchTile($url){
    //check if we are using tile cache. If yes, return the tile in cache.
    if ($this->useTileCache && ($cached = $this->_checkTileCache($url))) return $cached;
    (DEBUG > 3) ? error_log("     _fetchTile: " . $url . "\n", 3, self::DEBUGLOG) : print "";
    // if the tile is not in the cache, or we are not using the cache, let's load it
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PROXY, $proxy);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0");
    $tile = curl_exec($ch);
    curl_close($ch);

    // we know we will be getting PNG files, so let's check for that instead than loading some exif stuff
    if ($tile && $this->useTileCache && (bin2hex($tile[0]) == '89' && $tile[1] == 'P' && $tile[2] == 'N' && $tile[3] == 'G')) {
      $this->_writeToCache($this->_tileUrlToFilename($url), $tile);
    }
    return $tile;
  }

  /*
   * showMap return an image map
   */
  public function showMap(){
    (DEBUG > 1) ? error_log("------------------ \nInitiating new map \n", 3, self::DEBUGLOG) : print "";
    $this->_parseParams();
    if ($this->useMapCache) {
      // use map cache, so check cache for map
      if (!$this->_checkMapCache()) {
        (DEBUG > 2) ? error_log("  map is not in cache, creating \n", 3, self::DEBUGLOG) : print "";
        // map is not in cache, needs to be built
        $this->_makeMap();
        $this->_sendHeader();
        $this->_writeToCache($this->_mapCacheIDToFilename(),imagepng($this->image, null, $quality));
        if (file_exists($this->mapCacheIDToFilename())) {
          return file_get_contents($this->_mapCacheIDToFilename());
        } else {
          return imagepng($this->image);
        }
      } else {
        (DEBUG > 2) ? error_log("  map is in cache, searching \n", 3, self::DEBUGLOG) : print "";
        // map is in cache
        $this->_sendHeader();
        return file_get_contents($this->_mapCacheIDToFilename());
      }
    } else {
      // no cache, make map, send headers and deliver png
      $this->_makeMap();
      $this->_sendHeader();
      return imagepng($this->image, null, $this->quality);
    }
  }

  private function _makeMap(){
    (DEBUG > 3) ? error_log("   _makeMap \n", 3, self::DEBUGLOG) : print "";
    $this->_initCoords();
    $this->_createBaseMap();
    if (count($this->markers)) $this->_placeMarkers();
    if ($this->Logo) $this->_copyrightNotice();
  }

  private function _sendHeader(){
    (DEBUG > 3) ? error_log("   _sendHeader \n", 3, self::DEBUGLOG) : print "";
    header('Content-Type: image/png');
    $expires = 60 * 60 * 24 * 14;
    header("Pragma: public");
    header("Cache-Control: maxage=" . $expires);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
  }
}

$map = new staticUnescoMap();
print $map->showMap();
