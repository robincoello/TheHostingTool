<?php
/* Copyright © 2014 TheHostingTool
 *
 * This file is part of TheHostingTool.
 *
 * TheHostingTool is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * TheHostingTool is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with TheHostingTool.  If not, see <http://www.gnu.org/licenses/>.
 */

class whm {

    # START THE MO TRUCKIN FUNCTIONS #

    public $name = "cPanel/WHM"; # THT Values
    public $hash = true; # Password or Access Hash?

    private $server;

    // Specifically reserved usernames
    private static $reservedUsernames = array("cpldap", "leechprotect", "modsec", "munin", "root", "postgres", "horde",
        "cphulkd", "eximstats", "roundcube", "logaholic", "virtfs", "spamassassin", "all", "dovecot", "tomcat",
        "mailman", "proftpd", "cpbackup", "files", "dirs", "tmp", "toor");

    // Valid username regex
    private static $validUsernameRegex = "/^[a-z][a-z0-9]{0,7}$/";

    public function __construct($serverId = null) {
        if(!is_null($serverId)) {
            $this->server = (int)$serverId;
        }
    }

    private function serverDetails($server) {
        global $db;
        global $main;
        $query = $db->query("SELECT * FROM `<PRE>servers` WHERE `id` = '{$db->strip($server)}'");
        if($db->num_rows($query) == 0) {
            $array['Error'] = "That server doesn't exist!";
            $array['Server ID'] = $server;
            $main->error($array);
            return;
        }
        else {
            return $db->fetch_array($query);
        }
    }

