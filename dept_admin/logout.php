<?php
session_start();
session_destroy();
header('Location: /jkkniu-admission/index.php');
exit();
?>
