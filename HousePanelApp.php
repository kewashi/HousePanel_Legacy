<?php

/**
 * Description of HousePanelApp
 *
 * @author kewashi
 * 
 */
class HousePanelApp {
    
    const APPNAME = "House Panel";
    const DEBUG = false;
    const DEBUG2 = false;
    const DEBUG3 = false;
    const DEBUG4 = false;
    
    private $allThings;
    private $pageTabs;
    private $accessToken;
    private $endPoint;
    private $siteName;
    private $accessCode;
    private $returnURL;
    
    private $groovytypes;
    private $thingtypes;
    
    function __construct() {
        ini_set('max_execution_time', 300);
        ini_set('max_input_vars', 20);
        session_start();
        error_reporting(E_ERROR);

        $this->allthings = array();
        $this->groovytypes = array("routines","switches", "lights", "dimmers","bulbs","momentaries","contacts",
                            "sensors", "locks", "thermostats", "temperatures", "musics", "valves",
                            "doors", "illuminances", "smokes", "waters", "weathers", "presences", 
                            "modes", "blanks", "images", "pistons", "others");
 
        $this->thingtypes = array("switch", "light", "switchlevel", "bulb", "momentary","contact",
                        "motion", "lock", "thermostat", "temperature", "music", "valve",
                        "door", "illuminance", "smoke", "water",
                        "weather", "presence", "mode", "routine", "piston", "other",
                        "clock","blank","image","frame","video");
        
    // set timezone so dates work where I live instead of where code runs
    date_default_timezone_set(TIMEZONE);
    $skindir = "skin-housepanel";
    
    // save authorization for this app for about one month
    $expiry = time()+31*24*3600;
    
    // get name of this webpage without any get parameters
    $serverName = $_SERVER['SERVER_NAME'];
    
    if ( isset($_SERVER['SERVER_PORT']) ) {
        $serverPort = $_SERVER['SERVER_PORT'];
    } else {
        $serverPort = '80';
    }

// fix logic of self discovery
//    $uri = $_SERVER['REQUEST_URI'];
//    $ipos = strpos($uri, '?');
//    if ( $ipos > 0 ) {  
//        $uri = substr($uri, 0, $ipos);
//    }
    $uri = $_SERVER['PHP_SELF'];
    
//    if ( $_SERVER['REQUEST_SCHEME'] && $_SERVER['REQUEST_SCHEME']=="http" ) {
    if ( is_ssl() ) {
       $url = "https://" . $serverName . ':' . $serverPort;
    } else {
       $url = "http://" . $serverName . ':' . $serverPort;
    }
    $returnURL = $url . $uri;
    
    // check if this is a return from a code authorization call
    $code = filter_input(INPUT_GET, "code", FILTER_SANITIZE_SPECIAL_CHARS);
    if ( $code ) {

        // unset session to force re-read of things since they could have changed
        unset($_SESSION["allthings"]);
        
        // check for manual reset flag for debugging purposes
        if ($code=="reset") {
            getAuthCode($returnURL);
    	    exit(0);
        }
        
        // make call to get the token
        $token = getAccessToken($returnURL, $code);
        
        // get the endpoint if the token is valid
        if ($token) {
            setcookie("hmtoken", $token, $expiry, "/", $serverName);
            $endptinfo = getEndpoint($token);
            $endpt = $endptinfo[0];
            $sitename = $endptinfo[1];
        
            // save endpt in a cookie and set success flag for authorization
            if ($endpt) {
                setcookie("hmendpoint", $endpt, $expiry, "/", $serverName);
                setcookie("hmsitename", $sitename, $expiry, "/", $serverName);
            }
                    
        }
    
        if (DEBUG2) {
            echo "<br />serverName = $serverName";
            echo "<br />returnURL = $returnURL";
            echo "<br />code  = $code";
            echo "<br />token = $token";
            echo "<br />endpt = $endpt";
            echo "<br />sitename = $sitename";
            echo "<br />cookies = <br />";
            print_r($_COOKIE);
            exit;
        }
    
        // reload the page to remove GET parameters and activate cookies
        $location = $returnURL;
        header("Location: $location");
    	
    // check for call to start a new authorization process
    // added GET option to enable easier Python and EventGhost use
    } else if ( isset($_POST["doauthorize"]) || isset($_GET["doauthorize"]) ) {
    
    	getAuthCode($returnURL);
    	exit(0);
    
    }

    // initial call or a content load or ajax call
    $tc = "";
    $first = false;
    $endpt = false;
    $access_token = false;

    // check for valid available token and access point
    // added GET option to enable easier Python and EventGhost use
    // add option for browsers that don't support cookies where user provided in config file
    if (USER_ACCESS_TOKEN!==FALSE  && USER_ENDPT!==FALSE) {
        $access_token = USER_ACCESS_TOKEN;
        $endpt = USER_ENDPT;
    } else if ( isset($_COOKIE["hmtoken"]) && isset($_COOKIE["hmendpoint"]) ) {
        $access_token = $_COOKIE["hmtoken"];
        $endpt = $_COOKIE["hmendpoint"];
    } else if ( isset($_REQUEST["hmtoken"]) && isset($_REQUEST["hmendpoint"]) ) {
        $access_token = $_REQUEST["hmtoken"];
        $endpt = $_REQUEST["hmendpoint"];
    }
    if ( $access_token && $endpt ) {
        if (USER_SITENAME!==FALSE) {
            $sitename = USER_SITENAME;
        } else if ( isset($_COOKIE["hmsitename"]) ) {
            $sitename = $_COOKIE["hmsitename"];
        } else {
            $sitename = "SmartHome";
            // setcookie("hmsitename", $sitename, $expiry, "/", $serverName);
        }

        if (DEBUG) {       
            $tc.= "<div class=\"debug\">";
            $tc.= "access_token = $access_token<br />";
            $tc.= "endpt = $endpt<br />";
            $tc.= "sitename = $sitename<br />";
            if (USER_ACCESS_TOKEN!==FALSE && USER_ENDPT!==FALSE) {
                $tc.= "cookies skipped - user provided the access_token and endpt values listed above<br />";
            } else {
                $tc.= "<br />cookies = <br /><pre>";
                $tc.= print_r($_COOKIE, true);
                $tc.= "</pre>";
            }
            $tc.= "</div>";
        }
    }
        
        
    } 
    
