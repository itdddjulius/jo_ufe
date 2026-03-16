<?php
namespace App\Helpers;

class FileHelper
{
    public static function sanitize($name)
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
