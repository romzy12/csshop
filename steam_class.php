<?php

class Steam
{
    const STEAM_LOGIN = 'https://steamcommunity.com/openid/login';
    const STEAM_AVATAR_PATH = 'http://cdn.akamai.steamstatic.com/steamcommunity/public/images/avatars';
    const STEAM_ICON_URL = 'http://steamcommunity-a.akamaihd.net/economy/image';

    private $inventory = array();

    public function getInventory($steamID)
    {
        $inventory = $this->_getInventory($steamID);

        if ($inventory['success'] == 1) {

            foreach($inventory['rgDescriptions'] as &$v){

                $v['icon_url'] = self::STEAM_ICON_URL."/".$v['icon_url'];

                $descriptions = "";
                foreach($v['descriptions'] as $dsc){
                    if($dsc['type']=="html") $descriptions .= '<p'.($dsc['color'] ? ' style="color:#'.$dsc['color'].'"' : "").'>'.$dsc['value'].'</p>';
                }
                $v['descriptions'] = $descriptions;

                foreach($v['tags'] as $tg){
                    $v["tag_".strtolower($tg['category'])] = $tg['name'];
                }

                $v['link_game'] = $v['actions'][0]['link'];
                unset($v['actions'], $v['market_actions'], $v['tags']);

            }

            return $inventory;

        } else {
            return false;
        }

    }

    public function checkInventoryAccess($steamID)
    {

        $inventory = $this->_getInventory($steamID);

        if ($inventory['success'] == 1) return true;
        return false;

    }

    private function _getInventory($steamID)
    {

        if ($this->inventory[$steamID]) return $this->inventory[$steamID];
        else {

            $inventory = @file_get_contents("http://steamcommunity.com/profiles/" . $steamID . "/inventory/json/730/2/?l=russian");
            $data = @json_decode($inventory, true);

            if ($data['success'] == 1) {
                $this->inventory[$steamID] = $data;
                return $data;
            }

        }

    }

    function getPrice($item)
    {

        $price = @file_get_contents("http://steamcommunity.com/market/priceoverview/?appid=730&currency=5&market_hash_name=".urlencode($item));
        $data = @json_decode($price, true);

        if ($data['success'] == 1) {
            return $data;
        }

        return false;

    }


    /**
     * Get the URL to sign into steam
     *
     * @param mixed returnTo URI to tell steam where to return, MUST BE THE FULL URI WITH THE PROTOCOL
     * @param bool useAmp Use & in the URL, true; or just &, false.
     * @return string The string to go in the URL
     */
    public static function loginUrl($returnTo = false, $useAmp = true)
    {
        $uri = explode("?", $_SERVER['REQUEST_URI']);
        $uri = str_replace("index.php", "", $uri[0]);
        $returnTo = (!$returnTo) ? (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $uri : $returnTo;

        $params = array(
            'openid.ns'			=> 'http://specs.openid.net/auth/2.0',
            'openid.mode'		=> 'checkid_setup',
            'openid.return_to'	=> $returnTo,
            'openid.realm'		=> (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'],
            'openid.identity'	=> 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id'	=> 'http://specs.openid.net/auth/2.0/identifier_select',
        );

        $sep = ($useAmp) ? '&' : '&';
        return self::STEAM_LOGIN . '?' . http_build_query($params, '', $sep);
    }

    /**
     * Validate the incoming data
     *
     * @return string Returns the SteamID64 if successful or empty string on failure
     */
    public static function loginValidate()
    {
        // Star off with some basic params
        $params = array(
            'openid.assoc_handle'	=> $_GET['openid_assoc_handle'],
            'openid.signed'			=> $_GET['openid_signed'],
            'openid.sig'			=> $_GET['openid_sig'],
            'openid.ns'				=> 'http://specs.openid.net/auth/2.0',
        );

        // Get all the params that were sent back and resend them for validation
        $signed = explode(',', $_GET['openid_signed']);
        foreach($signed as $item)
        {
            $val = $_GET['openid_' . str_replace('.', '_', $item)];
            $params['openid.' . $item] = get_magic_quotes_gpc() ? stripslashes($val) : $val;
        }

        // Finally, add the all important mode.
        $params['openid.mode'] = 'check_authentication';

        // Stored to send a Content-Length header
        $data =  http_build_query($params);
        $context = stream_context_create(array(
            'http' => array(
                'method'  => 'POST',
                'header'  =>
                    "Accept-language: en\r\n".
                    "Content-type: application/x-www-form-urlencoded\r\n" .
                    "Content-Length: " . strlen($data) . "\r\n",
                'content' => $data,
            ),
        ));

        $result = file_get_contents(self::STEAM_LOGIN, false, $context);

        // Validate wheather it's true and if we have a good ID
        preg_match("#^http://steamcommunity.com/openid/id/([0-9]{17,25})#", $_GET['openid_claimed_id'], $matches);
        $steamID64 = is_numeric($matches[1]) ? $matches[1] : 0;

        // Return our final value
        //return preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $steamID64 : '';
        $slf = "http://steamcommunity.com/profiles/$steamID64/?xml=1";
        $url = simplexml_load_file($slf);

        $ret = array("result"=>true,
            "nick"=>(string)$url->steamID[0],
            "username"=>(string)$url->steamID64[0],
            "avatarIcon"=>(string)$url->avatarIcon[0],
            "avatarMedium"=>(string)$url->avatarMedium[0],
            "avatarFull"=>(string)$url->avatarFull[0]);
        // Return our final value
        return preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $ret : array("result"=>false);

    }
}
