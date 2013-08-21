<?php
namespace Utils;
 
class Color {
    protected static $ANSI_CODES = array(
        "off"        => 0,
        "b"          => 1,
        "i"          => 3,
        "underline"  => 4,
        "blink"      => 5,
        "inverse"    => 7,
        "hidden"     => 8,
        "black"      => 30,
        "red"        => 31,
        "green"      => 32,
        "yellow"     => 33,
        "blue"       => 34,
        "magenta"    => 35,
        "cyan"       => 36,
        "white"      => 37,
        "blackbg"   => 40,
        "redbg"     => 41,
        "greenbg"   => 42,
        "yellowbg"  => 43,
        "bluebg"    => 44,
        "magentabg" => 45,
        "cyanbg"    => 46,
        "whitebg"   => 47
    );
 
    public static function set($str) {
      //primeiro eu pego o valor da tag
      $reg = '#</([a-z]+)>#iU';

      if ($c = preg_match_all($reg, $str, $matches)) {
        $final_text = "";
        $arr_itens = array();

        //adiciona comeco sem tag
        $pos = strpos($str, '<');
        if ($pos > 0) {
          $notag = substr($str, 0, $pos);
          $arr = explode($notag, $str);
          $str = $arr[1];

          $final_text .= $notag;
        }

        $str_temp = $str;
        $arr_exp = $matches[1];

        foreach ($arr_exp as $exploder) {
          if (strlen($str_temp) != strlen($str)) {
            $arr_temp = explode($str_temp, $str);
            $str_temp = $arr_temp[1];
          }

          $arr = explode('</'.$exploder.'>', $str_temp);
          $str_temp = $arr[0];
          

          array_push($arr_itens, $arr[0]);

          //adiciona resto sem tag
          $pos = strpos($arr[1], '<');
          if ($pos === false) {
            array_push($arr_itens, $arr[1]);
          }
        }

        #echo "\n\n***ITENS***\n";
        #print_r($arr_itens);

        //limpa as tags que não serao usadas
        $arr_clean = array();
        foreach ($arr_itens as $item) {
          $str_clean = $item;
          foreach ($arr_exp as $exploder) {
            $str_clean = str_replace('</'.$exploder.'>', '', $str_clean);
          }

          array_push($arr_clean, $str_clean);
        }

        $arr_colors = array();
        $arr_contents = array();
        foreach ($arr_clean as $item) {
          $reg = '(<[^>]+>)';
          $color = "";

          if ($c = preg_match_all("/".$reg."/is", $item, $matches)) {
            $tags = $matches[1];
           
            foreach ($tags as $tag) {
              $re = '.*?((?:[a-z][a-z0-9_]*))';

              if ($c = preg_match_all("/".$re."/is", $tag, $matches)) {
                  $color .= $matches[1][0] . "+";
              }
            }

            $color = substr($color, 0, -1);
            array_push($arr_colors, $color);
          }

          //agora eu pego o conteudo dentro dessa tag
          $str_content = $item;
          foreach ($arr_exp as $exploder) $str_content = str_replace('<'.$exploder.'>', '', $str_content);
          if(strlen($str_content) > 0) array_push($arr_contents, $str_content);
        }

        
        for ($i = 0; $i < count($arr_contents); $i++) {
          $color = str_replace($arr_contents[$i], '', $arr_colors[$i]);
          $final_text .= self::returnColorfulText($arr_contents[$i], $color);
        }

        return $final_text;
      }

      return $str;
    }

    private static function returnColorfulText($txt, $color) {
      $color_attrs = explode("+", $color);
      $ansi_str = "";
      foreach ($color_attrs as $attr) {
        $ansi_str .= "\033[" . self::$ANSI_CODES[$attr] . "m";
      }

      $ansi_str .= $txt . "\033[" . self::$ANSI_CODES["off"] . "m";
      return $ansi_str;
    }
}
