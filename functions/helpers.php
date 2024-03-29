<?php

if (!function_exists('str_tabs_replace')) {
    function str_tabs_replace(array|string $search, array|string $replace, array|string $subject): array|string
    {
        return str_replace("\t", '   ', str_replace($search, $replace, $subject));
    }
}
