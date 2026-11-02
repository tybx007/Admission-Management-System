<?php

$password = '1234';

$hash = password_hash($password, PASSWORD_BCRYPT);

echo $hash;