<?php
namespace App\Services;

class EditorFactory
{
    public static function make($type)
    {
        return "Adapter for ".$type;
    }
}