    private function remote($url, $xml = 0, $term = false, $returnErrors = false, $post = null) {
        global $db;
        $data = $this->serverDetails($this->server);
        $cleanaccesshash = preg_replace("'(\r|\n)'","",$data['accesshash']);
        $authstr = $data['user'] . ":" . $cleanaccesshash;
        $ch = curl_init();
        if($db->config("whm-ssl") == 1) {
            $fullUrl = "https://" . $data['host'] . ":2087" . $url;
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        else {
            $fullUrl = "http://" . $data['host'] . ":2086" . $url;
        }
        if($post != null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: WHM $authstr"));
        $data = curl_exec($ch);
        if($data === false) {
            if($returnErrors) {
                return curl_error($ch);
            }
            global $main;
            $main->error(array("WHM Connection Error" => curl_error($ch)));
            return false;
        }
        curl_close($ch);
        if(stripos($data, "Content-type: text/html") !== false) {
            if($returnErrors) {
                return "WHM returned HTML. Is it unlicensed?";
            }
            global $main;
            $main->error(array("WHM Error" => "WHM returned HTML. Is it unlicensed?"));
            return false;
        }
        //END
        if($term == true) {
            return true;

        }
        elseif(strstr($data, "SSL encryption is required")) {
            if($returnErrors) {
                return "THT must connect via SSL!";
            }
            global $main;
            $main->error(array("WHM Error" => "THT must connect via SSL!"));
            return false;
        }
        elseif(!$xml) {
            $xml = new SimpleXMLElement($data);
        }
        else {
            return $data;
        }
        return $xml;
    }

    public function GenUsername() {
        $user = "";
        $t = rand(5,8);
        for ($digit = 0; $digit < $t; $digit++) {
            $r = rand(0,1);
            $c = ($r==0)? rand(65,90) : rand(97,122);
            $user .= chr($c);
        }
        return $user;
    }

    public function GenPassword() {
        $passwd = "";
        for ($digit = 0; $digit < 5; $digit++) {
            $r = rand(0,1);
            $c = ($r==0)? rand(65,90) : rand(97,122);
            $passwd .= chr($c);
        }
        return $passwd;
    }

    public function signup($server, $reseller, $user = '', $email = '', $pass = '') {
        global $main;
        global $db;
        if ($user == '') { $user = $main->getvar['username']; }
        if ($email == '') { $email = $main->getvar['email']; }
        if ($pass == '') { $pass = $main->getvar['password']; }
        $this->server = $server;
        $action = "/xml-api/createacct".
                    "?username=". $user . "".
                    "&password=". $pass ."".
                    "&domain=". $main->getvar['fdom'] ."".
                    "&plan=". $main->getvar['fplan'] ."".
                    "&contactemail=". $email ."";
        if($reseller) {
            $action .= "&reseller=1";
        }
        //echo $action."<br />". $reseller;
        $command = $this->remote($action);

        if($command->result->status == 1) {
            return true;
        }
        else {
            echo "Error: ". (isset($command->result->statusmsg) ? $command->result->statusmsg : $command->statusmsg);
        }
    }

    public function suspend($user, $server, $reason = false) {
        $this->server = $server;
        $action = "/xml-api/suspendacct?user=" . strtolower($user);
        $command = $this->remote($action);
                if($reason == false) {
                    $command = $this->remote($action);
                }
                else {
                    $command = $this->remote($action . "&reason=" . str_replace(" ", "%20", $reason));
                }
        if($command->result->status == 1) {
            return true;
        }
        else {
            return false;
        }
    }

    public function unsuspend($user, $server) {
        $this->server = $server;
        $action = "/xml-api/unsuspendacct?user=" . strtolower($user);
        $command = $this->remote($action);
        if($command->result->status == 1) {
            return true;
        }
        else {
            return false;
        }
    }
    public function terminate($user, $server) {
        $this->server = $server;
        $action = "/xml-api/removeacct?user=" . strtolower($user);
        $command = $this->remote($action, 0, true);
        if($command == true) {
            return true;
        }
        else {
            return false;
        }
    }
    public function listaccs($server) {
        $this->server = $server;
        $action = "/xml-api/listaccts";
        $command = $this->remote($action, 1);
        $xml = new DOMDocument();
        $xml->loadXML($command);
        $list = $xml->getElementsByTagName('user');
        //This code underneath taken from http://www.phpclasses.org/browse/file/20658.html CBA to code my own =]
        $i=0;
        foreach ($list AS $element)
        {
            foreach ($element->childNodes AS $item)
            {
                $result[$i]['user']=$item->nodeValue;
                $i++;
            }
        }

        $list = $xml->getElementsByTagName('domain');
        $i=0;
        foreach ($list AS $element)
        {
            foreach ($element->childNodes AS $item)
            {
                $result[$i]['domain']=$item->nodeValue;
                $i++;
            }
        }

        $list = $xml->getElementsByTagName('plan');
        $i=0;
        foreach ($list AS $element)
        {
            foreach ($element->childNodes AS $item)
            {
                $result[$i]['package']=$item->nodeValue;
                $i++;
            }
        }

        $list = $xml->getElementsByTagName('unix_startdate');
        $i=0;
        foreach ($list AS $element)
        {
            foreach ($element->childNodes AS $item)
            {
                $result[$i]['start_date']=$item->nodeValue;
                $i++;
            }
        }

        $list = $xml->getElementsByTagName('email');
        $i=0;
        foreach ($list AS $element)
        {
            foreach ($element->childNodes AS $item)
            {
                $result[$i]['email']=$item->nodeValue;
                $i++;
            }
        }
        //return the result array
        return $result;
    }
    public function changePwd($acct, $newpwd, $server)
    {
        $this->server = $server;
        $action = '/xml-api/passwd?user=' . $acct . '&pass=' . $newpwd;
        $command = $this->remote($action);
        if($command->passwd->status == '1') {
            return true;
        }
        else {
            if(isset($command->passwd->statusmsg)) {
                return $command->passwd->statusmsg;
            }
            else {
                return false;
            }
        }
    }

    public function testConnection($serverId = null) {
        if(!is_null($serverId)) {
            $this->server = (int)$serverId;
        }

        $command = $this->remote("/xml-api/version", 0, false, true);
        if((is_object($command)) and (get_class($command) == "SimpleXMLElement")) {
            if(isset($command->version)) {
                return true;
            }
            if(isset($command->data->reason)) {
                return $command->data->reason;
            }
            return print_r($command, true);
        }
        else {
            return $command;
        }
    }

    public function passwdStrength($passwd) {
        $data = $this->serverDetails($this->server);
        // The cPanel API is pretty nice. We can easily access internal APIs from the external one.
        $strength = $this->remote("/xml-api/cpanel", 0, false, true, array("cpanel_xmlapi_user" => $data["user"],
            "cpanel_xmlapi_module" => "PasswdStrength", "cpanel_xmlapi_func" => "get_password_strength", "password" => $passwd));
        $required = $this->remote("/xml-api/cpanel", 0, false, true, array("cpanel_xmlapi_user" => $data["user"],
            "cpanel_xmlapi_module" => "PasswdStrength", "cpanel_xmlapi_func" => "get_required_strength", "app" => "createacct"));
        if(is_string($strength)) {
            return $strength;
        }
        if(is_string($required)) {
            return $required;
        }
        return array("strength" => (int)$strength->data->strength, "required" => (int)$required->data->strength);
    }

    public function checkUsername($username) {
        // We're not going to check with the actual server because that's an expensive (time) operation
        // cPanel only allows >8 char usernames in very specific instances
        if(!preg_match(self::$validUsernameRegex, $username)) {
            return "Username must be alphanumeric, cannot start with a number, lowercase, and between 1 and 8 characters.";
        }
        if(in_array($username, self::$reservedUsernames, true)) {
            return "Reserved username.";
        }
        if(strlen($username) >= 4 && substr($username, 0, 4) == "test") {
            return "Username cannot begin with \"test\"";
        }
        if(strlen($username) >= 7 && substr($username, -7) == "assword") {
            return "Username cannot end with \"assword\"";
        }
        return true;
    }
}
