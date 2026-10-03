<?php
session_start();
session_destroy();
header("Location: /myproject/login.php");
exit();
?>