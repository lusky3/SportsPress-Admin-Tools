<?php
require_once 'C:\development\git\SportsPress-Admin-Tools\tests\bootstrap.php';
$req = new WP_REST_Request();
$req->set_header('X-Signature', 'abc');
var_dump(array_keys($req->get_headers()));
