<?php
declare(strict_types=1);
namespace OpenFrontMC\Security;
final class InputValidator{
    public static function countryName(string $name):bool{
        $name=trim($name);
        preg_match_all('/./us',$name,$characters);
        $length=count($characters[0]);
        return $length>=3&&$length<=24&&preg_match('/^[\p{L}\p{N} _-]+$/u',$name)===1;
    }
    public static function playerName(string $name):bool{return preg_match('/^[A-Za-z0-9_]{3,16}$/',$name)===1;}
    public static function coordinate(int $value,int $limit):bool{return $value>=0&&$value<$limit;}
    /** @param mixed $value */
    public static function positiveInteger($value,int $maximum=1000000):?int{
        if(is_int($value)){$parsed=$value;}elseif(is_string($value)&&preg_match('/^[0-9]{1,9}$/',$value)===1){$parsed=(int)$value;}else{return null;}
        return $parsed>0&&$parsed<=$maximum?$parsed:null;
    }
}
