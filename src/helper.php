<?php 
use Jtech\Dumper\Dumper;
if(!function_exists('jd')){
    function jd(mixed $args){
        Dumper::dump($args);
    }
}

if(!function_exists('jdd')){
    function jdd(mixed $args){
        Dumper::dump($args);
        exit;
    }
}