    function renderPage() {

        // no authorization so show auth button
        if (!$this->endPoint || !$this->accessToken) {
            unset($_SESSION["allthings"]);
            $tc .= "<div class=\"authorize\"><h2>" . HousePanelApp::APPNAME . "</h2>";
            $tc.= $this->authButton();
            $tc.= "</div>";
        // reload the page after the options Ajax changes
        } else if ( isset($_POST["options"]) ) {
            $location = $this->returnURL;
            header("Location: $location");
            exit(0);
        // show the main page
        } else {
            
            // read all the smartthings from API
            $allthings = getAllThings();
        
        // get the options tab and options values
        $options= getOptions($allthings);
        $thingoptions = $options["things"];
        $roomoptions = $options["rooms"];
        $indexoptions = $options["index"];

        // get the skin directory name or use the default
        $skindir = $options["skin"];
        if (! $skindir || !file_exists("$skindir/housepanel.css") ) {
            $skindir = "skin-housepanel";
        }
        
        // check if custom tile CSS is present
        // if it isn't then refactor the index and create one
        if ( !file_exists("$skindir/customtiles.css")) {
            refactorOptions($allthings);
            writeCustomCss($skindir, "");
        }
                
        $tc.= '<div id="tabs"><ul id="roomtabs">';
        // go through rooms in order of desired display
        for ($k=0; $k< count($roomoptions); $k++) {
            
            // get name of the room in this column
            $room = array_search($k, $roomoptions);
            // $roomlist = array_keys($roomoptions, $k);
            // $room = $roomlist[0];
            
            // use the list of things in this room
            if ($room !== FALSE) {
                $tc.= "<li class=\"drag\"><a href=\"#" . strtolower($room) . "-tab\">$room</a></li>";
            }
        }
        
        // create a configuration tab
//        $room = "Options";
//        $tc.= "<li class=\"nodrag\"><a href=\"#" . strtolower($room) . "-tab\">$room</a></li>";
        $tc.= '</ul>';
        
        $cnt = 0;
        $kioskmode = ($options["kiosk"] == "true" || $options["kiosk"] == "yes" || $options["kiosk"] == "1");

        // changed this to show rooms in the order listed
        // this is so we just need to rewrite order to make sortable permanent
        // for ($k=0; $k< count($roomoptions); $k++) {
        foreach ($roomoptions as $room => $kroom) {
            
            // get name of the room in this column
            // $room = array_search($k, $roomoptions);
            // $roomlist = array_keys($roomoptions, $k);
            // $room = $roomlist[0];

            // use the list of things in this room
            // if ($room !== FALSE) {
            if ( key_exists($room, $thingoptions)) {
                $things = $thingoptions[$room];
                $tc.= getNewPage($cnt, $allthings, $room, $kroom, $things, $indexoptions, $kioskmode);
            }
        }
        
        // add the options tab - changed to show as a separate page; see below
//        $tc.= "<div id=\"options-tab\">";
//        $tc.= getOptionsPage($options, $returnURL, $allthings, $sitename);
//        $tc.= "</div>";
        // end of the tabs
        $tc.= "</div>";
        
        // create button to show the Options page instead of as a Tab
        // but only do this if we are not in kiosk mode
        $tc.= "<div class=\"buttons\">";
        if ( !$kioskmode ) {
            $tc.= "<form class=\"buttons\" action=\"$returnURL\"  method=\"POST\">";
            $tc.= hidden("useajax", "showoptions");
            $tc.= hidden("type", "none");
            $tc.= hidden("id", 0);
            $tc.= "<input class=\"submitbutton\" value=\"Options\" name=\"submitoption\" type=\"submit\" />";
            $tc.= "</form>";
            $tc.= "<form class=\"buttons\" action=\"$returnURL\"  method=\"POST\">";
            $tc.= hidden("useajax", "refresh");
            $tc.= hidden("type", "none");
            $tc.= hidden("id", 0);
            $tc.= "<input class=\"submitbutton\" value=\"Refresh\" name=\"submitrefresh\" type=\"submit\" />";
            $tc.= "</form>";
            $tc.='<div id="restoretabs" class="restoretabs">Hide Tabs</div>';
        }
        $tc.='</div>';
   
    } else {

// this should never ever happen...
        if (!$first) {
            echo "<br />Invalid request... you are not authorized for this action.";
            // echo "<br />access_token = $access_token";
            // echo "<br />endpoint = $endpt";
            echo "<br /><br />";
            echo $tc;
            exit;    
        }
    
    }

    // display the dynamically created web site
    echo htmlHeader($skindir);
    echo $tc;
    echo htmlFooter();
           
        }
    }

       
        
    }

    function getSecret() {
        return "client_secret=" . urlencode(CLIENT_SECRET) . "&scope=app&client_id=" . urlencode(CLIENT_ID);
    }
    
    function loadThings($thingtype) {

        $host = $this->endPoint . '/' . $thingtype;
        $headertype = array("Authorization: Bearer " . $this->accessToken);
        $nvpreq = $this->getSecret();
        $response = curl_call($host, $headertype, $nvpreq, "POST");
        if ($response && is_array($response) && count($response)) {
            foreach ($response as $k => $content) {
                $id = $content["id"];
                $thetype = $content["type"];

                // make a unique index for this thing based on id and type
                $idx = $thetype . "|" . $id;
                $this->allthings[$idx] = array("id" => $id, "name" => $content["name"], "value" => $content["value"], "type" => $thetype);
            }
            return true;
        }
        return false;
    }
    
    function getAllThings() {
        return $this->allthings;
    }
    
    function updateThings(Thing $thing) {
        
    }
    
    function loadAllThings() {
        $this->allthings = array();
     
        if ( isset($_SESSION["allthings"]) ) {
            $this->allthings = $_SESSION["allthings"];
        }
    
    // if a prior call failed then we need to reset the session and reload
    if (count($allthings) <= 2 && $endpt && $access_token ) {
        session_unset();
        foreach ($this->groovytypes as $key) {
            $thing = new Thing($key);
            $this->loadThings($key);
        }
        
        $clock = new Thing("clock");
        $this->updateThings($clock);

        // add a clock tile
        $weekday = date("l");
        $dateofmonth = date("M d, Y");
        $timeofday = date("g:i a");
        $timezone = date("T");
        $todaydate = array("weekday" => $weekday, "date" => $dateofmonth, "time" => $timeofday, "tzone" => $timezone);
        $allthings["clock|clockdigital"] = array("id" => "clockdigital", "name" => "Digital Clock", "value" => $todaydate, "type" => "clock");
        // TODO - implement an analog clock
        // $allthings["clock|clockanalog"] = array("id" => "clockanalog", "name" => "Analog Clock", "value" => $todaydate, "type" => "clock");

        // add 4 generic iFrame tiles
        $forecast = "<iframe width=\"490\" height=\"220\" src=\"forecast.html\" frameborder=\"0\"></iframe>";
        $allthings["frame|frame1"] = array("id" => "frame1", "name" => "Weather Forecast", "value" => array("name"=>"Weather Forecast", "frame"=>"$forecast","status"=>"stop"), "type" => "frame");
        $allthings["frame|frame2"] = array("id" => "frame2", "name" => "Frame 2", "value" => array("name"=>"Frame 2", "frame"=>"","status"=>"stop"), "type" => "frame");
        $allthings["frame|frame3"] = array("id" => "frame3", "name" => "Frame 3", "value" => array("name"=>"Frame 3", "frame"=>"","status"=>"stop"), "type" => "frame");
        $allthings["frame|frame4"] = array("id" => "frame4", "name" => "Frame 4", "value" => array("name"=>"Frame 4", "frame"=>"","status"=>"stop"), "type" => "frame");
        
        // add a video tile
        $allthings["video|vid1"] = array("id" => "vid1", "name" => "Video", "value" => array("name"=>"Sample Video", "url"=>"vid1"), "type" => "video");
        
        $_SESSION["allthings"] = $allthings;
    }
    return $allthings; 
}
        
    }

    // function to get authorization code
    // this does a redirect back here with results
    function getAuthCode($returl)
    {
        $nvpreq="response_type=code&client_id=" . urlencode(CLIENT_ID) . "&scope=app&redirect_uri=" . urlencode($this->returnURL);
        $location = ST_WEB . "/oauth/authorize?" . $nvpreq;
        header("Location: $location");
    }

    function getAccessToken($code) {

        $host = ST_WEB . "/oauth/token";
        $ctype = "application/x-www-form-urlencoded";
        $headertype = array('Content-Type: ' . $ctype);

        $nvpreq = "grant_type=authorization_code&code=" . urlencode($code) . "&client_id=" . urlencode(CLIENT_ID) .
                             "&client_secret=" . urlencode(CLIENT_SECRET) . "&scope=app" . "&redirect_uri=" . $this->returnURL;

        $response = curl_call($host, $headertype, $nvpreq, "POST");
        if ($response) {
            $this->accessToken = $response["access_token"];
        }

    }

    // returns an array of the first endpoint and the sitename
    // this only works if the clientid within theendpoint matches our auth version
    function getEndpoint() {

        $host = ST_WEB . "/api/smartapps/endpoints";
        $headertype = array("Authorization: Bearer " . $this->accessToken);
        $response = curl_call($host, $headertype);

        if ($response && is_array($response)) {
            $endclientid = $response[0]["oauthClient"]["clientId"];
            if ($endclientid == CLIENT_ID) {
                    $this->endPoint = $response[0]["uri"];
                    $this->siteName = $response[0]["location"]["name"];
            }
        }
    }
    

}
   