<?php
class Scraper {
    public function scrape_web_html($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $ip = rand(0, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(0, 255);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["REMOTE_ADDR: $ip", "HTTP_X_FORWARDED_FOR: $ip"]);
        curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:56.0) Gecko/20100101 Firefox/56.0");
        curl_setopt($curl, CURLOPT_REFERER, "http://www.google.com");
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        $html = curl_exec($curl);
        curl_close($curl);
        return $html;
    }
	
    public function scrape_web_json($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $ip = rand(0, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(0, 255);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["REMOTE_ADDR: $ip", "HTTP_X_FORWARDED_FOR: $ip", "Content-Type: application/json"]);
        curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:56.0) Gecko/20100101 Firefox/56.0");
        curl_setopt($curl, CURLOPT_REFERER, "http://www.google.com");
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        $json = curl_exec($curl);
        curl_close($curl);
        return $json;
	}

    public function match_all_key_value($regex, $str, $keyIndex = 1, $valueIndex = 2) {
        $arr = array();
        preg_match_all($regex, $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $arr[$m[$keyIndex]] = $m[$valueIndex];
        }
        return $arr;
    }

    public function match_all($regex, $str, $index = 0) {
        if (preg_match_all($regex, $str, $matches) === false) {
            return false;
        } else {
            return $matches[$index];
        }
    }

    public function match($regex, $str, $index = 0) {
        if (preg_match($regex, $str, $match) == 1) {
            return $match[$index];
        } else {
            return false;
        }
    }
}