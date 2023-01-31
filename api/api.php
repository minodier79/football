<?php
header('Content-type: application/json; charset=UTF-8');

$path = 'api';

// include header
include_once ("../partials/header.php");

echo $class->launch();
